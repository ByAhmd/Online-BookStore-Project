<?php

declare(strict_types=1);

/**
 * Docker HEALTHCHECK: exits 0 when this container's /health.php answers
 * {"status":"ok"} (HTTP 200), 1 otherwise. Apache listens on PORT.
 *
 * Usage: php docker/healthcheck.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$port = getenv('PORT');
$port = is_string($port) && preg_match('/^\d{1,5}$/D', $port) ? $port : '8080';

$context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5]]);
// Non-2xx responses (503 while the database is unavailable) return false.
$body = @file_get_contents('http://127.0.0.1:' . $port . '/health.php', false, $context);
$health = is_string($body) ? json_decode($body, true) : null;

exit(is_array($health) && ($health['status'] ?? null) === 'ok' ? 0 : 1);
