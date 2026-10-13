<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

require_post();
verify_csrf();

$action = input_string($_POST, 'action', 20);
$email = normalize_email(input_string($_POST, 'email', 320));
// Passwords are used exactly as typed (never trimmed).
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

if ($action === 'register') {
    $name = input_string($_POST, 'name', 255);
    $confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';

    $errors = [];
    if (($error = validate_email($email)) !== null) {
        $errors['signup_email'] = $error;
    }
    if (($error = validate_person_name($name)) !== null) {
        $errors['signup_name'] = $error;
    }
    if (($error = validate_new_password($password, $confirmation)) !== null) {
        // A valid password that only differs from its confirmation is reported on the confirmation field.
        $mismatchOnly = validate_new_password($password, $password) === null;
        $errors[$mismatchOnly ? 'signup_password_confirmation' : 'signup_password'] = $error;
    }

    $userId = $errors === [] ? register_user($email, $name, $password) : null;
    if ($errors === [] && $userId === null) {
        $errors['signup_email'] = 'An account with this email already exists.';
    }

    if ($userId === null) {
        remember_input(['signup_email' => mb_substr($email, 0, 255), 'signup_name' => $name]);
        remember_errors($errors);
        redirect('index.php?form=signup');
    }

    login_user(find_user($userId) ?? throw new RuntimeException('Newly registered user could not be loaded.'));

    // The address is not verified, so the email contains no user-supplied text
    // (a crafted "name" must not turn it into a phishing message from our sender).
    $html = '<p>Hello,</p>'
        . '<p>Welcome to BookWise! Your account for this email address is ready. '
        . '<a href="' . e(absolute_url('Explore_Page.php')) . '">Start exploring our collection of books</a>.</p>'
        . '<p>If you did not create this account, you can ignore this email.</p>'
        . '<p>Best regards,<br>BookWise Team</p>';
    $text = "Hello,\n\nWelcome to BookWise! Your account for this email address is ready. "
        . "Start exploring our collection of books:\n" . absolute_url('Explore_Page.php')
        . "\n\nIf you did not create this account, you can ignore this email.\n\nBest regards,\nBookWise Team";
    if (!send_mail($email, '', 'Welcome to BookWise!', $html, $text)) {
        app_log('warning', 'Welcome email could not be sent', ['user_id' => $userId]);
    }

    flash('success', 'Welcome to BookWise, ' . $name . '! Your account is ready.');
    redirect(post_login_redirect('user'));
}

if ($action === 'login') {
    $errors = [];
    if ($email === '') {
        $errors['login_email'] = 'Please enter your email address.';
    } elseif (strlen($email) > 255) {
        $errors['login_email'] = 'Please enter a valid email address.';
    }
    if ($password === '') {
        $errors['login_password'] = 'Please enter your password.';
    }
    if ($errors !== []) {
        remember_input(['login_email' => mb_substr($email, 0, 255)]);
        remember_errors($errors);
        redirect('index.php?form=login');
    }

    $ip = client_ip();
    if (login_is_throttled($email, $ip)) {
        app_log('warning', 'Login blocked by throttling', ['ip' => $ip]);
        flash('error', 'Too many failed sign-in attempts. Please wait ' . LOGIN_LOCKOUT_MINUTES . ' minutes and try again.');
        remember_input(['login_email' => $email]);
        redirect('index.php?form=login');
    }

    $result = attempt_login($email, $password);
    if ($result === null) {
        record_failed_login($email, $ip);
        flash('error', 'Incorrect email or password.');
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
    flash('success', $displayName !== '' ? 'Welcome back, ' . $displayName . '!' : 'Welcome back!');
    redirect(post_login_redirect($result['type']));
}

abort(400, 'Unknown form action.');
