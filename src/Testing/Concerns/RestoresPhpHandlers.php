<?php

namespace NourApi\Framework\Testing\Concerns;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/** Restore process-wide PHP handlers installed by applications during a test. */
trait RestoresPhpHandlers
{
    private $originalErrorHandler;
    private $originalExceptionHandler;
    private int $originalErrorReporting;

    #[Before]
    protected function rememberPhpHandlers(): void
    {
        $this->originalErrorReporting = error_reporting();
        $this->originalErrorHandler = set_error_handler(static function () {});
        restore_error_handler();
        $this->originalExceptionHandler = set_exception_handler(static function () {});
        restore_exception_handler();
    }

    #[After]
    protected function restorePhpHandlers(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $current = set_error_handler(static function () {});
            restore_error_handler();
            if ($current === $this->originalErrorHandler) {
                break;
            }
            restore_error_handler();
        }
        for ($i = 0; $i < 100; $i++) {
            $current = set_exception_handler(static function () {});
            restore_exception_handler();
            if ($current === $this->originalExceptionHandler) {
                break;
            }
            restore_exception_handler();
        }
        error_reporting($this->originalErrorReporting);
    }
}
