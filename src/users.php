<?php

declare(strict_types=1);

/**
 * Customer accounts: registration, profile, language preference, email
 * verification and account deletion.
 */

/** Email-verification links stay valid this long. */
const EMAIL_VERIFICATION_TTL_HOURS = 24;
/** At most one verification or "someone tried to sign up" email per address in this window. */
const ACCOUNT_EMAIL_COOLDOWN_MINUTES = 5;

function user_email_exists(string $email): bool
{
    return db_value('SELECT 1 FROM users WHERE Eaddress = ?', [$email]) !== null
        || db_value('SELECT 1 FROM admins WHERE Eaddress = ?', [$email]) !== null;
}

/**
 * Creates a customer account and returns its ID, or null if the email is
 * already registered (also enforced by the unique key under concurrency).
 * The account starts unverified (email_verified_at NULL) and remembers the
 * visitor's current language (or $locale) for emails.
 */
function register_user(string $email, string $name, string $password, ?string $locale = null): ?int
{
    if (user_email_exists($email)) {
        return null;
    }
    return insert_customer($email, $name, hash_password($password), $locale);
}

/**
 * Inserts a customer row with an already hashed password; null on a duplicate email.
 */
function insert_customer(string $email, string $name, string $passwordHash, ?string $locale = null): ?int
{
    $locale = is_supported_locale($locale) ? $locale : current_locale();
    try {
        db_run('INSERT INTO users (Eaddress, name, pass, locale) VALUES (?, ?, ?, ?)', [$email, $name, $passwordHash, $locale]);
    } catch (PDOException $e) {
        if (is_duplicate_key_error($e)) {
            return null;
        }
        throw $e;
    }
    return (int) db()->lastInsertId();
}

/**
 * @return array<string, mixed>|null
 */
function find_user(int $userId): ?array
{
    return db_one(
        'SELECT id, Eaddress, name, favorite_genre, date_of_birth, payment_method, profile_image FROM users WHERE id = ?',
        [$userId]
    );
}

/**
 * Account fields used by sign-in, emails and account settings.
 *
 * @return array{id: int, Eaddress: string, name: string, locale: string, email_verified_at: string|null, profile_image: string|null}|null
 */
function find_user_account(int $userId): ?array
{
    return account_row(db_one(
        'SELECT id, Eaddress, name, locale, email_verified_at, profile_image FROM users WHERE id = ?',
        [$userId]
    ));
}

/**
 * @return array{id: int, Eaddress: string, name: string, locale: string, email_verified_at: string|null, profile_image: string|null}|null
 */
function find_user_account_by_email(string $email): ?array
{
    return account_row(db_one(
        'SELECT id, Eaddress, name, locale, email_verified_at, profile_image FROM users WHERE Eaddress = ?',
        [$email]
    ));
}

/**
 * @param array<string, mixed>|null $row
 * @return array{id: int, Eaddress: string, name: string, locale: string, email_verified_at: string|null, profile_image: string|null}|null
 */
function account_row(?array $row): ?array
{
    if ($row === null) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'Eaddress' => (string) $row['Eaddress'],
        'name' => (string) $row['name'],
        'locale' => is_supported_locale($row['locale']) ? (string) $row['locale'] : default_locale(),
        'email_verified_at' => $row['email_verified_at'] !== null ? (string) $row['email_verified_at'] : null,
        'profile_image' => $row['profile_image'] !== null ? (string) $row['profile_image'] : null,
    ];
}

/**
 * Updates profile fields. Pass $profileImage = null to keep the current picture.
 */
function update_user_profile(int $userId, string $name, string $favoriteGenre, ?string $dateOfBirth, ?string $profileImage = null): void
{
    if ($profileImage === null) {
        db_run(
            'UPDATE users SET name = ?, favorite_genre = ?, date_of_birth = ? WHERE id = ?',
            [$name, $favoriteGenre, $dateOfBirth, $userId]
        );
        return;
    }
    db_run(
        'UPDATE users SET name = ?, favorite_genre = ?, date_of_birth = ?, profile_image = ? WHERE id = ?',
        [$name, $favoriteGenre, $dateOfBirth, $profileImage, $userId]
    );
}

/**
 * Stores the customer's preferred payment method label (never card data).
 */
function update_payment_preference(int $userId, ?string $preference): bool
{
    if ($preference !== null && !in_array($preference, PAYMENT_PREFERENCES, true)) {
        return false;
    }
    db_run('UPDATE users SET payment_method = ? WHERE id = ?', [$preference, $userId]);
    return true;
}

