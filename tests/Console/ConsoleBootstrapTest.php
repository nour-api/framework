<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use NourApi\Framework\Application;
use NourApi\Framework\Console\Kernel;
use NourApi\Framework\Exceptions\Handler;
use NourApi\Framework\Testing\Concerns\RestoresPhpHandlers;
use PHPUnit\Framework\TestCase;

class ConsoleBootstrapTest extends TestCase
{
    use RestoresPhpHandlers;

    private function application(): array
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->configure('app');
        $app->singleton(ExceptionHandler::class, Handler::class);
        $provider = new class($app) extends ServiceProvider {
            public int $boots = 0;
            public function boot(): void { $this->boots++; }
        };
        $app->register($provider);
        return [new Kernel($app), $provider];
    }

    public function testExplicitBootstrapBootsServiceProvidersOnlyOnce(): void
    {
        [$kernel, $provider] = $this->application();
        $kernel->bootstrap();
        $kernel->bootstrap();
        self::assertSame(1, $provider->boots);
    }

    public function testProgrammaticCommandsBootServiceProviders(): void
    {
        [$kernel, $provider] = $this->application();
        self::assertSame(0, $kernel->call('list', ['--raw' => true]));
        self::assertSame(1, $provider->boots);
        $kernel->all();
        self::assertSame(1, $provider->boots);
    }
}
