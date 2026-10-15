<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

/*
 * The demo's one-click sign-in (the two buttons on the sign-in page). It only
 * exists in demo mode (APP_DEMO=true) and answers 404 otherwise.
 *  - as=customer creates a fresh demo guest for this visitor (a throwaway
 *    customer account, see create_demo_guest()) and signs it in;
 *  - as=admin signs in to the shared read-only administrator, through which
 *    nothing can be changed (require_admin_write()).
 * Both are POST forms with the session's CSRF token, like every sign-in.
 */
if (!demo_logins_enabled()) {
    abort(404);
}
require_post();
verify_csrf();

$as = input_string($_POST, 'as', 20);

if ($as === 'customer') {
    $ip = client_ip();
    $result = create_demo_guest($ip);
    if ($result['user'] === null) {
        if ($result['status'] === 'throttled') {
            app_log('warning', 'Demo guest creation blocked by throttling', ['ip' => $ip]);
            flash('error', t('auth.demo.throttled'));
        } elseif ($result['status'] === 'crowded') {
            app_log('warning', 'Demo guest creation blocked by the limit for all visitors', ['limit' => DEMO_GUEST_RATE_LIMIT_TOTAL]);
            flash('error', t('auth.demo.crowded'));
        } else {
            flash('error', t('auth.demo.busy'));
        }
        redirect('index.php?form=login');
    }

    login_user($result['user']);
    flash('success', tn('auth.demo.customer_welcome', DEMO_GUEST_TTL_HOURS, ['name' => (string) $result['user']['name']]));
    redirect(post_login_redirect('user'));
}

if ($as === 'admin') {
    login_admin(demo_viewer_admin());
    flash('success', t('auth.demo.admin_welcome'));
    redirect(post_login_redirect('admin'));
}

abort(400, t('auth.errors.unknown_action'));
