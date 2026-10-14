<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that use the real database through db().
 *
 * Safety: the suite refuses to run unless the configured (and the connected)
 * database name ends in "_test", because on first use in a run it drops every
 * table and recreates database/schema.sql + database/seed.sql. Before each
 * test the mutable tables are emptied; the seeded catalog is kept and only
 * re-seeded when a test changed it. When the server is unreachable every
 * integration test is skipped with an explanation.
 */
abstract class DatabaseTestCase extends TestCase
{
    use EnvironmentOverrides;

    public const DEFAULT_PASSWORD = 'correct-horse-battery';
    public const SEED_BOOK_COUNT = 55;
    /** A valid delivery address (Saudi National Address), as a customer would type it. */
    public const SAMPLE_ADDRESS = [
        'recipient_name' => 'Sara Ahmed',
        'phone' => '0512345678',
        'city' => 'Riyadh',
        'district' => 'Al Olaya',
        'street' => 'King Fahd Road',
        'building_number' => '1234',
        'postal_code' => '12345',
        'additional_number' => '6789',
        'short_address' => 'RRRD2929',
    ];

    /** Emptied before every test (children before parents is not required: FK checks are off). */
    private const MUTABLE_TABLES = [
        'comment_interactions',
        'comments',
        'favorites',
        'cart',
        'sales',
        'order_addresses',
        'order_items',
        'orders',
        'addresses',
        'reset_tokens',
        'email_verifications',
        'login_attempts',
        'demo_mail',
        'admins',
        'users',
    ];

