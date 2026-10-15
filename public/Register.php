<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

require_post();
verify_csrf();

$action = input_string($_POST, 'action', 20);
$email = normalize_email(input_string($_POST, 'email', 320));
// Passwords are used exactly as typed (never trimmed).
$password = input_password($_POST, 'password');

if ($action === 'register') {
    $name = input_string($_POST, 'name', 255);
    $confirmation = input_password($_POST, 'password_confirmation');

    $errors = array_filter([
        'signup_email' => email_error($email),
        'signup_name' => person_name_error($name),
    ]) + new_password_errors($password, $confirmation, 'signup_password', 'signup_password_confirmation');
    $remembered = ['signup_email' => mb_substr($email, 0, 255), 'signup_name' => $name];

    if ($errors !== []) {
        remember_input($remembered);
        remember_errors($errors);
        redirect('index.php?form=signup');
    }

    // Demo inbox: mail kept for this address from an earlier, deleted account
    // must not be shown to whoever registers the address now.
    demo_inbox_forget_unowned_mail($email);

    if (email_verification_required()) {
        // New, already registered or administrator address: the visitor always
        // gets the same answer, so sign-up cannot be used to discover accounts.
        if (sign_up_with_verification($email, $name, $password) === 'created') {
            // Demo inbox: this browser may read the mail of the account it has
            // just created, never that of an address that was already registered.
            demo_inbox_remember_account($email);
        }
        remember_input(['verify_email' => mb_substr($email, 0, 255)]);
        flash('success', t('auth.signup.check_inbox'));
        redirect('index.php?form=verify');
    }

    $userId = register_user($email, $name, $password);
    if ($userId === null) {
        remember_input($remembered);
        remember_errors(['signup_email' => t('auth.signup.email_taken')]);
        redirect('index.php?form=signup');
    }

    login_user(find_user($userId) ?? throw new RuntimeException('Newly registered user could not be loaded.'));
    demo_inbox_remember_account($email);
    send_welcome_email($email, $userId);

    flash('success', t('auth.signup.welcome', ['name' => bidi_isolate($name)]));
    redirect(post_login_redirect('user'));
}

if ($action === 'login') {
    $errors = [];
    if ($email === '') {
        $errors['login_email'] = t('auth.validation.email_required');
    } elseif (strlen($email) > 255) {
        $errors['login_email'] = t('auth.validation.email_invalid');
    }
    if ($password === '') {
        $errors['login_password'] = t('auth.validation.password_required');
    }
    if ($errors !== []) {
        remember_input(['login_email' => mb_substr($email, 0, 255)]);
        remember_errors($errors);
        redirect('index.php?form=login');
    }

    $ip = client_ip();
    if (login_is_throttled($email, $ip)) {
        app_log('warning', 'Login blocked by throttling', ['ip' => $ip]);
        flash('error', tn('auth.login.throttled', LOGIN_LOCKOUT_MINUTES));
        remember_input(['login_email' => $email]);
        redirect('index.php?form=login');
    }

    $result = attempt_login($email, $password);
    // An unconfirmed account answers exactly like a wrong password. Otherwise
    // anyone could sign up with an address and then log in with the password
    // they just chose, and tell new addresses (unconfirmed) from registered
    // ones (wrong password). Its owner gets a fresh link (throttled, silent).
    $unconfirmed = $result !== null && $result['type'] === 'user'
        && customer_awaits_verification((int) $result['record']['id']);
    if ($result === null || $unconfirmed) {
        record_failed_login($email, $ip);
        if ($unconfirmed) {
            resend_email_verification($email);
        }
        flash('error', email_verification_required() ? t('auth.login.failed_or_unconfirmed') : t('auth.login.failed'));
        remember_input(['login_email' => $email]);
        redirect('index.php?form=login');
    }

    clear_failed_logins($email);

    if ($result['type'] === 'admin') {
        login_admin($result['record']);
    } else {
        login_user($result['record']);
    }

    $displayName = trim((string) ($result['record']['name'] ?? ''));
    flash('success', $displayName !== '' ? t('auth.login.welcome_back_name', ['name' => bidi_isolate($displayName)]) : t('auth.login.welcome_back'));
    redirect(post_login_redirect($result['type']));
}

abort(400, t('auth.errors.unknown_action'));
