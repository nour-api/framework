<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use NourApi\Framework\Application;
use NourApi\Framework\Exceptions\Handler;

class AuthenticationTestingTest extends \NourApi\Framework\Testing\TestCase
{
    public function createApplication()
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->configure('app');
        $app->singleton(ExceptionHandler::class, Handler::class);
        $app['auth']->viaRequest('api', static fn () => null);
        $app->router->get('/', static fn (Request $request) => $request->user()?->getAuthIdentifier() ?? 'guest');
        return $app;
    }

    public function testActingAsPersistsAcrossMultipleTestRequests(): void
    {
        $this->actingAs(new GenericUser(['id' => 'alice']));
        $this->get('/');
        self::assertSame('alice', $this->response->getContent());
        $this->get('/');
        self::assertSame('alice', $this->response->getContent());
    }

    public function testActingAsCanChangeUserAfterARequest(): void
    {
        $this->actingAs(new GenericUser(['id' => 'alice']));
        $this->get('/');
        $this->actingAs(new GenericUser(['id' => 'bob']));
        $this->get('/');
        self::assertSame('bob', $this->response->getContent());
    }

    public function testRefreshingApplicationClearsSimulatedIdentity(): void
    {
        $this->actingAs(new GenericUser(['id' => 'alice']));
        $this->get('/');
        $this->refreshApplication();
        $this->get('/');
        self::assertSame('guest', $this->response->getContent());
    }
}
