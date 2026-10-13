<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

require_post();
verify_csrf();

$email = normalize_email(input_string($_POST, 'email', 320));
if (($error = validate_email($email)) !== null) {
    remember_input(['reset_email' => mb_substr($email, 0, 255)]);
    remember_errors(['reset_email' => $error]);
    redirect('index.php?form=reset');
}

request_password_reset($email);

// Identical response whether or not the account exists (no account enumeration).
flash('success', "If an account exists for that email, we've sent a password reset link. It expires in "
    . RESET_TOKEN_TTL_MINUTES . ' minutes.');
redirect('index.php?form=login');
