<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use NourApi\Framework\Application;
use NourApi\Framework\Console\Kernel;
use NourApi\Framework\Http\Request;
use NourApi\Framework\Testing\Concerns\RestoresPhpHandlers;
use NourApi\Framework\Testing\DatabaseTransactions;
use PHPUnit\Framework\TestCase;

class DatabaseIntegrationTest extends TestCase
{
    use RestoresPhpHandlers;

    private function application(): Application
    {
        $app = new Application(dirname(__DIR__));
        $app->configure('app');
        $app->singleton(\Illuminate\Contracts\Debug\ExceptionHandler::class, \NourApi\Framework\Exceptions\Handler::class);
        $app->configure('database');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
        $app->withEloquent();
        $app->boot();
        $app['db']->connection()->getSchemaBuilder()->create('integration_users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
        });
        return $app;
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::unsetEventDispatcher();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function testEloquentCrudAndHttpDispatchUseRealSqlite(): void
    {
        $app = $this->application();
        $user = IntegrationUser::create(['email' => 'alice@example.com']);
        $app->router->get('/users/{id}', static fn ($id) => IntegrationUser::findOrFail($id));
        $response = $app->handle(Request::create('/users/'.$user->id));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('alice@example.com', json_decode($response->getContent(), true)['email']);
        $user->update(['email' => 'updated@example.com']);
        self::assertSame('updated@example.com', IntegrationUser::findOrFail($user->id)->email);
        $user->delete();
        self::assertSame(0, IntegrationUser::count());
    }

    public function testUntrustedValuesCannotChangeExecutedQueryMeaning(): void
    {
        $app = $this->application();
        $table = $app['db']->table('integration_users');
        $table->insert(['email' => 'alice@example.com']);
        self::assertSame(0, $app['db']->table('integration_users')->where('email', "' OR 1=1 --")->count());
        self::assertSame(1, $app['db']->table('integration_users')->count());
    }

    public function testUniqueValidationQueriesTheConfiguredDatabase(): void
    {
        $app = $this->application();
        IntegrationUser::create(['email' => 'alice@example.com']);
        $validator = $app['validator']->make(['email' => 'alice@example.com'], ['email' => 'required|email|unique:integration_users,email']);
        self::assertTrue($validator->fails());
        self::assertArrayHasKey('email', $validator->errors()->toArray());
        self::assertTrue($app['validator']->make(['email' => 'bob@example.com'], ['email' => 'unique:integration_users,email'])->passes());
    }

    public function testTransactionHelperRollsBackRealWritesBeforeDisconnecting(): void
    {
        $app = $this->application();
        $pdo = $app['db']->connection()->getPdo();
        $harness = new class($app) {
            use DatabaseTransactions;
            public array $callbacks = [];
            public function __construct(public Application $app) {}
            protected function beforeApplicationDestroyed(callable $callback) { $this->callbacks[] = $callback; }
        };
        $harness->beginDatabaseTransaction();
        $app['db']->table('integration_users')->insert(['email' => 'temporary@example.com']);
        self::assertSame(1, $app['db']->table('integration_users')->count());
        foreach ($harness->callbacks as $callback) { $callback(); }
        self::assertSame(0, (int) $pdo->query('select count(*) from integration_users')->fetchColumn());
        self::assertFalse($pdo->inTransaction());
    }

    public function testArtisanMigrationAndRollbackAgainstTemporaryDatabase(): void
    {
        $app = $this->application();
        $directory = sys_get_temp_dir().'/nour-migrations-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $file = $directory.'/2026_01_01_000000_create_integration_events.php';
        file_put_contents($file, <<<'MIGRATION'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::create('integration_events', function ($table) { $table->id(); });
    }
    public function down(): void {
        \Illuminate\Support\Facades\Schema::dropIfExists('integration_events');
    }
};
MIGRATION);
        try {
            $kernel = new Kernel($app);
            $arguments = ['--path' => [$directory], '--realpath' => true, '--force' => true];
            self::assertSame(0, $kernel->call('migrate', $arguments), $kernel->output());
            self::assertTrue($app['db']->connection()->getSchemaBuilder()->hasTable('integration_events'));
            self::assertSame(0, $kernel->call('migrate:rollback', $arguments), $kernel->output());
            self::assertFalse($app['db']->connection()->getSchemaBuilder()->hasTable('integration_events'));
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }
}

class IntegrationUser extends Model
{
    protected $table = 'integration_users';
    protected $fillable = ['email'];
    public $timestamps = false;
}
