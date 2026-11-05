<?php

use Illuminate\Support\ServiceProvider;
use NourApi\Framework\Application;
use NourApi\Framework\Testing\Concerns\RestoresPhpHandlers;
use PHPUnit\Framework\TestCase;

class ProviderLifecycleTest extends TestCase
{
    use RestoresPhpHandlers;

    public function testProvidersRegisteredDuringBootAreBootedBeforeCompletion(): void
    {
        $app = new Application;
        $child = new class($app) extends ServiceProvider {
            public int $boots = 0;
            public function boot(): void { $this->boots++; }
        };
        $parent = new class($app, $child) extends ServiceProvider {
            public function __construct($app, private ServiceProvider $child) { parent::__construct($app); }
            public function boot(): void { $this->app->register($this->child); }
        };
        $app->register($parent);
        $app->boot();
        $app->boot();
        self::assertSame(1, $child->boots);
    }

    public function testReentrantBootDoesNotBootAProviderRecursively(): void
    {
        $app = new Application;
        $provider = new class($app) extends ServiceProvider {
            public int $boots = 0;
            public function boot(): void {
                if (++$this->boots > 1) { throw new RuntimeException('Recursive provider boot.'); }
                $this->app->boot();
            }
        };
        $app->register($provider);
        $app->boot();
        self::assertSame(1, $provider->boots);
    }

    public function testSuccessfulProvidersAreNotRepeatedWhenAnotherProviderRetries(): void
    {
        $app = new Application;
        $first = new class($app) extends ServiceProvider {
            public int $boots = 0;
            public function boot(): void { $this->boots++; }
        };
        $flaky = new class($app) extends ServiceProvider {
            public int $boots = 0;
            public function boot(): void {
                if (++$this->boots === 1) { throw new RuntimeException('Transient boot failure.'); }
            }
        };
        $app->register($first);
        $app->register($flaky);
        try { $app->boot(); } catch (RuntimeException) {}
        $app->boot();
        self::assertSame(1, $first->boots);
        self::assertSame(2, $flaky->boots);
    }
}
