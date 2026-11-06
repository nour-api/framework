<?php

namespace NourApi\Framework\Runtime;

use InvalidArgumentException;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

/** Run independent I/O tasks concurrently; settle all tasks before returning or throwing. */
final class Concurrent
{
    public static function run(array $tasks, int $concurrency = 16): array
    {
        if ($concurrency < 1) {
            throw new InvalidArgumentException('Concurrency must be positive.');
        }
        foreach ($tasks as $task) {
            if (! is_callable($task)) {
                throw new InvalidArgumentException('Every concurrent task must be callable.');
            }
        }
        if ($tasks === []) {
            return [];
        }
        if (! extension_loaded('swoole')) {
            throw new RuntimeException('Concurrent tasks require ext-swoole; synchronous application handling remains available.');
        }
        if (Coroutine::getCid() < 0) {
            $result = [];
            $error = null;
            \Swoole\Coroutine\run(static function () use ($tasks, $concurrency, &$result, &$error): void {
                try {
                    $result = self::run($tasks, $concurrency);
                } catch (Throwable $exception) {
                    $error = $exception;
                }
            });
            if ($error !== null) {
                throw $error;
            }
            return $result;
        }

        $keys = array_keys($tasks);
        $next = 0;
        $values = [];
        $errors = [];
        $workers = min(count($tasks), $concurrency);
        $done = new Channel($workers);
        for ($worker = 0; $worker < $workers; $worker++) {
            $id = Coroutine::create(static function () use ($tasks, $keys, &$next, &$values, &$errors, $done): void {
                try {
                    while ($next < count($keys)) {
                        $key = $keys[$next++];
                        try {
                            $values[$key] = $tasks[$key]();
                        } catch (Throwable $exception) {
                            $errors[$key] = $exception;
                        }
                    }
                } finally {
                    $done->push(true);
                }
            });
            if ($id === false) {
                // Already started workers still drain the queue and are joined below.
                $errors[] = new RuntimeException('Unable to create a coroutine.');
                $workers = $worker;
                break;
            }
        }
        for ($worker = 0; $worker < $workers; $worker++) {
            $done->pop();
        }
        $done->close();
        if ($errors !== []) {
            throw reset($errors);
        }
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $values[$key];
        }
        return $result;
    }
}
