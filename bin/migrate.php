<?php

declare(strict_types=1);

/**
 * Applies pending schema migrations (database/migrations) to the database
 * configured in .env. Safe to run repeatedly.
 *
 * Usage: php bin/migrate.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

if (!db_table_exists('users')) {
    fwrite(STDERR, 'The database "' . config('db.name') . '" has no BookWise tables. Run: php bin/install.php --seed' . PHP_EOL);
    exit(1);
}

$applied = run_migrations(static function (string $line): void {
    echo $line . PHP_EOL;
});

echo $applied === []
    ? 'Database schema is up to date.' . PHP_EOL
    : 'Applied ' . count($applied) . ' migration(s).' . PHP_EOL;