/* ---------------------------------------------------------------------
 * Language preference
 * ------------------------------------------------------------------- */

/**
 * Saves the account's language (used for its emails). Returns false for an
 * unsupported locale.
 */
function update_user_locale(int $userId, string $locale): bool
{
    if (!is_supported_locale($locale)) {
        return false;
    }
    db_run('UPDATE users SET locale = ? WHERE id = ?', [$locale, $userId]);
    return true;
}

/**
 * Remembers the visitor's interface language in the same cookie that the
 * ?lang= switch (handle_locale_switch) sets.
 */
function remember_locale_cookie(string $locale): void
{
    if (!is_supported_locale($locale)) {
        return;
    }
    if (!headers_sent()) {
        setcookie(LOCALE_COOKIE, $locale, [
            'expires' => time() + 365 * 24 * 60 * 60,
            'path' => '/',
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $_COOKIE[LOCALE_COOKIE] = $locale;
}

/* ---------------------------------------------------------------------
 * Localized validation messages for the account forms
 * (the rules themselves live in validation.php)
 * ------------------------------------------------------------------- */

/** Stored when a customer has not chosen a favorite genre (the column default); shown as an empty field. */
const FAVORITE_GENRE_UNSET = 'Unknown';

function favorite_genre_error(string $genre): ?string
{
    if (validate_favorite_genre($genre) === null) {
        return null;
    }
    if ($genre === '') {
        return t('account.validation.favorite_genre_required');
    }
    if (mb_strlen($genre) > 100) {
        return tn('account.validation.favorite_genre_too_long', 100);
    }
    return t('account.validation.favorite_genre_invalid');
}

function date_of_birth_error(string $date): ?string
{
    if (validate_date_of_birth($date) === null) {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
        return t('account.validation.date_of_birth_invalid');
    }
    return t('account.validation.date_of_birth_range');
}

/**
 * Message for a rejected avatar upload in the visitor's language.
 */
function upload_error_message(UploadException $e): string
{
    $key = match ($e->getMessage()) {
        'Profile pictures must be 2 MB or smaller.' => 'too_large',
        'The picture could not be uploaded. Please try again.' => 'failed',
        'The selected file is empty. Please choose an image.' => 'empty',
        'Please upload a JPEG, PNG, GIF or WebP image.' => 'type',
        'Please upload a valid JPEG, PNG, GIF or WebP image.' => 'invalid_image',
        'Images must be at most 4000×4000 pixels.' => 'dimensions',
        default => null,
    };
    return $key === null ? $e->getMessage() : t('account.upload.' . $key);
}

/**
 * Welcome email after an immediate sign-up (when email verification is off),
 * in the new customer's language. The address is not verified, so the email
 * contains no user-supplied text (a crafted name must not turn it into a
 * phishing message from our sender).
 */
function send_welcome_email(string $email, int $userId): void
{
    $link = absolute_url('Explore_Page.php');
    $sent = send_localized_mail($email, '', null, static function () use ($link): array {
        return [
            t('auth.mail.welcome.subject'),
            mail_html_layout(
                '<p>' . e(t('auth.mail.greeting')) . '</p>'
                . '<p>' . e(t('auth.mail.welcome.body')) . ' '
                . '<a href="' . e($link) . '">' . e(t('auth.mail.welcome.cta')) . '</a>.</p>'
                . '<p>' . e(t('auth.mail.welcome.ignore')) . '</p>'
                . mail_html_signature()
            ),
            t('auth.mail.greeting') . "\n\n"
                . t('auth.mail.welcome.body') . ' ' . t('auth.mail.welcome.cta') . ":\n" . $link . "\n\n"
                . t('auth.mail.welcome.ignore') . "\n\n"
                . mail_text_signature(),
        ];
    });
    if (!$sent) {
        app_log('warning', 'Welcome email could not be sent', ['user_id' => $userId]);
    }
}

/* ---------------------------------------------------------------------
 * Email verification
 * ------------------------------------------------------------------- */

/**
 * True when new customer accounts must confirm their email before signing in.
 */
function email_verification_required(): bool
{
    return (bool) config('auth.require_email_verification', true);
}

/**
 * True when this customer may not sign in yet because their address is unconfirmed.
 */
function customer_awaits_verification(int $userId): bool
{
    if (!email_verification_required()) {
        return false;
    }
    return db_value('SELECT 1 FROM users WHERE id = ? AND email_verified_at IS NULL', [$userId]) !== null;
}

function email_verification_token_hash(string $rawToken): string
{
    return hash('sha256', $rawToken);
}

/**
 * True when a verification or sign-up notice email went to this account
 * within the cooldown (every such email leaves a row in email_verifications).
 */
function account_email_recently_sent(int $userId): bool
{
    return db_value(
        'SELECT 1 FROM email_verifications WHERE user_id = ? AND created_at > (NOW() - INTERVAL ? MINUTE) LIMIT 1',
        [$userId, ACCOUNT_EMAIL_COOLDOWN_MINUTES]
    ) !== null;
}

/**
 * Creates a new 256-bit verification token for the account, replacing older
 * ones, and returns the raw token (only its SHA-256 hash is stored).
 */
function issue_email_verification_token(int $userId): string
{
    $rawToken = bin2hex(random_bytes(32));
    db_transaction(static function () use ($userId, $rawToken): void {
        db_run('DELETE FROM email_verifications WHERE user_id = ?', [$userId]);
        db_run(
            'INSERT INTO email_verifications (user_id, token, expiry) VALUES (?, ?, NOW() + INTERVAL ? HOUR)',
            [$userId, email_verification_token_hash($rawToken), EMAIL_VERIFICATION_TTL_HOURS]
        );
    });
    return $rawToken;
}

/**
 * Emails a fresh verification link to an unconfirmed account, in the
 * account's language. Returns false when nothing was sent (throttled,
 * already verified, or the mail transport failed).
 *
 * @param array{id: int, Eaddress: string, name: string, locale: string, email_verified_at: string|null} $account
 */
function send_email_verification(array $account): bool
{
    if ($account['email_verified_at'] !== null || account_email_recently_sent($account['id'])) {
        return false;
    }
    $rawToken = issue_email_verification_token($account['id']);
    $link = absolute_url('verify_email.php?token=' . $rawToken);

    // The address is not confirmed yet, so the email carries no user-supplied
    // text (a crafted name must not turn it into a message from our sender).
    $sent = send_localized_mail($account['Eaddress'], '', $account['locale'], static function () use ($link): array {
        $expiry = tn('auth.mail.verify.expiry', EMAIL_VERIFICATION_TTL_HOURS);
        return [
            t('auth.mail.verify.subject'),
            mail_html_layout(
                '<p>' . e(t('auth.mail.greeting')) . '</p>'
                . '<p>' . e(t('auth.mail.verify.body')) . '</p>'
                . '<p><a href="' . e($link) . '">' . e(t('auth.mail.verify.cta')) . '</a></p>'
                . '<p>' . e($expiry) . '</p>'
                . '<p>' . e(t('auth.mail.verify.ignore')) . '</p>'
                . mail_html_signature()
            ),
            t('auth.mail.greeting') . "\n\n"
                . t('auth.mail.verify.body') . "\n\n"
                . t('auth.mail.verify.cta') . ":\n" . $link . "\n\n"
                . $expiry . "\n\n"
                . t('auth.mail.verify.ignore') . "\n\n"
                . mail_text_signature(),
        ];
    });
    if (!$sent) {
        app_log('warning', 'Verification email could not be sent', ['user_id' => $account['id']]);
    }
    return $sent;
}

/**
 * Tells the owner of an existing customer account that someone tried to sign
 * up with their address (throttled; the visible sign-up response is the same).
 *
 * @param array{id: int, Eaddress: string, name: string, locale: string, email_verified_at: string|null} $account
 */
function send_signup_attempt_notice(array $account): bool
{
    if (account_email_recently_sent($account['id'])) {
        return false;
    }
    // Remember the send for throttling: a row that expired at creation can never verify anything.
    db_run('DELETE FROM email_verifications WHERE user_id = ? AND expiry <= NOW() AND created_at < (NOW() - INTERVAL 1 DAY)', [$account['id']]);
    db_run(
        'INSERT INTO email_verifications (user_id, token, expiry) VALUES (?, ?, NOW())',
        [$account['id'], email_verification_token_hash(bin2hex(random_bytes(32)))]
    );

    $loginLink = absolute_url('index.php?form=login');
    $resetLink = absolute_url('index.php?form=reset');
    // The account may be unconfirmed (possibly created by someone else), so no stored name is included.
    $sent = send_localized_mail($account['Eaddress'], '', $account['locale'], static function () use ($loginLink, $resetLink): array {
        return [
            t('auth.mail.signup_notice.subject'),
            mail_html_layout(
                '<p>' . e(t('auth.mail.greeting')) . '</p>'
                . '<p>' . e(t('auth.mail.signup_notice.body')) . '</p>'
                . '<p>' . e(t('auth.mail.signup_notice.if_you')) . '</p>'
                . '<p><a href="' . e($loginLink) . '">' . e(t('auth.mail.signup_notice.login')) . '</a> · '
                . '<a href="' . e($resetLink) . '">' . e(t('auth.mail.signup_notice.reset')) . '</a></p>'
                . '<p>' . e(t('auth.mail.signup_notice.ignore')) . '</p>'
                . mail_html_signature()
            ),
            t('auth.mail.greeting') . "\n\n"
                . t('auth.mail.signup_notice.body') . "\n\n"
                . t('auth.mail.signup_notice.if_you') . "\n"
                . t('auth.mail.signup_notice.login') . ': ' . $loginLink . "\n"
                . t('auth.mail.signup_notice.reset') . ': ' . $resetLink . "\n\n"
                . t('auth.mail.signup_notice.ignore') . "\n\n"
                . mail_text_signature(),
        ];
    });
    if (!$sent) {
        app_log('warning', 'Sign-up notice email could not be sent', ['user_id' => $account['id']]);
    }
    return $sent;
}

/**
 * Sign-up when email verification is required. Whatever the outcome, the
 * caller shows the same "check your inbox" response, so nobody can learn
 * whether an address is registered:
 *  - new address: an unverified account is created in the visitor's language
 *    and a verification link is emailed (the visitor is not signed in);
 *  - existing customer: the owner gets a short notice instead;
 *  - administrator address: nothing is sent.
 *
 * @return 'created'|'existing' For logging and tests only; never shown.
 */
function sign_up_with_verification(string $email, string $name, string $password): string
{
    // Hash in every branch so all of them take about the same time.
    $passwordHash = hash_password($password);

    if (db_value('SELECT 1 FROM admins WHERE Eaddress = ?', [$email]) !== null) {
        return 'existing';
    }
    $existing = find_user_account_by_email($email);
    if ($existing !== null) {
        send_signup_attempt_notice($existing);
        return 'existing';
    }

    $userId = insert_customer($email, $name, $passwordHash);
    $account = $userId !== null ? find_user_account($userId) : null;
    if ($account === null) {
        return 'existing'; // registered concurrently by another request
    }
    send_email_verification($account);
    return 'created';
}

/**
 * Sends a new verification link if $email belongs to an unconfirmed customer
 * (throttled). Silent for unknown, confirmed and administrator addresses.
 */
function resend_email_verification(string $email): void
{
    $account = find_user_account_by_email($email);
    if ($account === null || $account['email_verified_at'] !== null) {
        return;
    }
    send_email_verification($account);
}

/**
 * Account ID for a valid, unexpired verification token, or null.
 */
function find_valid_email_verification(string $rawToken): ?int
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $rawToken)) {
        return null;
    }
    $userId = db_value(
        'SELECT user_id FROM email_verifications WHERE token = ? AND expiry > NOW()',
        [email_verification_token_hash($rawToken)]
    );
    return $userId !== null ? (int) $userId : null;
}

