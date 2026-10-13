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
        ],
        'db' => [
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', '3306'),
            'name' => env('DB_NAME', 'bookwise'),
            'user' => env('DB_USER', 'root'),
            'pass' => env('DB_PASS', ''),
        ],
        'stripe' => [
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'currency' => strtolower((string) env('STRIPE_CURRENCY', 'sar')),
            // Test-only override used by the automated test suite's local Stripe mock.
            'api_base' => env('STRIPE_API_BASE'),
        ],
        'mail' => [
            'driver' => strtolower((string) env('MAIL_DRIVER', 'log')),
            'from_address' => env('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
            'from_name' => env('MAIL_FROM_NAME', 'BookWise'),
            'sendgrid_api_key' => env('SENDGRID_API_KEY'),
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
