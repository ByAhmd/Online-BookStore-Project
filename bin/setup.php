<?php

declare(strict_types=1);

/**
 * Sets up BookWise as a public demo on a server without Docker, for example
 * the free alwaysdata plan (docs/DEPLOYMENT.md). It:
 *  1. checks the PHP version, the required extensions and that the folders
 *     the store writes to are writable;
 *  2. asks for the site's address and the database settings (or takes them
 *     from options or environment variables) and checks that the database
 *     answers;
 *  3. writes .env from .env.example with safe values for a public demo:
 *     production mode without error details, demo mode with the built-in
 *     payment simulator, no email (MAIL_DRIVER=null) and no email
 *     confirmation. The file is readable by its owner only;
 *  4. creates the tables and loads the sample catalog (bin/install.php
 *     --seed), or applies pending migrations to an existing BookWise database;
 *  5. optionally creates an administrator.
 * All questions come first: nothing is changed until every answer is valid.
 * Passwords are never printed, and on Linux and macOS they are not shown
 * while you type them.
 *
 * Usage:
 *   php bin/setup.php                  asks for everything
 *   php bin/setup.php --app-url=https://example.alwaysdata.net \
 *       --db-host=mysql-example.alwaysdata.net --db-name=example_bookwise \
 *       --db-user=example --admin-email=you@example.com
 *
 * Options (each falls back to the environment variable in brackets, then to a question):
 *   --app-url=URL        public address of the site                   [APP_URL]
 *   --db-host=HOST       database server                              [DB_HOST]
 *   --db-port=PORT       database port, usually 3306                  [DB_PORT]
 *   --db-name=NAME       database name                                [DB_NAME]
 *   --db-user=USER       database user                                [DB_USER]
 *                        Its password comes from DB_PASS or a question, never from an option.
 *   --admin-email=EMAIL  also create (or update) this administrator   [BOOKWISE_ADMIN_EMAIL]
 *                        Its password comes from BOOKWISE_ADMIN_PASSWORD or a question.
 *   --env-file=PATH      where to write the settings (default: .env in the project root)
 *   --force              replace an existing settings file
 *   --no-interaction     never ask: missing values are errors (an empty DB_PASS is allowed)
 *   --help               show this help
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

// This file avoids PHP 8 syntax, so that an older PHP still runs it far
// enough to say which version is needed.

/**
 * Prints an error and stops.
 *
 * @return never
 */
function setup_fail(string $message): void
{
    fwrite(STDERR, 'Error: ' . $message . PHP_EOL);
    exit(1);
}

