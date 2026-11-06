<?php

// Internal IPC entry point. It is never a public HTTP document root.
require $argv[1];

define('NOUR_HTTP_RUNTIME', true);

use NourApi\Framework\Runtime\RequestWorker;

try {
    $payload = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $result = null;
    $failure = null;
    $handle = static function () use ($argv, $payload, &$result, &$failure): void {
        try {
            $result = RequestWorker::handle($argv[2], $payload, $argv[3]);
        } catch (Throwable $exception) {
            $failure = $exception;
        }
    };
    if (extension_loaded('swoole')) {
        // Only this disposable request process is hooked. The existing FPM mode is untouched.
        Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        Swoole\Coroutine\run($handle);
    } else {
        $handle();
    }
    if ($failure !== null) {
        throw $failure;
    }
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    // Errors are deliberately generic: bootstrap exceptions may contain credentials.
    fwrite(STDERR, "Nour-API request worker failed; inspect application logs.\n");
    exit(1);
}
