<?php

// Explicit local integration check. Never uses an application database or server.
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use NourApi\Framework\Application;
use NourApi\Framework\Console\Kernel;
use NourApi\Framework\Exceptions\Handler;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class MariaDbIntegrationRecord extends Model
{
    protected $table = 'integration_records';
    protected $fillable = ['email'];
    public $timestamps = false;
}

$directory = null;
$server = null;
$exit = 0;
try {
    $finder = new ExecutableFinder;
    $binary = $finder->find('mariadbd');
    $initializer = $finder->find('mariadb-install-db');
    if ($binary === null || $initializer === null || ! in_array('mysql', PDO::getAvailableDrivers(), true) || ! function_exists('posix_getpwuid')) {
        throw new RuntimeException('Requires mariadbd, mariadb-install-db, a PDO MySQL driver and Unix POSIX functions.');
    }
    $identity = posix_getpwuid(posix_geteuid());
    if ($identity === false) { throw new RuntimeException('Cannot determine the current Unix user.'); }
    $directory = sys_get_temp_dir().'/nour-mariadb-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $data = $directory.'/data';
    $socket = $directory.'/mysql.sock';
    (new Process([$initializer, '--no-defaults', '--datadir='.$data, '--auth-root-authentication-method=socket', '--skip-test-db'], null, null, null, 120))->mustRun();
    $server = new Process([$binary, '--no-defaults', '--datadir='.$data, '--socket='.$socket, '--skip-networking', '--user='.$identity['name'], '--pid-file='.$directory.'/server.pid', '--log-error='.$directory.'/server.log', '--innodb-buffer-pool-size=16M', '--innodb-log-file-size=4M'], null, null, null, 120);
    $server->start();
    $pdo = null;
    for ($attempt = 0; $attempt < 100 && $server->isRunning(); $attempt++) {
        try {
            $pdo = new PDO('mysql:unix_socket='.$socket, $identity['name'], '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            break;
        } catch (PDOException) {
            usleep(100000);
        }
    }
    if ($pdo === null) { throw new RuntimeException('The isolated MariaDB server did not become ready; inspect its startup requirements.'); }
    $pdo->exec('create database nour_integration character set utf8mb4 collate utf8mb4_unicode_ci');

    $app = new Application(dirname(__DIR__, 2));
    $app->configure('app');
    $app->singleton(ExceptionHandler::class, Handler::class);
    $app->configure('database');
    $app['config']->set('database.default', 'integration');
    $app['config']->set('database.connections.integration', [
        'driver' => 'mariadb', 'unix_socket' => $socket, 'host' => 'localhost',
        'database' => 'nour_integration', 'username' => $identity['name'], 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
    ]);
    $app->withEloquent();
    $app->boot();
    $connection = $app['db']->connection();
    $schema = $connection->getSchemaBuilder();
    $schema->create('integration_records', static function (Blueprint $table) { $table->id(); $table->string('email')->unique(); });
    $record = MariaDbIntegrationRecord::create(['email' => 'alice@example.com']);
    $record->update(['email' => 'updated@example.com']);
    if (MariaDbIntegrationRecord::findOrFail($record->id)->email !== 'updated@example.com') { throw new RuntimeException('Eloquent CRUD failed.'); }
    if ($connection->table('integration_records')->where('email', "' OR 1=1 --")->count() !== 0) { throw new RuntimeException('SQL value binding failed.'); }
    if (! $app['validator']->make(['email' => 'updated@example.com'], ['email' => 'unique:integration_records,email'])->fails()) { throw new RuntimeException('Database-backed validation failed.'); }
    try {
        $connection->transaction(static function () use ($connection) {
            $connection->table('integration_records')->insert(['email' => 'rolled-back@example.com']);
            throw new RuntimeException('Expected transaction rollback.');
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'Expected transaction rollback.') { throw $e; }
    }
    if ($connection->table('integration_records')->count() !== 1) { throw new RuntimeException('Transaction rollback failed.'); }
    $record->delete();
    if (MariaDbIntegrationRecord::count() !== 0) { throw new RuntimeException('Eloquent deletion failed.'); }

    $migrations = $directory.'/migrations';
    mkdir($migrations);
    file_put_contents($migrations.'/2026_01_01_000000_create_mysql_probe.php', <<<'MIGRATION'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void { \Illuminate\Support\Facades\Schema::create('mysql_probe', function ($table) { $table->id(); }); }
    public function down(): void { \Illuminate\Support\Facades\Schema::dropIfExists('mysql_probe'); }
};
MIGRATION);
    $kernel = new Kernel($app);
    $arguments = ['--path' => [$migrations], '--realpath' => true, '--force' => true];
    if ($kernel->call('migrate', $arguments) !== 0 || ! $schema->hasTable('mysql_probe')) { throw new RuntimeException('MariaDB migrations failed.'); }
    if ($kernel->call('migrate:rollback', $arguments) !== 0 || $schema->hasTable('mysql_probe')) { throw new RuntimeException('MariaDB migration rollback failed.'); }
    $connection->disconnect();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    $exit = 1;
} finally {
    if ($server !== null && $server->isRunning()) { $server->stop(5); }
    if ($directory !== null && ! (new Filesystem)->deleteDirectory($directory)) {
        fwrite(STDERR, "Could not remove the private MariaDB temporary directory.\n");
        $exit = 1;
    }
}
if ($exit === 0) {
    echo "PASS: private MariaDB server, Eloquent CRUD, SQL binding, unique validation, transactions and Artisan migration/rollback\n";
}
exit($exit);
