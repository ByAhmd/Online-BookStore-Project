<?php

declare(strict_types=1);

/* ---------------------------------------------------------------------
 * Output escaping
 * ------------------------------------------------------------------- */

/**
 * Escapes a value for safe output in HTML text and quoted attributes.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------------------------------------------------------------------
 * Runtime, logging and HTTP headers
 * ------------------------------------------------------------------- */

function base_path(string $path = ''): string
{
    return BASE_PATH . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

/**
 * Absolute path of a file in the configured log directory (LOG_DIR).
 */
function log_path(string $file): string
{
    $dir = (string) config('app.log_dir', 'storage/logs');
    if (!preg_match('#^([A-Za-z]:[\\\\/]|/)#', $dir)) {
        $dir = base_path($dir);
    }
    $dir = rtrim($dir, '/\\');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/' . $file;
}

/**
 * Configures error handling, logging and the default timezone.
 */
function configure_runtime(): void
{
    $debug = (bool) config('app.debug');

    error_reporting(E_ALL);
    ini_set('display_errors', $debug ? '1' : '0');
    ini_set('log_errors', '1');
    ini_set('error_log', log_path('php-error.log'));
    date_default_timezone_set((string) config('app.timezone'));

    // Turn warnings/notices into exceptions so bugs fail loudly instead of
    // rendering half a page. Deprecations are only logged.
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
            app_log('warning', "Deprecated: $message", ['file' => $file, 'line' => $line]);
            return true;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    set_exception_handler(static function (Throwable $e): void {
        app_log('error', get_class($e) . ': ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
        ]);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, (string) $e . PHP_EOL);
            exit(1);
        }

        $detail = config('app.debug') ? get_class($e) . ': ' . $e->getMessage() : null;
        render_error_page(500, t('common.error.server_error'), $detail);
    });
}

/**
 * Appends a structured line to app.log in LOG_DIR. Never log secrets,
 * passwords, raw tokens or full card data.
 *
 * @param array<string, mixed> $context
 */
function app_log(string $level, string $message, array $context = []): void
{
    $line = sprintf(
        "[%s] %s: %s%s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        str_replace(["\r", "\n"], ' ', $message),
        $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    if (@file_put_contents(log_path('app.log'), $line, FILE_APPEND | LOCK_EX) === false) {
        error_log(trim($line));
    }
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return str_starts_with((string) config('app.url'), 'https://');
}

/**
 * Sends defensive HTTP headers, including a Content-Security-Policy that only
 * allows same-origin scripts (pages must not use inline <script> or on* handlers).
 */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    $formTargets = ["'self'", 'https://checkout.stripe.com'];
    $apiBase = config('stripe.api_base');
    if (is_string($apiBase) && preg_match('#^https?://[^/]+#', $apiBase, $m)) {
        $formTargets[] = $m[0]; // local Stripe mock used by the test suite
    }

    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; "
        . "img-src 'self' data: https:; "
        . "connect-src 'self'; "
        . 'form-action ' . implode(' ', $formTargets) . '; '
        . "frame-ancestors 'none'; base-uri 'self'; object-src 'none'");

    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

const SESSION_IDLE_LIMIT_SECONDS = 2 * 60 * 60;
const SESSION_ABSOLUTE_LIMIT_SECONDS = 24 * 60 * 60;

/**
 * Starts the session with hardened cookie settings, an idle timeout and an
 * absolute lifetime counted from sign-in.
 */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    // Keep session files at least as long as the idle limit (PHP's default is 24 minutes).
    ini_set('session.gc_maxlifetime', (string) SESSION_IDLE_LIMIT_SECONDS);
    session_name('bookwise_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax', // Lax so the Stripe redirect back to success_pay.php keeps the session.
    ]);
    session_start();

    $now = time();
    $idle = isset($_SESSION['_last_activity']) && ($now - (int) $_SESSION['_last_activity']) > SESSION_IDLE_LIMIT_SECONDS;
    $tooOld = isset($_SESSION['_login_at']) && ($now - (int) $_SESSION['_login_at']) > SESSION_ABSOLUTE_LIMIT_SECONDS;
    if ($idle || $tooOld) {
        $wasSignedIn = isset($_SESSION['user_id']) || isset($_SESSION['admin_email']);
        $_SESSION = [];
        session_regenerate_id(true);
        if ($wasSignedIn) {
            flash('info', t('common.session_expired'));
        }
    }
    $_SESSION['_last_activity'] = $now;
}

