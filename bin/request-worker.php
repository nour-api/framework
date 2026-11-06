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
    // A dedicated descriptor prevents startup/shutdown output from corrupting the response.
    $wire = fopen('php://fd/3', 'w');
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    $written = 0;
    while ($written < strlen($encoded)) {
        $bytes = fwrite($wire, substr($encoded, $written));
        if ($bytes === false || $bytes === 0) {
            throw new RuntimeException('Unable to write the response envelope.');
        }
        $written += $bytes;
    }
    fclose($wire);
} catch (Throwable $exception) {
    // Errors are deliberately generic: bootstrap exceptions may contain credentials.
    fwrite(STDERR, "Nour-API request worker failed; inspect application logs.\n");
    exit(1);
}
