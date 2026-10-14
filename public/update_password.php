<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

require_post();
verify_csrf();

$token = input_string($_POST, 'token', 128);
if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
    flash('error', t('auth.reset.invalid_link'));
    redirect('index.php?form=reset');
}

// Passwords are used exactly as typed (never trimmed).
$password = input_password($_POST, 'password');
$confirmation = input_password($_POST, 'password_confirmation');

$errors = new_password_errors($password, $confirmation, 'password', 'password_confirmation');
if ($errors !== []) {
    remember_errors($errors);
    redirect('reset_form.php?token=' . $token);
}

// Completing a reset also confirms the account's email address.
if (!complete_password_reset($token, $password)) {
    flash('error', t('auth.reset.invalid_link'));
    redirect('index.php?form=reset');
}

flash('success', t('auth.reset.updated'));
redirect('index.php?form=login');
