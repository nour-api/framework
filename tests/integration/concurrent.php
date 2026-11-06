<?php

require __DIR__.'/../../vendor/autoload.php';

use NourApi\Framework\Runtime\Concurrent;
use Swoole\Coroutine;

if (! extension_loaded('swoole')) {
    throw new RuntimeException('This explicit integration check requires ext-swoole.');
}
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
$active = 0;
$maximum = 0;
$intervals = [];
$tasks = [];
foreach (['third', 'first', 'second', 'fourth'] as $key) {
    $tasks[$key] = static function () use ($key, &$active, &$maximum, &$intervals) {
        $active++;
        $maximum = max($maximum, $active);
        $intervals[$key]['start'] = hrtime(true);
        usleep(100000);
        $intervals[$key]['end'] = hrtime(true);
        $active--;
        return $key;
    };
}
$values = Concurrent::run($tasks, 2);
if (array_keys($values) !== array_keys($tasks) || $maximum !== 2 || $active !== 0
    || $intervals['first']['start'] >= $intervals['third']['end']) {
    throw new RuntimeException('Bounded concurrent execution or key ordering failed.');
}
$finished = false;
try {
    Concurrent::run([
        static function () { Coroutine::sleep(.01); throw new LogicException('expected failure'); },
        static function () use (&$finished) { Coroutine::sleep(.05); $finished = true; },
    ]);
    throw new RuntimeException('Task exceptions must propagate.');
} catch (LogicException $exception) {
    if (! $finished || $exception->getMessage() !== 'expected failure') {
        throw new RuntimeException('Failed groups must settle all tasks before propagating their error.');
    }
}
$nested = Concurrent::run(['nested' => static fn () => Concurrent::run(['null' => static fn () => null, 4 => static fn () => false])]);
if ($nested !== ['nested' => ['null' => null, 4 => false]]) {
    throw new RuntimeException('Nested groups must preserve keys and null/false results.');
}
echo "PASS: hooked I/O overlap, bounded task concurrency, ordered keys, nested groups and exception settlement\n";
