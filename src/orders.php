<?php

declare(strict_types=1);

/**
 * Orders and Stripe Checkout.
 *
 * Flow:
 *  1. start_checkout() snapshots the cart into a `pending` order (trusted DB
 *     prices) and creates a Stripe Checkout Session for exactly that amount.
 *  2. The customer pays on Stripe's hosted page.
 *  3. The order is marked `paid` only by fulfill_order(), using the session
 *     as retrieved from Stripe's API: when the customer returns to
 *     success_pay.php, or when a signature-verified webhook notifies us.
 *     Visiting success_pay.php without a genuinely paid session changes nothing.
 *  4. fulfill_order() locks the order row, so the success page and webhook can
 *     race safely: sales rows and cart removal happen exactly once.
 */

/** A checkout problem whose message is safe to show to the customer. */
final class CheckoutException extends RuntimeException
{
}

const CHECKOUT_SESSION_TTL_SECONDS = 3600; // Stripe allows 30 minutes to 24 hours.
const ORDER_STATUSES = ['pending', 'paid', 'canceled', 'expired', 'failed'];
/**
 * Smallest order total sent to Stripe, in minor units. Stripe rejects charges
 * below roughly USD 0.50; for SAR the minimum is 2.00.
 */
const CHECKOUT_MIN_TOTAL_MINOR = 200;
/**
 * Amounts are converted with two decimal places, so currencies Stripe treats
 * as zero- or three-decimal would be charged the wrong amount.
 */
const NON_TWO_DECIMAL_CURRENCIES = [
    'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
    'bhd', 'jod', 'kwd', 'omr', 'tnd',
];

function stripe_client(): \Stripe\StripeClient
{
    static $client = null;
    if ($client instanceof \Stripe\StripeClient) {
        return $client;
    }

    $key = config('stripe.secret_key');
    if (!is_string($key) || $key === '') {
        throw new CheckoutException('Online payments are not configured yet. Please try again later.');
    }
    // Guard against accidentally charging real cards from a development setup:
    // outside production only test-mode secret or restricted keys are accepted.
    if (config('app.env') !== 'production' && !preg_match('/^(sk|rk)_test_/', $key)) {
        app_log('error', 'Refusing to use a non-test Stripe key outside APP_ENV=production');
        throw new CheckoutException('Online payments are not available in this environment.');
    }
    $currency = (string) config('stripe.currency');
    if (!preg_match('/^[a-z]{3}$/D', $currency) || in_array($currency, NON_TWO_DECIMAL_CURRENCIES, true)) {
        app_log('error', 'STRIPE_CURRENCY must be a three-letter code of a two-decimal currency', ['currency' => $currency]);
        throw new CheckoutException('Online payments are not configured correctly. Please try again later.');
    }

    $options = ['api_key' => $key];
    $apiBase = config('stripe.api_base');
    if (is_string($apiBase) && $apiBase !== '') {
        $options['api_base'] = rtrim($apiBase, '/');
    }
    \Stripe\Stripe::setMaxNetworkRetries(2);

    return $client = new \Stripe\StripeClient($options);
}

/* ---------------------------------------------------------------------
 * Order records
 * ------------------------------------------------------------------- */

/**
 * Snapshots cart lines into a new pending order.
 *
 * @param list<array{book_id: int, title: string, price: string, quantity: int, line_minor: int}> $lines
 * @return array<string, mixed> The order row
 */
function create_pending_order(int $userId, array $lines): array
{
    if ($lines === []) {
        throw new CheckoutException('Your cart is empty.');
    }
    foreach ($lines as $line) {
        if ($line['quantity'] < 1 || $line['quantity'] > CART_MAX_QUANTITY) {
            throw new CheckoutException('Please choose a quantity between 1 and ' . CART_MAX_QUANTITY . ' for each book.');
        }
    }
    $amountMinor = cart_total_minor($lines);
    if ($amountMinor < CHECKOUT_MIN_TOTAL_MINOR) {
        throw new CheckoutException('The minimum order total is ' . format_minor(CHECKOUT_MIN_TOTAL_MINOR) . '. Please add another book.');
    }
    $currency = (string) config('stripe.currency');

    return db_transaction(static function () use ($userId, $lines, $amountMinor, $currency): array {
        db_run(
            "INSERT INTO orders (user_id, status, currency, total_amount, amount_minor) VALUES (?, 'pending', ?, ?, ?)",
            [$userId, $currency, minor_to_decimal($amountMinor), $amountMinor]
        );
        $orderId = (int) db()->lastInsertId();
        foreach ($lines as $line) {
            db_run(
                'INSERT INTO order_items (order_id, book_id, title, unit_price, quantity) VALUES (?, ?, ?, ?, ?)',
                [$orderId, $line['book_id'], $line['title'], $line['price'], $line['quantity']]
            );
        }
        return (array) db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    });
}

