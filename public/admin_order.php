<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

require_admin();

if (!in_array(request_method(), ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, POST');
    abort(405);
}

$id = parse_int($_GET['id'] ?? null);
$order = $id !== null ? admin_order_detail($id) : null;
if ($order === null) {
    abort(404, t('admin.order.not_found'));
}
$orderUrl = 'admin_order.php?id=' . $id;

if (is_post()) {
    verify_csrf();
    if (input_string($_POST, 'action', 50) !== 'refund') {
        abort(400, t('admin.order.unknown_action'));
    }
    // refund_order() re-checks the order's state; nothing from the form is trusted but the id.
    try {
        $outcome = refund_order($id);
        flash($outcome === 'refunded' ? 'success' : 'info', t($outcome === 'refunded' ? 'admin.refund.done' : 'admin.refund.already', ['id' => $id]));
    } catch (RefundException $e) {
        flash('error', $e->getMessage());
    }
    redirect($orderUrl);
}

$money = static fn (string|int|float $amount): string => format_money($amount, (string) $order['currency']);
$timestamp = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '<span class="muted">' . e(t('admin.order.none')) . '</span>';
    }
    $date = new DateTimeImmutable($value);
    return '<time datetime="' . e($date->format('Y-m-d\TH:i:s')) . '">' . e(display_date($date, true)) . '</time>';
};
$reference = static function (?string $value): string {
    return $value === null || $value === ''
        ? '<span class="muted">' . e(t('admin.order.none')) . '</span>'
        : '<code class="stripe-ref" dir="ltr">' . e($value) . '</code>';
};
$total = $money((string) $order['total_amount']);
$status = (string) $order['status'];

page_header(t('admin.order.title', ['id' => (int) $order['id']]), ['Admin_dashboard.css']);
?>
        <div class="page-container admin-page">
            <p class="admin-back"><a href="admin_orders.php"><span aria-hidden="true" class="back-arrow">&larr;</span> <?= e(t('admin.order.back')) ?></a></p>

            <header class="admin-heading">
                <h1 class="page-title"><?= e(t('admin.order.title', ['id' => (int) $order['id']])) ?></h1>
                <p class="admin-welcome"><span class="status-badge status-<?= e($status) ?>"><?= e(t('common.status.' . $status)) ?></span></p>
            </header>

            <div class="order-detail-layout">
                <section class="admin-content" aria-labelledby="order-items-title">
                    <h2 id="order-items-title"><?= e(t('admin.order.items')) ?></h2>
                    <div class="table-scroll" role="region" aria-labelledby="order-items-title" tabindex="0">
                        <table class="admin-table order-items-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?= e(t('admin.order.col_book')) ?></th>
                                    <th scope="col" class="num"><?= e(t('admin.order.col_unit')) ?></th>
                                    <th scope="col" class="num"><?= e(t('admin.order.col_qty')) ?></th>
                                    <th scope="col" class="num"><?= e(t('admin.order.col_total')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
<?php foreach ($order['items'] as $item):
    $quantity = (int) $item['quantity'];
    $lineMinor = money_to_minor((string) $item['unit_price']) * $quantity;
    ?>
                                <tr>
                                    <th scope="row">
<?php if ($item['book_id'] !== null): ?>
                                        <a href="edit_book.php?id=<?= (int) $item['book_id'] ?>" dir="auto"><?= e($item['title']) ?></a>
<?php else: ?>
                                        <span dir="auto"><?= e($item['title']) ?></span>
                                        <span class="muted item-deleted"><?= e(t('admin.order.book_deleted')) ?></span>
<?php endif; ?>
                                    </th>
                                    <td class="num"><?= e($money((string) $item['unit_price'])) ?></td>
                                    <td class="num"><?= $quantity ?></td>
                                    <td class="num"><?= e($money(minor_to_decimal($lineMinor))) ?></td>
                                </tr>
<?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th scope="row" colspan="3"><?= e(t('admin.order.total')) ?></th>
                                    <td class="num order-total"><?= e($total) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                <section class="admin-content" aria-labelledby="order-customer-title">
                    <h2 id="order-customer-title"><?= e(t('admin.order.customer')) ?></h2>
<?php if ($order['customer_email'] !== null): ?>
                    <p class="customer-name" dir="auto"><?= e($order['customer_name']) ?></p>
                    <p class="customer-email"><a href="mailto:<?= e(rawurlencode((string) $order['customer_email'])) ?>"><?= e($order['customer_email']) ?></a></p>
<?php else: ?>
                    <p class="customer-name customer-deleted"><?= e(t('admin.orders.deleted_account')) ?></p>
<?php endif; ?>

                    <h2 id="order-payment-title"><?= e(t('admin.order.payment')) ?></h2>
                    <dl class="order-facts">
                        <dt><?= e(t('admin.order.status')) ?></dt>
                        <dd><span class="status-badge status-<?= e($status) ?>"><?= e(t('common.status.' . $status)) ?></span></dd>
                        <dt><?= e(t('admin.order.created')) ?></dt>
                        <dd><?= $timestamp($order['created_at']) ?></dd>
                        <dt><?= e(t('admin.order.paid')) ?></dt>
                        <dd><?= $timestamp($order['paid_at']) ?></dd>
                        <dt><?= e(t('admin.order.refunded')) ?></dt>
                        <dd><?= $timestamp($order['refunded_at']) ?></dd>
                        <dt><?= e(t('admin.order.stripe_session')) ?></dt>
                        <dd><?= $reference($order['stripe_session_id']) ?></dd>
                        <dt><?= e(t('admin.order.payment_intent')) ?></dt>
                        <dd><?= $reference($order['stripe_payment_intent']) ?></dd>
                        <dt><?= e(t('admin.order.refund_id')) ?></dt>
                        <dd><?= $reference($order['stripe_refund_id']) ?></dd>
                    </dl>
                </section>

                <section class="admin-content order-refund" id="refund" aria-labelledby="order-refund-title">
                    <h2 id="order-refund-title"><?= e(t('admin.order.refund_title')) ?></h2>
<?php if (order_is_refundable($order)): ?>
                    <p id="refund-help"><?= e(t(payment_simulator_enabled() ? 'shop.pay.refund_text' : 'admin.order.refund_text', ['amount' => $total])) ?></p>
                    <form method="post" action="<?= e($orderUrl) ?>" data-confirm="<?= e(t('admin.order.refund_confirm', ['id' => (int) $order['id'], 'amount' => $total])) ?>" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="refund">
                        <button type="submit" class="btn btn-danger" aria-describedby="refund-help"><?= e(t('admin.order.refund_button')) ?></button>
                    </form>
<?php elseif ($status === 'refunded'): ?>
                    <p><?= e(t('admin.order.refunded_note')) ?></p>
<?php else: ?>
                    <p><?= e(t('admin.order.not_refundable')) ?></p>
<?php endif; ?>
                </section>
            </div>
        </div>
<?php
page_footer();
