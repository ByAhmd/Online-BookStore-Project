<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (current_user() !== null) {
    redirect('Home_Page.php');
}
if (current_admin() !== null) {
    redirect('admin_dashboard.php');
}

$panels = ['signup' => 'Sign Up', 'login' => 'Login', 'reset' => 'Reset Password'];
$requested = $_GET['form'] ?? 'login';
$active = is_string($requested) && isset($panels[$requested]) ? $requested : 'login';

$errors = [];
foreach (['signup_email', 'signup_name', 'signup_password', 'signup_password_confirmation', 'login_email', 'login_password', 'reset_email'] as $key) {
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

$toggle = static function (string $inputId): string {
    return '<button type="button" class="password-toggle" aria-controls="' . e($inputId) . '" aria-pressed="false" aria-label="Show password" hidden>'
        . '<img src="imges/eye.png" alt="" width="26" height="18">'
        . '</button>';
};

page_header($panels[$active], ['sign.css'], ['bodyClass' => 'auth-page']);
?>
        <div class="auth-shell">
            <div class="auth-card">
                <h1 class="sr-only">Sign up or log in to BookWise</h1>

                <div class="auth-switch" role="group" aria-label="Choose a form">
                    <a class="pill pill-dark" href="index.php?form=signup" data-auth-target="signup"<?= $currentIf('signup') ?>>Sign Up</a>
                    <a class="pill pill-red" href="index.php?form=login" data-auth-target="login"<?= $currentIf('login') ?>>Login</a>
                </div>

                <section class="auth-panel" id="panel-signup" data-panel="signup" data-title="<?= e($panels['signup']) ?>" aria-labelledby="signup-title"<?= $hiddenUnless('signup') ?>>
                    <h2 class="auth-title" id="signup-title" tabindex="-1">Sign Up</h2>
                    <form class="auth-form" method="post" action="Register.php" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="register">

                        <div class="form-field">
                            <label for="signup-email">Email Address</label>
                            <input class="auth-input" type="email" id="signup-email" name="email" value="<?= e(old('signup_email')) ?>"
                                autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="255" required<?= $describe('signup-email', $errors['signup_email']) ?>>
                            <?= $errorText('signup-email', $errors['signup_email']) ?>
                        </div>

                        <div class="form-field">
                            <label for="signup-name">Name</label>
                            <input class="auth-input" type="text" id="signup-name" name="name" value="<?= e(old('signup_name')) ?>"
                                autocomplete="name" maxlength="<?= NAME_MAX_LENGTH ?>" required<?= $describe('signup-name', $errors['signup_name']) ?>>
                            <?= $errorText('signup-name', $errors['signup_name']) ?>
                        </div>

                        <div class="form-field">
                            <label for="signup-password">Password</label>
                            <div class="password-wrapper">
                                <input class="auth-input" type="password" id="signup-password" name="password"
                                    autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_BYTES ?>"
                                    data-max-bytes="<?= PASSWORD_MAX_BYTES ?>" required<?= $describe('signup-password', $errors['signup_password'], true) ?>>
                                <?= $toggle('signup-password') ?>
                            </div>
                            <p class="form-hint" id="signup-password-hint">At least <?= PASSWORD_MIN_LENGTH ?> characters.</p>
                            <?= $errorText('signup-password', $errors['signup_password']) ?>
                        </div>

                        <div class="form-field">
                            <label for="signup-password-confirmation">Confirm Password</label>
                            <div class="password-wrapper">
                                <input class="auth-input" type="password" id="signup-password-confirmation" name="password_confirmation"
                                    autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_BYTES ?>"
                                    data-match="signup-password" required<?= $describe('signup-password-confirmation', $errors['signup_password_confirmation']) ?>>
                                <?= $toggle('signup-password-confirmation') ?>
                            </div>
                            <?= $errorText('signup-password-confirmation', $errors['signup_password_confirmation']) ?>
                        </div>

                        <button type="submit" class="auth-submit">Sign Up</button>
                    </form>
                    <p class="auth-alt">Already have an account? <a class="auth-link" href="index.php?form=login" data-auth-target="login">Log in</a></p>
                </section>

                <section class="auth-panel" id="panel-login" data-panel="login" data-title="<?= e($panels['login']) ?>" aria-labelledby="login-title"<?= $hiddenUnless('login') ?>>
                    <h2 class="auth-title" id="login-title" tabindex="-1">Login</h2>
                    <form class="auth-form" method="post" action="Register.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="login">

                        <div class="form-field">
                            <label for="login-email">Email Address</label>
                            <input class="auth-input" type="email" id="login-email" name="email" value="<?= e(old('login_email')) ?>"
                                autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="255" required<?= $describe('login-email', $errors['login_email']) ?>>
                            <?= $errorText('login-email', $errors['login_email']) ?>
                        </div>

                        <div class="form-field">
                            <label for="login-password">Password</label>
                            <div class="password-wrapper">
                                <input class="auth-input" type="password" id="login-password" name="password"
                                    autocomplete="current-password" maxlength="255" required<?= $describe('login-password', $errors['login_password']) ?>>
                                <?= $toggle('login-password') ?>
                            </div>
                            <?= $errorText('login-password', $errors['login_password']) ?>
                        </div>

                        <p class="auth-forgot"><a class="auth-link" href="index.php?form=reset" data-auth-target="reset">Forgot your password?</a></p>

                        <button type="submit" class="auth-submit">Login</button>
                    </form>
                    <p class="auth-alt">New to BookWise? <a class="auth-link" href="index.php?form=signup" data-auth-target="signup">Create an account</a></p>
                </section>

                <section class="auth-panel" id="panel-reset" data-panel="reset" data-title="<?= e($panels['reset']) ?>" aria-labelledby="reset-title"<?= $hiddenUnless('reset') ?>>
                    <h2 class="auth-title" id="reset-title" tabindex="-1">Reset Password</h2>
                    <p class="auth-intro">Enter the email address of your account and we will send you a link to choose a new password.</p>
                    <form class="auth-form" method="post" action="reset_password.php" data-once>
                        <?= csrf_field() ?>

                        <div class="form-field">
                            <label for="reset-email">Email Address</label>
                            <input class="auth-input" type="email" id="reset-email" name="email" value="<?= e(old('reset_email')) ?>"
                                autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="255" required<?= $describe('reset-email', $errors['reset_email'], true) ?>>
                            <p class="form-hint" id="reset-email-hint">The link expires in <?= RESET_TOKEN_TTL_MINUTES ?> minutes.</p>
                            <?= $errorText('reset-email', $errors['reset_email']) ?>
                        </div>

                        <button type="submit" class="auth-submit">Send Reset Link</button>
                    </form>
                    <p class="auth-alt">Remembered it? <a class="auth-link" href="index.php?form=login" data-auth-target="login">Back to login</a></p>
                </section>
            </div>
        </div>
<?php
page_footer(['index.js']);
