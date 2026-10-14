<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;

final class DatabaseTest extends DatabaseTestCase
{
    public function testConnectionSettings(): void
    {
        $pdo = db();

        self::assertSame($pdo, db(), 'One shared connection');
        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        // Named zone when the server has time zone tables (DST-aware), else the current UTC offset.
        self::assertContains(
            db_value('SELECT @@session.time_zone'),
            [date_default_timezone_get(), (new DateTimeImmutable('now'))->format('P')],
            'The session uses the app timezone'
        );
        $drift = abs((new DateTimeImmutable((string) db_value('SELECT NOW()')))->getTimestamp() - time());
        self::assertLessThan(5, $drift, 'NOW() matches the application clock');
        self::assertSame('utf8mb4', db_value('SELECT @@character_set_connection'));
    }

    public function testNativePreparedStatementsReturnTypedValues(): void
    {
        $row = db_one('SELECT id, title, price FROM books WHERE id = ?', [7]);

        self::assertSame(7, $row['id'], 'Integers stay integers');
        self::assertSame('5.50', $row['price'], 'DECIMAL stays an exact string');
        self::assertNull(db_one('SELECT id FROM books WHERE id = ?', [999999]));
        self::assertNull(db_value('SELECT id FROM books WHERE id = ?', [999999]));
        self::assertSame([], db_all('SELECT id FROM books WHERE id = ?', [999999]));
    }

    public function testTransactionCommitsAndReturnsTheCallbackResult(): void
    {
        $result = db_transaction(static function (PDO $pdo): string {
            db_run('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)', ['tx@example.com', '198.51.100.1']);
            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, $this->countRows('login_attempts'));
        self::assertFalse(db()->inTransaction());
    }

    public function testTransactionRollsBackAndRethrows(): void
    {
        try {
            db_transaction(static function (): void {
                db_run('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)', ['tx@example.com', '198.51.100.1']);
                throw new RuntimeException('boom');
            });
            self::fail('Expected the exception to be rethrown');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(0, $this->countRows('login_attempts'));
        self::assertFalse(db()->inTransaction());
    }

    public function testDuplicateKeyErrorsAreRecognised(): void
    {
        $this->createUser('unique@example.com');

        try {
            db_run('INSERT INTO users (Eaddress, name, pass) VALUES (?, ?, ?)', ['unique@example.com', 'Again', 'x']);
            self::fail('Expected a duplicate-key error');
        } catch (PDOException $e) {
            self::assertTrue(is_duplicate_key_error($e));
        }

        try {
            db_run('INSERT INTO cart (user_id, book_id, quantity) VALUES (?, ?, 1)', [999999, 7]);
            self::fail('Expected a foreign-key error');
        } catch (PDOException $e) {
            self::assertFalse(is_duplicate_key_error($e), 'Other integrity errors are not duplicates');
        }
    }
}
