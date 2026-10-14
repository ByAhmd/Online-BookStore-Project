<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// The token is in the URL: keep it out of Referer headers and caches.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$method = request_method();
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    abort(405, t('auth.verify.link_only'));
}
if ($method === 'HEAD') {
    // Link checkers in mail systems send HEAD requests: never use up the token for them.
    exit;
}

/*
 * Opening the link only shows a form. The address is confirmed (and the
 * customer signed in) when the account's password is entered, so:
 *  - mail scanners that fetch links cannot use up the token or get a session;
 *  - someone who registered another person's address cannot keep access once
 *    the owner confirms it: the owner, who does not know that password, uses
 *    "Forgot your password?" instead, which also confirms the address and
 *    signs out every other session;
 *  - a link sent to someone else cannot sign them in to the sender's account.
 */
if ($method === 'POST') {
    verify_csrf();
    $token = input_string($_POST, 'token', 128);
} else {
    $token = input_string($_GET, 'token', 128);
}
$userId = $token !== '' ? find_valid_email_verification($token) : null;
$account = $userId !== null ? find_user_account($userId) : null;

$passwordError = null;
if ($account !== null && $method === 'POST') {
    $email = (string) $account['Eaddress'];
    $ip = client_ip();
    $password = input_password($_POST, 'password');

    if (login_is_throttled($email, $ip)) {
        app_log('warning', 'Email confirmation blocked by throttling', ['ip' => $ip]);
        $passwordError = tn('auth.login.throttled', LOGIN_LOCKOUT_MINUTES);
    } else {
        $result = $password !== '' ? attempt_login($email, $password) : null;
        if ($result !== null && $result['type'] === 'user' && (int) $result['record']['id'] === $userId) {
            $confirmed = complete_email_verification($token);
            if ($confirmed !== null) {
                clear_failed_logins($email);
                login_user($confirmed);
                app_log('info', 'Email address confirmed', ['user_id' => $confirmed['id']]);
                flash('success', t('auth.verify.success', ['name' => $confirmed['name']]));
                redirect('Home_Page.php');
            }
            $account = null; // used up meanwhile by another request
        } else {
            record_failed_login($email, $ip);
            $passwordError = $password === '' ? t('auth.validation.password_required') : t('auth.verify.confirm_failed');
        }
    }
}

if ($account === null) {
    $showResend = email_verification_required();
    http_response_code(404);
    page_header(t('auth.verify.invalid_title'), ['sign.css'], ['bodyClass' => 'auth-page']);
    ?>
        <div class="auth-shell">
            <section class="auth-card" aria-labelledby="verify-invalid-title">
                <h1 class="auth-title" id="verify-invalid-title"><?= e(t('auth.verify.invalid_heading')) ?></h1>
                <p class="auth-intro"><?= e(tn('auth.verify.invalid_intro', EMAIL_VERIFICATION_TTL_HOURS)) ?></p>
<?php if (config('app.demo')): ?>
                <p class="auth-notice" role="note"><?= e(t('auth.reset.demo_notice')) ?></p>
<?php endif; ?>
<?php if ($showResend): ?>
                <form class="auth-form" method="post" action="resend_verification.php" data-once>
                    <?= csrf_field() ?>

                    <div class="form-field">
                        <label for="verify-email"><?= e(t('auth.fields.email')) ?></label>
                        <input class="auth-input" type="email" id="verify-email" name="email"
                            autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="255" required>
                    </div>

                    <button type="submit" class="auth-submit"><?= e(t('auth.verify.resend_submit')) ?></button>
                </form>
<?php endif; ?>
                <p class="auth-alt"><?= e(t('auth.verify.already_confirmed')) ?> <a class="auth-link" href="index.php?form=login"><?= e(t('auth.signup.login_link')) ?></a></p>
            </section>
        </div>
    <?php
    page_footer();
    exit;
}

if ($passwordError !== null) {
    http_response_code(422);
}
page_header(t('auth.panels.verify'), ['sign.css'], ['bodyClass' => 'auth-page']);
?>
        <div class="auth-shell">
            <section class="auth-card" aria-labelledby="verify-confirm-title">
                <h1 class="auth-title" id="verify-confirm-title"><?= e(t('auth.verify.heading')) ?></h1>
                <p class="auth-intro"><?= t_html('auth.verify.confirm_intro', ['email' => '<strong dir="ltr">' . e((string) $account['Eaddress']) . '</strong>']) ?></p>

                <form class="auth-form" method="post" action="verify_email.php" data-once>
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <?php /* Unnamed, so never submitted: lets password managers fill in the right account. */ ?>
                    <input type="email" autocomplete="username" value="<?= e((string) $account['Eaddress']) ?>" readonly hidden>

                    <div class="form-field">
                        <label for="verify-password"><?= e(t('auth.fields.password')) ?></label>
                        <div class="password-wrapper">
                            <input class="auth-input" type="password" id="verify-password" name="password"
                                autocomplete="current-password" maxlength="<?= PASSWORD_MAX_BYTES ?>" required autofocus<?= $passwordError !== null ? ' aria-invalid="true" aria-describedby="verify-password-error"' : '' ?>>
                            <button type="button" class="password-toggle" aria-controls="verify-password" aria-pressed="false" aria-label="<?= e(t('auth.show_password')) ?>" hidden>
                                <img src="imges/eye.png" alt="" width="26" height="18">
                            </button>
                        </div>
<?php if ($passwordError !== null): ?>
                        <p class="field-error" id="verify-password-error"><?= e($passwordError) ?></p>
<?php endif; ?>
                    </div>

                    <button type="submit" class="auth-submit"><?= e(t('auth.verify.confirm_submit')) ?></button>
                </form>
                <p class="auth-alt"><?= e(t('auth.verify.not_you')) ?> <a class="auth-link" href="index.php?form=reset"><?= e(t('auth.login.forgot')) ?></a></p>
            </section>
        </div>
<?php
page_footer(['index.js']);
