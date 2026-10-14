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

// cart_update_quantity() only touches the customer's own line and never sets
// more copies than are in stock.
$result = $cartId !== null ? cart_update_quantity($userId, $cartId, $quantity ?? 0) : null;

if ($result === null || $result['status'] === 'missing') {
    flash('error', t('shop.cart.line_missing'));
} elseif ($result['status'] === 'invalid') {
    flash('error', t('shop.cart.quantity_error', ['max' => CART_MAX_QUANTITY]));
} elseif ($result['status'] === 'out_of_stock') {
    flash('error', t('shop.cart.out_of_stock', ['title' => $result['title']]));
} elseif ($result['status'] === 'capped') {
    flash('info', tn('shop.cart.capped', $result['quantity'], ['title' => $result['title']]));
} else {
    flash('success', tn('shop.cart.updated', $result['quantity'], ['title' => $result['title']]));
}

redirect('cart.php');
