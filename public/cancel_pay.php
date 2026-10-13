<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = require_user();

// Read-only: the pending order is superseded at the next checkout or expires on Stripe.
$orderId = parse_int($_GET['order'] ?? null);
$order = $orderId !== null ? find_user_order($orderId, (int) $user['id']) : null;
$alreadyPaid = $order !== null && $order['status'] === 'paid';
$cartCount = cart_item_count((int) $user['id']);

header('Cache-Control: no-store');
page_header($alreadyPaid ? 'Order already paid' : 'Payment canceled', ['checkout.css']);
?>
        <div class="page-container checkout-result">
<?php if ($alreadyPaid): ?>
            <section class="card result-card result-paid" aria-labelledby="result-title">
                <span class="result-icon" aria-hidden="true">&#10003;</span>
                <h1 id="result-title" class="result-title">Order #<?= (int) $order['id'] ?> is already paid</h1>
                <p class="result-lead">This order was paid successfully, so there is nothing to cancel.</p>
                <div class="result-actions">
                    <a class="btn btn-secondary" href="profilePage.php?section=orders">View my orders</a>
                    <a class="btn btn-pay" href="Explore_Page.php">Continue exploring</a>
                </div>
            </section>
<?php else: ?>
            <section class="card result-card result-canceled" aria-labelledby="result-title">
                <span class="result-icon" aria-hidden="true">&#10005;</span>
<?php if ($order !== null): ?>
                <p class="result-eyebrow">Order #<?= (int) $order['id'] ?></p>
<?php endif; ?>
                <h1 id="result-title" class="result-title">Checkout canceled</h1>
                <p class="result-lead">Payment canceled. You have not been charged and your cart is saved.</p>
<?php if ($cartCount > 0): ?>
                <p class="result-note">Your cart still holds <?= $cartCount ?> <?= $cartCount === 1 ? 'book' : 'books' ?>. You can review it and pay whenever you are ready.</p>
<?php endif; ?>
                <div class="result-actions">
                    <a class="btn btn-pay" href="cart.php">Return to cart</a>
                    <a class="btn btn-secondary" href="Explore_Page.php">Continue exploring</a>
                </div>
            </section>
<?php endif; ?>
        </div>
<?php
page_footer();
