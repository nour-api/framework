<?php

use NourApi\Framework\Runtime\RequestStorage;
use PHPUnit\Framework\TestCase;

class RequestStorageTest extends TestCase
{
    public function testWorkerShutdownClosesAllOwnedDirectories(): void
    {
        $first = new RequestStorage;
        $second = new RequestStorage;
        try {
            file_put_contents($first->path.'/.metadata', 'first');
            file_put_contents($second->path.'/.metadata', 'second');
            RequestStorage::closeAll();
            self::assertDirectoryDoesNotExist($first->path);
            self::assertDirectoryDoesNotExist($second->path);
        } finally {
            $first->close();
            $second->close();
        }
    }

    public function testRemovesHiddenAndNestedRequestFilesAndCanCloseTwice(): void
    {
        $storage = new RequestStorage;
        try {
            self::assertSame(0700, fileperms($storage->path) & 0777);
            file_put_contents($storage->path.'/.metadata', 'request data');
            mkdir($storage->path.'/nested');
            file_put_contents($storage->path.'/nested/partial', 'request data');
            $storage->close();
            $storage->close();
            self::assertDirectoryDoesNotExist($storage->path);
        } finally {
            $storage->close();
        }
    }

    public function testCleanupDoesNotFollowLinksOutsideItsDirectory(): void
    {
        $outside = new RequestStorage;
        $storage = new RequestStorage;
        try {
            file_put_contents($outside->path.'/keep', 'untouched');
            symlink($outside->path, $storage->path.'/directory-link');
            symlink($outside->path.'/keep', $storage->path.'/file-link');
            symlink($outside->path.'/missing', $storage->path.'/dangling-link');
            $storage->close();
            self::assertSame('untouched', file_get_contents($outside->path.'/keep'));
            self::assertDirectoryDoesNotExist($storage->path);
        } finally {
            $storage->close();
            $outside->close();
        }
    }
}
