<?php

declare(strict_types=1);

/**
 * Minimal .env loader and typed configuration access.
 *
 * Real environment variables always win over values in the .env file, so the
 * same code works locally (with .env) and on servers that inject variables.
 */

/**
 * Loads KEY=VALUE pairs from a .env file into the process environment.
 * Supports blank lines, `#` comments (full-line and after values), optional
 * `export ` prefixes and single- or double-quoted values (double quotes
 * understand \n, \" and \\). Missing files are ignored.
 */
function load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = substr($line, 7);
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eq));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key)) {
            continue;
        }
        $value = parse_env_value(trim(substr($line, $eq + 1)));

        if (getenv($key) !== false || array_key_exists($key, $_ENV)) {
            continue;
        }
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

/**
 * Parses the right-hand side of a .env assignment.
 */
function parse_env_value(string $value): string
{
    if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"\s*(?:#.*)?$/sD', $value, $m)) {
        return (string) preg_replace_callback(
            '/\\\\(.)/s',
            static fn (array $e): string => match ($e[1]) {
                'n' => "\n",
                '"' => '"',
                '\\' => '\\',
                default => '\\' . $e[1],
            },
            $m[1]
        );
    }
    if (preg_match("/^'([^']*)'\\s*(?:#.*)?$/sD", $value, $m)) {
        return $m[1];
    }
    // Unquoted: strip a trailing inline comment ("value # note").
    $hash = strpos($value, ' #');
    if ($hash !== false) {
        $value = rtrim(substr($value, 0, $hash));
    }
    return $value;
}

/**
 * Writes a value for a .env file so that load_env() reads it back unchanged
 * (used by bin/setup.php): simple values stay bare, anything else is
 * double-quoted with \n, \" and \\ escapes.
 */
function env_file_value(string $value): string
{
    if (preg_match('#^[A-Za-z0-9_.,/:@+=-]*$#D', $value)) {
        return $value;
    }
    return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value) . '"';
}

/**
 * Reads an environment variable, returning $default when unset or empty.
 */
function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return (string) $value;
}

function env_bool(string $key, bool $default = false): bool
{
    $value = env($key);
    if ($value === null) {
        return $default;
    }
    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Application configuration built from the environment.
 *
 * @return array<string, mixed>
 */
function app_config(bool $refresh = false): array
{
    static $config = null;
    if ($config !== null && !$refresh) {
        return $config;
    }

    $config = [
        'app' => [
            'name' => env('APP_NAME', 'BookWise'),
            'env' => env('APP_ENV', 'production'),
            'debug' => env_bool('APP_DEBUG', false),
            'url' => rtrim((string) env('APP_URL', 'http://localhost:8000'), '/'),
            'timezone' => env('APP_TIMEZONE', 'Asia/Riyadh'),
            // Directory for app.log, mail.log and php-error.log; relative paths start at the project root.
            'log_dir' => env('LOG_DIR', 'storage/logs'),
            // Public demo: shows a banner and uses the built-in payment simulator; MAIL_DRIVER=database
            // then shows its emails in the demo inbox (inbox.php).
            'demo' => env_bool('APP_DEMO', false),
            'default_locale' => env('APP_DEFAULT_LOCALE', 'en'),
        ],
        'auth' => [
            // New accounts must confirm their email address before signing in.
            'require_email_verification' => env_bool('REQUIRE_EMAIL_VERIFICATION', true),
        ],
        'db' => [
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', '3306'),
            'name' => env('DB_NAME', 'bookwise'),
            'user' => env('DB_USER', 'root'),
            'pass' => env('DB_PASS', ''),
            // TLS for hosted databases: a CA certificate file, or its PEM text (e.g. from a hosting dashboard).
            'ssl_ca' => env('DB_SSL_CA'),
            'ssl_ca_pem' => env('DB_SSL_CA_PEM'),
        ],
        'stripe' => [
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'currency' => strtolower((string) env('STRIPE_CURRENCY', 'sar')),
            // Test-only override used by the automated test suite's local Stripe mock.
            'api_base' => env('STRIPE_API_BASE'),
            // Built-in payment simulator instead of Stripe (src/payment_simulator.php); on by default in demo mode.
            'simulator' => env_bool('PAYMENT_SIMULATOR', env_bool('APP_DEMO', false)),
            // The simulator's state file; relative paths start at the project root.
            'simulator_store' => env('PAYMENT_SIMULATOR_STORE', 'storage/payment-simulator.json'),
        ],
        'mail' => [
            // log, smtp, database (demo inbox, needs APP_DEMO=true) or null.
            'driver' => strtolower((string) env('MAIL_DRIVER', 'log')),
            'from_address' => env('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
            'from_name' => env('MAIL_FROM_NAME', 'BookWise'),
            // MAIL_DRIVER=smtp. encryption: tls (STARTTLS), ssl (implicit TLS) or none (local test servers
            // only; with none, the username and password are only sent to localhost).
            'smtp' => [
                'host' => env('SMTP_HOST'),
                'port' => (int) env('SMTP_PORT', '587'),
                'username' => env('SMTP_USERNAME'),
                'password' => env('SMTP_PASSWORD'),
                'encryption' => strtolower((string) env('SMTP_ENCRYPTION', 'tls')),
                'timeout' => (int) env('SMTP_TIMEOUT', '10'),
            ],
        ],
    ];

    return $config;
}

/**
 * Dot-notation access to app_config(), e.g. config('db.host').
 */
function config(string $key, mixed $default = null): mixed
{
    $value = app_config();
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}
