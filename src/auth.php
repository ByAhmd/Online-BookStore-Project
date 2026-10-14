<?php

declare(strict_types=1);

/** Failures allowed for one email from one IP address. */
const LOGIN_MAX_ATTEMPTS_PER_EMAIL = 5;
const LOGIN_MAX_ATTEMPTS_PER_IP = 20;
/** Soft brake against distributed guessing of a single account from many IPs. */
const LOGIN_MAX_ATTEMPTS_PER_EMAIL_GLOBAL = 100;
const LOGIN_LOCKOUT_MINUTES = 15;

/* ---------------------------------------------------------------------
 * Password hashing
 * ------------------------------------------------------------------- */

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * The original project stored unsalted MD5 digests. They are still accepted
 * once so existing accounts keep working, then upgraded to password_hash().
 */
function is_legacy_md5_hash(string $hash): bool
{
    return (bool) preg_match('/^[a-f0-9]{32}$/iD', $hash);
}

/**
 * Verifies a password against a modern or legacy hash.
 *
 * @return array{valid: bool, needs_rehash: bool}
 */
function check_password(string $password, ?string $storedHash): array
{
    $storedHash = (string) $storedHash;
    if ($storedHash === '') {
        return ['valid' => false, 'needs_rehash' => false];
    }
    if (is_legacy_md5_hash($storedHash)) {
        $valid = hash_equals(strtolower($storedHash), md5($password));
        return ['valid' => $valid, 'needs_rehash' => $valid];
    }
    $valid = password_verify($password, $storedHash);
    return ['valid' => $valid, 'needs_rehash' => $valid && password_needs_rehash($storedHash, PASSWORD_DEFAULT)];
}

/**
 * A hash with the current algorithm and cost, used to spend the same time on
 * unknown accounts as on real ones.
 */
function dummy_password_hash(): string
{
    static $hash = null;
    return $hash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
}

/* ---------------------------------------------------------------------
 * Login throttling
 * ------------------------------------------------------------------- */

/**
 * Throttles by email+IP (so an attacker elsewhere cannot lock a user out), by
 * IP, and with a high global per-email ceiling.
 */
function login_is_throttled(string $email, string $ip): bool
{
    $window = LOGIN_LOCKOUT_MINUTES;
    $row = db_one(
        "SELECT
            SUM(email = ? AND ip_address = ?) AS by_email_ip,
            SUM(ip_address = ?) AS by_ip,
            SUM(email = ?) AS by_email
         FROM login_attempts
         WHERE (email = ? OR ip_address = ?) AND attempted_at > (NOW() - INTERVAL $window MINUTE)",
        [$email, $ip, $ip, $email, $email, $ip]
    );
    return (int) ($row['by_email_ip'] ?? 0) >= LOGIN_MAX_ATTEMPTS_PER_EMAIL
        || (int) ($row['by_ip'] ?? 0) >= LOGIN_MAX_ATTEMPTS_PER_IP
        || (int) ($row['by_email'] ?? 0) >= LOGIN_MAX_ATTEMPTS_PER_EMAIL_GLOBAL;
}

