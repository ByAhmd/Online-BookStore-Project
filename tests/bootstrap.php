<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Tests are hermetic: the project's .env file is never loaded and
 * configure_runtime() is not called (PHPUnit handles errors itself).
 * Configuration comes from the <env> defaults in phpunit.xml.dist, which real
 * environment variables override. No session is started; tests that need one
 * set $_SESSION themselves.
 */

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

// Tests must never reach the real Stripe API. Until a test starts the local
// mock (BookWise\Tests\Support\StripeMock), any accidental call goes to a
// closed local port and fails fast.
putenv('STRIPE_API_BASE=http://127.0.0.1:9');
$_ENV['STRIPE_API_BASE'] = 'http://127.0.0.1:9';

app_config(true);

error_reporting(E_ALL);
date_default_timezone_set((string) config('app.timezone'));
