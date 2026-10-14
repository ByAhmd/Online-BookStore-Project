<?php

declare(strict_types=1);

/**
 * What happens to an order after it is paid: delivery (fulfilment), the
 * customer's order page, "Buy again" and the order emails.
 *
 * Fulfilment is separate from the payment status. fulfill_order() (see
 * src/orders.php) starts every paid order that has a delivery address at
 * 'processing'; staff then mark it 'shipped' (optionally with the carrier
 * and tracking number) and 'delivered' on admin_order.php, one step at a
 * time. Refunds work at any stage; a refunded order keeps the stage it had
 * reached but shows as refunded. Delivery is free and only within Saudi
 * Arabia, and prices include 15% VAT.
 *
 * The customer gets one email for each event (order confirmation, shipped,
 * delivered, refunded), sent by the request that made the change once the
 * change is committed. Each change happens exactly once, so each email is
 * sent once, even when the payment page and a webhook confirm the same
 * payment at the same moment.
 */

/** Delivery stages of a paid order, in order. */
const FULFILMENT_STATUSES = ['processing', 'shipped', 'delivered'];
/** VAT included in every price (Saudi Arabia). Prices are shown and charged VAT-inclusive. */
const VAT_RATE_PERCENT = 15;
const CARRIER_MAX_LENGTH = 60;
const TRACKING_NUMBER_MAX_LENGTH = 40;
/** Suggestions for the carrier field on the admin order page; any other name may be typed. */
const CARRIER_SUGGESTIONS = ['SMSA Express', 'Aramex', 'SPL (Saudi Post)', 'Naqel Express', 'J&T Express', 'DHL Express'];
/** The emails a customer gets about an order. */
const ORDER_EMAIL_KINDS = ['confirmation', 'shipped', 'delivered', 'refunded'];

/* ---------------------------------------------------------------------
 * Amounts and states for display
 * ------------------------------------------------------------------- */

/**
 * The VAT contained in a VAT-inclusive amount (total x 15/115), in minor
 * units, rounded half up.
 */
function vat_included_minor(int $totalMinor): int
{
    $gross = 100 + VAT_RATE_PERCENT;
    return intdiv(2 * $totalMinor * VAT_RATE_PERCENT + $gross, 2 * $gross);
}

/**
 * An order's total split for receipts: the total, the VAT it contains and
 * the amount before VAT, in minor units.
 *
 * @param array<string, mixed> $order
 * @return array{total: int, vat: int, net: int}
 */
function order_vat_breakdown(array $order): array
{
    $total = (int) $order['amount_minor'];
    $vat = vat_included_minor($total);
    return ['total' => $total, 'vat' => $vat, 'net' => $total - $vat];
}

/**
 * The single state a customer sees for an order: the payment status, or for
 * a paid order whose delivery is tracked its delivery stage.
 *
 * @param array<string, mixed> $order
 */
function order_display_status(array $order): string
{
    $status = (string) $order['status'];
    $fulfilment = $order['fulfilment_status'] ?? null;
    return $status === 'paid' && in_array($fulfilment, FULFILMENT_STATUSES, true) ? (string) $fulfilment : $status;
}

/**
 * Label of a payment status (common.status.*) or a delivery stage
 * (shop.fulfilment.status.*).
 */
function order_status_label(string $state): string
{
    return in_array($state, FULFILMENT_STATUSES, true) ? t('shop.fulfilment.status.' . $state) : t('common.status.' . $state);
}

/**
 * The progress of a paid or refunded order, step by step: 'paid', then for
 * a tracked delivery 'processing', 'shipped' and 'delivered', and
 * 'refunded' last for a refunded order. 'at' is when the step was reached,
 * or null for a step still ahead. A refunded order leaves out the steps it
 * never reached. Unpaid orders have no steps.
 *
 * @param array<string, mixed> $order
 * @return list<array{step: string, at: string|null}>
 */
