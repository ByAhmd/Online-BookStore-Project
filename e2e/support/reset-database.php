<?php

declare(strict_types=1);

/**
 * Drops every table of the configured database, so that the browser tests'
 * local store (e2e/local-server.js) starts from a fresh install:
 *
 *     DB_NAME=bookwise_e2e_test php e2e/support/reset-database.php
 *
 * TEST TOOL ONLY; the application never loads it. Like the PHPUnit suites, it
 * refuses to touch a database whose name does not end in "_test", both the
 * configured name and the one the connection actually uses.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$configured = (string) config('db.name');
if (!str_ends_with($configured, '_test')) {
    fwrite(STDERR, 'Refusing to reset the database "' . $configured . '": the browser tests only use databases whose name ends in "_test".' . PHP_EOL);
    exit(1);
}
$connected = (string) db_value('SELECT DATABASE()');
if ($connected !== $configured) {
    fwrite(STDERR, 'Refusing to reset the database: connected to "' . $connected . '" instead of "' . $configured . '".' . PHP_EOL);
    exit(1);
}

$tables = array_map(static fn (array $row): string => (string) reset($row), db_all('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\''));
db_run('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $table) {
    db_run('DROP TABLE `' . str_replace('`', '``', $table) . '`');
}
db_run('SET FOREIGN_KEY_CHECKS = 1');

echo 'Dropped ' . count($tables) . ' table(s) of "' . $configured . '".' . PHP_EOL;
