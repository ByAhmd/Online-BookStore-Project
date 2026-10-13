<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

require_post();
// Signed-out (e.g. expired) sessions go to the login page rather than a 419.
$user = require_user();
verify_csrf();

// Prices, totals and the Stripe session are all built server-side from the stored cart.
try {
    redirect(start_checkout($user), 303);
} catch (CheckoutException $e) {
    flash('error', $e->getMessage());
    redirect('cart.php');
}
