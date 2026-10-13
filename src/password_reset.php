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
    $user = db_one('SELECT id, name, Eaddress FROM users WHERE Eaddress = ?', [$email]);
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
    $name = (string) $user['name'];
    $html = '<p>Hello ' . e($name) . ',</p>'
        . '<p>We received a request to reset your BookWise password. '
        . '<a href="' . e($link) . '">Choose a new password</a>. This link expires in '
        . RESET_TOKEN_TTL_MINUTES . ' minutes and can be used once.</p>'
        . '<p>If you did not request this, you can ignore this email.</p>';
    $text = "Hello $name,\n\nReset your BookWise password using this link (valid for "
        . RESET_TOKEN_TTL_MINUTES . " minutes, single use):\n$link\n\nIf you did not request this, ignore this email.";

    send_mail((string) $user['Eaddress'], $name, 'Reset your BookWise password', $html, $text);
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
 * account. Returns false if the token is invalid, expired or already used.
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
            'UPDATE users SET pass = ?, session_version = session_version + 1 WHERE Eaddress = ?',
            [hash_password($newPassword), $row['email']]
        );
        db_run('DELETE FROM reset_tokens WHERE email = ?', [$row['email']]);
        db_run('DELETE FROM login_attempts WHERE email = ?', [$row['email']]);
        return $updated->rowCount() > 0;
    });
}
