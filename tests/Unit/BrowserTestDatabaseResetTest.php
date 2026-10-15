<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use BookWise\Tests\Docker\RunsProjectScripts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * e2e/support/reset-database.php, which drops every table before the browser
 * tests' local store is installed (e2e/local-server.js), refuses any database
 * whose name does not end in "_test", before it connects.
 */
final class BrowserTestDatabaseResetTest extends TestCase
{
    use RunsProjectScripts;

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function realDatabases(): iterable
    {
        yield 'the default name' => ['bookwise'];
        yield 'a production-like name' => ['myaccount_bookwise'];
        yield '"_test" not at the end' => ['bookwise_test_copy'];
    }

    #[DataProvider('realDatabases')]
    public function testOnlyTestDatabasesAreReset(string $name): void
    {
        // A port nothing listens on: the script must stop before connecting.
        $result = self::runProjectScript('e2e/support/reset-database.php', [
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => (string) self::closedPort(),
            'DB_NAME' => $name,
        ]);

        self::assertSame(1, $result['status'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('Refusing to reset the database "' . $name . '"', $result['stderr']);
        self::assertSame('', $result['stdout']);
    }
}
