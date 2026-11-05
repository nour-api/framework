<?php

use Illuminate\Validation\ValidationException;
use NourApi\Framework\Application;
use NourApi\Framework\Exceptions\Handler;
use NourApi\Framework\Http\Request;
use NourApi\Framework\Testing\Concerns\RestoresPhpHandlers;
use PHPUnit\Framework\TestCase;

class ExceptionResponseTest extends TestCase
{
    use RestoresPhpHandlers;

    public function testProductionJsonDoesNotExposeInternalDetails(): void
    {
        $app = new Application;
        $app->configure('app');
        $app['config']->set('app.debug', false);
        $response = (new Handler)->render(Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']), new RuntimeException('database password'));
        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['message' => 'Server Error'], $response->getData(true));
    }

    public function testProductionHttpServerErrorDoesNotExposeInternalDetails(): void
    {
        $app = new Application;
        $app->configure('app');
        $app['config']->set('app.debug', false);
        $response = (new Handler)->render(Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']), new \Symfony\Component\HttpKernel\Exception\HttpException(503, 'private connection string'));
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['message' => 'Server Error'], $response->getData(true));
    }

    public function testValidationExceptionWithoutResponseReturns422(): void
    {
        $app = new Application;
        $app->configure('app');
        $exception = new ValidationException($app['validator']->make(['email' => 'invalid'], ['email' => 'email'], ['email.email' => 'Invalid email.']));
        $response = (new Handler)->render(Request::create('/'), $exception);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['email' => ['Invalid email.']], $response->getData(true)['errors']);
    }
}