function record_failed_login(string $email, string $ip): void
{
    db_run('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)', [mb_substr($email, 0, 255), $ip]);
    db_run('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
}

function clear_failed_logins(string $email): void
{
    db_run('DELETE FROM login_attempts WHERE email = ?', [$email]);
}

/* ---------------------------------------------------------------------
 * Authentication
 * ------------------------------------------------------------------- */

/**
 * Checks credentials against the admins table, then the users table.
 * Upgrades legacy hashes on success.
 *
 * @return array{type: 'admin'|'user', record: array<string, mixed>}|null
 */
function attempt_login(string $email, string $password): ?array
{
    // Exactly one bcrypt verification per attempt, whatever accounts exist,
    // so response times do not reveal admins or registered customers.
    $verified = false;

    $admin = db_one('SELECT Eaddress, name, password FROM admins WHERE Eaddress = ?', [$email]);
    if ($admin !== null) {
        $check = check_password($password, $admin['password']);
        $verified = !is_legacy_md5_hash((string) $admin['password']);
        if ($check['valid']) {
            if ($check['needs_rehash']) {
                db_run('UPDATE admins SET password = ? WHERE Eaddress = ?', [hash_password($password), $admin['Eaddress']]);
            }
            unset($admin['password']);
            return ['type' => 'admin', 'record' => $admin];
        }
        // Fall through: the same email may also belong to a customer account.
    }

    $user = db_one('SELECT id, Eaddress, name, pass FROM users WHERE Eaddress = ?', [$email]);
    $check = $user !== null ? check_password($password, (string) $user['pass']) : null;
    if ($user !== null && !is_legacy_md5_hash((string) $user['pass'])) {
        $verified = true;
    }
    if (!$verified) {
        // Unknown email or legacy MD5 hash: spend the time a bcrypt check would take.
        password_verify($password, dummy_password_hash());
    }
    if ($user === null || $check === null || !$check['valid']) {
        return null;
    }
    if ($check['needs_rehash']) {
        db_run('UPDATE users SET pass = ? WHERE id = ?', [hash_password($password), $user['id']]);
    }
    unset($user['pass']);
    return ['type' => 'user', 'record' => $user];
}

/**
 * Starts an authenticated customer session (new session ID prevents fixation).
 *
 * @param array<string, mixed> $user
 */
function login_user(array $user): void
{
    session_regenerate_id(true);
    unset($_SESSION['admin_email']);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = 'user';
    $_SESSION['session_version'] = (int) db_value('SELECT session_version FROM users WHERE id = ?', [(int) $user['id']]);
    $_SESSION['_login_at'] = time();
    rotate_csrf_token();
}

/**
 * @param array<string, mixed> $admin
 */
function login_admin(array $admin): void
{
    session_regenerate_id(true);
    unset($_SESSION['user_id']);
    $_SESSION['admin_email'] = (string) $admin['Eaddress'];
    $_SESSION['role'] = 'admin';
    $_SESSION['session_version'] = (int) db_value('SELECT session_version FROM admins WHERE Eaddress = ?', [(string) $admin['Eaddress']]);
    $_SESSION['_login_at'] = time();
    rotate_csrf_token();
}

/**
 * Sessions carry the account's session_version from sign-in. Changing a
 * password increments it, which signs out every other session.
 *
 * @param array<string, mixed>|null $row
 */
function session_version_matches(?array $row): bool
{
    return $row !== null && (int) $row['session_version'] === (int) ($_SESSION['session_version'] ?? -1);
}

function logout_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie((string) session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }
    session_destroy();
    // Start a clean session so a flash message can be shown on the next page.
    session_start();
    session_regenerate_id(true);
}

/**
 * The signed-in customer, or null. Cached for the request.
 *
 * @return array<string, mixed>|null
 */
function current_user(bool $refresh = false): ?array
{
    static $cache = [];
    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id)) {
        return null;
    }
    if ($refresh || !array_key_exists($id, $cache)) {
        $row = db_one(
            'SELECT id, Eaddress, name, favorite_genre, date_of_birth, payment_method, profile_image, session_version
             FROM users WHERE id = ?',
            [$id]
        );
        if (!session_version_matches($row)) {
            // Account removed, or its password changed since this session signed in.
            unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['session_version']);
            $row = null;
        } else {
            unset($row['session_version']);
        }
        $cache[$id] = $row;
    }
    return $cache[$id];
}

/**
 * The signed-in administrator, or null.
 *
 * @return array<string, mixed>|null
 */
function current_admin(): ?array
{
    static $cache = [];
    $email = $_SESSION['admin_email'] ?? null;
    if (!is_string($email)) {
        return null;
    }
    if (!array_key_exists($email, $cache)) {
        $row = db_one('SELECT Eaddress, name, session_version FROM admins WHERE Eaddress = ?', [$email]);
        if (!session_version_matches($row)) {
            unset($_SESSION['admin_email'], $_SESSION['role'], $_SESSION['session_version']);
            $row = null;
        } else {
            unset($row['session_version']);
        }
        $cache[$email] = $row;
    }
    return $cache[$email];
}

