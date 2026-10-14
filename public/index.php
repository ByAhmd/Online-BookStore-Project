<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (current_user() !== null) {
    redirect('Home_Page.php');
}
if (current_admin() !== null) {
    redirect('admin_dashboard.php');
}

$verificationRequired = email_verification_required();
$isDemo = (bool) config('app.demo');
$appName = (string) config('app.name', 'BookWise');

$panels = [
    'signup' => t('auth.panels.signup'),
    'login' => t('auth.panels.login'),
    'reset' => t('auth.panels.reset'),
];
if ($verificationRequired) {
    // "Confirm your email" (with the resend form) only exists when confirmation is required.
    $panels['verify'] = t('auth.panels.verify');
}
$requested = $_GET['form'] ?? 'login';
$active = is_string($requested) && isset($panels[$requested]) ? $requested : 'login';

$errors = [];
foreach (['signup_email', 'signup_name', 'signup_password', 'signup_password_confirmation', 'login_email', 'login_password', 'reset_email', 'verify_email'] as $key) {
    $errors[$key] = form_error($key);
}

// aria-invalid / aria-describedby linking an input to its hint and error.
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
$hiddenUnless = static fn (string $panel): string => $panel === $active ? '' : ' hidden';
$currentIf = static fn (string $panel): string => $panel === $active ? ' aria-current="page"' : '';
$panelTitle = static fn (string $panel): string => $panels[$panel] . ' · ' . $appName;

$toggle = static function (string $inputId): string {
    return '<button type="button" class="password-toggle" aria-controls="' . e($inputId) . '" aria-pressed="false" aria-label="' . e(t('auth.show_password')) . '" hidden>'
        . '<img src="imges/eye.png" alt="" width="26" height="18">'
        . '</button>';
};

page_header(t('auth.panels.' . $active), ['sign.css'], ['bodyClass' => 'auth-page']);
?>
        <div class="auth-shell">
            <div class="auth-card" data-i18n-mismatch="<?= e(t('auth.js.password_mismatch')) ?>" data-i18n-too-long="<?= e(t('auth.js.password_too_long')) ?>">
                <h1 class="sr-only"><?= e(t('auth.page_heading')) ?></h1>

                <div class="auth-switch" role="group" aria-label="<?= e(t('auth.choose_form')) ?>">
                    <a class="pill pill-dark" href="index.php?form=signup" data-auth-target="signup"<?= $currentIf('signup') ?>><?= e(t('auth.panels.signup')) ?></a>
                    <a class="pill pill-red" href="index.php?form=login" data-auth-target="login"<?= $currentIf('login') ?>><?= e(t('auth.panels.login')) ?></a>
                </div>

                <section class="auth-panel" id="panel-signup" data-panel="signup" data-title="<?= e($panelTitle('signup')) ?>" aria-labelledby="signup-title"<?= $hiddenUnless('signup') ?>>
                    <h2 class="auth-title" id="signup-title" tabindex="-1"><?= e(t('auth.panels.signup')) ?></h2>
                    <form class="auth-form" method="post" action="Register.php" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="register">

                        <div class="form-field">
                            <label for="signup-email"><?= e(t('auth.fields.email')) ?></label>
                            <input class="auth-input" type="email" id="signup-email" name="email" value="<?= e(old('signup_email')) ?>"
                                autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="255" required<?= $describe('signup-email', $errors['signup_email']) ?>>
                            <?= $errorText('signup-email', $errors['signup_email']) ?>
                        </div>

                        <div class="form-field">
                            <label for="signup-name"><?= e(t('auth.fields.name')) ?></label>
                            <input class="auth-input" type="text" id="signup-name" name="name" value="<?= e(old('signup_name')) ?>" dir="auto"
                                autocomplete="name" maxlength="<?= NAME_MAX_LENGTH ?>" required<?= $describe('signup-name', $errors['signup_name']) ?>>
                            <?= $errorText('signup-name', $errors['signup_name']) ?>
                        </div>

                        <div class="form-field">
                            <label for="signup-password"><?= e(t('auth.fields.password')) ?></label>
                            <div class="password-wrapper">
                                <input class="auth-input" type="password" id="signup-password" name="password"
                                    autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_BYTES ?>"
                                    data-max-bytes="<?= PASSWORD_MAX_BYTES ?>" required<?= $describe('signup-password', $errors['signup_password'], true) ?>>
                                <?= $toggle('signup-password') ?>
                            </div>
                            <p class="form-hint" id="signup-password-hint"><?= e(tn('auth.password_hint', PASSWORD_MIN_LENGTH)) ?></p>
                            <?= $errorText('signup-password', $errors['signup_password']) ?>
                        </div>

                        <div class="form-field">
                            <label for="signup-password-confirmation"><?= e(t('auth.fields.password_confirmation')) ?></label>
                            <div class="password-wrapper">
                                <input class="auth-input" type="password" id="signup-password-confirmation" name="password_confirmation"
                                    autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_BYTES ?>"
                                    data-match="signup-password" required<?= $describe('signup-password-confirmation', $errors['signup_password_confirmation']) ?>>
                                <?= $toggle('signup-password-confirmation') ?>
                            </div>
                            <?= $errorText('signup-password-confirmation', $errors['signup_password_confirmation']) ?>
                        </div>

<?php if ($verificationRequired): ?>
                        <p class="auth-note"><?= e(t('auth.signup.verification_hint')) ?></p>
