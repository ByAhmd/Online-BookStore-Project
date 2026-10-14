<?php

declare(strict_types=1);

const RESET_TOKEN_TTL_MINUTES = 60;
const RESET_REQUEST_COOLDOWN_MINUTES = 5;

function reset_token_hash(string $rawToken): string
{
    return hash('sha256', $rawToken);
}

/**
 * Creates a reset token for a customer account and emails the link.
 * Always behaves the same for unknown emails so accounts cannot be enumerated.
 * Only the token's SHA-256 hash is stored; the raw token exists only in the email.
 */
function request_password_reset(string $email): void
{
    $user = find_user_account_by_email($email);
    if ($user === null) {
        return;
    }

    // Throttle: at most one email per account every few minutes.
    $recent = db_value(
        'SELECT 1 FROM reset_tokens WHERE email = ? AND expiry > (NOW() + INTERVAL ? MINUTE) LIMIT 1',
        [$user['Eaddress'], RESET_TOKEN_TTL_MINUTES - RESET_REQUEST_COOLDOWN_MINUTES]
    );
    if ($recent !== null) {
        return;
    }

    $rawToken = bin2hex(random_bytes(32));
    db_transaction(static function () use ($user, $rawToken): void {
        // A new request invalidates any older link.
        db_run('DELETE FROM reset_tokens WHERE email = ?', [$user['Eaddress']]);
        db_run(
            'INSERT INTO reset_tokens (email, token, expiry) VALUES (?, ?, NOW() + INTERVAL ? MINUTE)',
            [$user['Eaddress'], reset_token_hash($rawToken), RESET_TOKEN_TTL_MINUTES]
        );
    });

    $link = absolute_url('reset_form.php?token=' . $rawToken);
    // An unconfirmed account's name may have been typed by someone else: leave it out.
    $name = email_verification_required() && $user['email_verified_at'] === null ? '' : $user['name'];

    send_localized_mail($user['Eaddress'], $name, $user['locale'], static function () use ($name, $link): array {
        $greeting = $name !== '' ? t('auth.mail.greeting_name', ['name' => $name]) : t('auth.mail.greeting');
        $expiry = tn('auth.mail.reset.expiry', RESET_TOKEN_TTL_MINUTES);
        return [
            t('auth.mail.reset.subject'),
            mail_html_layout(
                '<p>' . e($greeting) . '</p>'
                . '<p>' . e(t('auth.mail.reset.body')) . '</p>'
                . '<p><a href="' . e($link) . '">' . e(t('auth.mail.reset.cta')) . '</a></p>'
                . '<p>' . e($expiry) . '</p>'
                . '<p>' . e(t('auth.mail.reset.ignore')) . '</p>'
                . mail_html_signature()
            ),
            $greeting . "\n\n"
                . t('auth.mail.reset.body') . "\n\n"
                . t('auth.mail.reset.cta') . ":\n" . $link . "\n\n"
                . $expiry . "\n\n"
                . t('auth.mail.reset.ignore') . "\n\n"
                . mail_text_signature(),
        ];
    });
}

/**
 * Returns the account email for a valid, unexpired token, or null.
 */
function find_valid_reset_email(string $rawToken): ?string
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $rawToken)) {
        return null;
    }
    $email = db_value(
        'SELECT email FROM reset_tokens WHERE token = ? AND expiry > NOW()',
        [reset_token_hash($rawToken)]
    );
    return is_string($email) ? $email : null;
}

/**
 * Sets a new password using a token, then invalidates every token for the
 * account. Using a link from the account's mailbox proves ownership of the
 * address, so the account also counts as verified from then on. Returns
 * false if the token is invalid, expired or already used.
 */
function complete_password_reset(string $rawToken, string $newPassword): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $rawToken)) {
        return false;
    }

    return db_transaction(static function () use ($rawToken, $newPassword): bool {
        $row = db_one(
            'SELECT email FROM reset_tokens WHERE token = ? AND expiry > NOW() FOR UPDATE',
            [reset_token_hash($rawToken)]
        );
        if ($row === null) {
            return false;
        }
        // Bumping session_version signs out every existing session of the account.
        $updated = db_run(
            'UPDATE users SET pass = ?, session_version = session_version + 1, email_verified_at = COALESCE(email_verified_at, NOW())
             WHERE Eaddress = ?',
            [hash_password($newPassword), $row['email']]
        );
        db_run('DELETE FROM reset_tokens WHERE email = ?', [$row['email']]);
        db_run('DELETE FROM login_attempts WHERE email = ?', [$row['email']]);
        db_run(
            'DELETE ev FROM email_verifications ev JOIN users u ON u.id = ev.user_id WHERE u.Eaddress = ?',
            [$row['email']]
        );
        return $updated->rowCount() > 0;
    });
}
