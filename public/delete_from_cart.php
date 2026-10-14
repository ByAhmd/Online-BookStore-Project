<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

require_post();
// Signed-out (e.g. expired) sessions go to the login page rather than a 419.
$user = require_user();
verify_csrf();
$userId = (int) $user['id'];

$cartId = parse_int($_POST['cart_id'] ?? null);

// Look the title up among the customer's own lines for the message;
// cart_remove() enforces ownership in SQL either way.
$title = null;
foreach ($cartId !== null ? cart_lines($userId) : [] as $line) {
    if ($line['cart_id'] === $cartId) {
        $title = $line['title'];
        break;
    }
}

if ($cartId !== null && $title !== null && cart_remove($userId, $cartId)) {
    flash('success', t('shop.cart.removed', ['title' => $title]));
} else {
    flash('error', t('shop.cart.line_missing'));
}

redirect('cart.php');
