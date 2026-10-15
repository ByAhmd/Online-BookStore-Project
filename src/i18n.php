<?php

declare(strict_types=1);

/**
 * Interface translations.
 *
 * Strings live in lang/<locale>/<namespace>.php files that return arrays:
 *     t('cart.title')                    -> lang/<locale>/cart.php ['title']
 *     t('cart.added', ['title' => $t])   -> replaces :title
 *     tn('cart.items', 3)                -> picks the plural form for 3
 * Missing keys fall back to English, then to the key itself. Replacement
 * values are inserted as-is: escape the result with e() when printing HTML.
 */

const SUPPORTED_LOCALES = ['en' => 'English', 'ar' => 'العربية'];
const RTL_LOCALES = ['ar'];
const LOCALE_COOKIE = 'bookwise_lang';

/**
 * The active interface language for this request.
 */
function current_locale(): string
{
    static $locale = null;
    if (isset($GLOBALS['__bookwise_locale'])) {
        return $GLOBALS['__bookwise_locale'];
    }
    if ($locale !== null) {
        return $locale;
    }
    return $locale = detect_locale();
}

/**
 * Overrides the locale for the rest of the request (e.g. to write an email in
 * the recipient's language). Pass null to restore the visitor's language.
 */
function use_locale(?string $locale): void
{
    if ($locale === null) {
        unset($GLOBALS['__bookwise_locale']);
        return;
    }
    $GLOBALS['__bookwise_locale'] = is_supported_locale($locale) ? $locale : default_locale();
}

function is_supported_locale(mixed $locale): bool
{
    return is_string($locale) && array_key_exists($locale, SUPPORTED_LOCALES);
}

function default_locale(): string
{
    $locale = config('app.default_locale', 'en');
    return is_supported_locale($locale) ? (string) $locale : 'en';
}

function is_rtl(?string $locale = null): bool
{
    return in_array($locale ?? current_locale(), RTL_LOCALES, true);
}

function text_direction(?string $locale = null): string
{
    return is_rtl($locale) ? 'rtl' : 'ltr';
}

/**
 * Visitor's language: explicit choice (cookie), then the browser's
 * Accept-Language preferences, then APP_DEFAULT_LOCALE.
 */
function detect_locale(): string
{
    $cookie = $_COOKIE[LOCALE_COOKIE] ?? null;
    if (is_supported_locale($cookie)) {
        return $cookie;
    }

    $header = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $best = null;
    $bestQuality = 0.0;
    foreach (explode(',', $header) as $part) {
        $pieces = explode(';', trim($part));
        $tag = strtolower(substr(trim($pieces[0]), 0, 2));
        $quality = 1.0;
        foreach (array_slice($pieces, 1) as $param) {
            if (preg_match('/^\s*q=([0-9.]+)\s*$/D', $param, $m)) {
                $quality = (float) $m[1];
            }
        }
        if (is_supported_locale($tag) && $quality > $bestQuality) {
            $best = $tag;
            $bestQuality = $quality;
        }
    }
    return $best ?? default_locale();
}

/**
 * Handles "?lang=xx": remembers the choice in a cookie (and on the signed-in
 * customer's account) and redirects to the same page without the parameter.
 */
