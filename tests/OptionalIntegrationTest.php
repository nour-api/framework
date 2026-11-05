<?php

use Illuminate\Contracts\Container\BindingResolutionException;
use NourApi\Framework\Application;
use NourApi\Framework\Http\Request;
use NourApi\Framework\Testing\Concerns\RestoresPhpHandlers;
use PHPUnit\Framework\TestCase;

class OptionalIntegrationTest extends TestCase
{
    use RestoresPhpHandlers;

    public function testRedisBindingGivesAnActionableErrorWithoutOptionalComponent(): void
    {
        $app = new Application;
        if (class_exists('Illuminate\\Redis\\RedisServiceProvider')) {
            self::assertInstanceOf('Illuminate\\Redis\\RedisManager', $app->make('redis'));
            return;
        }
        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Please install illuminate/redis:^12.21');
        $app->make('redis');
    }

    public function testPsrResponseConversionReportsMissingBridge(): void
    {
        $app = new Application;
        $app->router->get('/', static fn () => 'ok');
        $app->handle(Request::create('/'));
        $response = new GuzzleHttp\Psr7\Response(201, [], 'psr response');
        if (class_exists('Symfony\\Bridge\\PsrHttpMessage\\Factory\\HttpFoundationFactory')) {
            $converted = $app->prepareResponse($response);
            self::assertSame(201, $converted->getStatusCode());
            self::assertSame('psr response', $converted->getContent());
            return;
        }
        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Please install symfony/psr-http-message-bridge');
        $app->prepareResponse($response);
    }
    public function testPsrRequestsAreRebuiltForEachHttpRequest(): void
    {
        $app = new Application;
        $app->router->addRoute(['GET', 'POST'], '/users/{id}', static function (\Psr\Http\Message\ServerRequestInterface $request) {
            return [
                'host' => $request->getUri()->getHost(),
                'path' => $request->getUri()->getPath(),
                'method' => $request->getMethod(),
            ];
        });
        if (! class_exists('Nyholm\\Psr7\\Factory\\Psr17Factory') || ! class_exists('Symfony\\Bridge\\PsrHttpMessage\\Factory\\PsrHttpFactory')) {
            $app->instance('request', Request::create('/'));
            $this->expectException(BindingResolutionException::class);
            $this->expectExceptionMessage('Please install symfony/psr-http-message-bridge and nyholm/psr7');
            $app->make(\Psr\Http\Message\ServerRequestInterface::class);
            return;
        }
        $response = $app->handle(Request::create('http://first.test/users/1'));
        self::assertSame(['host' => 'first.test', 'path' => '/users/1', 'method' => 'GET'], json_decode($response->getContent(), true));
        $first = $app->make(\Psr\Http\Message\ServerRequestInterface::class);
        $response = $app->handle(Request::create('https://second.test/users/2', 'POST'));
        self::assertSame(['host' => 'second.test', 'path' => '/users/2', 'method' => 'POST'], json_decode($response->getContent(), true));
        self::assertNotSame($first, $app->make(\Psr\Http\Message\ServerRequestInterface::class));
    }

}
