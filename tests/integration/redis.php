<?php

// Explicit optional integration check. Owns only its temporary Redis process/socket.
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';

use Illuminate\Contracts\Queue\ShouldQueue;
use NourApi\Framework\Application;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class RedisRoundTripJob implements ShouldQueue
{
    public function handle(): void
    {
        app('events')->dispatch('redis.integration.job');
    }
}

$directory = null;
$process = null;
$exit = 0;
try {
    $binary = (new ExecutableFinder)->find('redis-server');
    if ($binary === null || ! extension_loaded('redis') || ! class_exists('Illuminate\\Redis\\RedisServiceProvider')) {
        throw new RuntimeException('Requires redis-server, phpredis, and optional illuminate/redis:^12.21. Pass an isolated Composer autoloader as the first argument.');
    }
    $directory = sys_get_temp_dir().'/nour-redis-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $socket = $directory.'/redis.sock';
    $process = new Process([$binary, '--port', '0', '--unixsocket', $socket, '--unixsocketperm', '700', '--save', '', '--appendonly', 'no', '--dir', $directory, '--loglevel', 'warning']);
    $process->start();
    for ($attempt = 0; $attempt < 100 && ! file_exists($socket) && $process->isRunning(); $attempt++) {
        usleep(10000);
    }
    if (! file_exists($socket)) {
        throw new RuntimeException('Temporary Redis server did not create its socket.');
    }

    $app = new Application(dirname(__DIR__, 2));
    $app->configure('app');
    $app->configure('database');
    $app['config']->set('database.redis.client', 'phpredis');
    foreach (['default', 'cache'] as $connection) {
        $app['config']->set('database.redis.'.$connection, ['host' => $socket, 'port' => 0, 'database' => 0]);
    }
    $app->configure('cache');
    $app['config']->set('cache.default', 'redis');
    $cache = $app['cache']->store('redis');
    $cache->put('integration', 'value', 60);
    if ($cache->get('integration') !== 'value' || $cache->add('integration', 'overwrite', 60)) {
        throw new RuntimeException('Redis cache read/write or atomic add failed.');
    }
    $firstLock = $cache->lock('integration-lock', 60);
    $secondLock = $cache->lock('integration-lock', 60);
    if (! $firstLock->get() || $secondLock->get() || ! $firstLock->release() || ! $secondLock->get()) {
        throw new RuntimeException('Redis cache lock ownership failed.');
    }
    $secondLock->release();

    $app->configure('queue');
    $app['config']->set('queue.default', 'redis');
    $handled = 0;
    $app['events']->listen('redis.integration.job', function () use (&$handled) { $handled++; });
    $queue = $app['queue']->connection('redis');
    $queue->push(new RedisRoundTripJob);
    $job = $queue->pop();
    if ($job === null) {
        throw new RuntimeException('Redis queue did not return the queued job.');
    }
    $job->fire();
    if ($handled !== 1 || $queue->size() !== 0) {
        throw new RuntimeException('Redis job execution or acknowledgement failed.');
    }
    echo "PASS: isolated Redis cache, atomic add, locks, queue push/pop, job execution and acknowledgement\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    $exit = 1;
} finally {
    if ($process !== null && $process->isRunning()) { $process->stop(2); }
    if ($directory !== null) {
        foreach (glob($directory.'/*') as $file) { unlink($file); }
        rmdir($directory);
    }
}
exit($exit);
