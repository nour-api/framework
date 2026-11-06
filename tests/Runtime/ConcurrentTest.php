<?php

use NourApi\Framework\Runtime\Concurrent;
use PHPUnit\Framework\TestCase;

class ConcurrentTest extends TestCase
{
    public function testEmptyTasksWorkWithoutAnOptionalExtension(): void
    {
        self::assertSame([], Concurrent::run([]));
    }

    public function testInvalidConcurrencyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Concurrent::run([], 0);
    }

    public function testInvalidTasksAreRejectedBeforeScheduling(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Concurrent::run(['invalid' => false]);
    }
}
