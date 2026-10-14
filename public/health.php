<?php

declare(strict_types=1);

/**
 * Health check for containers and hosting platforms (Docker HEALTHCHECK,
 * Render's healthCheckPath, uptime monitors).
 *
 *   200 {"status":"ok"}           the database answers SELECT 1
 *   503 {"status":"unavailable"}  it does not
 *
 * GET (and HEAD) only, no session, never cached, and no details: the reason
 * for a failure goes to app.log, not to the caller.
 */

define('BOOKWISE_NO_SESSION', true);

require __DIR__ . '/../src/bootstrap.php';

if (!in_array(request_method(), ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    json_response(['status' => 'method_not_allowed'], 405);
}

try {
    $healthy = (int) db_value('SELECT 1') === 1;
} catch (Throwable $e) {
    $healthy = false;
    app_log('warning', 'Health check: database unavailable (' . get_class($e) . ': ' . $e->getMessage() . ')');
}

json_response(['status' => $healthy ? 'ok' : 'unavailable'], $healthy ? 200 : 503);