function setup_say(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

/**
 * Turns terminal echo off (for passwords) or back on. Returns false when the
 * input is not a terminal or echo cannot be controlled (e.g. on Windows).
 */
function setup_terminal_echo(bool $on): bool
{
    static $restoreAtExit = false;
    if (DIRECTORY_SEPARATOR === '\\' || !stream_isatty(STDIN) || !function_exists('shell_exec')) {
        return false;
    }
    if (!$on && !$restoreAtExit) {
        // Give the echo back even if the script stops while it is off.
        $restoreAtExit = true;
        register_shutdown_function(static function (): void {
            shell_exec('stty echo 2>/dev/null');
        });
    }
    shell_exec('stty ' . ($on ? 'echo' : '-echo') . ' 2>/dev/null');
    return true;
}

/**
 * Asks one question on the terminal. Secrets are read without echo where
 * possible and keep their spaces.
 */
function setup_ask(string $question, string $default = '', bool $secret = false): string
{
    $hidden = $secret && setup_terminal_echo(false);
    if ($secret && !$hidden && stream_isatty(STDIN)) {
        $question .= ' (shown as you type)';
    }
    fwrite(STDOUT, $question . ($default !== '' ? " [$default]" : '') . ': ');
    $line = fgets(STDIN);
    if ($hidden) {
        setup_terminal_echo(true);
        fwrite(STDOUT, PHP_EOL);
    }
    if ($line === false) {
        setup_fail('No answer to "' . $question . '": standard input is closed. Use --no-interaction with options or environment variables instead.');
    }
    $answer = $secret ? rtrim($line, "\r\n") : trim($line);
    return $answer === '' ? $default : $answer;
}

/**
 * A value from --option=VALUE, else from the environment variable, else
 * asked for (unless --no-interaction), else $default.
 *
 * @param array<string, string|false> $options
 */
function setup_value(array $options, string $option, string $variable, string $question, string $default, bool $interactive): string
{
    if (isset($options[$option]) && is_string($options[$option])) {
        return trim($options[$option]);
    }
    $environment = getenv($variable);
    if (is_string($environment) && $environment !== '') {
        return trim($environment);
    }
    return $interactive ? setup_ask($question, $default) : $default;
}

$options = getopt('', ['app-url:', 'db-host:', 'db-port:', 'db-name:', 'db-user:', 'admin-email:', 'env-file:', 'force', 'no-interaction', 'help']);
if ($options === false || isset($options['help'])) {
    preg_match('#/\*\*(.*?)\*/#s', (string) file_get_contents(__FILE__), $help);
    setup_say(trim((string) preg_replace('/^ ?\* ?/m', '', $help[1] ?? '')));
    exit($options === false ? 1 : 0);
}
$interactive = !isset($options['no-interaction']);
$force = isset($options['force']);

/* ---------------------------------------------------------------------
 * 1. The server
 * ------------------------------------------------------------------- */

setup_say('BookWise setup: a public demo with simulated payments.');
setup_say();

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    setup_fail('BookWise needs PHP 8.1 or newer; this is PHP ' . PHP_VERSION . '. Choose a newer PHP version for your account or site and run the script with it.');
}
$missing = array_values(array_filter(['pdo_mysql', 'mbstring', 'curl', 'json', 'fileinfo'], static function (string $extension): bool {
    return !extension_loaded($extension);
}));
if ($missing !== []) {
    setup_fail('PHP is missing these extensions: ' . implode(', ', $missing) . '. Enable them in your PHP settings and run the script again.');
}
if (!is_file($root . '/vendor/autoload.php')) {
    setup_fail('The dependencies are not installed. Run "composer install --no-dev --optimize-autoloader" in ' . $root . ' first.');
}
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}
require $root . '/vendor/autoload.php';

setup_say('PHP ' . PHP_VERSION . ' with ' . (extension_loaded('gd') ? 'all extensions BookWise uses.' : 'the required extensions. gd is missing: uploaded pictures are stored without being re-encoded.'));

$writable = ['storage', 'storage/logs', 'public/uploads/avatars', 'public/uploads/covers'];
foreach ($writable as $directory) {
    $path = $root . '/' . $directory;
    if (!is_dir($path)) {
        @mkdir($path, 0755, true);
    }
    if (!is_dir($path) || !is_writable($path)) {
        setup_fail("$path must be a folder this user can write to (for example: chmod u+w $path).");
    }
}
setup_say('Writable: ' . implode(', ', $writable) . '.');

/* ---------------------------------------------------------------------
 * 2. The answers
 * ------------------------------------------------------------------- */

$envFile = isset($options['env-file']) && is_string($options['env-file']) && $options['env-file'] !== '' ? $options['env-file'] : $root . '/.env';
if (is_file($envFile) && !$force) {
    if (!$interactive || strtolower(setup_ask("$envFile already exists. Replace it? (y/N)", 'n')) !== 'y') {
        setup_fail("$envFile already exists. Edit it by hand, or run the script again with --force to replace it.");
    }
}

$appUrl = rtrim(setup_value($options, 'app-url', 'APP_URL', 'Address of the site (e.g. https://myaccount.alwaysdata.net)', '', $interactive), '/');
if (!preg_match('#^https?://[^/\s]+(/\S*)?$#D', $appUrl)) {
    setup_fail('The address of the site must start with https:// (or http://), e.g. https://myaccount.alwaysdata.net' . ($appUrl === '' ? '.' : "; got \"$appUrl\"."));
}

