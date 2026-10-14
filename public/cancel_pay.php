<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = require_user();

// Read-only: the pending order is superseded at the next checkout or expires on Stripe.
$orderId = parse_int($_GET['order'] ?? null);
$order = $orderId !== null ? find_user_order($orderId, (int) $user['id']) : null;
$alreadyPaid = $order !== null && in_array($order['status'], ['paid', 'refunded'], true);
$cartCount = cart_item_count((int) $user['id']);

header('Cache-Control: no-store');
page_header(t($alreadyPaid ? 'shop.result.cancel.already_paid_title' : 'shop.result.cancel.canceled_title'), ['checkout.css']);
?>
        <div class="page-container checkout-result">
<?php if ($alreadyPaid): ?>
            <section class="card result-card result-paid" aria-labelledby="result-title">
                <span class="result-icon" aria-hidden="true">&#10003;</span>
                <h1 id="result-title" class="result-title"><?= e(t('shop.result.cancel.already_paid_heading', ['id' => (int) $order['id']])) ?></h1>
                <p class="result-lead"><?= e(t('shop.result.cancel.already_paid_lead')) ?></p>
                <div class="result-actions">
                    <a class="btn btn-secondary" href="profilePage.php?section=orders"><?= e(t('shop.result.view_orders')) ?></a>
                    <a class="btn btn-pay" href="Explore_Page.php"><?= e(t('shop.result.continue')) ?></a>
                </div>
            </section>
<?php else: ?>
            <section class="card result-card result-canceled" aria-labelledby="result-title">
                <span class="result-icon" aria-hidden="true">&#10005;</span>
<?php if ($order !== null): ?>
                <p class="result-eyebrow"><?= e(t('shop.result.cancel.order_eyebrow', ['id' => (int) $order['id']])) ?></p>
<?php endif; ?>
                <h1 id="result-title" class="result-title"><?= e(t('shop.result.cancel.canceled_heading')) ?></h1>
                <p class="result-lead"><?= e(t('shop.result.cancel.canceled_lead')) ?></p>
<?php if ($cartCount > 0): ?>
                <p class="result-note"><?= e(tn('shop.result.cancel.cart_note', $cartCount)) ?></p>
<?php endif; ?>
                <div class="result-actions">
                    <a class="btn btn-pay" href="cart.php"><?= e(t('shop.result.cancel.return_to_cart')) ?></a>
                    <a class="btn btn-secondary" href="Explore_Page.php"><?= e(t('shop.result.continue')) ?></a>
                </div>
            </section>
<?php endif; ?>
        </div>
<?php
page_footer();
