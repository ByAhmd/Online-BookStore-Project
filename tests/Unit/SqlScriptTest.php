<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use BookWise\Tests\Support\SqlScript;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The schema loader used by the integration tests must split the real
 * schema/seed files correctly.
 */
final class SqlScriptTest extends TestCase
{
    public function testSplitsOnSemicolonsOutsideStringsAndComments(): void
    {
        $sql = <<<'SQL'
            -- Reviews. `username` stores the author's email; never shown
            SET NAMES utf8mb4;
            # hash comment; with 'quote
            INSERT INTO t (a, b) VALUES ('x;y', 'it\'s'), ('it''s', "dq;\"x");
            /* block; 'comment' */ SELECT `we;ird``name` FROM t;
            SELECT 1--1;
            SELECT 2
            SQL;

        self::assertSame([
            'SET NAMES utf8mb4',
            "INSERT INTO t (a, b) VALUES ('x;y', 'it\\'s'), ('it''s', \"dq;\\\"x\")",
            'SELECT `we;ird``name` FROM t',
            'SELECT 1--1',
            'SELECT 2',
        ], SqlScript::split($sql));
    }

    public function testKeepsExecutableComments(): void
    {
        self::assertSame(['/*!40101 SET NAMES utf8mb4 */'], SqlScript::split('/*!40101 SET NAMES utf8mb4 */;'));
    }

    public function testRejectsUnterminatedStrings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SqlScript::split("SELECT 'oops");
    }

    public function testRejectsDelimiterBlocks(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SqlScript::split("DELIMITER //\nCREATE PROCEDURE p() BEGIN SELECT 1; END//");
    }

    public function testProjectSchemaAndSeedSplitIntoExpectedStatements(): void
    {
        $schema = SqlScript::split((string) file_get_contents(BASE_PATH . '/database/schema.sql'));
        $creates = array_values(array_filter($schema, static fn (string $s): bool => str_starts_with($s, 'CREATE TABLE')));
        self::assertCount(count($schema) - 1, $creates, 'SET NAMES plus one CREATE TABLE per table');
        self::assertCount(12, $creates);

        $seed = SqlScript::split((string) file_get_contents(BASE_PATH . '/database/seed.sql'));
        self::assertCount(2, $seed);
        self::assertSame(55, substr_count($seed[1], "', 'imges/"), 'All 55 seed rows stay in one INSERT');
    }
}
