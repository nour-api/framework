<?php

namespace NourApi\Framework\Runtime;

use NourApi\Framework\Application;
use NourApi\Framework\Http\Request;
use RuntimeException;
use Illuminate\Http\UploadedFile;

/** A single request, in a disposable PHP process, including application termination. */
final class RequestWorker
{
    public static function handle(string $bootstrap, array $payload, ?string $uploadDirectory = null): array
    {
        $temporaryFiles = [];
        $level = ob_get_level();
        ob_start();
        try {
            $server = $payload['server'];
            $server['SCRIPT_NAME'] = '/index.php';
            $server['SCRIPT_FILENAME'] = dirname($bootstrap, 2).'/public/index.php';
            $server['PHP_SELF'] = '/index.php';
            $content = $payload['content'];
            $files = self::uploads($payload['files'] ?? [], $temporaryFiles, $uploadDirectory ?? sys_get_temp_dir());
            $_GET = $payload['query'];
            $_POST = $payload['post'];
            $_COOKIE = $payload['cookies'];
            $_SERVER = $server;
            $_REQUEST = array_merge($_GET, $_POST);
            $_FILES = self::fileGlobals($files);

            $app = require $bootstrap;
            if (! $app instanceof Application) {
                throw new RuntimeException('The bootstrap must return a Nour-API application.');
            }
            try {
                $request = new Request($_GET, $_POST, [], $_COOKIE, $files, $server, $content);
                $response = $app->handle($request);
                // The gateway buffers streaming/file output; it does not advertise live streaming.
                ob_clean();
                $response->sendContent();
                $body = ob_get_contents();
                ob_clean();
            } finally {
                $app->terminate();
            }

            $headers = $response->headers->allPreserveCaseWithoutCookies();
            foreach ($response->headers->getCookies() as $cookie) {
                $headers['Set-Cookie'][] = (string) $cookie;
            }
            return ['status' => $response->getStatusCode(), 'headers' => $headers, 'body' => $body];
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            foreach ($temporaryFiles as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private static function uploads(array $files, array &$temporaryFiles, string $directory): array
    {
        $result = [];
        foreach ($files as $key => $value) {
            if (($value['__nour_upload'] ?? null) !== true) {
                $result[$key] = self::uploads($value, $temporaryFiles, $directory);
                continue;
            }
            $content = $value['content'];
            $path = tempnam($directory, 'nour-upload-');
            if ($path === false) {
                throw new RuntimeException('Unable to create upload storage.');
            }
            $temporaryFiles[] = $path;
            if (file_put_contents($path, $content) !== strlen($content)) {
                throw new RuntimeException('Unable to store the upload.');
            }
            $result[$key] = new UploadedFile($path, $value['name'], $value['type'], $value['error'], true);
        }
        return $result;
    }

    private static function fileGlobals(array $files): array
    {
        $result = [];
        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFile) {
                $result[$key] = [
                    'name' => $file->getClientOriginalName(), 'type' => $file->getClientMimeType(),
                    'tmp_name' => $file->getPathname(), 'error' => $file->getError(), 'size' => $file->getSize(),
                ];
            } else {
                $nested = self::fileGlobals($file);
                foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $property) {
                    foreach ($nested as $name => $attributes) {
                        $result[$key][$property][$name] = $attributes[$property];
                    }
                }
            }
        }
        return $result;
    }
}