/**
 * Confirms the address for a valid token: marks the account verified and
 * deletes all of its tokens (single use). Returns the account, or null for
 * an invalid, expired or used token.
 *
 * @return array{id: int, Eaddress: string, name: string, locale: string, email_verified_at: string|null, profile_image: string|null}|null
 */
function complete_email_verification(string $rawToken): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $rawToken)) {
        return null;
    }
    $userId = db_transaction(static function () use ($rawToken): ?int {
        $row = db_one(
            'SELECT user_id FROM email_verifications WHERE token = ? AND expiry > NOW() FOR UPDATE',
            [email_verification_token_hash($rawToken)]
        );
        if ($row === null) {
            return null;
        }
        $userId = (int) $row['user_id'];
        db_run('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [$userId]);
        db_run('DELETE FROM email_verifications WHERE user_id = ?', [$userId]);
        return $userId;
    });
    return $userId !== null ? find_user_account($userId) : null;
}

/* ---------------------------------------------------------------------
 * Account deletion
 * ------------------------------------------------------------------- */

/**
 * Permanently deletes a customer account after checking its password.
 *
 * Open checkouts are resolved first (settle_pending_orders) while holding the
 * customer's checkout lock, so no payment can complete for an account that
 * no longer exists. Then, in one transaction: the customer's reviews and
 * reactions, reset tokens, login attempts, demo inbox messages and the users
 * row are deleted (cart, favorites, sales and verification tokens cascade;
 * orders are kept with user_id set to NULL by the schema).
 *
 * @return array{status: 'deleted'|'wrong_password'|'throttled'|'unresolved', account: array<string, mixed>|null}
 */
