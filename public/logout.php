<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// Logging out changes state, so it only happens through the CSRF-protected form.
if (!is_post()) {
    redirect('Home_Page.php');
}

// Already signed out (for example after the session expired): nothing to protect.
if (current_user() === null && current_admin() === null) {
    flash('info', t('auth.logout.done'));
    redirect('index.php?form=login');
}

verify_csrf();
logout_session();
flash('info', t('auth.logout.done'));
redirect('index.php?form=login');