function order_progress(array $order): array
{
    $status = (string) $order['status'];
    if ($status !== 'paid' && $status !== 'refunded') {
        return [];
    }
    $at = static function (string $column) use ($order): ?string {
        $value = $order[$column] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    };

    $steps = [['step' => 'paid', 'at' => $at('paid_at')]];
    if (in_array($order['fulfilment_status'] ?? null, FULFILMENT_STATUSES, true)) {
        $steps[] = ['step' => 'processing', 'at' => $at('paid_at')];
        $steps[] = ['step' => 'shipped', 'at' => $at('shipped_at')];
        $steps[] = ['step' => 'delivered', 'at' => $at('delivered_at')];
    }
    if ($status === 'refunded') {
        $steps = array_values(array_filter($steps, static fn (array $step): bool => $step['at'] !== null));
        $steps[] = ['step' => 'refunded', 'at' => $at('refunded_at')];
    }
    return $steps;
}

/* ---------------------------------------------------------------------
 * Orders as their customers see them
 * ------------------------------------------------------------------- */

/**
 * The delivery address copied into an order at checkout, or null (orders
 * placed before deliveries were tracked, or whose copy was deleted with the
 * customer's account).
 *
 * @return array<string, mixed>|null
 */
function order_address(int $orderId): ?array
{
    return db_one('SELECT ' . implode(', ', ADDRESS_FIELDS) . ' FROM order_addresses WHERE order_id = ?', [$orderId]);
}

/**
 * One of the customer's own orders for its page (order.php): the order row
 * with its items ('items', with the book's author and current cover while
 * the book is in the catalog) and its delivery address ('address'). Null for
 * every other order.
 *
 * @return array<string, mixed>|null
 */
function customer_order(int $orderId, int $userId): ?array
{
    $order = find_user_order($orderId, $userId);
    if ($order === null) {
        return null;
    }
    $order['items'] = db_all(
        'SELECT oi.book_id, oi.title, oi.unit_price, oi.quantity, b.author, b.cover_image
         FROM order_items oi
         LEFT JOIN books b ON b.id = oi.book_id
         WHERE oi.order_id = ?
         ORDER BY oi.id',
        [$orderId]
    );
    $order['address'] = order_address($orderId);
    return $order;
}

/**
 * "Buy again": puts the books of one of the customer's orders back into the
 * cart, as many copies as were ordered, within the stock and the per-title
 * limit like any other addition. 'added' counts the books now in the cart;
 * 'unavailable' lists the titles that are sold out or no longer in the
 * catalog. Null when the order is not the customer's.
 *
 * @return array{added: int, unavailable: list<string>}|null
 */
function buy_again(int $userId, int $orderId): ?array
{
    if (find_user_order($orderId, $userId) === null) {
        return null;
    }
    $added = 0;
    $unavailable = [];
    foreach (order_items($orderId) as $item) {
        $result = $item['book_id'] !== null
            ? cart_add_detailed($userId, (int) $item['book_id'], (int) $item['quantity'])
            : ['status' => 'missing'];
        if (in_array($result['status'], ['added', 'capped', 'at_limit'], true)) {
            $added++;
        } else {
            $unavailable[] = (string) $item['title'];
        }
    }
    return ['added' => $added, 'unavailable' => $unavailable];
}

/* ---------------------------------------------------------------------
 * Fulfilment (staff, admin_order.php)
 * ------------------------------------------------------------------- */

/**
 * Validates the optional carrier and tracking number of a shipment.
 *
 * @param array<string, mixed> $input
 * @return array{0: array{carrier: string, tracking_number: string}, 1: array<string, string>} [clean values, errors by field]
 */
function validate_shipment_input(array $input): array
{
    $values = [
        'carrier' => input_string($input, 'carrier', 200),
        'tracking_number' => (string) preg_replace('/\s+/u', ' ', ascii_digits(input_string($input, 'tracking_number', 200))),
    ];
    $errors = [];
    if (mb_strlen($values['carrier']) > CARRIER_MAX_LENGTH) {
        $errors['carrier'] = t('admin.fulfilment.carrier_too_long', ['max' => CARRIER_MAX_LENGTH]);
    } elseif (preg_match('/[\x00-\x1F\x7F<>]/u', $values['carrier'])) {
        $errors['carrier'] = t('admin.fulfilment.carrier_invalid');
    }
    if ($values['tracking_number'] !== '' && (
        strlen($values['tracking_number']) > TRACKING_NUMBER_MAX_LENGTH
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]*$/D', $values['tracking_number'])
    )) {
        $errors['tracking_number'] = t('admin.fulfilment.tracking_invalid', ['max' => TRACKING_NUMBER_MAX_LENGTH]);
    }
    return [$values, $errors];
}

