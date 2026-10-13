<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

require_post();
verify_csrf();

$invalidLinkMessage = 'This reset link is invalid or has expired. Please request a new one.';

$token = input_string($_POST, 'token', 128);
if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
    flash('error', $invalidLinkMessage);
    redirect('index.php?form=reset');
}

// Passwords are used exactly as typed (never trimmed).
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
$confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';

if (($error = validate_new_password($password, $confirmation)) !== null) {
    $mismatchOnly = validate_new_password($password, $password) === null;
    remember_errors([$mismatchOnly ? 'password_confirmation' : 'password' => $error]);
    redirect('reset_form.php?token=' . $token);
}

if (!complete_password_reset($token, $password)) {
    flash('error', $invalidLinkMessage);
    redirect('index.php?form=reset');
}

flash('success', 'Your password has been updated. You can now log in.');
redirect('index.php?form=login');
