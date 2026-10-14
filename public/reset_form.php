<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// The token is in the URL: keep it out of Referer headers and caches.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if (!in_array(request_method(), ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    abort(405, t('auth.reset.link_only'));
}

$token = input_string($_GET, 'token', 128);
$email = $token !== '' ? find_valid_reset_email($token) : null;

if ($email === null) {
    http_response_code(404);
    page_header(t('auth.reset.invalid_title'), ['sign.css'], ['bodyClass' => 'auth-page']);
    ?>
        <div class="auth-shell">
            <section class="auth-card" aria-labelledby="reset-invalid-title">
                <h1 class="auth-title" id="reset-invalid-title"><?= e(t('auth.reset.invalid_heading')) ?></h1>
                <p class="auth-intro"><?= e(tn('auth.reset.invalid_intro', RESET_TOKEN_TTL_MINUTES)) ?></p>
                <div class="auth-actions">
                    <a class="auth-submit" href="index.php?form=reset"><?= e(t('auth.reset.request_new')) ?></a>
                </div>
                <p class="auth-alt"><a class="auth-link" href="index.php?form=login"><?= e(t('auth.reset.back_to_login')) ?></a></p>
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
$toggle = static function (string $inputId): string {
    return '<button type="button" class="password-toggle" aria-controls="' . e($inputId) . '" aria-pressed="false" aria-label="' . e(t('auth.show_password')) . '" hidden>'
        . '<img src="imges/eye.png" alt="" width="26" height="18">'
        . '</button>';
};

page_header(t('auth.reset.form_title'), ['sign.css'], ['bodyClass' => 'auth-page']);
?>
        <div class="auth-shell">
            <section class="auth-card" aria-labelledby="new-password-title" data-i18n-mismatch="<?= e(t('auth.js.password_mismatch')) ?>" data-i18n-too-long="<?= e(t('auth.js.password_too_long')) ?>">
                <h1 class="auth-title" id="new-password-title"><?= e(t('auth.reset.form_title')) ?></h1>
                <p class="auth-intro"><?= t_html('auth.reset.form_intro', ['email' => '<strong dir="ltr">' . e($email) . '</strong>']) ?></p>

                <form class="auth-form" method="post" action="update_password.php" data-once>
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <?php /* Unnamed, so never submitted: lets password managers pair the new password with the account. */ ?>
                    <input type="email" autocomplete="username" value="<?= e($email) ?>" readonly hidden>

                    <div class="form-field">
                        <label for="new-password"><?= e(t('auth.fields.new_password')) ?></label>
                        <div class="password-wrapper">
                            <input class="auth-input" type="password" id="new-password" name="password"
                                autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_BYTES ?>"
                                data-max-bytes="<?= PASSWORD_MAX_BYTES ?>" required<?= $describe('new-password', $errors['password'], true) ?>>
                            <?= $toggle('new-password') ?>
                        </div>
                        <p class="form-hint" id="new-password-hint"><?= e(tn('auth.password_hint', PASSWORD_MIN_LENGTH)) ?></p>
                        <?= $errorText('new-password', $errors['password']) ?>
                    </div>

                    <div class="form-field">
                        <label for="new-password-confirmation"><?= e(t('auth.fields.new_password_confirmation')) ?></label>
                        <div class="password-wrapper">
                            <input class="auth-input" type="password" id="new-password-confirmation" name="password_confirmation"
                                autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_BYTES ?>"
                                data-match="new-password" required<?= $describe('new-password-confirmation', $errors['password_confirmation']) ?>>
                            <?= $toggle('new-password-confirmation') ?>
                        </div>
                        <?= $errorText('new-password-confirmation', $errors['password_confirmation']) ?>
                    </div>

                    <button type="submit" class="auth-submit"><?= e(t('auth.reset.update_submit')) ?></button>
                </form>
                <p class="auth-alt"><a class="auth-link" href="index.php?form=login"><?= e(t('auth.reset.back_to_login')) ?></a></p>
            </section>
        </div>
<?php
page_footer(['index.js']);
