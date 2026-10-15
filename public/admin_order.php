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
    // Read-only administrators may look but never change anything (refunds and every other action).
    require_admin_write();
    verify_csrf();
    $action = input_string($_POST, 'action', 50);

    if ($action === 'ship') {
        // ship_order() only moves a paid order from processing to shipped.
        [$shipment, $errors] = validate_shipment_input($_POST);
        if ($errors !== []) {
            remember_input($shipment);
            remember_errors($errors);
            flash('error', t('admin.fulfilment.not_saved'));
            redirect($orderUrl . '#fulfilment');
        }
        $shipped = ship_order($id, $shipment['carrier'], $shipment['tracking_number']);
        flash($shipped ? 'success' : 'info', t($shipped ? 'admin.fulfilment.shipped' : 'admin.fulfilment.not_processing', ['id' => $id]));
        redirect($orderUrl . '#fulfilment');
    }

    if ($action === 'deliver') {
        if (deliver_order($id)) {
            flash('success', t('admin.fulfilment.delivered', ['id' => $id]));
        } else {
            // Nothing changed: say why, from the order as it is now (a page out
            // of date may have been clicked after someone else's change).
            $current = find_order($id) ?? $order;
            $reason = match (true) {
                $current['status'] === 'refunded' => 'admin.fulfilment.refunded_unchanged',
                $current['fulfilment_status'] === 'delivered' => 'admin.fulfilment.already_delivered',
                $current['status'] === 'paid' && $current['fulfilment_status'] === 'processing' => 'admin.fulfilment.not_shipped',
                default => 'admin.fulfilment.not_deliverable',
            };
            flash('info', t($reason, ['id' => $id]));
        }
        redirect($orderUrl . '#fulfilment');
    }

    if ($action !== 'refund') {
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
$address = is_array($order['address']) ? $order['address'] : null;
$fulfilment = in_array($order['fulfilment_status'], FULFILMENT_STATUSES, true) ? (string) $order['fulfilment_status'] : null;
// Carrier suggestions in the admin's language; any other name may be typed, and it is stored as typed.
$carriers = translation_entry('admin.fulfilment.carriers', current_locale());
$shipmentValues = ['carrier' => old('carrier'), 'tracking_number' => old('tracking_number')];
$shipmentErrors = ['carrier' => form_error('carrier'), 'tracking_number' => form_error('tracking_number')];
$shipmentAria = static function (string $field, string $hintId) use ($shipmentErrors): string {
    $describedBy = array_filter([$hintId, $shipmentErrors[$field] !== null ? 'shipment-' . $field . '-error' : null]);
    return ($shipmentErrors[$field] !== null ? ' aria-invalid="true"' : '') . ' aria-describedby="' . e(implode(' ', $describedBy)) . '"';
};
// Read-only administrators see the actions switched off and the customer's details shortened.
$locked = admin_can_write() ? '' : ' disabled';
$showCustomers = admin_sees_customer_details();
// The checkout session id opens the order's payment page, so viewers see only its prefix.
$reference = static function (?string $value) use ($showCustomers): string {
    return $value === null || $value === ''
        ? '<span class="muted">' . e(t('admin.order.none')) . '</span>'
        : '<code class="stripe-ref" dir="ltr">' . e($showCustomers ? $value : masked_reference($value)) . '</code>';
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
<?php if ($order['customer_email'] !== null && $showCustomers): ?>
                    <p class="customer-name" dir="auto"><?= e($order['customer_name']) ?></p>
                    <p class="customer-email"><a href="mailto:<?= e(rawurlencode((string) $order['customer_email'])) ?>"><?= e($order['customer_email']) ?></a></p>
<?php elseif ($order['customer_email'] !== null): ?>
                    <p class="customer-name" dir="auto"><?= e(masked_name((string) $order['customer_name'])) ?></p>
                    <p class="customer-email"><?= e(masked_email((string) $order['customer_email'])) ?></p>
<?php else: ?>
                    <p class="customer-name customer-deleted"><?= e(t('admin.orders.deleted_account')) ?></p>
<?php endif; ?>

                    <h2 id="order-address-title"><?= e(t('admin.order.address')) ?></h2>
<?php if ($address !== null && !$showCustomers): ?>
                    <p class="address-text"><?= t_markup('admin.order.address_shortened', ['city' => '<bdi dir="auto">' . e((string) $address['city']) . '</bdi>']) ?></p>
<?php elseif ($address !== null): ?>
                    <p class="address-text"><?= address_html($address) ?></p>
                    <p class="address-call"><a href="tel:<?= e((string) $address['phone']) ?>"><?= t_markup('admin.order.call', ['phone' => '<span dir="ltr">' . e(format_saudi_mobile((string) $address['phone'])) . '</span>']) ?></a></p>
<?php else: ?>
                    <p class="muted"><?= e(t('admin.order.no_address')) ?></p>
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

                <section class="admin-content order-fulfilment" id="fulfilment" aria-labelledby="order-fulfilment-title">
                    <h2 id="order-fulfilment-title"><?= e(t('admin.fulfilment.title')) ?></h2>
<?php if ($fulfilment !== null): ?>
                    <dl class="fulfilment-facts">
                        <dt><?= e(t('admin.fulfilment.status')) ?></dt>
                        <dd><span class="fulfilment-badge status-<?= e($fulfilment) ?>"><?= e(order_status_label($fulfilment)) ?></span></dd>
                        <dt><?= e(t('admin.fulfilment.shipped_at')) ?></dt>
                        <dd><?= $timestamp($order['shipped_at']) ?></dd>
                        <dt><?= e(t('admin.fulfilment.carrier')) ?></dt>
                        <dd><?= is_string($order['carrier']) && $order['carrier'] !== '' ? '<span dir="auto">' . e($order['carrier']) . '</span>' : '<span class="muted">' . e(t('admin.order.none')) . '</span>' ?></dd>
                        <dt><?= e(t('admin.fulfilment.tracking_number')) ?></dt>
                        <dd><?= is_string($order['tracking_number']) && $order['tracking_number'] !== '' ? '<span class="tracking-number" dir="ltr">' . e($order['tracking_number']) . '</span>' : '<span class="muted">' . e(t('admin.order.none')) . '</span>' ?></dd>
                        <dt><?= e(t('admin.fulfilment.delivered_at')) ?></dt>
                        <dd><?= $timestamp($order['delivered_at']) ?></dd>
                    </dl>
<?php endif; ?>
<?php if ($status === 'paid' && $fulfilment === 'processing'): ?>
                    <p id="ship-help"><?= e(t('admin.fulfilment.ship_text')) ?></p>
                    <form class="shipment-form" method="post" action="<?= e($orderUrl) ?>" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="ship">
                        <div class="form-field">
                            <label for="shipment-carrier"><?= e(t('admin.fulfilment.carrier')) ?></label>
                            <input class="form-control" type="text" id="shipment-carrier" name="carrier" maxlength="<?= CARRIER_MAX_LENGTH ?>" list="carrier-options" autocomplete="off" dir="auto" value="<?= e($shipmentValues['carrier']) ?>"<?= $shipmentAria('carrier', 'shipment-carrier-hint') ?><?= $locked ?>>
<?php if (is_array($carriers)): ?>
                            <datalist id="carrier-options">
<?php foreach ($carriers as $carrier): ?>
                                <option value="<?= e($carrier) ?>"></option>
<?php endforeach; ?>
                            </datalist>
<?php endif; ?>
                            <p class="form-hint" id="shipment-carrier-hint"><?= e(t('admin.fulfilment.carrier_hint')) ?></p>
<?php if ($shipmentErrors['carrier'] !== null): ?>
                            <p class="field-error" id="shipment-carrier-error"><?= e($shipmentErrors['carrier']) ?></p>
<?php endif; ?>
                        </div>
                        <div class="form-field">
                            <label for="shipment-tracking_number"><?= e(t('admin.fulfilment.tracking_number')) ?></label>
                            <input class="form-control" type="text" id="shipment-tracking_number" name="tracking_number" maxlength="<?= TRACKING_NUMBER_MAX_LENGTH ?>" autocomplete="off" spellcheck="false" dir="ltr" value="<?= e($shipmentValues['tracking_number']) ?>"<?= $shipmentAria('tracking_number', 'shipment-tracking_number-hint') ?><?= $locked ?>>
                            <p class="form-hint" id="shipment-tracking_number-hint"><?= e(t('admin.fulfilment.tracking_hint')) ?></p>
<?php if ($shipmentErrors['tracking_number'] !== null): ?>
                            <p class="field-error" id="shipment-tracking_number-error"><?= e($shipmentErrors['tracking_number']) ?></p>
<?php endif; ?>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn admin-submit" aria-describedby="ship-help"<?= $locked ?>><?= e(t('admin.fulfilment.ship_button')) ?></button>
                        </div>
                    </form>
<?php elseif ($status === 'paid' && $fulfilment === 'shipped'): ?>
                    <p id="deliver-help"><?= e(t('admin.fulfilment.deliver_text')) ?></p>
                    <form method="post" action="<?= e($orderUrl) ?>" data-confirm="<?= e(t('admin.fulfilment.deliver_confirm', ['id' => (int) $order['id']])) ?>" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="deliver">
                        <button type="submit" class="btn admin-submit" aria-describedby="deliver-help"<?= $locked ?>><?= e(t('admin.fulfilment.deliver_button')) ?></button>
                    </form>
<?php elseif ($status === 'refunded'): ?>
                    <p><?= e(t('admin.fulfilment.refunded_note')) ?></p>
<?php elseif ($fulfilment === 'delivered'): ?>
                    <p><?= e(t('admin.fulfilment.delivered_note')) ?></p>
<?php elseif ($status === 'paid'): ?>
                    <p><?= e(t('admin.fulfilment.untracked_note')) ?></p>
<?php else: ?>
                    <p><?= e(t('admin.fulfilment.unpaid_note')) ?></p>
<?php endif; ?>
                </section>

                <section class="admin-content order-refund" id="refund" aria-labelledby="order-refund-title">
                    <h2 id="order-refund-title"><?= e(t('admin.order.refund_title')) ?></h2>
<?php if (order_is_refundable($order)): ?>
                    <p id="refund-help"><?= e(t(payment_simulator_enabled() ? 'shop.pay.refund_text' : 'admin.order.refund_text', ['amount' => $total])) ?> <?= e(t(order_refund_restocks($order) ? 'admin.order.refund_restock' : 'admin.order.refund_no_restock')) ?></p>
                    <form method="post" action="<?= e($orderUrl) ?>" data-confirm="<?= e(t('admin.order.refund_confirm', ['id' => (int) $order['id'], 'amount' => $total])) ?>" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="refund">
                        <button type="submit" class="btn btn-danger" aria-describedby="refund-help"<?= $locked ?>><?= e(t('admin.order.refund_button')) ?></button>
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
