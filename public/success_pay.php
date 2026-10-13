<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = require_user();

// Never trusts the redirect itself: the session is verified with Stripe and
// fulfilled at most once, so reloading this page is safe.
$sessionId = input_string($_GET, 'session_id', 300);
$result = confirm_checkout_return((int) $user['id'], $sessionId);
$state = $result['state'];
$order = $result['order'];

$orderMoney = static function (string|int|float $amount) use ($order): string {
    $currency = strtoupper((string) ($order['currency'] ?? config('stripe.currency')));
    return $currency . ' ' . number_format((float) $amount, 2);
};

$items = $state === 'paid' && $order !== null ? order_items((int) $order['id']) : [];

$paidAt = null;
if ($state === 'paid' && !empty($order['paid_at'])) {
    try {
        $paidAt = new DateTimeImmutable((string) $order['paid_at']);
    } catch (Exception) {
        $paidAt = null;
    }
}

$titles = [
    'paid' => 'Payment received',
    'processing' => 'Confirming payment',
    'unpaid' => 'Payment not completed',
    'invalid' => 'Payment not found',
    'error' => 'Payment status unavailable',
    'review' => 'Payment under review',
];
$pageTitle = $titles[$state];

if ($state === 'invalid') {
    http_response_code(404);
} elseif ($state === 'error') {
    http_response_code(502);
}
header('Cache-Control: no-store');

page_header($pageTitle, ['checkout.css']);
?>
        <div class="page-container checkout-result">
            <section class="card result-card result-<?= e($state) ?>" aria-labelledby="result-title">
<?php if ($state === 'paid' && $order !== null): ?>
                <span class="result-icon" aria-hidden="true">&#10003;</span>
                <h1 id="result-title" class="result-title">Thank you! Your payment was received.</h1>
                <p class="result-lead">
                    Order <strong>#<?= (int) $order['id'] ?></strong> is confirmed<?php if ($paidAt !== null): ?>,
                    paid on <time datetime="<?= e($paidAt->format('c')) ?>"><?= e($paidAt->format('j M Y, H:i')) ?></time><?php endif; ?>.
                </p>

<?php if ($items !== []): ?>
                <table class="order-table">
                    <caption class="sr-only">Books in order #<?= (int) $order['id'] ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">Book</th>
                            <th scope="col" class="num">Qty</th>
                            <th scope="col" class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
<?php foreach ($items as $item):
    $quantity = (int) $item['quantity'];
    $lineMinor = money_to_minor((string) $item['unit_price']) * $quantity;
    ?>
                        <tr>
                            <td>
<?php if ($item['book_id'] !== null): ?>
                                <a class="order-book" href="Selected.php?id=<?= (int) $item['book_id'] ?>"><?= e($item['title']) ?></a>
<?php else: ?>
                                <span class="order-book"><?= e($item['title']) ?></span>
<?php endif; ?>
                                <span class="order-unit"><?= e($orderMoney((string) $item['unit_price'])) ?> each</span>
                            </td>
                            <td class="num"><?= $quantity ?></td>
                            <td class="num"><?= e($orderMoney(minor_to_decimal($lineMinor))) ?></td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row" colspan="2">Total paid</th>
                            <td class="num"><?= e($orderMoney((string) $order['total_amount'])) ?></td>
                        </tr>
                    </tfoot>
                </table>
<?php endif; ?>

                <p class="result-note">Your books are now in your purchase history, and you can rate and review them.</p>
                <div class="result-actions">
                    <a class="btn btn-secondary" href="profilePage.php?section=orders">View my orders</a>
                    <a class="btn btn-pay" href="Explore_Page.php">Continue exploring</a>
                </div>

<?php elseif ($state === 'processing'): ?>
                <span class="result-icon" aria-hidden="true">&hellip;</span>
                <h1 id="result-title" class="result-title">We're confirming your payment&hellip;</h1>
                <p class="result-lead">Stripe has received your payment details and is still processing them. This usually takes a few moments.</p>
                <p class="result-note">There is no need to pay again. Refresh this page to check, or find the order in your history once it is confirmed.</p>
                <div class="result-actions">
                    <a class="btn btn-pay" href="success_pay.php?session_id=<?= e(rawurlencode($sessionId)) ?>">Refresh status</a>
                    <a class="btn btn-secondary" href="profilePage.php?section=orders">View my orders</a>
                </div>

<?php elseif ($state === 'unpaid'): ?>
                <span class="result-icon" aria-hidden="true">&#10005;</span>
                <h1 id="result-title" class="result-title">Payment not completed</h1>
                <p class="result-lead">Your payment wasn't completed. You have not been charged.</p>
                <p class="result-note">Your cart is saved, so you can try again whenever you are ready.</p>
                <div class="result-actions">
                    <a class="btn btn-pay" href="cart.php">Back to cart</a>
                    <a class="btn btn-secondary" href="Explore_Page.php">Continue exploring</a>
                </div>

<?php elseif ($state === 'review'): ?>
                <span class="result-icon" aria-hidden="true">!</span>
                <h1 id="result-title" class="result-title">Payment under review</h1>
                <p class="result-lead">We received a payment for this checkout, but it doesn't match your order, so we haven't confirmed it automatically.</p>
                <p class="result-note">
                    Our team has been notified and will reconcile it with Stripe. Please don't pay again.
<?php if ($order !== null): ?>
                    Mention order <strong>#<?= (int) $order['id'] ?></strong> if you contact us.
<?php endif; ?>
                </p>
                <div class="result-actions">
                    <a class="btn btn-secondary" href="profilePage.php?section=orders">View my orders</a>
                </div>

<?php elseif ($state === 'error'): ?>
                <span class="result-icon" aria-hidden="true">!</span>
                <h1 id="result-title" class="result-title">Payment status unavailable</h1>
                <p class="result-lead">We couldn't confirm your payment right now. If you were charged, your order will update automatically — please check your orders shortly.</p>
                <div class="result-actions">
                    <a class="btn btn-pay" href="success_pay.php?session_id=<?= e(rawurlencode($sessionId)) ?>">Try again</a>
                    <a class="btn btn-secondary" href="profilePage.php?section=orders">View my orders</a>
                </div>

<?php else: ?>
                <span class="result-icon" aria-hidden="true">?</span>
                <h1 id="result-title" class="result-title">Payment not found</h1>
                <p class="result-lead">We couldn't find that payment.</p>
                <p class="result-note">The link may be incomplete, or the payment belongs to a different account. Your orders and cart are unchanged.</p>
                <div class="result-actions">
                    <a class="btn btn-pay" href="cart.php">Go to cart</a>
                    <a class="btn btn-secondary" href="profilePage.php?section=orders">View my orders</a>
                </div>
<?php endif; ?>
            </section>
        </div>
<?php
page_footer();