<?php endif; ?>
                        <button type="submit" class="auth-submit"><?= e(t('auth.signup.submit')) ?></button>
                    </form>
                    <p class="auth-alt"><?= e(t('auth.signup.have_account')) ?> <a class="auth-link" href="index.php?form=login" data-auth-target="login"><?= e(t('auth.signup.login_link')) ?></a></p>
                </section>

                <section class="auth-panel" id="panel-login" data-panel="login" data-title="<?= e($panelTitle('login')) ?>" aria-labelledby="login-title"<?= $hiddenUnless('login') ?>>
                    <h2 class="auth-title" id="login-title" tabindex="-1"><?= e(t('auth.panels.login')) ?></h2>
                    <form class="auth-form" method="post" action="Register.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="login">

                        <div class="form-field">
                            <label for="login-email"><?= e(t('auth.fields.email')) ?></label>
                            <input class="auth-input" type="email" id="login-email" name="email" value="<?= e(old('login_email')) ?>"
                                autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="255" required<?= $describe('login-email', $errors['login_email']) ?>>
                            <?= $errorText('login-email', $errors['login_email']) ?>
                        </div>

                        <div class="form-field">
                            <label for="login-password"><?= e(t('auth.fields.password')) ?></label>
                            <div class="password-wrapper">
                                <input class="auth-input" type="password" id="login-password" name="password"
                                    autocomplete="current-password" maxlength="255" required<?= $describe('login-password', $errors['login_password']) ?>>
                                <?= $toggle('login-password') ?>
                            </div>
                            <?= $errorText('login-password', $errors['login_password']) ?>
                        </div>

                        <p class="auth-forgot"><a class="auth-link" href="index.php?form=reset" data-auth-target="reset"><?= e(t('auth.login.forgot')) ?></a></p>

                        <button type="submit" class="auth-submit"><?= e(t('auth.login.submit')) ?></button>
                    </form>
                    <p class="auth-alt"><?= e(t('auth.login.new_here')) ?> <a class="auth-link" href="index.php?form=signup" data-auth-target="signup"><?= e(t('auth.login.create_account')) ?></a></p>
                </section>

                <section class="auth-panel" id="panel-reset" data-panel="reset" data-title="<?= e($panelTitle('reset')) ?>" aria-labelledby="reset-title"<?= $hiddenUnless('reset') ?>>
                    <h2 class="auth-title" id="reset-title" tabindex="-1"><?= e(t('auth.panels.reset')) ?></h2>
                    <p class="auth-intro"><?= e(t('auth.reset.intro')) ?></p>
<?php if ($isDemo): ?>
                    <p class="auth-notice" role="note"><?= demo_mail_notice_html() ?></p>
<?php endif; ?>
                    <form class="auth-form" method="post" action="reset_password.php" data-once>
                        <?= csrf_field() ?>

                        <div class="form-field">
                            <label for="reset-email"><?= e(t('auth.fields.email')) ?></label>
                            <input class="auth-input" type="email" id="reset-email" name="email" value="<?= e(old('reset_email')) ?>"
                                autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="255" required<?= $describe('reset-email', $errors['reset_email'], true) ?>>
                            <p class="form-hint" id="reset-email-hint"><?= e(tn('auth.reset.hint', RESET_TOKEN_TTL_MINUTES)) ?></p>
                            <?= $errorText('reset-email', $errors['reset_email']) ?>
                        </div>

                        <button type="submit" class="auth-submit"><?= e(t('auth.reset.submit')) ?></button>
                    </form>
                    <p class="auth-alt"><?= e(t('auth.reset.remembered')) ?> <a class="auth-link" href="index.php?form=login" data-auth-target="login"><?= e(t('auth.reset.back_to_login')) ?></a></p>
                </section>
<?php if ($verificationRequired): ?>

                <section class="auth-panel" id="panel-verify" data-panel="verify" data-title="<?= e($panelTitle('verify')) ?>" aria-labelledby="verify-title"<?= $hiddenUnless('verify') ?>>
                    <h2 class="auth-title" id="verify-title" tabindex="-1"><?= e(t('auth.verify.heading')) ?></h2>
                    <p class="auth-intro"><?= e(tn('auth.verify.intro', EMAIL_VERIFICATION_TTL_HOURS)) ?></p>
<?php if ($isDemo): ?>
                    <p class="auth-notice" role="note"><?= demo_mail_notice_html() ?></p>
<?php endif; ?>
                    <p class="auth-intro" id="verify-email-hint"><?= e(t('auth.verify.resend_intro')) ?></p>
                    <form class="auth-form" method="post" action="resend_verification.php" data-once>
                        <?= csrf_field() ?>

                        <div class="form-field">
                            <label for="verify-email"><?= e(t('auth.fields.email')) ?></label>
                            <input class="auth-input" type="email" id="verify-email" name="email" value="<?= e(old('verify_email')) ?>"
                                autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="255" required<?= $describe('verify-email', $errors['verify_email'], true) ?>>
                            <?= $errorText('verify-email', $errors['verify_email']) ?>
                        </div>

                        <button type="submit" class="auth-submit"><?= e(t('auth.verify.resend_submit')) ?></button>
                    </form>
                    <p class="auth-alt"><?= e(t('auth.verify.already_confirmed')) ?> <a class="auth-link" href="index.php?form=login" data-auth-target="login"><?= e(t('auth.signup.login_link')) ?></a></p>
                </section>
<?php endif; ?>
            </div>
        </div>
<?php
page_footer(['index.js']);
