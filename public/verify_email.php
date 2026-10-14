<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// The token is in the URL: keep it out of Referer headers and caches.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if (!in_array(request_method(), ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    abort(405, t('auth.verify.link_only'));
}
if (request_method() === 'HEAD') {
    // Link checkers in mail systems send HEAD requests: never use up the token for them.
    exit;
}

$token = input_string($_GET, 'token', 128);
$account = $token !== '' ? complete_email_verification($token) : null;

if ($account !== null) {
    login_user($account);
    app_log('info', 'Email address confirmed', ['user_id' => $account['id']]);
    flash('success', t('auth.verify.success', ['name' => $account['name']]));
    redirect('Home_Page.php');
}

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