// On alwaysdata the database server and user are named after the account.
$account = preg_match('#^https?://([a-z0-9-]+)\.alwaysdata\.net(?:/|$)#', $appUrl, $match) ? $match[1] : null;
$dbHost = setup_value($options, 'db-host', 'DB_HOST', 'Database server', $account !== null ? "mysql-$account.alwaysdata.net" : '127.0.0.1', $interactive);
$dbPort = setup_value($options, 'db-port', 'DB_PORT', 'Database port', '3306', $interactive);
$dbName = setup_value($options, 'db-name', 'DB_NAME', 'Database name', $account !== null ? "{$account}_bookwise" : '', $interactive);
$dbUser = setup_value($options, 'db-user', 'DB_USER', 'Database user', $account ?? '', $interactive);
$dbPass = getenv('DB_PASS');
if (!is_string($dbPass) || $dbPass === '') {
    $dbPass = $interactive ? setup_ask('Database password', '', true) : '';
}

if ($dbHost === '' || preg_match('/\s/', $dbHost)) {
    setup_fail('The database server is required (DB_HOST or --db-host).');
}
if (!preg_match('/^\d{1,5}$/D', $dbPort) || (int) $dbPort < 1 || (int) $dbPort > 65535) {
    setup_fail("The database port must be a number from 1 to 65535; got \"$dbPort\".");
}
if ($dbName === '' || $dbUser === '') {
    setup_fail('The database name and user are required (DB_NAME and DB_USER, or --db-name and --db-user).');
}

$adminEmail = setup_value($options, 'admin-email', 'BOOKWISE_ADMIN_EMAIL', '', '', false);
if ($adminEmail === '' && $interactive && strtolower(setup_ask('Create an administrator account? (y/N)', 'n')) === 'y') {
    $adminEmail = setup_ask('Administrator email');
}
$adminPassword = '';
if ($adminEmail !== '') {
    $adminEmail = normalize_email($adminEmail);
    if (($error = validate_email($adminEmail)) !== null) {
        setup_fail("Administrator email: $error");
    }
    $adminPassword = (string) getenv('BOOKWISE_ADMIN_PASSWORD');
    $confirmation = $adminPassword;
    if ($adminPassword === '') {
        if (!$interactive) {
            setup_fail('Set BOOKWISE_ADMIN_PASSWORD for the administrator, or run the script without --no-interaction to be asked for it.');
        }
        $adminPassword = setup_ask('Administrator password (at least ' . PASSWORD_MIN_LENGTH . ' characters)', '', true);
        $confirmation = setup_ask('Administrator password again', '', true);
    }
    if (($error = validate_new_password($adminPassword, $confirmation)) !== null) {
        setup_fail("Administrator password: $error");
    }
}

/* ---------------------------------------------------------------------
 * 3. The database connection
 * ------------------------------------------------------------------- */

$values = [
    'APP_NAME' => 'BookWise',
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'APP_URL' => $appUrl,
    'APP_TIMEZONE' => 'Asia/Riyadh',
    'LOG_DIR' => 'storage/logs',
    'APP_DEFAULT_LOCALE' => 'en',
    'APP_DEMO' => 'true',
    // The demo sends no email, so new customers must be able to sign in without a confirmation link.
    'REQUIRE_EMAIL_VERIFICATION' => 'false',
    'DB_HOST' => $dbHost,
    'DB_PORT' => (string) (int) $dbPort,
    'DB_NAME' => $dbName,
    'DB_USER' => $dbUser,
    'DB_PASS' => $dbPass,
    'DB_SSL_CA' => '',
    'DB_SSL_CA_PEM' => '',
    'PAYMENT_SIMULATOR' => 'true',
    'PAYMENT_SIMULATOR_STORE' => 'storage/payment-simulator.json',
    // Not used while the simulator takes the payments.
    'STRIPE_SECRET_KEY' => '',
    // Random and known to nobody, so every request to stripe_webhook.php is refused.
    'STRIPE_WEBHOOK_SECRET' => 'whsec_' . bin2hex(random_bytes(24)),
    'STRIPE_CURRENCY' => 'sar',
    'MAIL_DRIVER' => 'null',
    'MAIL_FROM_ADDRESS' => 'no-reply@example.com',
    'MAIL_FROM_NAME' => 'BookWise',
    'SENDGRID_API_KEY' => '',
];

