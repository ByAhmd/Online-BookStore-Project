<?php

declare(strict_types=1);

/**
 * Schema migrations.
 *
 * - database/schema.sql always describes the latest schema and records every
 *   migration it already contains in `schema_migrations`.
 * - Databases created from an older schema are brought up to date with
 *   `php bin/migrate.php`, which applies the files in database/migrations/
 *   that are not recorded yet, in name order.
 * - A migration file returns `static function (PDO $pdo): void { ... }`.
 *   MySQL commits DDL implicitly, so migrations must be safe to re-run
 *   (check before altering).
 */

/** Version recorded for databases created from the first published schema. */
const MIGRATION_BASELINE = '001_initial';
/** Tables of the first published schema: all must exist before it is recorded as the baseline. */
const MIGRATION_BASELINE_TABLES = [
    'users', 'admins', 'books', 'cart', 'favorites', 'comments', 'comment_interactions',
    'reset_tokens', 'orders', 'order_items', 'sales', 'login_attempts',
];

/* ---------------------------------------------------------------------
 * Schema inspection
 * ------------------------------------------------------------------- */

function db_table_exists(string $table): bool
{
    return db_value('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) !== null;
}

function db_column_exists(string $table, string $column): bool
{
    return db_value(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    ) !== null;
}

function db_index_exists(string $table, string $index): bool
{
    return db_value(
        'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [$table, $index]
    ) !== null;
}

function db_constraint_exists(string $table, string $name): bool
{
    return db_value(
        'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
        [$table, $name]
    ) !== null;
}

/**
 * @return list<string>
 */
function db_foreign_keys(string $table): array
{
    return array_values(array_map('strval', db_run(
        "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
        [$table]
    )->fetchAll(PDO::FETCH_COLUMN)));
}

/* ---------------------------------------------------------------------
 * SQL scripts
 * ------------------------------------------------------------------- */

/**
 * Splits a MySQL script into statements. Semicolons inside quoted strings,
 * quoted identifiers and comments never end a statement. DELIMITER blocks
 * (stored routines) are not supported.
 *
 * @return list<string>
 */
function sql_split_statements(string $sql): array
{
    $statements = [];
    $current = '';
    $length = strlen($sql);
    $i = 0;

    while ($i < $length) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($char === "'" || $char === '"' || $char === '`') {
            $j = $i + 1;
            while ($j < $length) {
                if ($sql[$j] === '\\' && $char !== '`') {
                    $j += 2;
                    continue;
                }
                if ($sql[$j] === $char) {
                    if (($sql[$j + 1] ?? '') === $char) {
                        $j += 2; // doubled quote inside the string
                        continue;
                    }
                    break;
                }
                $j++;
            }
            if ($j >= $length) {
                throw new InvalidArgumentException("Unterminated quoted string at offset $i");
            }
            $current .= substr($sql, $i, $j + 1 - $i);
            $i = $j + 1;
            continue;
        }

        $isDashComment = $char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]));
        if ($char === '#' || $isDashComment) {
            $eol = strpos($sql, "\n", $i);
            $i = $eol === false ? $length : $eol;
            continue;
        }

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
            if (trim($current) !== '') {
                $statements[] = trim($current);
            }
            $current = '';
            $i++;
            continue;
        }

        $current .= $char;
        $i++;
    }

    if (trim($current) !== '') {
        $statements[] = trim($current);
    }
    return $statements;
}

/**
 * Executes every statement of an SQL file.
 */
function db_execute_sql_file(string $path): int
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read $path");
    }
    $count = 0;
    foreach (sql_split_statements($sql) as $statement) {
        db()->exec($statement);
        $count++;
    }
    return $count;
}

/* ---------------------------------------------------------------------
 * Migration runner
 * ------------------------------------------------------------------- */

/**
 * Migration versions available in database/migrations, in order.
 *
 * @return array<string, string> version => file path
 */
function available_migrations(): array
{
    $files = glob(base_path('database/migrations/*.php')) ?: [];
    sort($files, SORT_STRING);
    $migrations = [];
    foreach ($files as $file) {
        $migrations[basename($file, '.php')] = $file;
    }
    return $migrations;
}

/**
 * Creates the bookkeeping table. A database that already has the BookWise
 * tables but no record of migrations was created from the first published
 * schema, so it is marked with the baseline version.
 *
 * @throws RuntimeException when only some of those tables exist: an
 *   installation that stopped halfway must not be mistaken for a complete one
 */
function ensure_migrations_table(): void
{
    if (db_table_exists('schema_migrations')) {
        return;
    }
    if (db_table_exists('users')) {
        $missing = array_values(array_filter(MIGRATION_BASELINE_TABLES, static fn (string $table): bool => !db_table_exists($table)));
        if ($missing !== []) {
            throw new RuntimeException(
                'The database "' . config('db.name') . '" was only partly installed (missing tables: ' . implode(', ', $missing) . '). '
                . 'Drop its tables (or create a new, empty database) and run php bin/install.php again.'
            );
        }
    }
    db()->exec(
        'CREATE TABLE schema_migrations (
            version    VARCHAR(100) NOT NULL,
            applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    if (db_table_exists('users')) {
        db_run('INSERT INTO schema_migrations (version) VALUES (?)', [MIGRATION_BASELINE]);
    }
}

/**
 * @return list<string>
 */
function applied_migrations(): array
{
    return array_values(array_map('strval', db_run('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)));
}

/**
 * Applies pending migrations and returns the versions applied.
 *
 * @param (callable(string): void)|null $report Progress callback
 * @return list<string>
 */
function run_migrations(?callable $report = null): array
{
    ensure_migrations_table();
    $applied = array_flip(applied_migrations());
    $done = [];

    foreach (available_migrations() as $version => $file) {
        if (isset($applied[$version])) {
            continue;
        }
        $migration = require $file;
        if (!is_callable($migration)) {
            throw new RuntimeException("Migration $version must return a callable");
        }
        if ($report !== null) {
            $report("Applying $version");
        }
        $migration(db());
        db_run('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
        $done[] = $version;
    }
    return $done;
}
