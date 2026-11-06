<?php

namespace NourApi\Framework\Runtime;

use RuntimeException;
use Swoole\Coroutine;

/** Nonblocking, bounded IPC with a fresh PHP request process. No shell is used. */
final class ProcessExchange
{
    /** @var array<int, resource> */
    private static array $processes = [];

    public function __construct(
        private readonly float $timeout = 30.0,
        private readonly int $maxOutputBytes = 16777216,
    ) {
        if ($timeout <= 0 || ! is_finite($timeout) || $maxOutputBytes < 1) {
            throw new \InvalidArgumentException('Process timeout and output limit must be positive.');
        }
    }

    public function execute(array $command, string $input): string
    {
        if (! extension_loaded('swoole') || Coroutine::getCid() < 0) {
            throw new RuntimeException('The process exchange requires an active Swoole coroutine.');
        }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the request process.');
        }
        $processId = get_resource_id($process);
        self::$processes[$processId] = $process;
        $output = '';
        $exitCode = null;
        $written = 0;
        $deadline = hrtime(true) + (int) ($this->timeout * 1_000_000_000);
        try {
            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }
            do {
                if (! self::isRegistered($processId)) {
                    throw new RuntimeException('The request process was stopped during gateway shutdown.');
                }
                if (hrtime(true) >= $deadline) {
                    throw new RequestTimeout('The request process exceeded its deadline.');
                }
                if (isset($pipes[0])) {
                    $length = fwrite($pipes[0], substr($input, $written, 65536));
                    if ($length === false) {
                        throw new RuntimeException('Unable to write the request payload.');
                    }
                    $written += $length;
                    if ($written === strlen($input)) {
                        fclose($pipes[0]);
                        unset($pipes[0]);
                    }
                }
                $output .= stream_get_contents($pipes[3]);
                // stdout/stderr cannot corrupt the private response pipe. Drain both to avoid deadlock.
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                if (strlen($output) > $this->maxOutputBytes) {
                    throw new RuntimeException('The request response exceeds the configured output limit.');
                }
                $status = proc_get_status($process);
                if (! $status['running'] && $status['exitcode'] >= 0) {
                    $exitCode = $status['exitcode'];
                }
                if (! $status['running'] && feof($pipes[1]) && feof($pipes[2]) && feof($pipes[3])) {
                    if ($exitCode !== 0) {
                        throw new RuntimeException('The request process failed. Consult the application log.');
                    }
                    return $output;
                }
                Coroutine::sleep(0.001);
            } while (true);
        } finally {
            $owned = self::isRegistered($processId);
            if ($owned && proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if ($owned) {
                proc_close($process);
            }
            unset(self::$processes[$processId]);
        }
    }

    private static function isRegistered(int $processId): bool
    {
        // Worker-exit callbacks can change ownership while the exchange coroutine is suspended.
        return isset(self::$processes[$processId]);
    }

    /** Stop and reap this gateway worker's direct request processes before it exits. */
    public static function shutdown(): void
    {
        foreach (self::$processes as $id => $process) {
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
            }
            unset(self::$processes[$id]);
        }
    }
}
