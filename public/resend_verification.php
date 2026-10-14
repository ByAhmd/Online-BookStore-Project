<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

require_post();
verify_csrf();

if (!email_verification_required()) {
    // Accounts can sign in without confirming their address: there is nothing to resend.
    redirect('index.php?form=login');
}

$email = normalize_email(input_string($_POST, 'email', 320));
if (($error = email_error($email)) !== null) {
    remember_input(['verify_email' => mb_substr($email, 0, 255)]);
    remember_errors(['verify_email' => $error]);
    redirect('index.php?form=verify');
}

// Sends a new link only to an unconfirmed customer address, at most once per
// cooldown; the answer is the same in every case (no account enumeration).
resend_email_verification($email);

remember_input(['verify_email' => $email]);
flash('success', tn('auth.verify.resent', EMAIL_VERIFICATION_TTL_HOURS));
redirect('index.php?form=verify');
