<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use NourApi\Framework\Application;
use NourApi\Framework\Testing\Concerns\RestoresPhpHandlers;
use PHPUnit\Framework\TestCase;

class FacadeRegistrationTest extends TestCase
{
    use RestoresPhpHandlers;

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function testCustomAliasesCanBeAddedAfterDefaultAliasesWereRegistered(): void
    {
        $first = new Application;
        $first->withAliases();
        $name = 'NourCustomCache'.bin2hex(random_bytes(8));
        $second = new Application;
        $second->withAliases([Cache::class => $name]);
        self::assertTrue(is_a($name, Cache::class, true));
        $laterName = 'NourLaterCache'.bin2hex(random_bytes(8));
        $second->withAliases([Cache::class => $laterName]);
        self::assertTrue(is_a($laterName, Cache::class, true));
    }

    public function testFacadeRootsDoNotLeakAcrossApplications(): void
    {
        $first = new Application;
        $first->instance('cache', $firstService = new stdClass);
        $first->withFacades(false);
        self::assertSame($firstService, Cache::getFacadeRoot());
        $second = new Application;
        $second->instance('cache', $secondService = new stdClass);
        $second->withFacades(false);
        self::assertSame($secondService, Cache::getFacadeRoot());
    }
}
