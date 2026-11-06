<?php

namespace NourApi\Framework\Runtime;

use InvalidArgumentException;
use RuntimeException;
use Swoole\Http\Server;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\FileBag;
use Throwable;

/** Concurrent HTTP gateway; application state is isolated by a process per request. */
final class SwooleServer
{
    private int $active = 0;

    public function __construct(
        private readonly string $bootstrap,
        private readonly string $autoload,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8000,
        private readonly int $workers = 1,
        private readonly int $concurrency = 16,
        private readonly float $timeout = 30.0,
        private readonly int $maxRequestBytes = 2097152,
        private readonly int $maxResponseBytes = 16777216,
        private readonly int $memoryLimitMegabytes = 128,
    ) {
        if (! is_file($bootstrap) || ! is_file($autoload)) {
            throw new InvalidArgumentException('Bootstrap and Composer autoloader must be existing files.');
        }
        if ($host === '' || $port < 1 || $port > 65535 || $workers < 1 || $concurrency < 1
            || $maxRequestBytes < 1 || $maxResponseBytes < 1 || $memoryLimitMegabytes < 16 || $timeout <= 0 || ! is_finite($timeout)) {
            throw new InvalidArgumentException('Invalid HTTP runtime configuration.');
        }
    }

    public function start(): void
    {
        if (PHP_SAPI !== 'cli' || ! extension_loaded('swoole') || version_compare(phpversion('swoole'), '6.0', '<') || ! function_exists('proc_open')) {
            throw new RuntimeException('Async HTTP serving requires CLI PHP, ext-swoole 6.x and proc_open.');
        }
        $server = new Server($this->host, $this->port, SWOOLE_PROCESS);
        $server->set([
            'worker_num' => $this->workers,
            'enable_coroutine' => true,
            'http_compression' => false,
            'enable_static_handler' => false,
            'package_max_length' => $this->maxRequestBytes,
            'max_coroutine' => $this->concurrency + 128,
        ]);
        $cleanup = static function (): void {
            ProcessExchange::shutdown();
            RequestStorage::closeAll();
        };
        $server->on('workerExit', $cleanup);
        $server->on('workerStop', $cleanup);
        $server->on('request', function ($request, $response): void {
            if ($this->active >= $this->concurrency) {
                $response->header('Retry-After', '1');
                self::error($request, $response, 503, 'Service unavailable');
                return;
            }
            $this->active++;
            $storage = null;
            try {
                $payload = self::payload($request);
                $storage = new RequestStorage;
                $output = (new ProcessExchange($this->timeout, $this->maxResponseBytes))->execute(
                    [PHP_BINARY, '-d', 'memory_limit='.$this->memoryLimitMegabytes.'M', dirname(__DIR__, 2).'/bin/request-worker.php', realpath($this->autoload), realpath($this->bootstrap), $storage->path],
                    WireCodec::encode($payload),
                );
                $result = WireCodec::decode($output);
                $body = $result['body'];
                if (! is_string($body) || ! is_int($result['status']) || $result['status'] < 200 || $result['status'] > 599) {
                    throw new RuntimeException('Invalid worker response.');
                }
                $response->status($result['status']);
                // Swoole owns message framing and connection persistence.
                $excluded = ['connection', 'transfer-encoding', 'content-length', 'keep-alive', 'upgrade', 'trailer', 'te', 'proxy-authenticate', 'proxy-authorization'];
                if ($request->server['request_method'] === 'HEAD' && ! in_array($result['status'], [204, 304], true)) {
                    // HEAD describes the GET representation, even though its own body is empty.
                    $excluded = array_values(array_diff($excluded, ['content-length']));
                }
                if (in_array($result['status'], [204, 304], true)) {
                    // Suppress automatic framing/type defaults; explicit application type metadata can follow.
                    $response->header('Content-Length', '');
                    $response->header('Content-Type', '');
                }
                foreach ($result['headers'] as $name => $values) {
                    if (strtolower($name) === 'connection') {
                        foreach ($values as $value) {
                            $excluded = array_merge($excluded, array_map('strtolower', array_map('trim', explode(',', $value))));
                        }
                    }
                }
                foreach ($result['headers'] as $name => $values) {
                    if (! in_array(strtolower($name), $excluded, true)) {
                        $response->header($name, $values);
                    }
                }
                $response->end($request->server['request_method'] === 'HEAD' ? '' : $body);
            } catch (RequestTimeout) {
                self::error($request, $response, 504, 'Gateway timeout');
            } catch (Throwable) {
                self::error($request, $response, 502, 'Application worker failed');
            } finally {
                $this->active--;
                // Reap all owned request files, including after a worker deadline.
                $storage?->close();
            }
        });
        $server->start();
    }

    private static function error(object $request, object $response, int $status, string $message): void
    {
        $response->status($status);
        $response->header('Content-Type', 'text/plain; charset=utf-8');
        if ($request->server['request_method'] === 'HEAD') {
            $response->header('Content-Length', (string) strlen($message));
            $response->end('');
        } else {
            $response->end($message);
        }
    }

    private static function payload(object $request): array
    {
        $server = [];
        foreach ($request->server as $name => $value) {
            $server[strtoupper($name)] = $value;
        }
        if (($server['QUERY_STRING'] ?? '') !== '' && ! str_contains($server['REQUEST_URI'], '?')) {
            $server['REQUEST_URI'] .= '?'.$server['QUERY_STRING'];
        }
        foreach ($request->header as $name => $value) {
            // HTTP_PROXY must never become a proxy configuration variable (httpoxy).
            if (strtolower($name) === 'proxy') {
                continue;
            }
            $name = strtoupper(str_replace('-', '_', $name));
            $server[in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $name : 'HTTP_'.$name] = $value;
        }
        $content = $request->rawContent();
        return [
            'server' => $server, 'query' => $request->get ?? [], 'post' => $request->post ?? [],
            'cookies' => $request->cookie ?? [], 'content' => $content === false ? '' : $content,
            'files' => self::uploads((new FileBag($request->files ?? []))->all()),
        ];
    }

    private static function uploads(array $files): array
    {
        $result = [];
        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFile) {
                $result[$key] = [
                    '__nour_upload' => true, 'name' => $file->getClientOriginalName(),
                    'type' => $file->getClientMimeType(), 'error' => $file->getError(),
                    'content' => is_file($file->getPathname()) ? file_get_contents($file->getPathname()) : '',
                ];
            } elseif (is_array($file)) {
                $result[$key] = self::uploads($file);
            }
        }
        return $result;
    }
}
