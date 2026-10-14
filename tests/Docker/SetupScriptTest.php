<?php

declare(strict_types=1);

namespace BookWise\Tests\Docker;

use BookWise\Tests\Support\DatabaseTestCase;

/**
 * bin/setup.php: the setup script for hosts without Docker (the alwaysdata
 * demo). It writes the settings to a throwaway file here (--env-file), never
 * to the project's .env, and installs into the test database.
 */
final class SetupScriptTest extends DatabaseTestCase
{
    use RunsProjectScripts;

    private const ADMIN_PASSWORD = 'Setup-admin-pass-1';

    private string $directory = '';
    private ?string $projectEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bookwise-setup-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->projectEnv = is_file(BASE_PATH . '/.env') ? (string) md5_file(BASE_PATH . '/.env') : null;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();
        self::assertSame($this->projectEnv, is_file(BASE_PATH . '/.env') ? md5_file(BASE_PATH . '/.env') : null, 'The project\'s .env is never touched');
    }

    private function envFile(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'demo.env';
    }

    /**
     * @return list<string>
     */
    private function databaseOptions(): array
    {
        return [
            '--db-host=' . config('db.host'),
            '--db-port=' . config('db.port'),
            '--db-name=' . config('db.name'),
            '--db-user=' . config('db.user'),
        ];
    }

    /**
     * The child's environment: the test database's password, and none of the
     * settings the script would otherwise take from the environment.
     *
     * @param array<string, string|null> $overrides
     * @return array<string, string|null>
     */
    private function environment(array $overrides = []): array
    {
        return $overrides + [
            'DB_PASS' => (string) config('db.pass'),
            'APP_URL' => null,
            'DB_HOST' => null,
            'DB_PORT' => null,
            'DB_NAME' => null,
            'DB_USER' => null,
            'BOOKWISE_ADMIN_EMAIL' => null,
            'BOOKWISE_ADMIN_PASSWORD' => null,
        ];
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string|null> $env
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function runSetup(array $arguments, array $env = [], string $stdin = ''): array
    {
        return self::runProjectScript('bin/setup.php', $this->environment($env), ['--env-file=' . $this->envFile(), ...$arguments], $stdin);
    }

    /**
     * The settings in a .env file, read the way load_env() reads them.
     *
     * @return array<string, string>
     */
    private static function readEnvFile(string $path): array
    {
        $settings = [];
        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', trim($line), $match)) {
                $settings[$match[1]] = parse_env_value(trim($match[2]));
            }
        }
        return $settings;
    }

    /**
     * @return list<string>
     */
    private static function exampleKeys(): array
    {
        preg_match_all('/^([A-Za-z_][A-Za-z0-9_]*)=/m', (string) file_get_contents(BASE_PATH . '/.env.example'), $matches);
        return $matches[1];
    }

    private function adminPasswordHash(string $email): ?string
    {
        $hash = db_value('SELECT password FROM admins WHERE Eaddress = ?', [$email]);
        return $hash === null ? null : (string) $hash;
    }

    public function testWritesADemoConfigurationAndInstallsTheStore(): void
    {
        $run = $this->runSetup(
            ['--no-interaction', '--app-url=https://bookwise-demo.example.net/', ...$this->databaseOptions(), '--admin-email=Admin@Example.test'],
            ['BOOKWISE_ADMIN_PASSWORD' => self::ADMIN_PASSWORD]
        );

        self::assertSame(0, $run['status'], $run['stdout'] . $run['stderr']);
        self::assertSame('', $run['stderr']);
        foreach (['The database answers.', 'Database schema is up to date.', 'Created administrator admin@example.test.', 'BookWise is ready at https://bookwise-demo.example.net'] as $line) {
            self::assertStringContainsString($line, $run['stdout']);
        }
        self::assertStringNotContainsString(self::ADMIN_PASSWORD, $run['stdout'] . $run['stderr'], 'Passwords are never printed');

        $settings = self::readEnvFile($this->envFile());
        self::assertSame(self::exampleKeys(), array_keys($settings), 'Every setting of .env.example, in its order');
        $expected = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://bookwise-demo.example.net',
            'APP_DEMO' => 'true',
            'REQUIRE_EMAIL_VERIFICATION' => 'false',
            'LOG_DIR' => 'storage/logs',
            'DB_HOST' => (string) config('db.host'),
            'DB_PORT' => (string) config('db.port'),
            'DB_NAME' => (string) config('db.name'),
            'DB_USER' => (string) config('db.user'),
            'DB_PASS' => (string) config('db.pass'),
            'PAYMENT_SIMULATOR' => 'true',
            'PAYMENT_SIMULATOR_STORE' => 'storage/payment-simulator.json',
            'STRIPE_SECRET_KEY' => '',
            'MAIL_DRIVER' => 'null',
        ];
        $actual = array_intersect_key($settings, $expected);
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual, 'Safe values for a public demo');
        self::assertMatchesRegularExpression('/^whsec_[0-9a-f]{48}$/', $settings['STRIPE_WEBHOOK_SECRET'], 'A random webhook secret nobody knows');
        self::assertStringNotContainsString(self::ADMIN_PASSWORD, (string) file_get_contents($this->envFile()), 'The administrator password is not stored');
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, fileperms($this->envFile()) & 0777, 'Only the owner may read the settings');
        }

        $hash = $this->adminPasswordHash('admin@example.test');
        self::assertNotNull($hash);
        self::assertTrue(check_password(self::ADMIN_PASSWORD, $hash)['valid']);
        self::assertSame(self::SEED_BOOK_COUNT, book_count());
    }

    public function testTheAnswersCanBeTypedIn(): void
    {
        $answers = implode("\n", [
            'https://student.alwaysdata.net',
            (string) config('db.host'),
            (string) config('db.port'),
            (string) config('db.name'),
            (string) config('db.user'),
            (string) config('db.pass'),
            'y',
            'typed-admin@example.test',
            self::ADMIN_PASSWORD,
            self::ADMIN_PASSWORD,
        ]) . "\n";

        $run = $this->runSetup([], ['DB_PASS' => null], $answers);

        self::assertSame(0, $run['status'], $run['stdout'] . $run['stderr']);
        // On alwaysdata the database server and names are suggested from the account.
        self::assertStringContainsString('Database server [mysql-student.alwaysdata.net]:', $run['stdout']);
        self::assertStringContainsString('Database name [student_bookwise]:', $run['stdout']);
        self::assertStringContainsString('Database user [student]:', $run['stdout']);
        self::assertStringNotContainsString(self::ADMIN_PASSWORD, $run['stdout'] . $run['stderr']);
        self::assertSame('https://student.alwaysdata.net', self::readEnvFile($this->envFile())['APP_URL']);
        self::assertNotNull($this->adminPasswordHash('typed-admin@example.test'));
    }

    public function testAnExistingSettingsFileIsReplacedOnlyWhenAskedTo(): void
    {
        file_put_contents($this->envFile(), "APP_NAME=Mine\n");
        $arguments = ['--no-interaction', '--app-url=https://shop.example.com', ...$this->databaseOptions()];

        $kept = $this->runSetup($arguments);
        self::assertSame(1, $kept['status']);
        self::assertStringContainsString('already exists', $kept['stderr']);
        self::assertStringContainsString('--force', $kept['stderr']);
        self::assertSame("APP_NAME=Mine\n", file_get_contents($this->envFile()));

        $declined = $this->runSetup(['--app-url=https://shop.example.com', ...$this->databaseOptions()], [], "n\n");
        self::assertSame(1, $declined['status']);
        self::assertSame("APP_NAME=Mine\n", file_get_contents($this->envFile()));

        $replaced = $this->runSetup([...$arguments, '--force']);
        self::assertSame(0, $replaced['status'], $replaced['stdout'] . $replaced['stderr']);
        self::assertSame('BookWise', self::readEnvFile($this->envFile())['APP_NAME']);
        self::assertStringContainsString('No administrator was created.', $replaced['stdout']);
    }

    public function testAnUnreachableDatabaseChangesNothingAndKeepsThePasswordSecret(): void
    {
        $secret = 'setup-db-secret-' . bin2hex(random_bytes(4));
        $run = $this->runSetup(
            ['--no-interaction', '--app-url=https://shop.example.com', '--db-host=127.0.0.1', '--db-port=' . self::closedPort(), '--db-name=bookwise', '--db-user=bookwise'],
            ['DB_PASS' => $secret]
        );

        self::assertSame(1, $run['status']);
        self::assertStringContainsString('Cannot connect to the database', $run['stderr']);
        self::assertStringContainsString('Nothing was changed.', $run['stderr']);
        self::assertStringNotContainsString($secret, $run['stdout'] . $run['stderr']);
        self::assertFileDoesNotExist($this->envFile());
    }

    public function testInvalidAnswersAreRefusedBeforeAnythingChanges(): void
    {
        $database = $this->databaseOptions();
        $cases = [
            'no address' => [['--no-interaction', ...$database], [], 'The address of the site must start with https://'],
            'not a web address' => [['--no-interaction', '--app-url=ftp://shop.example.com', ...$database], [], 'The address of the site must start with https://'],
            'port' => [['--no-interaction', '--app-url=https://shop.example.com', '--db-host=127.0.0.1', '--db-port=abc', '--db-name=x', '--db-user=x'], [], 'The database port must be a number'],
            'no database name' => [['--no-interaction', '--app-url=https://shop.example.com', '--db-host=127.0.0.1', '--db-user=x'], [], 'The database name and user are required'],
            'administrator email' => [['--no-interaction', '--app-url=https://shop.example.com', ...$database, '--admin-email=not-an-email'], ['BOOKWISE_ADMIN_PASSWORD' => self::ADMIN_PASSWORD], 'Administrator email:'],
            'short password' => [['--no-interaction', '--app-url=https://shop.example.com', ...$database, '--admin-email=admin@example.test'], ['BOOKWISE_ADMIN_PASSWORD' => 'short'], 'Administrator password:'],
            'no password' => [['--no-interaction', '--app-url=https://shop.example.com', ...$database, '--admin-email=admin@example.test'], [], 'Set BOOKWISE_ADMIN_PASSWORD'],
            'closed input' => [['--app-url=https://shop.example.com', ...$database], ['DB_PASS' => null], 'standard input is closed'],
        ];
        foreach ($cases as $label => [$arguments, $env, $message]) {
            $run = $this->runSetup($arguments, $env);
            self::assertSame(1, $run['status'], $label);
            self::assertStringContainsString($message, $run['stderr'], $label);
            self::assertFileDoesNotExist($this->envFile(), $label);
        }
        self::assertSame(0, $this->countRows('admins'));
    }

    public function testHelpExplainsTheOptions(): void
    {
        $run = self::runProjectScript('bin/setup.php', [], ['--help']);

        self::assertSame(0, $run['status']);
        foreach (['Usage:', '--app-url=URL', '--no-interaction', '--env-file=PATH', 'BOOKWISE_ADMIN_PASSWORD'] as $text) {
            self::assertStringContainsString($text, $run['stdout']);
        }
        self::assertStringNotContainsString('/**', $run['stdout']);
    }

    public function testWrittenValuesAreReadBackUnchanged(): void
    {
        foreach (['', 'plain', 'https://shop.example.com', 'p@ss word', 'with "quotes"', 'back\\slash', 'hash #1', "two\nlines", ' padded ', '$HOME', "it's"] as $value) {
            self::assertSame($value, parse_env_value(env_file_value($value)), json_encode($value) ?: '');
        }
        self::assertSame('plain', env_file_value('plain'));
        self::assertSame('"p@ss word"', env_file_value('p@ss word'));
    }
}
