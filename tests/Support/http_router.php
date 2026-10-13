<?php

declare(strict_types=1);

/**
 * Router for the application server of the HTTP end-to-end suite, started by
 * BookWise\Tests\Support\AppServer:
 *
 *     php -S 127.0.0.1:<port> -t public tests/Support/http_router.php
 *
 * TEST TOOL ONLY; the application never loads it.
 *
 * The harness passes its complete configuration in the process environment,
 * but Windows silently drops empty variables (e.g. DB_PASS= or
 * SENDGRID_API_KEY=) from a child process, and load_env() would then fill
 * them from the developer's .env file. Seeding $_ENV with every configured
 * key, empty ones included, keeps .env out of the tests on every platform
 * (load_env() skips keys present in $_ENV, and env() reads $_ENV first).
 *
 * Returning false lets the built-in server serve the requested PHP script or
 * static file exactly as it would without a router.
 */

$environmentFile = getenv('BOOKWISE_HTTP_ENV_FILE');
if (is_string($environmentFile) && $environmentFile !== '' && is_file($environmentFile)) {
    $variables = json_decode((string) file_get_contents($environmentFile), true);
    if (is_array($variables)) {
        foreach ($variables as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $_ENV[$key] = $value;
            }
        }
    }
}

return false;
