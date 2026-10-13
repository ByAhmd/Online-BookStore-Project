<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

use InvalidArgumentException;

/**
 * Splits a MySQL/MariaDB script into single statements. Semicolons, quotes
 * and comment markers inside quoted strings, quoted identifiers and comments
 * never end a statement. DELIMITER blocks (stored routines) are not supported.
 */
final class SqlScript
{
    /**
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($char === "'" || $char === '"' || $char === '`') {
                $end = self::endOfQuoted($sql, $i);
                $current .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }

            // "-- " needs whitespace after the dashes in MySQL ("1--1" is arithmetic).
            $isDashComment = $char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]));
            if ($char === '#' || $isDashComment) {
                $eol = strpos($sql, "\n", $i);
                $i = $eol === false ? $length : $eol;
                continue;
            }

            // Plain block comments are dropped; /*! ... */ is executable and kept.
            if ($char === '/' && $next === '*' && ($sql[$i + 2] ?? '') !== '!') {
                $close = strpos($sql, '*/', $i + 2);
                if ($close === false) {
                    throw new InvalidArgumentException("Unterminated /* comment at offset $i");
                }
                $current .= ' ';
                $i = $close + 2;
                continue;
            }

            if ($char === ';') {
                self::push($statements, $current);
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        self::push($statements, $current);
        return $statements;
    }

    /**
     * Offset just after the closing quote of the string or identifier at $start.
     */
    private static function endOfQuoted(string $sql, int $start): int
    {
        $quote = $sql[$start];
        $length = strlen($sql);
        $i = $start + 1;

        while ($i < $length) {
            $char = $sql[$i];
            if ($char === '\\' && $quote !== '`') {
                $i += 2;
                continue;
            }
            if ($char === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2; // doubled quote, e.g. 'it''s'
                    continue;
                }
                return $i + 1;
            }
            $i++;
        }

        throw new InvalidArgumentException("Unterminated quoted string starting at offset $start");
    }

    /**
     * @param list<string> $statements
     */
    private static function push(array &$statements, string $statement): void
    {
        $statement = trim($statement);
        if ($statement === '') {
            return;
        }
        if (preg_match('/^DELIMITER\b/i', $statement) === 1) {
            throw new InvalidArgumentException('DELIMITER blocks are not supported by the test schema loader.');
        }
        $statements[] = $statement;
    }
}
