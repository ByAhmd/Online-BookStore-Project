<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Docker\RunsProjectScripts;
use BookWise\Tests\Support\DatabaseTestCase;

/**
 * e2e/support/reset-database.php against the suite's own "_test" database:
 * it drops every table, so that the browser tests' local store
 * (e2e/local-server.js) starts from a fresh install, and it touches nothing
 * when the connection uses another database than the configured one. The
 * refusal of names without "_test" is in tests/Unit. These tests drop the
 * schema, so each is followed by a rebuild.
 */
final class BrowserTestDatabaseResetTest extends DatabaseTestCase
{
    use RunsProjectScripts;

    protected function tearDown(): void
    {
        self::schemaChanged();
        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private static function tables(): array
    {
        return array_map(
            static fn (array $row): string => (string) reset($row),
            db_all('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')
        );
    }

    public function testDropsEveryTableOfTheTestDatabase(): void
    {
        // A table the schema does not know, which refers to one it does.
        db()->exec('CREATE TABLE e2e_reset_probe (id INT PRIMARY KEY, user_id INT NOT NULL, FOREIGN KEY (user_id) REFERENCES users (id))');
        $user = $this->createUser();
        db_run('INSERT INTO e2e_reset_probe (id, user_id) VALUES (1, ?)', [$user['id']]);
        $tables = self::tables();
        self::assertContains('e2e_reset_probe', $tables);
        self::assertContains('users', $tables);

        $result = self::runProjectScript('e2e/support/reset-database.php');

        self::assertSame(0, $result['status'], $result['stdout'] . $result['stderr']);
        self::assertSame('', $result['stderr']);
        self::assertSame(
            'Dropped ' . count($tables) . ' table(s) of "' . config('db.name') . '".' . PHP_EOL,
            $result['stdout']
        );
        self::assertSame([], self::tables());
    }

    public function testRefusesWhenTheConnectionUsesAnotherDatabase(): void
    {
        // The name ends in "_test", but the extra DSN field makes PDO open
        // the suite's database: the script must notice and drop nothing.
        $database = (string) config('db.name');
        $configured = $database . ';note=_test';
        $tables = self::tables();

        $result = self::runProjectScript('e2e/support/reset-database.php', ['DB_NAME' => $configured]);

        self::assertSame(1, $result['status'], $result['stdout'] . $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString(
            'Refusing to reset the database: connected to "' . $database . '" instead of "' . $configured . '".',
            $result['stderr']
        );
        self::assertSame($tables, self::tables());
    }
}
