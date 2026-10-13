<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

require_post();
// Signed-out (e.g. expired) sessions go to the login page rather than a 419.
$user = require_user();
verify_csrf();
$userId = (int) $user['id'];

$cartId = parse_int($_POST['cart_id'] ?? null);
$quantity = parse_int($_POST['quantity'] ?? null, 1, CART_MAX_QUANTITY);

// Only the customer's own lines are visible here; cart_set_quantity() re-checks ownership.
$line = null;
foreach ($cartId !== null ? cart_lines($userId) : [] as $candidate) {
    if ($candidate['cart_id'] === $cartId) {
        $line = $candidate;
        break;
    }
}

if ($line === null) {
    flash('error', 'That item is no longer in your cart.');
} elseif ($quantity === null) {
    flash('error', 'Choose a quantity between 1 and ' . CART_MAX_QUANTITY . '.');
} elseif (!cart_set_quantity($userId, $cartId, $quantity)) {
    flash('error', 'That item is no longer in your cart.');
} else {
    flash('success', sprintf('Updated “%s” to %d %s.', $line['title'], $quantity, $quantity === 1 ? 'copy' : 'copies'));
}

redirect('cart.php');
