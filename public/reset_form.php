<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// The token is in the URL: keep it out of Referer headers and caches.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if (!in_array(request_method(), ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    abort(405, 'This page can only be opened from the link in your email.');
}

$token = input_string($_GET, 'token', 128);
$email = $token !== '' ? find_valid_reset_email($token) : null;

if ($email === null) {
    http_response_code(404);
    page_header('Reset link expired', ['sign.css'], ['bodyClass' => 'auth-page']);
    ?>
        <div class="auth-shell">
            <section class="auth-card" aria-labelledby="reset-invalid-title">
                <h1 class="auth-title" id="reset-invalid-title">This reset link is invalid or has expired</h1>
                <p class="auth-intro">Reset links work once and expire after <?= RESET_TOKEN_TTL_MINUTES ?> minutes. Request a new link to choose a new password.</p>
                <div class="auth-actions">
                    <a class="auth-submit" href="index.php?form=reset">Request a new link</a>
                </div>
                <p class="auth-alt"><a class="auth-link" href="index.php?form=login">Back to login</a></p>
            </section>
        </div>
    <?php
    page_footer();
    exit;
}

$errors = [
    'password' => form_error('password'),
    'password_confirmation' => form_error('password_confirmation'),
];
$describe = static function (string $id, ?string $error, bool $hasHint = false): string {
    $ids = [];
    if ($hasHint) {
        $ids[] = $id . '-hint';
    }
    if ($error !== null) {
        $ids[] = $id . '-error';
    }
    $attributes = $ids === [] ? '' : ' aria-describedby="' . e(implode(' ', $ids)) . '"';
    return ($error !== null ? ' aria-invalid="true"' : '') . $attributes;
};
$errorText = static function (string $id, ?string $error): string {
    return $error === null ? '' : '<p class="field-error" id="' . e($id) . '-error">' . e($error) . '</p>';
};

page_header('Choose a new password', ['sign.css'], ['bodyClass' => 'auth-page']);
?>
        <div class="auth-shell">
            <section class="auth-card" aria-labelledby="new-password-title">
                <h1 class="auth-title" id="new-password-title">Choose a new password</h1>
                <p class="auth-intro">Set a new password for <strong><?= e($email) ?></strong>.</p>

                <form class="auth-form" method="post" action="update_password.php" data-once>
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <?php /* Unnamed, so never submitted: lets password managers pair the new password with the account. */ ?>
                    <input type="email" autocomplete="username" value="<?= e($email) ?>" readonly hidden>

                    <div class="form-field">
                        <label for="new-password">New Password</label>
                        <div class="password-wrapper">
                            <input class="auth-input" type="password" id="new-password" name="password"
                                autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_BYTES ?>"
                                data-max-bytes="<?= PASSWORD_MAX_BYTES ?>" required<?= $describe('new-password', $errors['password'], true) ?>>
                            <button type="button" class="password-toggle" aria-controls="new-password" aria-pressed="false" aria-label="Show password" hidden>
                                <img src="imges/eye.png" alt="" width="26" height="18">
                            </button>
                        </div>
                        <p class="form-hint" id="new-password-hint">At least <?= PASSWORD_MIN_LENGTH ?> characters.</p>
                        <?= $errorText('new-password', $errors['password']) ?>
                    </div>

                    <div class="form-field">
                        <label for="new-password-confirmation">Confirm New Password</label>
                        <div class="password-wrapper">
                            <input class="auth-input" type="password" id="new-password-confirmation" name="password_confirmation"
                                autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_BYTES ?>"
                                data-match="new-password" required<?= $describe('new-password-confirmation', $errors['password_confirmation']) ?>>
                            <button type="button" class="password-toggle" aria-controls="new-password-confirmation" aria-pressed="false" aria-label="Show password" hidden>
                                <img src="imges/eye.png" alt="" width="26" height="18">
                            </button>
                        </div>
                        <?= $errorText('new-password-confirmation', $errors['password_confirmation']) ?>
                    </div>

                    <button type="submit" class="auth-submit">Update Password</button>
                </form>
                <p class="auth-alt"><a class="auth-link" href="index.php?form=login">Back to login</a></p>
            </section>
        </div>
<?php
page_footer(['index.js']);
