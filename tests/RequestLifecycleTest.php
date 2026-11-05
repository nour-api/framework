<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request as IlluminateRequest;
use NourApi\Framework\Application;
use NourApi\Framework\Http\Request;
use NourApi\Framework\Testing\Concerns\RestoresPhpHandlers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class RequestLifecycleTest extends TestCase
{
    use RestoresPhpHandlers;

    public function testRunSendsResponseAndTerminatesApplication(): void
    {
        $app = new Application;
        $terminated = false;
        $app->terminating(function () use (&$terminated) { $terminated = true; });
        $app->router->get('/', static fn () => 'ready');
        ob_start();
        try {
            $app->run(Request::create('/'));
            $body = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame('ready', $body);
        self::assertTrue($terminated);
    }

    public function testGroupAttributesAreRestoredAfterException(): void
    {
        $app = new Application;
        try {
            $app->router->group(['prefix' => 'private', 'middleware' => 'missing'], function () {
                throw new RuntimeException('registration failed');
            });
        } catch (RuntimeException) {
        }
        $app->router->get('/public', static fn () => 'public');
        self::assertFalse($app->router->hasGroupStack());
        self::assertSame('public', $app->handle(Request::create('/public'))->getContent());
    }

    public function testRequestHelpersWorkBeforeRouting(): void
    {
        $request = Request::create('/missing?foo=bar');
        self::assertFalse($request->routeIs('private.*'));
        self::assertTrue(isset($request['foo']));
        self::assertFalse(isset($request['missing']));
    }

    public function testSymfonyRequestIsConvertedToFrameworkRequest(): void
    {
        $app = new Application;
        $app->router->get('/users/{id}', function (IlluminateRequest $request) {
            return $request->route('id', 'missing');
        });
        self::assertSame('42', $app->handle(SymfonyRequest::create('/users/42'))->getContent());
        self::assertInstanceOf(Request::class, $app['request']);
    }

    public function testFrameworkRequestDependencyIsCurrentRequest(): void
    {
        $app = new Application;
        $app->router->get('/users/{id}', static fn (Request $request) => $request->route('id'));
        self::assertSame('42', $app->handle(IlluminateRequest::create('/users/42'))->getContent());
    }

    public function testNamedRouteParametersCannotInjectQueryOrPathSegments(): void
    {
        $app = new Application;
        $app->router->get('/users/{name}', ['as' => 'user', static fn () => 'ok']);
        $app->handle(Request::create('/users/abc'));
        self::assertSame('http://localhost/users/a%2Fb%3Fadmin%3D1%23fragment', route('user', ['name' => 'a/b?admin=1#fragment']));
    }

    public function testMissingRouteDoesNotExposePreviousRoute(): void
    {
        $app = new Application;
        $app->configure('app');
        $app->make('config')->set('logging.default', 'null');
        $app->router->get('/users/{id}', static fn ($id) => $id);
        $app->handle(Request::create('/users/42'));
        self::assertSame(404, $app->handle(Request::create('/missing'))->getStatusCode());
        self::assertNull($app['request']->route());
    }

    public function testUrlCacheTracksRequestHostAndScheme(): void
    {
        $app = new Application;
        $app->router->get('/', static fn () => url('/users'));
        self::assertSame('http://first.test/users', $app->handle(Request::create('http://first.test/'))->getContent());
        self::assertSame('https://second.test/users', $app->handle(Request::create('https://second.test/'))->getContent());
        $app['url']->forceRootUrl('https://fixed.test');
        $app['url']->forceScheme('https');
        self::assertSame('https://fixed.test/users', $app->handle(Request::create('http://third.test/'))->getContent());
    }

    public function testAuthenticatedIdentityDoesNotLeakIntoNextRequest(): void
    {
        $app = new Application;
        $app['auth']->viaRequest('api', static fn ($request) => $request->header('X-User')
            ? new GenericUser(['id' => $request->header('X-User')]) : null);
        $app->router->get('/', static fn (IlluminateRequest $request) => $request->user()?->getAuthIdentifier() ?? 'guest');
        self::assertSame('alice', $app->handle(Request::create('/', 'GET', [], [], [], ['HTTP_X_USER' => 'alice']))->getContent());
        $guard = $app['auth']->guard();
        self::assertSame('guest', $app->handle(Request::create('/'))->getContent());
        self::assertSame($guard, $app['auth']->guard());
        self::assertSame('bob', $app->handle(Request::create('/', 'GET', [], [], [], ['HTTP_X_USER' => 'bob']))->getContent());
    }

    public function testMiddlewareShortCircuitIsNormalizedAndHeadHasNoBody(): void
    {
        $app = new Application;
        $app->middleware(static fn ($request, $next) => 'blocked');
        self::assertSame('blocked', $app->handle(Request::create('/'))->getContent());
        self::assertSame('', $app->handle(Request::create('/', 'HEAD'))->getContent());
    }

    public function testStaticAndDynamicRoutesReturn405WithAllowHeader(): void
    {
        $app = new Application;
        $app->router->get('/users/{id}', static fn ($id) => ['id' => $id]);
        $response = $app->handle(Request::create('/users/42', 'POST'));
        self::assertSame(405, $response->getStatusCode());
        self::assertStringContainsString('GET', $response->headers->get('Allow'));
        $response = $app->handle(Request::create('/users/42', 'HEAD'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }
}
