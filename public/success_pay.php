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
    return format_money($amount, (string) ($order['currency'] ?? config('stripe.currency')));
};

$items = $state === 'paid' && $order !== null ? order_items((int) $order['id']) : [];
$refunded = $state === 'paid' && ($order['status'] ?? null) === 'refunded';
$vatMinor = $order !== null ? vat_included_minor((int) $order['amount_minor']) : 0;
// A paid order with a delivery address is now being prepared (see src/fulfilment.php).
$delivering = $state === 'paid' && !$refunded && ($order['fulfilment_status'] ?? null) !== null;

$paidAt = null;
if ($state === 'paid' && !empty($order['paid_at'])) {
    try {
        $paidAt = new DateTimeImmutable((string) $order['paid_at']);
    } catch (Exception) {
        $paidAt = null;
    }
}

$pageTitle = t('shop.result.titles.' . $state);
$orderNumber = $order !== null ? '<strong>#' . (int) $order['id'] . '</strong>' : '';

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
                <h1 id="result-title" class="result-title"><?= e(t('shop.result.paid_heading')) ?></h1>
                <p class="result-lead">
<?php if ($paidAt !== null): ?>
                    <?= t_markup('shop.result.paid_lead_on', [
                        'order' => $orderNumber,
                        'date' => '<time datetime="' . e($paidAt->format('c')) . '">' . e(display_date($paidAt, true)) . '</time>',
                    ]) ?>
<?php else: ?>
                    <?= t_markup('shop.result.paid_lead', ['order' => $orderNumber]) ?>
<?php endif; ?>
                </p>
<?php if ($refunded): ?>
                <p class="result-note result-refunded"><?= e(t('shop.result.refunded_note')) ?></p>
<?php endif; ?>

<?php if ($items !== []): ?>
                <table class="order-table">
                    <caption class="sr-only"><?= e(t('shop.result.items_caption', ['id' => (int) $order['id']])) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?= e(t('shop.result.col_book')) ?></th>
                            <th scope="col" class="num"><?= e(t('shop.result.col_qty')) ?></th>
                            <th scope="col" class="num"><?= e(t('shop.result.col_total')) ?></th>
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
                                <a class="order-book" href="Selected.php?id=<?= (int) $item['book_id'] ?>" dir="auto"><?= e($item['title']) ?></a>
<?php else: ?>
                                <span class="order-book" dir="auto"><?= e($item['title']) ?></span>
<?php endif; ?>
                                <span class="order-unit"><?= e(t('shop.result.each', ['price' => $orderMoney((string) $item['unit_price'])])) ?></span>
                            </td>
                            <td class="num"><?= $quantity ?></td>
                            <td class="num"><?= e($orderMoney(minor_to_decimal($lineMinor))) ?></td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row" colspan="2"><?= e(t('shop.result.total_paid')) ?></th>
                            <td class="num"><?= e($orderMoney((string) $order['total_amount'])) ?></td>
                        </tr>
                        <tr class="order-vat">
                            <th scope="row" colspan="2"><?= e(t('shop.totals.vat_included', ['rate' => VAT_RATE_PERCENT])) ?></th>
                            <td class="num"><?= e($orderMoney(minor_to_decimal($vatMinor))) ?></td>
                        </tr>
                    </tfoot>
                </table>
<?php endif; ?>

<?php if ($delivering): ?>
                <p class="result-note"><?= e(t('shop.result.delivery_note')) ?></p>
<?php endif; ?>
<?php if (!$refunded): ?>
                <p class="result-note"><?= e(t('shop.result.paid_note')) ?></p>
<?php endif; ?>
                <div class="result-actions">
                    <a class="btn btn-secondary" href="order.php?id=<?= (int) $order['id'] ?>"><?= e(t('shop.result.view_order')) ?></a>
                    <a class="btn btn-secondary" href="profilePage.php?section=orders"><?= e(t('shop.result.view_orders')) ?></a>
                    <a class="btn btn-pay" href="Explore_Page.php"><?= e(t('shop.result.continue')) ?></a>
                </div>

<?php elseif ($state === 'processing'): ?>
                <span class="result-icon" aria-hidden="true">&hellip;</span>
                <h1 id="result-title" class="result-title"><?= e(t('shop.result.processing_heading')) ?></h1>
                <p class="result-lead"><?= e(t(payment_simulator_enabled() ? 'shop.pay.processing_lead' : 'shop.result.processing_lead')) ?></p>
                <p class="result-note"><?= e(t('shop.result.processing_note')) ?></p>
                <div class="result-actions">
                    <a class="btn btn-pay" href="success_pay.php?session_id=<?= e(rawurlencode($sessionId)) ?>"><?= e(t('shop.result.refresh')) ?></a>
                    <a class="btn btn-secondary" href="profilePage.php?section=orders"><?= e(t('shop.result.view_orders')) ?></a>
                </div>

<?php elseif ($state === 'unpaid'): ?>
                <span class="result-icon" aria-hidden="true">&#10005;</span>
                <h1 id="result-title" class="result-title"><?= e(t('shop.result.unpaid_heading')) ?></h1>
                <p class="result-lead"><?= e(t('shop.result.unpaid_lead')) ?></p>
                <p class="result-note"><?= e(t('shop.result.unpaid_note')) ?></p>
                <div class="result-actions">
                    <a class="btn btn-pay" href="cart.php"><?= e(t('shop.result.back_to_cart')) ?></a>
                    <a class="btn btn-secondary" href="Explore_Page.php"><?= e(t('shop.result.continue')) ?></a>
                </div>

<?php elseif ($state === 'review'): ?>
                <span class="result-icon" aria-hidden="true">!</span>
                <h1 id="result-title" class="result-title"><?= e(t('shop.result.review_heading')) ?></h1>
                <p class="result-lead"><?= e(t('shop.result.review_lead')) ?></p>
                <p class="result-note">
                    <?= e(t('shop.result.review_note')) ?>
<?php if ($order !== null): ?>
                    <?= t_markup('shop.result.review_mention', ['order' => $orderNumber]) ?>
<?php endif; ?>
                </p>
                <div class="result-actions">
                    <a class="btn btn-secondary" href="profilePage.php?section=orders"><?= e(t('shop.result.view_orders')) ?></a>
                </div>

<?php elseif ($state === 'error'): ?>
                <span class="result-icon" aria-hidden="true">!</span>
                <h1 id="result-title" class="result-title"><?= e(t('shop.result.error_heading')) ?></h1>
                <p class="result-lead"><?= e(t('shop.result.error_lead')) ?></p>
                <div class="result-actions">
                    <a class="btn btn-pay" href="success_pay.php?session_id=<?= e(rawurlencode($sessionId)) ?>"><?= e(t('shop.result.try_again')) ?></a>
                    <a class="btn btn-secondary" href="profilePage.php?section=orders"><?= e(t('shop.result.view_orders')) ?></a>
                </div>

<?php else: ?>
                <span class="result-icon" aria-hidden="true">?</span>
                <h1 id="result-title" class="result-title"><?= e(t('shop.result.invalid_heading')) ?></h1>
                <p class="result-lead"><?= e(t('shop.result.invalid_lead')) ?></p>
                <p class="result-note"><?= e(t('shop.result.invalid_note')) ?></p>
                <div class="result-actions">
                    <a class="btn btn-pay" href="cart.php"><?= e(t('shop.result.go_to_cart')) ?></a>
                    <a class="btn btn-secondary" href="profilePage.php?section=orders"><?= e(t('shop.result.view_orders')) ?></a>
                </div>
<?php endif; ?>
            </section>
        </div>
<?php
page_footer();
