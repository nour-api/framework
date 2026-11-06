<?php

namespace NourApi\Framework\Runtime;

use FilesystemIterator;
use RuntimeException;

/** Owns only the private directory allocated for one request. Never follows symbolic links. */
final class RequestStorage
{
    /** @var array<string, true> */
    private static array $directories = [];

    public readonly string $path;

    public function __construct()
    {
        $path = sys_get_temp_dir().'/nour-request-'.bin2hex(random_bytes(16));
        if (! mkdir($path, 0700)) {
            throw new RuntimeException('Unable to allocate private request storage.');
        }
        $this->path = $path;
        self::$directories[$path] = true;
    }

    public function close(): void
    {
        self::remove($this->path);
        unset(self::$directories[$this->path]);
    }

    public static function closeAll(): void
    {
        foreach (array_keys(self::$directories) as $path) {
            self::remove($path);
            unset(self::$directories[$path]);
        }
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $file) {
            self::remove($file->getPathname());
        }
        rmdir($path);
    }
}