    private static bool $schemaReady = false;
    private static ?string $skipReason = null;
    private static ?string $catalogFingerprint = null;
    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::prepareDatabase();
    }

    /**
     * Skips database tests when no server is reachable, except when
     * BOOKWISE_REQUIRE_DB is set (CI), where a missing database must fail the run.
     */
    private static function skipOrFail(string $reason): never
    {
        if (in_array(strtolower((string) getenv('BOOKWISE_REQUIRE_DB')), ['1', 'true', 'yes'], true)) {
            self::fail($reason . ' (BOOKWISE_REQUIRE_DB is set, so this is a failure.)');
        }
        self::markTestSkipped($reason);
    }

    private static function prepareDatabase(): void
    {
        self::assertSafeDatabaseName((string) config('db.name'), 'DB_NAME');
        if (self::$skipReason !== null) {
            self::skipOrFail(self::$skipReason);
        }

        try {
            $pdo = db();
        } catch (PDOException $e) {
            self::$skipReason = sprintf(
                'Integration tests skipped: cannot connect to the test database "%s" at %s:%d (%s). '
                . 'Start MySQL/MariaDB, create the database (CREATE DATABASE %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci) '
                . 'and point the suite at it, e.g. DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=root DB_PASS=secret vendor/bin/phpunit.',
                (string) config('db.name'),
                (string) config('db.host'),
                (int) config('db.port'),
                $e->getMessage(),
                (string) config('db.name')
            );
            self::skipOrFail(self::$skipReason);
        }

        self::assertSafeDatabaseName((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), 'the connected database');
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (!self::$schemaReady) {
            self::recreateSchema($pdo);
            self::$schemaReady = true;
        }
        self::resetData($pdo);
    }

    /**
     * For tests that change the schema itself: the next test starts from a
     * freshly created schema again.
     */
    protected static function schemaChanged(): void
    {
        self::$schemaReady = false;
    }

    private static function assertSafeDatabaseName(string $name, string $source): void
    {
        if (!str_ends_with($name, '_test')) {
            self::fail(sprintf(
                'Refusing to run integration tests: %s is "%s" but must end with "_test". '
                . 'The suite drops and recreates every table, so it never runs against a development or production database.',
                $source,
                $name
            ));
        }
    }

    private static function recreateSchema(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $tables = $pdo->query(
                'SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            )->fetchAll();
            foreach ($tables as $table) {
                $identifier = '`' . str_replace('`', '``', (string) $table['name']) . '`';
                $pdo->exec(($table['type'] === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . $identifier);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        self::runScript($pdo, BASE_PATH . '/database/schema.sql');
        self::runScript($pdo, BASE_PATH . '/database/seed.sql');
        self::$catalogFingerprint = self::catalogFingerprint($pdo);
    }

    private static function resetData(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (self::MUTABLE_TABLES as $table) {
                $pdo->exec('DELETE FROM ' . $table);
            }
            if (self::catalogFingerprint($pdo) !== self::$catalogFingerprint) {
                $pdo->exec('DELETE FROM books');
                self::runScript($pdo, BASE_PATH . '/database/seed.sql');
                if (self::catalogFingerprint($pdo) !== self::$catalogFingerprint) {
                    self::fail('Re-seeding the books table did not restore the seeded catalog.');
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private static function runScript(PDO $pdo, string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            self::fail("Cannot read $path");
        }
        foreach (SqlScript::split($sql) as $statement) {
            $pdo->exec($statement);
        }
    }

    private static function catalogFingerprint(PDO $pdo): string
    {
        $row = $pdo->query(
            "SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|', id, title, author, description, genre, price, stock, cover_image, created_at))), 0) AS crc
             FROM books"
        )->fetch();
        return $row['n'] . ':' . $row['crc'];
    }

    /* -----------------------------------------------------------------
     * Fixtures
     * --------------------------------------------------------------- */

    protected static function uniqueEmail(string $prefix = 'reader'): string
    {
        return sprintf('%s-%d-%s@example.com', $prefix, ++self::$sequence, bin2hex(random_bytes(3)));
    }

    /**
     * Registers a customer through register_user() and returns the find_user() row.
     *
     * @return array<string, mixed>
     */
    protected function createUser(?string $email = null, string $name = 'Test Reader', string $password = self::DEFAULT_PASSWORD): array
    {
        $email ??= self::uniqueEmail();
        $id = register_user($email, $name, $password);
        self::assertIsInt($id, "Could not register test user $email");
        $user = find_user($id);
        self::assertNotNull($user);
        return $user;
    }

    /**
     * @return array{Eaddress: string, name: string}
     */
    protected function createAdmin(?string $email = null, string $name = 'Test Admin', string $password = self::DEFAULT_PASSWORD): array
    {
        $email ??= self::uniqueEmail('admin');
        db_run('INSERT INTO admins (Eaddress, name, password) VALUES (?, ?, ?)', [$email, $name, hash_password($password)]);
        return ['Eaddress' => $email, 'name' => $name];
    }

    /**
     * Saves a delivery address for the customer through save_customer_address()
     * (their first address becomes the default) and returns its id.
     *
     * @param array<string, string> $overrides
     */
    protected function createAddress(int $userId, array $overrides = [], bool $makeDefault = false): int
    {
        [$address, $errors] = validate_address_input($overrides + self::SAMPLE_ADDRESS);
        self::assertSame([], $errors, 'The test address must be valid');
        $saved = save_customer_address($userId, $address, null, $makeDefault);
        self::assertSame('created', $saved['status'], 'Could not save the test address');
        return (int) $saved['id'];
    }

    /**
     * The id of the customer's default delivery address, which is created when they have none.
     *
     * @param array<string, mixed> $user
     */
    protected function deliveryAddressId(array $user): int
    {
        return default_address_id((int) $user['id']) ?? $this->createAddress((int) $user['id']);
    }

    /**
     * The customer's default delivery address (created when they have none), as stored.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    protected function deliveryAddress(array $user): array
    {
        $address = find_user_address((int) $user['id'], $this->deliveryAddressId($user));
        self::assertNotNull($address);
        return $address;
    }

    /**
     * Inserts a book directly (bypassing validation, e.g. for float-trap
     * prices below BOOK_PRICE_MIN). The catalog is re-seeded before the next test.
     *
     * @param array<string, string> $overrides
     */
    protected function insertBook(array $overrides = []): int
    {
        $book = $overrides + [
            'title' => 'Test Book ' . bin2hex(random_bytes(3)),
            'author' => 'Test Author',
            'description' => 'A book created by the test suite.',
            'genre' => 'Testing',
            'price' => '10.00',
            'cover_image' => 'imges/All_section/biology.png',
        ];
        db_run(
            'INSERT INTO books (title, author, description, genre, price, cover_image) VALUES (?, ?, ?, ?, ?, ?)',
            [$book['title'], $book['author'], $book['description'], $book['genre'], $book['price'], $book['cover_image']]
        );
        return (int) db()->lastInsertId();
    }

    /**
     * @param list<mixed> $params
     */
    protected function countRows(string $table, string $where = '1 = 1', array $params = []): int
    {
        return (int) db_value("SELECT COUNT(*) FROM $table WHERE $where", $params);
    }
}
