<?php

declare(strict_types=1);

/**
 * Front-matter for every page in public/:
 *     require __DIR__ . '/../src/bootstrap.php';
 *
 * Define BOOKWISE_NO_SESSION before requiring it for endpoints that must not
 * start a session (e.g. the Stripe webhook).
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

if (!is_file(BASE_PATH . '/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'BookWise is not installed yet. Run "composer install" in the project root (see README.md).';
    exit(1);
}

require BASE_PATH . '/vendor/autoload.php';

load_env(BASE_PATH . '/.env');
app_config(true);
configure_runtime();

if (PHP_SAPI !== 'cli') {
    // Buffer output so an exception mid-render can be replaced by a clean error page.
    ob_start();
    if (!defined('BOOKWISE_NO_SESSION')) {
        start_secure_session();
    }
    send_security_headers();
    handle_locale_switch();
}
