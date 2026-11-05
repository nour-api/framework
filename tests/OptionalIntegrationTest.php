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
}