// From here on the application reads exactly these settings, whatever the
// environment or another .env file says.
foreach ($values as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $value;
}
app_config(true);
date_default_timezone_set((string) config('app.timezone'));

setup_say();
setup_say("Connecting to the database $dbName at $dbHost:$dbPort as $dbUser...");
try {
    if ((int) db_value('SELECT 1') !== 1) {
        throw new RuntimeException('Unexpected answer to SELECT 1');
    }
} catch (Throwable $e) {
    // The driver's message ("Access denied for user ...", "Connection refused") never contains the password.
    setup_fail('Cannot connect to the database: ' . trim((string) preg_replace('/\s+/', ' ', $e->getMessage()))
        . PHP_EOL . 'Check the server, port, name, user and password, and that the user may use this database. Nothing was changed.');
}
setup_say('The database answers.');

/* ---------------------------------------------------------------------
 * 4. The settings file
 * ------------------------------------------------------------------- */

$lines = ['# Written by php bin/setup.php on ' . date('Y-m-d H:i') . ': a public demo with simulated payments and no email.'];
$lines[] = '# See .env.example and docs/DEPLOYMENT.md for every setting. Keep this file private.';
$lines[] = '';
$written = [];
$example = preg_split('/\R/', rtrim((string) file_get_contents($root . '/.env.example'))) ?: [];
foreach ($example as $line) {
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=/', $line, $match) && array_key_exists($match[1], $values)) {
        $line = $match[1] . '=' . env_file_value($values[$match[1]]);
        $written[$match[1]] = true;
    }
    $lines[] = $line;
}
foreach (array_diff_key($values, $written) as $key => $value) {
    $lines[] = $key . '=' . env_file_value($value);
}

$directory = dirname($envFile);
if (!is_dir($directory) || !is_writable($directory)) {
    setup_fail("Cannot write to $directory.");
}
$temporary = $directory . '/.env.setup-' . bin2hex(random_bytes(4));
$umask = umask(0077);
try {
    $saved = file_put_contents($temporary, implode("\n", $lines) . "\n", LOCK_EX) !== false
        && @chmod($temporary, 0600)
        && rename($temporary, $envFile);
} finally {
    umask($umask);
}
if (!$saved) {
    @unlink($temporary);
    setup_fail("Could not write $envFile.");
}
@chmod($envFile, 0600);
setup_say("Wrote $envFile (readable by its owner only).");

/* ---------------------------------------------------------------------
 * 5. The database tables, the sample catalog and the administrator
 * ------------------------------------------------------------------- */

setup_say();
setup_say('Preparing the database (php bin/install.php --seed)...');
// bin/install.php creates the administrator from these two settings.
$_ENV['BOOKWISE_ADMIN_EMAIL'] = $adminEmail;
$_ENV['BOOKWISE_ADMIN_PASSWORD'] = $adminPassword;
putenv('BOOKWISE_ADMIN_EMAIL=' . $adminEmail);
putenv('BOOKWISE_ADMIN_PASSWORD=' . $adminPassword);
$argv = [$root . '/bin/install.php', '--seed'];
require $root . '/bin/install.php';
putenv('BOOKWISE_ADMIN_PASSWORD');
unset($_ENV['BOOKWISE_ADMIN_PASSWORD']);

setup_say();
setup_say("BookWise is ready at $appUrl");
setup_say('  Demo mode: payments are simulated on the store\'s own page, and no email is sent.');
if ($adminEmail !== '') {
    setup_say("  Administrator: $adminEmail (sign in on the store's Log in page).");
} else {
    setup_say('  No administrator was created. Create one later with: php bin/create-admin.php you@example.com "Your Name"');
}
setup_say('  To change a setting, edit ' . $envFile . '.');