function is_admin(): bool
{
    return current_admin() !== null;
}

/**
 * Requires a signed-in customer. Guests are sent to the login form (and
 * returned afterwards); administrators are sent to their dashboard.
 *
 * @return array<string, mixed>
 */
function require_user(): array
{
    $user = current_user();
    if ($user !== null) {
        return $user;
    }
    if (is_admin()) {
        flash('info', t('auth.guard.admin_shop'));
        redirect('admin_dashboard.php');
    }

    if (request_method() === 'GET') {
        remember_intended_url();
    }
    flash('info', t('auth.guard.login_required'));
    redirect('index.php?form=login');
}

/**
 * Requires a signed-in administrator; everyone else gets 403 or the login form.
 *
 * @return array<string, mixed>
 */
function require_admin(): array
{
    $admin = current_admin();
    if ($admin !== null) {
        return $admin;
    }
    if (current_user() !== null) {
        abort(403, t('auth.guard.admin_forbidden'));
    }
    if (request_method() === 'GET') {
        remember_intended_url();
    }
    flash('info', t('auth.guard.admin_login_required'));
    redirect('index.php?form=login');
}

/**
 * Remembers the current page so the visitor returns to it after signing in.
 */
function remember_intended_url(): void
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $page = basename((string) parse_url($requestUri, PHP_URL_PATH));
    $query = (string) parse_url($requestUri, PHP_URL_QUERY);
    $_SESSION['intended'] = safe_redirect_path($page . ($query !== '' ? '?' . $query : ''), 'Home_Page.php');
}

/**
 * Where to send someone right after they sign in.
 */
function post_login_redirect(string $type): string
{
    $intended = $_SESSION['intended'] ?? null;
    unset($_SESSION['intended']);
    if ($type === 'admin') {
        // Administrators only return to admin pages; customer pages would bounce them.
        $target = safe_redirect_path($intended, 'admin_dashboard.php');
        return preg_match('/^(admin_dashboard|edit_book|admin_orders|admin_order)\.php/', $target) ? $target : 'admin_dashboard.php';
    }
    return safe_redirect_path($intended, 'Home_Page.php');
}

/**
 * Translates $key into escaped HTML whose :placeholders listed in $html are
 * replaced by ready-made HTML fragments (escape their contents yourself), so
 * a translation can put markup such as <strong> wherever its language needs it.
 *
 * @param array<string, string> $html placeholder => HTML fragment
 * @param array<string, string|int|float> $replace plain-text placeholders
 */
function t_html(string $key, array $html, array $replace = []): string
{
    $markers = [];
    foreach (array_keys($html) as $name) {
        $markers[$name] = "\u{E000}" . $name . "\u{E001}";
    }
    $text = e(t($key, $markers + $replace));
    foreach ($html as $name => $fragment) {
        $text = str_replace($markers[$name], $fragment, $text);
    }
    return $text;
}

/* ---------------------------------------------------------------------
 * Localized validation messages for the sign-in forms
 * (the rules themselves live in validation.php)
 * ------------------------------------------------------------------- */

function email_error(string $email): ?string
{
    if (validate_email($email) === null) {
        return null;
    }
    return $email === '' ? t('auth.validation.email_required') : t('auth.validation.email_invalid');
}

function person_name_error(string $name): ?string
{
    if (validate_person_name($name) === null) {
        return null;
    }
    if ($name === '') {
        return t('auth.validation.name_required');
    }
    if (mb_strlen($name) > NAME_MAX_LENGTH) {
        return tn('auth.validation.name_too_long', NAME_MAX_LENGTH);
    }
    return t('auth.validation.name_invalid');
}

/**
 * Validates a new password and its confirmation. A valid password that only
 * differs from its confirmation is reported on the confirmation field.
 *
 * @return array<string, string> field => message (empty when valid)
 */