/* ---------------------------------------------------------------------
 * Requests, redirects and responses
 * ------------------------------------------------------------------- */

function request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function is_post(): bool
{
    return request_method() === 'POST';
}

/**
 * Rejects anything but POST with 405 Method Not Allowed.
 */
function require_post(): void
{
    if (!is_post()) {
        header('Allow: POST');
        abort(405, t('common.error.post_only'));
    }
}

/**
 * Reads a trimmed string from an input array. Non-strings and text that is not
 * valid UTF-8 are treated as empty; line endings are normalized to "\n" so
 * lengths match what the browser counted.
 *
 * @param array<string, mixed> $source
 */
function input_string(array $source, string $key, int $maxLength = 10000): string
{
    $value = $source[$key] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        return '';
    }
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    return mb_substr($value, 0, $maxLength);
}

/**
 * Parses a strictly positive integer (no signs, decimals or junk) within bounds.
 */
function parse_int(mixed $value, int $min = 1, int $max = PHP_INT_MAX): ?int
{
    if (is_int($value)) {
        $int = $value;
    } elseif (is_string($value) && preg_match('/^\d{1,10}$/D', trim($value))) {
        $int = (int) trim($value);
    } else {
        return null;
    }
    return ($int >= $min && $int <= $max) ? $int : null;
}

/**
 * Only allows local, relative page paths like "cart.php" or "Selected.php?id=4".
 */
function safe_redirect_path(mixed $path, string $fallback = 'Home_Page.php'): string
{
    if (is_string($path) && preg_match('#^[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_\-=&%.+]*)?$#D', $path)) {
        return $path;
    }
    return $fallback;
}

function redirect(string $path, int $status = 303): never
{
    header('Location: ' . $path, true, $status);
    exit;
}

/**
 * Absolute URL for links that leave the site (emails, Stripe redirects).
 */
function absolute_url(string $path): string
{
    return config('app.url') . '/' . ltrim($path, '/');
}

/**
 * @param array<string, mixed> $data
 */
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
}

/**
 * Stops the request with an HTTP error page.
 */
function abort(int $status, string $message = ''): never
{
    render_error_page($status, $message);
}

function render_error_page(int $status, string $message = '', ?string $detail = null): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $title = translation_entry('common.error.titles.' . $status, current_locale()) !== null
        ? t('common.error.titles.' . $status)
        : t('common.error.titles.default');
    $message = $message !== '' ? $message : t('common.error.default_message');
    try {
        render('error', compact('status', 'title', 'message', 'detail'));
    } catch (Throwable) {
        // Never let a broken template hide the original error.
        echo '<!DOCTYPE html><meta charset="utf-8"><title>' . e($title) . '</title><h1>' . e($title) . '</h1><p>' . e($message) . '</p>';
    }
    exit;
}

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/* ---------------------------------------------------------------------
 * Flash messages and old input (one-request session values)
 * ------------------------------------------------------------------- */

/**
 * Queues a message for the next page view. $type: success | error | info.
 */
function flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Returns and clears queued flash messages.
 *
 * @return list<array{type: string, message: string}>
 */
function take_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($messages) ? array_values($messages) : [];
}

/**
 * Remembers submitted form values (never passwords) for re-display after a redirect.
 *
 * @param array<string, string> $values
 */
function remember_input(array $values): void
{
    $_SESSION['_old_input'] = $values;
}

/**
 * Returns a remembered form value once, then forgets all remembered input.
 */