function delete_customer_account(int $userId, string $password, string $ip): array
{
    $user = db_one('SELECT id, Eaddress, name, pass, locale, profile_image FROM users WHERE id = ?', [$userId]);
    if ($user === null) {
        return ['status' => 'wrong_password', 'account' => null];
    }
    $email = (string) $user['Eaddress'];
    if (login_is_throttled($email, $ip)) {
        return ['status' => 'throttled', 'account' => null];
    }
    if (!check_password($password, (string) $user['pass'])['valid']) {
        record_failed_login($email, $ip);
        return ['status' => 'wrong_password', 'account' => null];
    }

    // The same lock start_checkout() takes: no new checkout can begin meanwhile.
    $lock = 'bookwise-checkout-' . $userId;
    if ((int) db_value('SELECT GET_LOCK(?, 15)', [$lock]) !== 1) {
        return ['status' => 'unresolved', 'account' => null];
    }
    try {
        try {
            $settled = settle_pending_orders($userId);
        } catch (Throwable $e) {
            app_log('warning', 'Could not settle pending orders before account deletion', ['user_id' => $userId, 'message' => $e->getMessage()]);
            return ['status' => 'unresolved', 'account' => null];
        }
        if ($settled['unresolved']) {
            return ['status' => 'unresolved', 'account' => null];
        }

        $status = db_transaction(static function () use ($userId, $email, $user): string {
            $locked = db_one('SELECT pass FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ($locked === null || !hash_equals((string) $user['pass'], (string) $locked['pass'])) {
                return 'wrong_password'; // deleted, or the password changed meanwhile
            }
            $pending = (int) db_value("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'pending' FOR UPDATE", [$userId]);
            if ($pending > 0) {
                return 'unresolved';
            }
            db_run('DELETE FROM comment_interactions WHERE user_id = ?', [$userId]);
            // Reactions to the customer's own reviews go with them (ON DELETE CASCADE).
            db_run('DELETE FROM comments WHERE username = ?', [$email]);
            db_run('DELETE FROM reset_tokens WHERE email = ?', [$email]);
            db_run('DELETE FROM login_attempts WHERE email = ?', [$email]);
            // Demo inbox (MAIL_DRIVER=database): its emails may greet the customer by name.
            db_run('DELETE FROM demo_mail WHERE recipient = ?', [normalize_email($email)]);
            db_run('DELETE FROM users WHERE id = ?', [$userId]);
            return 'deleted';
        });
    } finally {
        db_value('SELECT RELEASE_LOCK(?)', [$lock]);
    }

    if ($status !== 'deleted') {
        return ['status' => $status, 'account' => null];
    }
    unset($user['pass']);
    return ['status' => 'deleted', 'account' => $user];
}

/**
 * Confirms to the former owner that their account was deleted.
 *
 * @param array<string, mixed> $account Row returned by delete_customer_account()
 */
function send_account_deleted_email(array $account): void
{
    $sent = send_localized_mail((string) $account['Eaddress'], '', is_string($account['locale'] ?? null) ? $account['locale'] : null, static function (): array {
        return [
            t('account.mail.deleted.subject'),
            mail_html_layout(
                '<p>' . e(t('auth.mail.greeting')) . '</p>'
                . '<p>' . e(t('account.mail.deleted.body')) . '</p>'
                . '<p>' . e(t('account.mail.deleted.orders')) . '</p>'
                . '<p>' . e(t('account.mail.deleted.not_you')) . '</p>'
                . mail_html_signature()
            ),
            t('auth.mail.greeting') . "\n\n"
                . t('account.mail.deleted.body') . "\n\n"
                . t('account.mail.deleted.orders') . "\n\n"
                . t('account.mail.deleted.not_you') . "\n\n"
                . mail_text_signature(),
        ];
    });
    if (!$sent) {
        app_log('warning', 'Account deletion email could not be sent');
    }
}
