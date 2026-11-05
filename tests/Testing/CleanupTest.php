<?php

use NourApi\Framework\Application;
use NourApi\Framework\Testing\Concerns\RestoresPhpHandlers;
use PHPUnit\Framework\TestCase;

class CleanupTest extends TestCase
{
    use RestoresPhpHandlers;

    public function testEveryCleanupRunsAndApplicationIsFlushedAfterFailure(): void
    {
        $harness = new class('placeholder') extends \NourApi\Framework\Testing\TestCase {
            public function createApplication() { return new Application(dirname(__DIR__, 2)); }
            public function start(): void { $this->setUp(); }
            public function finish(): void { $this->tearDown(); }
            public function addCleanup(callable $callback): void { $this->beforeApplicationDestroyed($callback); }
            public function application() { return $this->app; }
        };
        $harness->start();
        $app = $harness->application();
        $app->instance('cleanup-marker', new stdClass);
        $laterCleanupRan = false;
        $harness->addCleanup(static function () { throw new RuntimeException('first cleanup failed'); });
        $harness->addCleanup(function () use (&$laterCleanupRan) { $laterCleanupRan = true; });
        $failure = null;
        try {
            $harness->finish();
        } catch (RuntimeException $e) {
            $failure = $e;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('first cleanup failed', $failure->getMessage());
        self::assertTrue($laterCleanupRan);
        self::assertNull($harness->application());
        self::assertFalse($app->bound('cleanup-marker'));
    }
}