/**
 * Marks a paid order that is being processed as shipped, with the optional
 * carrier and tracking number, and emails the customer. False (and nothing
 * changes) when the order is not paid or not at 'processing', for example
 * because someone else shipped it a moment ago.
 */
function ship_order(int $orderId, string $carrier = '', string $trackingNumber = ''): bool
{
    $shipped = db_run(
        "UPDATE orders SET fulfilment_status = 'shipped', shipped_at = NOW(), carrier = ?, tracking_number = ?
         WHERE id = ? AND status = 'paid' AND fulfilment_status = 'processing'",
        [$carrier !== '' ? $carrier : null, $trackingNumber !== '' ? $trackingNumber : null, $orderId]
    )->rowCount() > 0;
    if ($shipped) {
        app_log('info', 'Order shipped', ['order_id' => $orderId]);
        send_order_email($orderId, 'shipped');
    }
    return $shipped;
}

/**
 * Marks a paid, shipped order as delivered and emails the customer. False
 * (and nothing changes) when the order is not paid or not at 'shipped'.
 */
function deliver_order(int $orderId): bool
{
    $delivered = db_transaction(static function () use ($orderId): bool {
        $changed = db_run(
            "UPDATE orders SET fulfilment_status = 'delivered', delivered_at = NOW()
             WHERE id = ? AND status = 'paid' AND fulfilment_status = 'shipped'",
            [$orderId]
        )->rowCount() > 0;
        if ($changed) {
            forget_anonymous_order_address($orderId);
        }
        return $changed;
    });
    if ($delivered) {
        app_log('info', 'Order delivered', ['order_id' => $orderId]);
        send_order_email($orderId, 'delivered');
    }
    return $delivered;
}

/**
 * Account deletion (inside its transaction, before the account goes): the
 * delivery addresses of the customer's orders are deleted, except those of
 * orders still on their way (paid and not delivered yet), which are needed
 * to deliver them and go once they are delivered or refunded.
 */
function forget_order_addresses_of_customer(int $userId): void
{
    db_run(
        "DELETE oa FROM order_addresses oa
         JOIN orders o ON o.id = oa.order_id
         WHERE o.user_id = ?
           AND NOT (o.status = 'paid' AND o.fulfilment_status IN ('processing', 'shipped'))",
        [$userId]
    );
}

/**
 * Deletes the delivery address of an order whose customer deleted their
 * account, once the order no longer needs it (delivered or refunded).
 */
function forget_anonymous_order_address(int $orderId): void
{
    db_run(
        'DELETE oa FROM order_addresses oa
         JOIN orders o ON o.id = oa.order_id
         WHERE oa.order_id = ? AND o.user_id IS NULL',
        [$orderId]
    );
}

/* ---------------------------------------------------------------------
 * Order emails
 * ------------------------------------------------------------------- */

/**
 * Emails the customer about their order in the account's language:
 * 'confirmation' (the payment was confirmed), 'shipped', 'delivered' or
 * 'refunded'. Called by fulfill_order(), ship_order(), deliver_order() and
 * apply_order_refund() after their change is committed; each of them makes
 * its change once, so each email goes out once. Orders of deleted accounts
 * have nobody to write to. The customer's name and the delivery address are
 * included only when the account's email address is confirmed: an
 * unconfirmed account may have been created with someone else's address,
 * and its text must not reach that person in an email from the store.
 * Returns true when the email was sent. The change it reports is already
 * committed, so a failure here is logged and never reaches the caller.
 */
function send_order_email(int $orderId, string $kind): bool
{
    if (!in_array($kind, ORDER_EMAIL_KINDS, true)) {
        return false;
    }
    try {
        $order = find_order($orderId);
        if ($order === null || $order['user_id'] === null) {
            return false;
        }
        $account = find_user_account((int) $order['user_id']);
        if ($account === null) {
            return false;
        }
        $confirmed = $account['email_verified_at'] !== null;
        $name = $confirmed ? $account['name'] : '';
        $items = order_items($orderId);
        $address = $confirmed ? order_address($orderId) : null;
        $link = absolute_url('order.php?id=' . $orderId);

        $sent = send_localized_mail(
            $account['Eaddress'],
            $name,
            $account['locale'],
            static fn (): array => order_email_content($kind, $order, $items, $address, $name, $link)
        );
    } catch (Throwable $e) {
        app_log('error', 'Order email failed: ' . $e->getMessage(), ['order_id' => $orderId, 'kind' => $kind]);
        return false;
    }
    if (!$sent) {
        app_log('warning', 'Order email could not be sent', ['order_id' => $orderId, 'kind' => $kind]);
    }
    return $sent;
}

