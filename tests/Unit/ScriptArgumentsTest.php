<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The command-line scripts read their arguments through $GLOBALS['argv'].
 * PHPStan on PHP 8.5 treats a bare $argv as possibly undefined, which only
 * the PHP 8.5 CI job would otherwise notice.
 */
final class ScriptArgumentsTest extends TestCase
{
    public function testScriptsDoNotReadTheBareArgvVariable(): void
    {
        $files = glob(BASE_PATH . '/bin/*.php') ?: [];
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            // bin/setup.php may set $argv for bin/install.php, which it includes.
            $reads = preg_match_all('/(?<![\'"\w])\$argv\b(?!\s*=[^=])/', $code);
            self::assertSame(0, $reads, basename($file) . " reads \$argv; use \$GLOBALS['argv'] instead");
        }
    }

    public function testScriptsAnswer404ThroughAWebServer(): void
    {
        $files = glob(BASE_PATH . '/bin/*.php') ?: [];
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            self::assertMatchesRegularExpression("/if \(PHP_SAPI !== 'cli'\) \{\R\s+http_response_code\(404\);\R\s+exit;\R\}/", $code, basename($file) . ' runs only on the command line');
        }
    }
}
