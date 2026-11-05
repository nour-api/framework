<?php

use Illuminate\Contracts\Log\ContextLogProcessor;
use Monolog\Handler\NullHandler;
use Monolog\LogRecord;
use NourApi\Framework\Application;
use NourApi\Framework\Testing\Concerns\RestoresPhpHandlers;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LoggingIntegrationTest extends TestCase
{
    use RestoresPhpHandlers;

    private function application(string $emergencyPath): Application
    {
        $app = new Application(dirname(__DIR__));
        $app->configure('app');
        $app->configure('logging');
        $app['config']->set('logging.default', 'null');
        $app['config']->set('logging.channels.emergency.path', $emergencyPath);
        return $app;
    }

    public function testConfiguredChannelDoesNotFallBackToEmergencyLogging(): void
    {
        $path = sys_get_temp_dir().'/nour-log-'.bin2hex(random_bytes(8));
        try {
            $app = $this->application($path);
            $logger = $app->make(LoggerInterface::class);
            $channel = $logger->channel('null');
            self::assertInstanceOf(NullHandler::class, $channel->getLogger()->getHandlers()[0]);
            $channel->info('A configured channel is available.');
            self::assertFalse(file_exists($path), 'The configured channel must not use the emergency log.');
            self::assertInstanceOf(ContextLogProcessor::class, $app->make(ContextLogProcessor::class));
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }

    public function testCustomContextProcessorBindingIsPreserved(): void
    {
        $path = sys_get_temp_dir().'/nour-log-'.bin2hex(random_bytes(8));
        try {
            $app = $this->application($path);
            $processor = new class implements ContextLogProcessor {
                public bool $called = false;
                public function __invoke(LogRecord $record): LogRecord {
                    $this->called = true;
                    return $record;
                }
            };
            $app->instance(ContextLogProcessor::class, $processor);
            $app->make(LoggerInterface::class)->info('Use the existing processor.');
            self::assertSame($processor, $app->make(ContextLogProcessor::class));
            self::assertTrue($processor->called);
            self::assertFalse(file_exists($path));
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }
}