/**
 * Subject, HTML and text of an order email in the active language.
 *
 * @param array<string, mixed> $order
 * @param list<array<string, mixed>> $items
 * @param array<string, mixed>|null $address Shown when not null
 * @return array{0: string, 1: string, 2: string}
 */
function order_email_content(string $kind, array $order, array $items, ?array $address, string $name, string $link): array
{
    $id = (int) $order['id'];
    $money = static fn (int $minor): string => format_money(minor_to_decimal($minor), (string) $order['currency']);
    $amounts = order_vat_breakdown($order);

    // Blocks: a paragraph (string), or a heading (may be '') with lines.
    $blocks = [];
    switch ($kind) {
        case 'confirmation':
            $blocks[] = t('shop.mail.confirmation.body', ['id' => $id]);
            $lines = [];
            foreach ($items as $item) {
                $lines[] = t('shop.mail.item', [
                    'quantity' => (int) $item['quantity'],
                    'title' => (string) $item['title'],
                    'amount' => $money(money_to_minor((string) $item['unit_price']) * (int) $item['quantity']),
                ]);
            }
            $blocks[] = [t('shop.mail.items_heading'), $lines];
            $blocks[] = ['', [
                t('shop.mail.delivery_free'),
                t('shop.mail.total', ['amount' => $money($amounts['total'])]),
                t('shop.mail.vat', ['rate' => VAT_RATE_PERCENT, 'amount' => $money($amounts['vat'])]),
            ]];
            if ($address !== null) {
                $blocks[] = [t('shop.mail.address_heading'), address_lines($address)];
            }
            $blocks[] = t('shop.mail.confirmation.next');
            break;

        case 'shipped':
            $blocks[] = t('shop.mail.shipped.body', ['id' => $id]);
            $tracking = [];
            if (is_string($order['carrier'] ?? null) && $order['carrier'] !== '') {
                $tracking[] = t('shop.mail.shipped.carrier', ['carrier' => $order['carrier']]);
            }
            if (is_string($order['tracking_number'] ?? null) && $order['tracking_number'] !== '') {
                $tracking[] = t('shop.mail.shipped.tracking', ['number' => $order['tracking_number']]);
            }
            if ($tracking !== []) {
                $blocks[] = ['', $tracking];
            }
            if ($address !== null) {
                $blocks[] = [t('shop.mail.address_heading'), address_lines($address)];
            }
            break;

        case 'delivered':
            $blocks[] = t('shop.mail.delivered.body', ['id' => $id, 'date' => display_date(is_string($order['delivered_at'] ?? null) ? $order['delivered_at'] : null)]);
            $blocks[] = t('shop.mail.delivered.review');
            break;

        default: // refunded
            $blocks[] = t('shop.mail.refunded.body', ['id' => $id, 'amount' => $money($amounts['total'])]);
            $blocks[] = t('shop.mail.refunded.timing');
            break;
    }

    $greeting = $name !== '' ? t('auth.mail.greeting_name', ['name' => $name]) : t('auth.mail.greeting');
    $html = '<p>' . e($greeting) . '</p>';
    $text = $greeting . "\n\n";
    foreach ($blocks as $block) {
        if (is_string($block)) {
            $html .= '<p>' . e($block) . '</p>';
            $text .= $block . "\n\n";
            continue;
        }
        [$heading, $lines] = $block;
        $html .= '<p>' . ($heading !== '' ? '<strong>' . e($heading) . '</strong><br>' : '') . implode('<br>', array_map('e', $lines)) . '</p>';
        $text .= ($heading !== '' ? $heading . "\n" : '') . implode("\n", $lines) . "\n\n";
    }
    $html .= '<p><a href="' . e($link) . '">' . e(t('shop.mail.view_order')) . '</a></p>' . mail_html_signature();
    $text .= t('shop.mail.view_order') . ":\n" . $link . "\n\n" . mail_text_signature();

    $subject = t('shop.mail.' . $kind . '.subject', ['id' => $id, 'app' => (string) config('app.name', 'BookWise')]);
    return [$subject, mail_html_layout($html), $text];
}
