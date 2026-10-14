<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\DatabaseTestCase;
use RuntimeException;

/**
 * The migration runner (src/migrations.php) against a real database: fresh
 * installs record every migration, older databases are brought up to date
 * exactly once, and a half-installed database is never taken for a complete
 * one. These tests change the schema, so each is followed by a rebuild.
 */
final class MigrationsTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        self::schemaChanged();
        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private static function migrationFiles(): array
    {
        return array_keys(available_migrations());
    }

    public function testAFreshSchemaRecordsEveryMigration(): void
    {
        self::assertContains('003_order_stock_taken', self::migrationFiles());
        self::assertSame([MIGRATION_BASELINE, ...self::migrationFiles()], applied_migrations());
        self::assertSame([], run_migrations(), 'Nothing to apply');
    }

    public function testMigration003RecordsTheCopiesPaidOrdersTookFromStock(): void
    {
        $user = $this->createUser();
        $orders = [];
        foreach (['paid', 'pending', 'refunded'] as $status) {
            db_run(
                'INSERT INTO orders (user_id, status, currency, total_amount, amount_minor) VALUES (?, ?, ?, ?, ?)',
                [$user['id'], $status, 'sar', '39.98', 3998]
            );
            $orders[$status] = (int) db()->lastInsertId();
            db_run(
                'INSERT INTO order_items (order_id, book_id, title, unit_price, quantity) VALUES (?, 51, ?, ?, 2)',
                [$orders[$status], 'Esabella', '19.99']
            );
        }

        // A database from before 003.
        db()->exec('ALTER TABLE order_items DROP COLUMN stock_taken');
        db_run('DELETE FROM schema_migrations WHERE version = ?', ['003_order_stock_taken']);

        self::assertSame(['003_order_stock_taken'], run_migrations());
        $taken = [];
        foreach ($orders as $status => $orderId) {
            $taken[$status] = (int) db_value('SELECT stock_taken FROM order_items WHERE order_id = ?', [$orderId]);
        }
        self::assertSame(['paid' => 2, 'pending' => 0, 'refunded' => 0], $taken, 'Only paid orders hold copies');
        self::assertSame([], run_migrations(), 'Applied once');
    }

    public function testMigration006CreatesTheSameTablesAndColumnsAsTheSchema(): void
    {
        $tables = ['orders' => 'orders', 'addresses' => 'addresses', 'order_addresses' => 'order_addresses'];
        $definitions = static fn (): array => array_map(
            static fn (string $table): string => (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) db_one('SHOW CREATE TABLE ' . $table)['Create Table']),
            $tables
        );
        $fresh = $definitions();
        $user = $this->createUser();
        db_run(
            "INSERT INTO orders (user_id, status, currency, total_amount, amount_minor, paid_at) VALUES (?, 'paid', 'sar', '19.99', 1999, NOW())",
            [$user['id']]
        );
        $paid = (int) db()->lastInsertId();

        // A database from before 006.
        db()->exec('DROP TABLE order_addresses');
        db()->exec('DROP TABLE addresses');
        db()->exec('ALTER TABLE orders DROP KEY idx_orders_fulfilment');
        db()->exec('ALTER TABLE orders DROP COLUMN fulfilment_status, DROP COLUMN shipped_at, DROP COLUMN delivered_at, DROP COLUMN carrier, DROP COLUMN tracking_number');
        db_run('DELETE FROM schema_migrations WHERE version = ?', ['006_order_fulfilment']);

        self::assertSame(['006_order_fulfilment'], run_migrations());
        self::assertSame($fresh, $definitions());
        $order = find_order($paid);
        self::assertNotNull($order);
        self::assertArrayHasKey('fulfilment_status', $order);
        self::assertNull($order['fulfilment_status'], 'Orders paid before 006 have no delivery to track');
        self::assertSame([], run_migrations(), 'Applied once');
    }
    public function testMigration005AddsAdministratorRolesAndDemoGuests(): void
    {
        $tables = ['admins', 'users', 'demo_guest_creations'];
        $fresh = self::tableDefinitions($tables);
        $admin = $this->createAdmin();
        $customer = $this->createUser();

        // A database from before 005.
        db()->exec('DROP TABLE demo_guest_creations');
        db()->exec('ALTER TABLE users DROP INDEX idx_users_demo_guest, DROP COLUMN demo_guest_at');
        db()->exec('ALTER TABLE admins DROP COLUMN role');
        db_run('DELETE FROM schema_migrations WHERE version = ?', ['005_admin_roles']);

        self::assertSame(['005_admin_roles'], run_migrations());
        self::assertSame(ADMIN_ROLE_OWNER, db_value('SELECT role FROM admins WHERE Eaddress = ?', [$admin['Eaddress']]), 'Existing administrators keep every right');
        self::assertNull(db_value('SELECT demo_guest_at FROM users WHERE id = ?', [$customer['id']]), 'Existing customers are not demo guests');
        self::assertSame($fresh, self::tableDefinitions($tables), 'The same tables as a fresh install from schema.sql');
        self::assertSame([], run_migrations(), 'Applied once');
    }

    /**
     * SHOW CREATE TABLE of each table, without the AUTO_INCREMENT counter.
     *
     * @param list<string> $tables
     * @return array<string, string>
     */
    private static function tableDefinitions(array $tables): array
    {
        $definitions = [];
        foreach ($tables as $table) {
            $row = db_one('SHOW CREATE TABLE `' . $table . '`');
            self::assertNotNull($row);
            $definitions[$table] = (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) $row['Create Table']);
        }
        return $definitions;
    }
    public function testACompleteDatabaseWithoutRecordsStartsFromTheBaseline(): void
    {
        // A database created from the first published schema has no record of
        // migrations. Every migration must then be safe to apply to it; here
        // they run against a schema that already has their changes.
        db()->exec('DROP TABLE schema_migrations');

        self::assertSame(self::migrationFiles(), run_migrations());
        self::assertSame([MIGRATION_BASELINE, ...self::migrationFiles()], applied_migrations());
        self::assertSame([], run_migrations());
    }

    public function testAPartlyInstalledDatabaseIsNotTakenForAComplete(): void
    {
        // An installation that stopped after creating some tables.
        db()->exec('DROP TABLE schema_migrations');
        db()->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            db()->exec('DROP TABLE login_attempts');
            db()->exec('DROP TABLE sales');
        } finally {
            db()->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        try {
            run_migrations();
            self::fail('Expected the half-installed database to be refused');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('only partly installed (missing tables: sales, login_attempts)', $e->getMessage());
        }
        self::assertFalse(db_table_exists('schema_migrations'), 'Nothing is recorded');
    }
}