function new_password_errors(string $password, string $confirmation, string $field, string $confirmationField): array
{
    if (validate_new_password($password, $confirmation) === null) {
        return [];
    }
    if (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
        return [$field => tn('auth.validation.password_too_short', PASSWORD_MIN_LENGTH)];
    }
    if (strlen($password) > PASSWORD_MAX_BYTES) {
        return [$field => tn('auth.validation.password_too_long', PASSWORD_MAX_BYTES)];
    }
    return [$confirmationField => t('auth.validation.password_mismatch')];
}

/**
 * Reads a password field exactly as typed (never trimmed); non-strings are empty.
 *
 * @param array<string, mixed> $source
 */
function input_password(array $source, string $key): string
{
    $value = $source[$key] ?? '';
    return is_string($value) ? $value : '';
}

/* ---------------------------------------------------------------------
 * Changing the password of a signed-in customer
 * ------------------------------------------------------------------- */

/**
 * Changes a customer's password after checking the current one. A wrong
 * current password counts as a failed sign-in for throttling. On success
 * session_version is incremented (every other session is signed out) and
 * outstanding reset links stop working; the caller keeps its own session
 * with keep_session_after_password_change().
 *
 * @return array{status: 'changed'|'wrong_password'|'throttled', session_version: int|null}
 */
function change_user_password(int $userId, string $currentPassword, string $newPassword, string $ip): array
{
    $email = db_value('SELECT Eaddress FROM users WHERE id = ?', [$userId]);
    if (!is_string($email)) {
        return ['status' => 'wrong_password', 'session_version' => null];
    }
    if (login_is_throttled($email, $ip)) {
        return ['status' => 'throttled', 'session_version' => null];
    }

    $newHash = hash_password($newPassword);
    $version = db_transaction(static function () use ($userId, $email, $currentPassword, $newHash): ?int {
        $row = db_one('SELECT pass FROM users WHERE id = ? FOR UPDATE', [$userId]);
        if ($row === null || !check_password($currentPassword, (string) $row['pass'])['valid']) {
            return null;
        }
        db_run('UPDATE users SET pass = ?, session_version = session_version + 1 WHERE id = ?', [$newHash, $userId]);
        db_run('DELETE FROM reset_tokens WHERE email = ?', [$email]);
        return (int) db_value('SELECT session_version FROM users WHERE id = ?', [$userId]);
    });

    if ($version === null) {
        record_failed_login($email, $ip);
        return ['status' => 'wrong_password', 'session_version' => null];
    }
    clear_failed_logins($email);
    return ['status' => 'changed', 'session_version' => $version];
}

/**
 * Keeps the current session signed in after its own password change: it
 * adopts the new session_version and gets a new session ID and CSRF token.
 */
function keep_session_after_password_change(int $sessionVersion): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['session_version'] = $sessionVersion;
    rotate_csrf_token();
}

/**
 * Tells the account owner that their password was changed.
 */
function send_password_changed_email(int $userId): void
{
    $account = find_user_account($userId);
    if ($account === null) {
        return;
    }
    $name = email_verification_required() && $account['email_verified_at'] === null ? '' : $account['name'];
    $resetLink = absolute_url('index.php?form=reset');
    $sent = send_localized_mail($account['Eaddress'], $name, $account['locale'], static function () use ($name, $resetLink): array {
        $greeting = $name !== '' ? t('auth.mail.greeting_name', ['name' => $name]) : t('auth.mail.greeting');
        return [
            t('account.mail.password_changed.subject'),
            mail_html_layout(
                '<p>' . e($greeting) . '</p>'
                . '<p>' . e(t('account.mail.password_changed.body')) . '</p>'
                . '<p>' . e(t('account.mail.password_changed.not_you')) . ' '
                . '<a href="' . e($resetLink) . '">' . e(t('account.mail.password_changed.reset')) . '</a></p>'
                . mail_html_signature()
            ),
            $greeting . "\n\n"
                . t('account.mail.password_changed.body') . "\n\n"
                . t('account.mail.password_changed.not_you') . "\n"
                . t('account.mail.password_changed.reset') . ': ' . $resetLink . "\n\n"
                . mail_text_signature(),
        ];
    });
    if (!$sent) {
        app_log('warning', 'Password change email could not be sent', ['user_id' => $userId]);
    }
}