/**
 * @return array<string, mixed>|null
 */
function find_order(int $orderId): ?array
{
    return db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
}

/**
 * @return array<string, mixed>|null
 */
function find_user_order(int $orderId, int $userId): ?array
{
    return db_one('SELECT * FROM orders WHERE id = ? AND user_id = ?', [$orderId, $userId]);
}

/**
 * @return list<array<string, mixed>>
 */
function order_items(int $orderId): array
{
    return db_all(
        'SELECT book_id, title, unit_price, quantity FROM order_items WHERE order_id = ? ORDER BY id',
        [$orderId]
    );
}

/**
 * A customer's orders, newest first, each with its items.
 *
 * @return list<array<string, mixed>>
 */
function user_orders(int $userId, int $limit = 20): array
{
    $orders = db_all(
        'SELECT id, status, currency, total_amount, created_at, paid_at FROM orders
         WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min($limit, 100)),
        [$userId]
    );
    foreach ($orders as &$order) {
        $order['items'] = order_items((int) $order['id']);
    }
    return $orders;
}

/**
 * Recent orders for the admin dashboard.
 *
 * @return list<array<string, mixed>>
 */
function recent_orders(int $limit = 20): array
{
    return db_all(
        'SELECT o.id, o.status, o.currency, o.total_amount, o.created_at, o.paid_at,
                u.name AS customer_name, u.Eaddress AS customer_email,
                (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
         FROM orders o
         JOIN users u ON u.id = o.user_id
         ORDER BY o.created_at DESC, o.id DESC
         LIMIT ' . max(1, min($limit, 100))
    );
}

/**
 * Moves an order to $status only if it is currently in one of $from.
 *
 * @param list<string> $from
 */
function mark_order_status(int $orderId, string $status, array $from = ['pending']): bool
{
    if (!in_array($status, ORDER_STATUSES, true) || $from === []) {
        return false;
    }
    $placeholders = implode(', ', array_fill(0, count($from), '?'));
    return db_run(
        "UPDATE orders SET status = ? WHERE id = ? AND status IN ($placeholders)",
        array_merge([$status, $orderId], $from)
    )->rowCount() > 0;
}

/* ---------------------------------------------------------------------
 * Checkout
 * ------------------------------------------------------------------- */

/**
 * Starts (or resumes) checkout for the customer and returns the URL to send
 * the browser to: Stripe's hosted page, or the success page when an earlier
 * pending order turns out to have been paid already.
 *
 * @param array<string, mixed> $user
 */
function start_checkout(array $user): string
{
    // One checkout at a time per customer (two tabs, double clicks, two devices),
    // so concurrent requests can never leave two payable sessions behind.
    $lock = 'bookwise-checkout-' . (int) $user['id'];
    if ((int) db_value('SELECT GET_LOCK(?, 15)', [$lock]) !== 1) {
        throw new CheckoutException('Another checkout is already in progress for your account. Please wait a moment and try again.');
    }
    try {
        return start_checkout_locked($user);
    } finally {
        db_value('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

/**
 * @param array<string, mixed> $user
 */
function start_checkout_locked(array $user): string
{
    $userId = (int) $user['id'];

    $settled = settle_pending_orders($userId);
    if ($settled['unresolved']) {
        // An earlier session might still be payable; never open a second one alongside it.
        throw new CheckoutException('We could not verify your previous checkout, so a new payment was not started. You have not been charged twice. Please try again in a few minutes.');
    }
    $lines = cart_lines($userId);
    if ($lines === []) {
        if ($settled['paid_session'] !== null) {
            return 'success_pay.php?session_id=' . rawurlencode($settled['paid_session']);
        }
        throw new CheckoutException('Your cart is empty.');
    }

    $stripe = stripe_client();
    $order = create_pending_order($userId, $lines);
    $orderId = (int) $order['id'];

    $lineItems = [];
    foreach ($lines as $line) {
        $lineItems[] = [
            'price_data' => [
                'currency' => (string) $order['currency'],
                'product_data' => ['name' => mb_substr($line['title'], 0, 250)],
                'unit_amount' => $line['unit_minor'],
            ],
            'quantity' => $line['quantity'],
        ];
    }

    $metadata = ['order_id' => (string) $orderId, 'user_id' => (string) $userId];
    try {
        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'client_reference_id' => (string) $orderId,
            'customer_email' => (string) $user['Eaddress'],
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'success_url' => absolute_url('success_pay.php') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => absolute_url('cancel_pay.php?order=' . $orderId),
            'expires_at' => time() + CHECKOUT_SESSION_TTL_SECONDS,
        ], [
            // Unique per order row (also across database resets), safe to retry.
            'idempotency_key' => 'bookwise-order-' . $orderId . '-' . substr(hash('sha256', $orderId . '|' . $order['created_at'] . '|' . $order['amount_minor']), 0, 24),
        ]);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        mark_order_status($orderId, 'failed');
        app_log('error', 'Stripe Checkout Session creation failed', [
            'order_id' => $orderId,
            'stripe_code' => $e->getStripeCode(),
            'http_status' => $e->getHttpStatus(),
            'message' => $e->getMessage(),
        ]);
        throw new CheckoutException('We could not start the payment right now. Your card has not been charged. Please try again.');
    }

    $amountTotal = isset($session->amount_total) ? (int) $session->amount_total : null;
    if (!is_string($session->url ?? null) || $session->url === '' || $amountTotal !== (int) $order['amount_minor']) {
        mark_order_status($orderId, 'failed');
        app_log('error', 'Stripe returned an unexpected Checkout Session', [
            'order_id' => $orderId,
            'amount_total' => $amountTotal,
            'expected' => (int) $order['amount_minor'],
        ]);
        throw new CheckoutException('We could not start the payment right now. Your card has not been charged. Please try again.');
    }

    $stored = db_run(
        "UPDATE orders SET stripe_session_id = ? WHERE id = ? AND status = 'pending' AND stripe_session_id IS NULL",
        [$session->id, $orderId]
    )->rowCount() > 0;
    if (!$stored) {
        // The order was settled by someone else meanwhile: never leave this session payable.
        try {
            $stripe->checkout->sessions->expire($session->id);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            app_log('error', 'Could not expire an orphaned Checkout Session', ['order_id' => $orderId, 'message' => $e->getMessage()]);
        }
        throw new CheckoutException('We could not start the payment right now. Your card has not been charged. Please try again.');
    }
    return $session->url;
}

/**
 * Resolves the customer's earlier pending orders before a new checkout so an
 * abandoned session can never be paid in addition to the new one:
 *  - sessions that were in fact paid are fulfilled (or flagged for review if
 *    the captured payment does not match the order),
 *  - sessions that are still open are expired on Stripe and marked canceled,
 *  - sessions Stripe had already expired are marked expired,
 *  - sessions Stripe does not know are marked failed (they can never be paid).
 * Anything that cannot be resolved right now (network/API errors, payments
 * still processing) is reported as unresolved so no second session is opened.
 *
 * @return array{paid_session: string|null, unresolved: bool}
 */
function settle_pending_orders(int $userId): array
{
    $result = ['paid_session' => null, 'unresolved' => false];
    $pending = db_all("SELECT id, stripe_session_id FROM orders WHERE user_id = ? AND status = 'pending'", [$userId]);

    foreach ($pending as $order) {
        $orderId = (int) $order['id'];
        $sessionId = $order['stripe_session_id'];
        if (!is_string($sessionId) || $sessionId === '') {
            // Session creation never completed, so nothing can be paid.
            mark_order_status($orderId, 'failed');
            continue;
        }

        try {
            $stripe = stripe_client();
            $session = $stripe->checkout->sessions->retrieve($sessionId);

            if (($session->payment_status ?? null) === 'paid') {
                $outcome = fulfill_order($orderId, $session);
                if ($outcome === 'paid' || $outcome === 'already_paid') {
                    $result['paid_session'] = $sessionId;
                } elseif ($outcome === 'mismatch') {
                    flag_order_for_review($orderId);
                }
                continue;
            }

            $status = $session->status ?? null;
            if ($status === 'expired') {
                mark_order_status($orderId, 'expired');
            } elseif ($status === 'open') {
                $expired = $stripe->checkout->sessions->expire($sessionId);
                if (($expired->status ?? null) === 'expired') {
                    mark_order_status($orderId, 'canceled');
                } else {
                    $result['unresolved'] = true;
                }
            } else {
                // 'complete' but not yet paid: an asynchronous payment is still processing.
                $result['unresolved'] = true;
            }
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            if ($e->getHttpStatus() === 404) {
                app_log('warning', 'Pending order references an unknown Checkout Session', ['order_id' => $orderId]);
                mark_order_status($orderId, 'failed');
            } else {
                app_log('warning', 'Could not settle pending order', ['order_id' => $orderId, 'message' => $e->getMessage()]);
                $result['unresolved'] = true;
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            app_log('warning', 'Could not settle pending order', ['order_id' => $orderId, 'message' => $e->getMessage()]);
            $result['unresolved'] = true;
        }
    }
    return $result;
}

/**
 * A payment was captured but does not match its order (amount, currency or
 * reference). Takes the order out of the pending queue and logs it so staff
 * can reconcile it in the Stripe Dashboard.
 */
function flag_order_for_review(int $orderId): void
{
    mark_order_status($orderId, 'failed', ['pending', 'canceled', 'expired']);
    app_log('error', 'MANUAL REVIEW REQUIRED: captured payment does not match order', ['order_id' => $orderId]);
}

/**
 * Marks an order paid if — and only if — the given Stripe Checkout Session
 * belongs to it and reports a completed payment of exactly the order amount.
 * Idempotent and safe under concurrency (the order row is locked).
 *
 * @return 'paid'|'already_paid'|'unpaid'|'mismatch'|'unknown_order'
 */
function fulfill_order(int $orderId, \Stripe\StripeObject $session): string
{
    return db_transaction(static function () use ($orderId, $session): string {
        $order = db_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if ($order === null) {
            return 'unknown_order';
        }
        if ($order['status'] === 'paid') {
            return 'already_paid';
        }

        $sessionOrderId = (string) ($session->client_reference_id ?? '');
        $amount = isset($session->amount_total) ? (int) $session->amount_total : -1;
        $currency = strtolower((string) ($session->currency ?? ''));
        if (
            ($session->id ?? null) !== $order['stripe_session_id']
            || $sessionOrderId !== (string) $orderId
            || $amount !== (int) $order['amount_minor']
            || $currency !== strtolower((string) $order['currency'])
        ) {
            app_log('error', 'Checkout Session does not match order; not fulfilling', [
                'order_id' => $orderId,
                'session_id' => $session->id ?? null,
                'session_amount' => $amount,
                'order_amount' => (int) $order['amount_minor'],
                'session_currency' => $currency,
            ]);
            return 'mismatch';
        }

        if (($session->payment_status ?? null) !== 'paid') {
            return 'unpaid';
        }

        if ($order['status'] !== 'pending') {
            // Money was captured for an order we had given up on; honour it.
            app_log('warning', 'Paid session received for non-pending order', ['order_id' => $orderId, 'status' => $order['status']]);
        }

        $paymentIntent = $session->payment_intent ?? null;
        if ($paymentIntent instanceof \Stripe\StripeObject) {
            $paymentIntent = $paymentIntent->id ?? null;
        }

        db_run(
            "UPDATE orders SET status = 'paid', paid_at = NOW(), stripe_payment_intent = ? WHERE id = ?",
            [is_string($paymentIntent) ? $paymentIntent : null, $orderId]
        );
        db_run(
            'INSERT INTO sales (book_id, user_id, order_id, quantity)
             SELECT oi.book_id, ?, oi.order_id, oi.quantity
             FROM order_items oi
             WHERE oi.order_id = ? AND oi.book_id IS NOT NULL',
            [$order['user_id'], $orderId]
        );
        // Remove the paid copies from the cart, keeping any copies added after checkout started.
        db_run(
            'DELETE c FROM cart c
             JOIN order_items oi ON oi.book_id = c.book_id AND oi.order_id = ?
             WHERE c.user_id = ? AND c.quantity <= oi.quantity',
            [$orderId, $order['user_id']]
        );
        db_run(
            'UPDATE cart c
             JOIN order_items oi ON oi.book_id = c.book_id AND oi.order_id = ?
             SET c.quantity = c.quantity - oi.quantity
             WHERE c.user_id = ? AND c.quantity > oi.quantity',
            [$orderId, $order['user_id']]
        );

        app_log('info', 'Order paid', ['order_id' => $orderId]);
        return 'paid';
    });
}

/**
 * Handles the customer's return from Stripe. Never trusts the redirect alone:
 * the session is fetched from Stripe and checked against the stored order.
 *
 * @return array{state: 'paid'|'processing'|'unpaid'|'review'|'invalid'|'error', order: array<string, mixed>|null}
 */
function confirm_checkout_return(int $userId, string $sessionId): array
{
    if (!preg_match('/^cs_[A-Za-z0-9_]{1,250}$/D', $sessionId)) {
        return ['state' => 'invalid', 'order' => null];
    }
    $order = db_one('SELECT * FROM orders WHERE stripe_session_id = ? AND user_id = ?', [$sessionId, $userId]);
    if ($order === null) {
        return ['state' => 'invalid', 'order' => null];
    }
    if ($order['status'] === 'paid') {
        return ['state' => 'paid', 'order' => $order];
    }

    try {
        $session = stripe_client()->checkout->sessions->retrieve($sessionId);
    } catch (\Stripe\Exception\ApiErrorException | CheckoutException $e) {
        app_log('error', 'Could not retrieve Checkout Session on return', ['order_id' => $order['id'], 'message' => $e->getMessage()]);
        return ['state' => 'error', 'order' => $order];
    }

    $outcome = fulfill_order((int) $order['id'], $session);
    if ($outcome === 'mismatch' && ($session->payment_status ?? null) === 'paid') {
        flag_order_for_review((int) $order['id']);
    }
    $order = find_order((int) $order['id']);

    return match ($outcome) {
        'paid', 'already_paid' => ['state' => 'paid', 'order' => $order],
        'unpaid' => ['state' => ($session->status ?? null) === 'complete' ? 'processing' : 'unpaid', 'order' => $order],
        'mismatch' => ['state' => 'review', 'order' => $order],
        default => ['state' => 'invalid', 'order' => null],
    };
}

/* ---------------------------------------------------------------------
 * Webhooks
 * ------------------------------------------------------------------- */

/**
 * Verifies and processes a Stripe webhook. Returns the HTTP status to send.
 */
function handle_stripe_webhook(string $payload, string $signatureHeader): int
{
    $secret = config('stripe.webhook_secret');
    if (!is_string($secret) || $secret === '') {
        app_log('error', 'Stripe webhook received but STRIPE_WEBHOOK_SECRET is not set');
        return 503;
    }

    try {
        $event = \Stripe\Webhook::constructEvent($payload, $signatureHeader, $secret);
    } catch (\UnexpectedValueException | \Stripe\Exception\SignatureVerificationException $e) {
        app_log('warning', 'Rejected Stripe webhook: ' . $e->getMessage());
        return 400;
    }

    $session = $event->data->object ?? null;
    if (!$session instanceof \Stripe\StripeObject || !str_starts_with($event->type, 'checkout.session.')) {
        return 200; // Not an event this application uses.
    }

    $orderId = parse_int($session->client_reference_id ?? null);
    if ($orderId === null) {
        app_log('warning', 'Webhook session without a BookWise order reference', ['event' => $event->id]);
        return 200;
    }
    $order = find_order($orderId);
    if ($order === null || $order['stripe_session_id'] !== ($session->id ?? null)) {
        app_log('warning', 'Webhook session does not match a known order', ['event' => $event->id, 'order_id' => $orderId]);
        return 200;
    }
    if ($order['status'] === 'paid') {
        return 200; // Final state: retries and late events change nothing.
    }

    // Treat the event only as a notification: act on the session's current state
    // as reported by the Stripe API. A leaked webhook secret therefore cannot mark
    // an unpaid order paid, and out-of-order deliveries are harmless.
    try {
        $current = stripe_client()->checkout->sessions->retrieve((string) $session->id);
    } catch (\Stripe\Exception\InvalidRequestException $e) {
        app_log('warning', 'Webhook refers to a Checkout Session Stripe does not know', ['event' => $event->id, 'order_id' => $orderId]);
        return 200;
    } catch (\Stripe\Exception\ApiErrorException | CheckoutException $e) {
        app_log('error', 'Could not retrieve Checkout Session for webhook; Stripe will retry', ['event' => $event->id, 'message' => $e->getMessage()]);
        return 503;
    }

    if (($current->payment_status ?? null) === 'paid') {
        if (fulfill_order($orderId, $current) === 'mismatch') {
            flag_order_for_review($orderId);
        }
    } elseif (($current->status ?? null) === 'expired') {
        mark_order_status($orderId, 'expired');
    } elseif ($event->type === 'checkout.session.async_payment_failed' && ($current->status ?? null) === 'complete') {
        mark_order_status($orderId, 'failed');
    }
    return 200;
}
