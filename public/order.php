<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

/*
 * One of the customer's orders: its progress (paid, processing, shipped
 * with tracking, delivered, or refunded), the delivery address, and a
 * receipt that prints on its own (css/order.css). "Buy again" (POST) puts the
 * books that are still available back into the cart. Other customers'
 * orders answer 404, exactly like orders that do not exist.
 */

if (!in_array(request_method(), ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, POST');
    abort(405);
}

$user = require_user();
$userId = (int) $user['id'];
$id = parse_int($_GET['id'] ?? null);
$order = $id !== null ? customer_order($id, $userId) : null;
if ($order === null) {
    abort(404, t('shop.order.not_found'));
}
$orderUrl = 'order.php?id=' . $id;

if (is_post()) {
    verify_csrf();
    if (input_string($_POST, 'action', 50) !== 'buy_again') {
        abort(400, t('shop.order.unknown_action'));
    }
    if (!order_can_be_bought_again($order)) {
        // An unpaid order's books are still in the cart; adding them again would double them.
        flash('error', t('shop.order.buy_again_unpaid', ['id' => $id]));
        redirect($orderUrl);
    }
    $result = buy_again($userId, $id);
    if ($result === null) {
        abort(404, t('shop.order.not_found'));
    }
    if ($result['added'] > 0) {
        flash('success', tn('shop.order.buy_again_added', $result['added'], ['id' => $id]));
    }
    if ($result['unavailable'] !== []) {
        $titles = array_map(static fn (string $title): string => t('shop.checkout.quoted_title', ['title' => bidi_isolate($title)]), $result['unavailable']);
        flash('error', tn('shop.order.buy_again_unavailable', count($titles), ['titles' => implode(t('shop.checkout.title_separator'), $titles)]));
    }
    redirect('cart.php');
}

$status = (string) $order['status'];
$currency = (string) $order['currency'];
$money = static fn (int $minor): string => format_money(minor_to_decimal($minor), $currency);
$amounts = order_vat_breakdown($order);
$state = order_display_status($order);
$progress = order_progress($order);
$reached = array_values(array_filter($progress, static fn (array $step): bool => $step['at'] !== null));
$currentStep = $reached !== [] ? $reached[count($reached) - 1]['step'] : null;
// Only paid (and later refunded) orders have a receipt; others show their summary.
$hasReceipt = $progress !== [];
// An unpaid order's books are still in the cart, so they are not offered again.
$canBuyAgain = order_can_be_bought_again($order);
$address = is_array($order['address']) ? $order['address'] : null;
$items = is_array($order['items']) ? $order['items'] : [];
$appName = (string) config('app.name', 'BookWise');
// The payment simulator takes no money, so its refunds return none.
$stepText = static fn (string $step): string => $step === 'refunded' && payment_simulator_enabled()
    ? t('shop.pay.order_refunded_text')
    : t('shop.order.progress.' . $step . '_text');

$time = static function (?string $value, bool $withTime = true): string {
    if ($value === null || $value === '') {
        return '';
    }
    $date = new DateTimeImmutable($value);
    return '<time datetime="' . e($date->format('Y-m-d\TH:i:s')) . '">' . e(display_date($date, $withTime)) . '</time>';
};

header('Cache-Control: no-store');
page_header(t('shop.order.title', ['id' => $id]), ['checkout.css', 'order.css']);
?>
        <div class="page-container order-page">
            <p class="order-back"><a href="profilePage.php?section=orders"><span aria-hidden="true" class="back-arrow">&larr;</span> <?= e(t('shop.order.back')) ?></a></p>

            <header class="order-heading">
                <h1 class="page-title"><?= e(t('shop.order.title', ['id' => $id])) ?></h1>
                <p class="order-meta">
                    <span class="status-badge status-<?= e($state) ?>"><?= e(order_status_label($state)) ?></span>
                    <span class="order-placed"><?= t_markup('shop.order.placed', ['date' => $time((string) $order['created_at'], false)]) ?></span>
                </p>
            </header>

<?php if ($canBuyAgain): ?>
            <div class="order-actions">
                <form method="post" action="<?= e($orderUrl) ?>" data-once>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="buy_again">
                    <button type="submit" class="btn btn-pay" aria-describedby="buy-again-hint"><?= e(t('shop.order.buy_again')) ?></button>
                </form>
<?php if ($hasReceipt): ?>
                <button type="button" class="btn btn-secondary" data-print data-print-title="<?= e(t('shop.order.print_title', ['id' => $id]) . ' · ' . $appName) ?>" hidden><?= e(t('shop.order.print')) ?></button>
<?php endif; ?>
                <p class="order-actions-hint" id="buy-again-hint"><?= e(t('shop.order.buy_again_hint')) ?></p>
            </div>
<?php endif; ?>

            <div class="order-layout">
                <section class="card order-card order-status" aria-labelledby="status-title">
                    <h2 id="status-title" class="order-card-title"><?= e(t('shop.order.status_heading')) ?></h2>
<?php if ($progress === []): ?>
                    <p class="order-note"><?= e(t('shop.order.unpaid.' . (in_array($status, ['pending', 'canceled', 'expired', 'failed'], true) ? $status : 'failed'))) ?></p>
<?php else: ?>
                    <ol class="order-timeline">
<?php foreach ($progress as $step):
    $done = $step['at'] !== null;
    $isCurrent = $step['step'] === $currentStep;
    ?>
                        <li class="timeline-step <?= $done ? 'is-done' : 'is-upcoming' ?> timeline-<?= e($step['step']) ?>"<?= $isCurrent ? ' aria-current="step"' : '' ?>>
                            <span class="timeline-marker" aria-hidden="true"></span>
                            <div class="timeline-body">
                                <p class="timeline-label"><?= e(t('shop.order.progress.' . $step['step'])) ?><?php if (!$done): ?> <span class="timeline-upcoming">(<?= e(t('shop.order.progress.upcoming')) ?>)</span><?php endif; ?></p>
<?php if ($done): ?>
                                <p class="timeline-date"><?= $time($step['at']) ?></p>
                                <p class="timeline-text"><?= e($stepText($step['step'])) ?></p>
<?php endif; ?>
<?php if ($step['step'] === 'shipped' && $done && is_string($order['carrier'] ?? null) && $order['carrier'] !== ''): ?>
                                <p class="timeline-text"><?= t_markup('shop.order.progress.carrier', ['carrier' => '<bdi dir="auto">' . e($order['carrier']) . '</bdi>']) ?></p>
<?php endif; ?>
<?php if ($step['step'] === 'shipped' && $done && is_string($order['tracking_number'] ?? null) && $order['tracking_number'] !== ''): ?>
                                <p class="timeline-text"><?= t_markup('shop.order.progress.tracking', ['number' => '<span class="tracking-number" dir="ltr">' . e($order['tracking_number']) . '</span>']) ?></p>
<?php endif; ?>
                            </div>
                        </li>
<?php endforeach; ?>
                    </ol>
<?php if ($status === 'paid' && !in_array($order['fulfilment_status'], FULFILMENT_STATUSES, true)): ?>
                    <p class="order-note"><?= e(t('shop.order.untracked')) ?></p>
<?php endif; ?>
<?php endif; ?>
                </section>

                <section class="card order-card order-delivery" aria-labelledby="address-title">
                    <h2 id="address-title" class="order-card-title"><?= e(t('shop.order.address_heading')) ?></h2>
<?php if ($address !== null): ?>
                    <p class="address-text"><?= address_html($address) ?></p>
<?php else: ?>
                    <p class="order-note"><?= e(t('shop.order.no_address')) ?></p>
<?php endif; ?>
                </section>

                <section class="card order-card order-receipt<?= $hasReceipt ? '' : ' order-summary' ?>" aria-labelledby="receipt-title">
                    <h2 id="receipt-title" class="order-card-title"><?= e(t($hasReceipt ? 'shop.order.receipt_heading' : 'shop.order.summary_heading')) ?></h2>
                    <p class="receipt-store"><?= e($appName) ?></p>
                    <dl class="receipt-facts">
                        <dt><?= e(t('shop.order.receipt_number')) ?></dt>
                        <dd>#<?= (int) $id ?></dd>
                        <dt><?= e(t('shop.order.receipt_placed')) ?></dt>
                        <dd><?= $time((string) $order['created_at']) ?></dd>
<?php if (!empty($order['paid_at'])): ?>
                        <dt><?= e(t('shop.order.receipt_paid')) ?></dt>
                        <dd><?= $time((string) $order['paid_at']) ?></dd>
<?php endif; ?>
<?php if (!empty($order['refunded_at'])): ?>
                        <dt><?= e(t('shop.order.receipt_refunded')) ?></dt>
                        <dd><?= $time((string) $order['refunded_at']) ?></dd>
<?php endif; ?>
                        <dt><?= e(t('shop.order.receipt_status')) ?></dt>
                        <dd><?= e(order_status_label($state)) ?></dd>
                    </dl>

                    <table class="order-table receipt-table">
                        <caption class="sr-only"><?= e(t('shop.order.items_caption', ['id' => $id])) ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?= e(t('shop.order.col_book')) ?></th>
                                <th scope="col" class="num"><?= e(t('shop.order.col_qty')) ?></th>
                                <th scope="col" class="num"><?= e(t('shop.order.col_price')) ?></th>
                                <th scope="col" class="num"><?= e(t('shop.order.col_total')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
<?php foreach ($items as $item):
    $quantity = (int) $item['quantity'];
    $unitMinor = money_to_minor((string) $item['unit_price']);
    $cover = $item['book_id'] !== null ? image_url(is_string($item['cover_image']) ? $item['cover_image'] : null, '') : '';
    ?>
                            <tr>
                                <td>
                                    <span class="receipt-book">
<?php if ($cover !== ''): ?>
                                        <?= cover_picture($cover, '40px', ['class' => 'receipt-cover', 'width' => 40, 'height' => 57, 'loading' => 'lazy']) ?>
<?php else: ?>
                                        <span class="receipt-cover receipt-cover-placeholder" aria-hidden="true"></span>
<?php endif; ?>
<?php if ($item['book_id'] !== null): ?>
                                        <a class="order-book" href="Selected.php?id=<?= (int) $item['book_id'] ?>" dir="auto"><?= e($item['title']) ?></a>
<?php else: ?>
                                        <span class="order-book" dir="auto"><?= e($item['title']) ?></span>
<?php endif; ?>
                                    </span>
                                </td>
                                <td class="num"><?= $quantity ?></td>
                                <td class="num"><?= e($money($unitMinor)) ?></td>
                                <td class="num"><?= e($money($unitMinor * $quantity)) ?></td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="row" colspan="3"><?= e(t('shop.totals.net')) ?></th>
                                <td class="num"><?= e($money($amounts['net'])) ?></td>
                            </tr>
                            <tr>
                                <th scope="row" colspan="3"><?= e(t('shop.totals.vat', ['rate' => VAT_RATE_PERCENT])) ?></th>
                                <td class="num"><?= e($money($amounts['vat'])) ?></td>
                            </tr>
                            <tr>
                                <th scope="row" colspan="3"><?= e(t('shop.totals.delivery')) ?></th>
                                <td class="num"><?= e(t('shop.totals.free')) ?></td>
                            </tr>
                            <tr class="receipt-total">
                                <th scope="row" colspan="3"><?= e(t('shop.totals.total')) ?></th>
                                <td class="num"><?= e($money($amounts['total'])) ?></td>
                            </tr>
                        </tfoot>
                    </table>
<?php if ($hasReceipt): ?>
                    <p class="receipt-note"><?= e(t('shop.order.receipt_note', ['rate' => VAT_RATE_PERCENT])) ?></p>
<?php endif; ?>
                </section>
            </div>
        </div>
<?php
page_footer(['order.js']);
