<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class BootstrapFailureTest extends TestCase
{
    public function testUncaughtCliExceptionExitsWithFailure(): void
    {
        $script = 'require '.var_export(__DIR__.'/../vendor/autoload.php', true).'; new NourApi\\Framework\\Application; throw new RuntimeException("CLI operation failed");';
        $file = tempnam(sys_get_temp_dir(), 'nour-cli-');
        try {
            file_put_contents($file, '<?php '.$script);
            $process = new Process([PHP_BINARY, $file], null, ['LOG_CHANNEL' => 'null']);
            $process->run();
            self::assertSame(1, $process->getExitCode());
            self::assertStringContainsString('CLI operation failed', $process->getOutput().$process->getErrorOutput());
        } finally {
            unlink($file);
        }
    }

    public function testInvalidEnvironmentDoesNotExposeSecretValues(): void
    {
        $directory = sys_get_temp_dir().'/nour-env-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            file_put_contents($directory.'/.env', "APP_KEY=\"synthetic-secret-without-closing-quote\n");
            $script = 'require '.var_export(__DIR__.'/../vendor/autoload.php', true).'; (new NourApi\\Framework\\Bootstrap\\LoadEnvironmentVariables('.var_export($directory, true).'))->bootstrap();';
            $process = new Process([PHP_BINARY, '-r', $script]);
            $process->run();
            self::assertSame(1, $process->getExitCode());
            $output = $process->getOutput().$process->getErrorOutput();
            self::assertStringContainsString('environment file is invalid', $output);
            self::assertStringNotContainsString('synthetic-secret', $output);
        } finally {
            unlink($directory.'/.env');
            rmdir($directory);
        }
    }
}