function old(string $key, string $default = ''): string
{
    static $old = null;
    if ($old === null) {
        $old = $_SESSION['_old_input'] ?? [];
        unset($_SESSION['_old_input']);
    }
    $value = $old[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

/**
 * Remembers per-field validation errors for re-display after a redirect.
 *
 * @param array<string, string> $errors field => message
 */
function remember_errors(array $errors): void
{
    $_SESSION['_form_errors'] = $errors;
}

/**
 * Returns the remembered error for a field (all errors are cleared on first read).
 */
function form_error(string $field): ?string
{
    static $errors = null;
    if ($errors === null) {
        $errors = $_SESSION['_form_errors'] ?? [];
        unset($_SESSION['_form_errors']);
    }
    $message = $errors[$field] ?? null;
    return is_string($message) ? $message : null;
}

/* ---------------------------------------------------------------------
 * Money
 * ------------------------------------------------------------------- */

/**
 * Converts a decimal amount (e.g. "19.99") to integer minor units (1999).
 */
function money_to_minor(string|int|float $amount): int
{
    if (is_string($amount) && preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', trim($amount), $m)) {
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
    return (int) round(((float) $amount) * 100);
}

function minor_to_decimal(int $minor): string
{
    return number_format($minor / 100, 2, '.', '');
}

/**
 * Formats an amount for display, e.g. "SAR 19.99". Pass a stored order's own
 * currency for historical amounts; catalog prices use STRIPE_CURRENCY.
 */
function format_money(string|int|float $amount, ?string $currency = null): string
{
    $currency = strtoupper($currency !== null && $currency !== '' ? $currency : (string) config('stripe.currency'));
    $number = number_format((float) $amount, 2);
    if (current_locale() === 'ar') {
        // Arabic puts the currency after the amount; the riyal has a customary abbreviation.
        $symbols = ['SAR' => 'ر.س'];
        return $number . ' ' . ($symbols[$currency] ?? $currency);
    }
    return $currency . ' ' . $number;
}

function format_minor(int $minor): string
{
    return format_money($minor / 100);
}

/* ---------------------------------------------------------------------
 * Views
 * ------------------------------------------------------------------- */

/**
 * Renders a template from src/views with the given variables.
 *
 * @param array<string, mixed> $data
 */
function render(string $template, array $data = []): void
{
    if (!preg_match('/^[a-z_\/]+$/D', $template)) {
        throw new InvalidArgumentException('Invalid template name');
    }
    extract($data, EXTR_SKIP);
    require base_path('src/views/' . $template . '.php');
}

/**
 * Outputs the document head, the navigation bar, flash messages and opens <main>.
 *
 * @param list<string> $styles  Stylesheets in public/css (after common.css)
 * @param array<string, mixed> $options  'bodyClass' => string
 */
function page_header(string $title, array $styles = [], array $options = []): void
{
    render('header', [
        'title' => $title,
        'styles' => $styles,
        'bodyClass' => (string) ($options['bodyClass'] ?? ''),
    ]);
}

/**
 * Closes <main>, outputs the footer and the given scripts from public/js.
 *
 * @param list<string> $scripts
 */
function page_footer(array $scripts = []): void
{
    render('footer', ['scripts' => $scripts]);
}

/**
 * Returns a safe image URL for a stored cover/avatar path, falling back when
 * the referenced local file does not exist. External images must use https
 * (the Content-Security-Policy blocks plain http images).
 */
function image_url(?string $path, string $fallback = 'imges/profileImage2.png'): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return $fallback;
    }
    if (preg_match('#^https://#i', $path)) {
        return filter_var($path, FILTER_VALIDATE_URL) !== false ? $path : $fallback;
    }
    if (str_contains($path, '..') || !preg_match('#^(imges|uploads)/[^\\\\:*?"<>|]+$#uD', $path)) {
        return $fallback;
    }
    return is_file(base_path('public/' . $path)) ? $path : $fallback;
}
