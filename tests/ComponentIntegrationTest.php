<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\ServiceProvider;
use NourApi\Framework\Application;
use NourApi\Framework\Exceptions\Handler;
use NourApi\Framework\Http\Request;
use NourApi\Framework\Routing\Controller;
use NourApi\Framework\Testing\Concerns\RestoresPhpHandlers;
use PHPUnit\Framework\TestCase;

class ComponentIntegrationTest extends TestCase
{
    use RestoresPhpHandlers;

    public function testContainerResolvesConfigurationContractAndSingleton(): void
    {
        $app = new Application;
        $app->configure('app');
        $app->singleton('service', static fn () => new stdClass);
        self::assertSame($app['config'], $app->make(Repository::class));
        self::assertSame($app['service'], $app['service']);
        $app->router->get('/', static fn (Repository $config) => $config->get('app.name'));
        self::assertSame($app['config']->get('app.name'), $app->handle(Request::create('/'))->getContent());
    }

    public function testEventsAndProvidersBootOnlyOnce(): void
    {
        $app = new Application;
        $provider = new class($app) extends ServiceProvider {
            public int $bootCount = 0;
            public function boot(): void { $this->bootCount++; }
        };
        $app->register($provider);
        $app->boot();
        $app->boot();
        $seen = [];
        $app['events']->listen('created', function ($value) use (&$seen) { $seen[] = $value; });
        $app['events']->dispatch('created', ['user']);
        self::assertSame(1, $provider->bootCount);
        self::assertSame(['user'], $seen);
    }

    public function testDatabaseQueryBuilderBindsUntrustedValues(): void
    {
        // Compilation only: no database connection is opened.
        $connection = new MySqlConnection(static function () {
            throw new LogicException('This test must not open a database connection.');
        });
        $input = "' OR 1=1 --";
        $query = $connection->table('users')->where('email', $input);
        self::assertSame('select * from `users` where `email` = ?', $query->toSql());
        self::assertSame([$input], $query->getBindings());
    }

    public function testControllerValidationReturns422AndValidatedInput(): void
    {
        $app = new Application;
        $app->configure('app');
        $app->router->post('/users', function (Request $request) {
            return (new Controller)->validate($request, ['email' => 'required|email']);
        });
        $response = $app->handle(Request::create('/users', 'POST', ['email' => 'invalid']));
        self::assertSame(422, $response->getStatusCode());
        $response = $app->handle(Request::create('/users', 'POST', ['email' => 'user@example.com', 'admin' => true]));
        self::assertSame(['email' => 'user@example.com'], json_decode($response->getContent(), true));
    }

    public function testAuthenticationExceptionReturns401(): void
    {
        $app = new Application;
        $response = (new Handler)->render(Request::create('/'), new AuthenticationException('private auth details'));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['message' => 'Unauthenticated.'], $response->getData(true));
    }
}
