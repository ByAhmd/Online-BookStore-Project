<?php

declare(strict_types=1);

namespace BookWise\Tests\Docker;

use BookWise\Tests\Support\DatabaseTestCase;

/**
 * docker/db-ready.php: the check docker/entrypoint.sh repeats while it waits
 * for the database. It must succeed only when the database answers and must
 * never print the password.
 */
final class DatabaseReadyScriptTest extends DatabaseTestCase
{
    use RunsProjectScripts;

    private string $logDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->logDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bookwise-db-ready-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->logDirectory);
        parent::tearDown();
    }

    public function testSucceedsSilentlyWhenTheDatabaseAnswers(): void
    {
        if ((string) config('db.pass') === '' && PHP_OS_FAMILY === 'Windows' && is_file(BASE_PATH . '/.env')) {
            // Windows drops empty variables from a child's environment, so the
            // script would read DB_PASS from the developer's .env instead.
            self::markTestSkipped('An empty DB_PASS cannot be passed to a child process on Windows while a .env file exists.');
        }

        $result = self::runProjectScript('docker/db-ready.php', $this->databaseEnvironment());

        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertSame('', $result['stdout'] . $result['stderr']);
    }

    public function testFailsWithAOneLineReasonAndNoPasswordWhenTheLoginIsRefused(): void
    {
        $password = 'db-ready-secret-' . bin2hex(random_bytes(4));

        $result = self::runProjectScript('docker/db-ready.php', ['DB_PASS' => $password] + $this->databaseEnvironment());

        self::assertSame(1, $result['status']);
        self::assertSame('', $result['stdout']);
        self::assertMatchesRegularExpression('/^[A-Za-z\\\\]*Exception: .*denied/i', $result['stderr']);
        self::assertSame(1, substr_count(rtrim($result['stderr']), "\n") + 1, 'The reason must be a single line');
        self::assertStringNotContainsString($password, $result['stderr']);
    }

    public function testFailsWhenNothingListens(): void
    {
        $result = self::runProjectScript('docker/db-ready.php', ['DB_PORT' => (string) self::closedPort()] + $this->databaseEnvironment());

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Exception: ', $result['stderr']);
    }

    /**
     * @return array<string, string>
     */
    private function databaseEnvironment(): array
    {
        return [
            'DB_HOST' => (string) config('db.host'),
            'DB_PORT' => (string) config('db.port'),
            'DB_NAME' => (string) config('db.name'),
            'DB_USER' => (string) config('db.user'),
            'DB_PASS' => (string) config('db.pass'),
            'DB_SSL_CA' => '',
            'DB_SSL_CA_PEM' => '',
            'LOG_DIR' => $this->logDirectory,
        ];
    }
}