function handle_locale_switch(): void
{
    if (request_method() !== 'GET' || !isset($_GET['lang'])) {
        return;
    }
    $locale = $_GET['lang'];
    if (is_supported_locale($locale)) {
        setcookie(LOCALE_COOKIE, $locale, [
            'expires' => time() + 365 * 24 * 60 * 60,
            'path' => '/',
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[LOCALE_COOKIE] = $locale;
        try {
            // current_user() also ends sessions that were signed out elsewhere
            // (password change), so those can no longer change the account.
            $user = current_user();
            if ($user !== null) {
                db_run('UPDATE users SET locale = ? WHERE id = ?', [$locale, (int) $user['id']]);
            }
        } catch (Throwable $e) {
            app_log('warning', 'Could not store language preference: ' . $e->getMessage());
        }
    }

    $query = $_GET;
    unset($query['lang']);
    $target = safe_redirect_path(current_page_name() . ($query !== [] ? '?' . http_build_query($query) : ''), 'Home_Page.php');
    redirect($target);
}

/**
 * File name of the requested page: "index.php" for a directory URL such as
 * "/" (its index page, the sign-in page), "Home_Page.php" for anything that
 * is not a PHP page.
 */
function current_page_name(): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($path === '' || str_ends_with($path, '/')) {
        return 'index.php';
    }
    $page = basename($path);
    return preg_match('/^[A-Za-z0-9_.-]+\.php$/D', $page) ? $page : 'Home_Page.php';
}

/**
 * URL of the current page in another language. Pages that cannot simply be
 * requested again (a POST-only action showing an error, or a web-server error
 * document, which never sees the query string) link to the home page instead.
 */
function locale_switch_url(string $locale): string
{
    if (!in_array(request_method(), ['GET', 'HEAD'], true) || defined('LOCALE_SWITCH_TO_HOME')) {
        return 'Home_Page.php?' . http_build_query(['lang' => $locale]);
    }
    $query = $_GET;
    $query['lang'] = $locale;
    return current_page_name() . '?' . http_build_query($query);
}

/**
 * Loads a translation namespace for a locale.
 *
 * @return array<string, mixed>
 */
function translation_namespace(string $locale, string $namespace): array
{
    static $cache = [];
    $key = $locale . '/' . $namespace;
    if (!array_key_exists($key, $cache)) {
        // Translations ship with the code, so they are located next to src/ (not via BASE_PATH).
        $file = dirname(__DIR__) . '/lang/' . $locale . '/' . $namespace . '.php';
        $data = preg_match('/^[a-z_]+$/D', $namespace) && is_file($file) ? require $file : [];
        $cache[$key] = is_array($data) ? $data : [];
    }
    return $cache[$key];
}

/**
 * Raw translation entry (string, or array of plural forms) or null.
 */
function translation_entry(string $key, string $locale): mixed
{
    $dot = strpos($key, '.');
    if ($dot === false) {
        return null;
    }
    $namespace = substr($key, 0, $dot);
    $value = translation_namespace($locale, $namespace);
    foreach (explode('.', substr($key, $dot + 1)) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }
    return $value;
}

/**
 * @param array<string, string|int|float> $replace
 */
function t(string $key, array $replace = []): string
{
    $entry = translation_entry($key, current_locale()) ?? translation_entry($key, 'en');
    if (is_array($entry)) {
        $entry = $entry['other'] ?? reset($entry);
    }
    return translation_replace(is_string($entry) ? $entry : $key, $replace);
}

/**
 * Plural-aware translation. The entry is an array keyed by CLDR plural
 * category (zero, one, two, few, many, other); :count is always available.
 *
 * @param array<string, string|int|float> $replace
 */
function tn(string $key, int $count, array $replace = []): string
{
    $locale = current_locale();
    $entry = translation_entry($key, $locale);
    if ($entry === null) {
        $locale = 'en';
        $entry = translation_entry($key, 'en');
    }
    $replace = ['count' => $count] + $replace;
    if (!is_array($entry)) {
        return translation_replace(is_string($entry) ? $entry : $key, $replace);
    }
    $category = plural_category($locale, $count);
    $text = $entry[$category] ?? $entry['other'] ?? reset($entry);
    return translation_replace(is_string($text) ? $text : $key, $replace);
}

/**
 * CLDR plural category for English and Arabic.
 */
function plural_category(string $locale, int $count): string
{
    $n = abs($count);
    if ($locale === 'ar') {
        $mod100 = $n % 100;
        return match (true) {
            $n === 0 => 'zero',
            $n === 1 => 'one',
            $n === 2 => 'two',
            $mod100 >= 3 && $mod100 <= 10 => 'few',
            $mod100 >= 11 => 'many',
            default => 'other',
        };
    }
    return $n === 1 ? 'one' : 'other';
}

/**
 * @param array<string, string|int|float> $replace
 */
function translation_replace(string $text, array $replace): string
{
    if ($replace === []) {
        return $text;
    }
    // Longest names first so :count is not clobbered by :c.
    uksort($replace, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    foreach ($replace as $name => $value) {
        $text = str_replace(':' . $name, (string) $value, $text);
    }
    return $text;
}

/**
 * $text in a first-strong isolate (U+2068 FSI … U+2069 PDI), for plain
 * text such as emails and flash messages (pages use <bdi>). Inside a
 * sentence of either direction it takes its own direction from its first
 * letter, and it no longer pulls its neighbours along: without it, the price
 * after a Latin book title in an Arabic line ("1 × «A Minor Fall»: 11.00 ر.س")
 * would join the title's left-to-right run and be drawn on the wrong side of
 * it.
 */
function bidi_isolate(string $text): string
{
    return "\u{2068}" . $text . "\u{2069}";
}

/**
 * Plain text as escaped HTML in which each isolate of bidi_isolate() is a
 * <bdi> element, which pages use for the same purpose. For flash messages:
 * they are kept in the session as plain text, and name books and people
 * inside sentences of the visitor's language. An isolate character without
 * its partner is left as it is.
 */
function bidi_isolates_html(string $text): string
{
    $html = e($text);
    return preg_replace('/\x{2068}([^\x{2068}\x{2069}]*)\x{2069}/u', '<bdi>$1</bdi>', $html) ?? $html;
}
