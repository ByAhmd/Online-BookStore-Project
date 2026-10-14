<?php

declare(strict_types=1);

/**
 * Exits 0 when the database configured in the environment (DB_HOST, DB_PORT,
 * DB_NAME, DB_USER, DB_PASS, DB_SSL_CA / DB_SSL_CA_PEM) answers SELECT 1, and
 * 1 otherwise with a one-line reason on STDERR. The reason is the driver's
 * message (e.g. "Connection refused" or "Access denied for user ..."), which
 * never contains the password.
 *
 * Used by docker/entrypoint.sh while it waits for the database.
 * Usage: php docker/db-ready.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    if ((int) db_value('SELECT 1') !== 1) {
        throw new RuntimeException('Unexpected answer to SELECT 1');
    }
} catch (Throwable $e) {
    $message = trim((string) preg_replace('/\s+/', ' ', $e->getMessage()));
    fwrite(STDERR, get_class($e) . ': ' . mb_substr($message, 0, 300) . PHP_EOL);
    exit(1);
}

exit(0);
