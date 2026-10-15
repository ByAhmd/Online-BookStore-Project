<?php

declare(strict_types=1);

/**
 * Orders, Stripe Checkout and refunds.
 *
 * Flow:
 *  1. start_checkout() checks the stock of every cart line, snapshots the cart
 *     and the delivery address the customer chose into a `pending` order
 *     (trusted DB prices) and creates a Stripe Checkout Session for exactly
 *     that amount.
 *  2. The customer pays on Stripe's hosted page.
 *  3. The order is marked `paid` only by fulfill_order(), using the session
 *     as retrieved from Stripe's API: when the customer returns to
 *     success_pay.php, or when a signature-verified webhook notifies us.
 *     Visiting success_pay.php without a genuinely paid session changes nothing.
 *  4. fulfill_order() locks the order row, so the success page and webhook can
 *     race safely: sales rows, the stock decrement, cart removal and the order
 *     confirmation email happen exactly once. The order's delivery then
 *     starts at `processing` (see src/fulfilment.php).
 *  5. refund_order() (admin) or a confirmed charge.refunded webhook marks a
 *     paid order `refunded` and removes its sales rows, again exactly once,
 *     at any stage of the delivery. The copies go back in stock only while
 *     they are still in the store (the order has not been shipped).
 */

/** A checkout problem whose message is safe to show to the customer. */
final class CheckoutException extends RuntimeException
{
}

/** A refund problem whose message is safe to show to staff. */
final class RefundException extends RuntimeException
{
}

const CHECKOUT_SESSION_TTL_SECONDS = 3600; // Stripe allows 30 minutes to 24 hours.
/** CheckoutException code: no delivery address was chosen (the customer goes back to the checkout page). */
const CHECKOUT_ERROR_ADDRESS = 1;
/** Demo mode: simulated purchases never take a book below this many copies. */
const DEMO_MIN_STOCK = 3;
/** Statuses mark_order_status() may set (refunds go through apply_order_refund()). */
const ORDER_STATUSES = ['pending', 'paid', 'canceled', 'expired', 'failed'];
/** Every order status, in the order the admin filter lists them. */
const ORDER_STATUS_FILTERS = ['pending', 'paid', 'refunded', 'canceled', 'expired', 'failed'];
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

/**
 * The Stripe client for this request. With the payment simulator on
 * (PAYMENT_SIMULATOR, by default in demo mode) every request is answered
 * in-process by src/payment_simulator.php and no Stripe account is needed.
 * Otherwise it talks to Stripe with STRIPE_SECRET_KEY (or to STRIPE_API_BASE,
 * the test suites' local mock). A real store that still asks for the
 * simulator gets no client at all (see payment_simulator_refused()).
 */
function stripe_client(): \Stripe\StripeClient
{
    static $client = null;
    if ($client instanceof \Stripe\StripeClient) {
        return $client;
    }

    $currency = (string) config('stripe.currency');
    if (!preg_match('/^[a-z]{3}$/D', $currency) || in_array($currency, NON_TWO_DECIMAL_CURRENCIES, true)) {
        app_log('error', 'STRIPE_CURRENCY must be a three-letter code of a two-decimal currency', ['currency' => $currency]);
        throw new CheckoutException(t('shop.checkout.misconfigured'));
    }

    if (payment_simulator_refused()) {
        app_log('error', 'Refusing the payment simulator on a real store (APP_ENV=production, APP_DEMO=false): set PAYMENT_SIMULATOR=false to take payments with Stripe');
        throw new CheckoutException(t('shop.checkout.not_available'));
    }
    if (payment_simulator_enabled()) {
        // stripe-php sends every request through one global HTTP client: the
        // simulator's answers them in-process, so nothing reaches the network.
        \Stripe\ApiRequestor::setHttpClient(new PaymentSimulatorHttpClient());
        return $client = new \Stripe\StripeClient(['api_key' => PAYMENT_SIMULATOR_API_KEY, 'api_base' => PAYMENT_SIMULATOR_API_BASE]);
    }

    $key = config('stripe.secret_key');
    if (!is_string($key) || $key === '') {
        throw new CheckoutException(t('shop.checkout.not_configured'));
    }
    // Guard against accidentally charging real cards from a development setup
    // or a public demo: there only test-mode secret or restricted keys are accepted.
    if (!preg_match('/^(sk|rk)_test_/', $key)) {
        if (config('app.env') !== 'production') {
            app_log('error', 'Refusing to use a non-test Stripe key outside APP_ENV=production');
            throw new CheckoutException(t('shop.checkout.not_available'));
        }
        if (config('app.demo')) {
            app_log('error', 'Refusing to use a non-test Stripe key in demo mode (APP_DEMO=true)');
            throw new CheckoutException(t('shop.checkout.not_available'));
        }
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
 * Snapshots cart lines and the delivery address into a new pending order.
 * The address is copied (order_addresses), so later edits or deletions of
 * the customer's saved address never change the order.
 *
 * @param list<array{book_id: int, title: string, price: string, quantity: int, line_minor: int}> $lines
 * @param array<string, mixed> $address A saved address (see src/addresses.php)
 * @return array<string, mixed> The order row
 */
function create_pending_order(int $userId, array $lines, array $address): array
{
    if ($lines === []) {
        throw new CheckoutException(t('shop.checkout.empty_cart'));
    }
    foreach ($lines as $line) {
        if ($line['quantity'] < 1 || $line['quantity'] > CART_MAX_QUANTITY) {
            throw new CheckoutException(t('shop.checkout.quantity_range', ['max' => CART_MAX_QUANTITY]));
        }
    }
    $amountMinor = cart_total_minor($lines);
    if ($amountMinor < CHECKOUT_MIN_TOTAL_MINOR) {
        throw new CheckoutException(t('shop.checkout.minimum_total', ['amount' => format_minor(CHECKOUT_MIN_TOTAL_MINOR)]));
    }
    $currency = (string) config('stripe.currency');

    return db_transaction(static function () use ($userId, $lines, $address, $amountMinor, $currency): array {
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
        db_run(
            'INSERT INTO order_addresses (order_id, ' . implode(', ', ADDRESS_FIELDS) . ')
             VALUES (?' . str_repeat(', ?', count(ADDRESS_FIELDS)) . ')',
            [$orderId, ...address_column_values($address)]
        );
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
 * A customer's orders, newest first, each with its items. Refunded orders
 * keep status 'refunded' (label: common.status.refunded) and refunded_at;
 * paid orders carry their delivery stage (see order_display_status()).
 *
 * @return list<array<string, mixed>>
 */
function user_orders(int $userId, int $limit = 20): array
{
    $orders = db_all(
        'SELECT id, status, currency, total_amount, created_at, paid_at, refunded_at, fulfilment_status, shipped_at, delivered_at FROM orders
         WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min($limit, 100)),
        [$userId]
    );
    foreach ($orders as &$order) {
        $order['items'] = order_items((int) $order['id']);
    }
    return $orders;
}

/**
 * Columns for admin order lists. customer_name and customer_email are NULL
 * when the customer has deleted their account.
 */
const ADMIN_ORDER_COLUMNS = 'o.id, o.status, o.currency, o.total_amount, o.created_at, o.paid_at, o.refunded_at, o.user_id,
                o.fulfilment_status, u.name AS customer_name, u.Eaddress AS customer_email,
                (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.id) AS item_count';

/**
 * Recent orders for the admin dashboard (including those of deleted accounts).
 *
 * @return list<array<string, mixed>>
 */
function recent_orders(int $limit = 20): array
{
    return db_all(
        'SELECT ' . ADMIN_ORDER_COLUMNS . '
         FROM orders o
         LEFT JOIN users u ON u.id = o.user_id
         ORDER BY o.created_at DESC, o.id DESC
         LIMIT ' . max(1, min($limit, 100))
    );
}

/**
 * One page of the admin order list, newest first. $status is one of
 * ORDER_STATUS_FILTERS (anything else means all); $fulfilment one of
 * FULFILMENT_STATUSES lists the paid orders at that delivery stage (refunded
 * orders are found with the status filter); $query matches an order number
 * ("42" or "#42") or part of the customer's email address. With
 * $matchEmails false (read-only administrators, who see addresses only
 * shortened) it matches the order number alone, so a search cannot tell
 * what the addresses contain.
 *
 * @return array{orders: list<array<string, mixed>>, pagination: array{page: int, pages: int, per_page: int, total: int, offset: int}}
 */
function admin_order_list(string $status, string $query, mixed $page, string $fulfilment = '', int $perPage = ADMIN_PAGE_SIZE, bool $matchEmails = true): array
{
    $where = [];
    $params = [];
    if (in_array($status, ORDER_STATUS_FILTERS, true)) {
        $where[] = 'o.status = ?';
        $params[] = $status;
    }
    if (in_array($fulfilment, FULFILMENT_STATUSES, true)) {
        $where[] = "o.status = 'paid' AND o.fulfilment_status = ?";
        $params[] = $fulfilment;
    }
    if ($query !== '' && !$matchEmails) {
        // The order number alone. Order ids start at 1, so anything else matches nothing.
        $where[] = 'o.id = ?';
        $params[] = preg_match('/^#?(\d{1,10})$/D', $query, $m) === 1 ? (int) $m[1] : 0;
    } elseif ($query !== '') {
        $like = '%' . addcslashes($query, '%_\\') . '%';
        if (preg_match('/^#?(\d{1,10})$/D', $query, $m)) {
            $where[] = '(o.id = ? OR u.Eaddress LIKE ?)';
            $params[] = (int) $m[1];
        } else {
            $where[] = 'u.Eaddress LIKE ?';
        }
        $params[] = $like;
    }
    $sqlWhere = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    $total = (int) db_value('SELECT COUNT(*) FROM orders o LEFT JOIN users u ON u.id = o.user_id' . $sqlWhere, $params);
    $pagination = paginate($total, $perPage, $page);
    $orders = db_all(
        'SELECT ' . ADMIN_ORDER_COLUMNS . '
         FROM orders o
         LEFT JOIN users u ON u.id = o.user_id' . $sqlWhere . '
         ORDER BY o.created_at DESC, o.id DESC
         LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
        $params
    );
    return ['orders' => $orders, 'pagination' => $pagination];
}

/**
 * An order with its customer (NULL name/email for deleted accounts), items
 * and delivery address ('address', null when none is stored), for the admin
 * detail page.
 *
 * @return array<string, mixed>|null
 */
function admin_order_detail(int $orderId): ?array
{
    $order = db_one(
        'SELECT o.*, u.name AS customer_name, u.Eaddress AS customer_email
         FROM orders o LEFT JOIN users u ON u.id = o.user_id
         WHERE o.id = ?',
        [$orderId]
    );
    if ($order === null) {
        return null;
    }
    $order['items'] = order_items($orderId);
    $order['address'] = order_address($orderId);
    return $order;
}

/**
 * True when staff can refund the order from the admin page.
 *
 * @param array<string, mixed> $order
 */
function order_is_refundable(array $order): bool
{
    return ($order['status'] ?? null) === 'paid'
        && is_string($order['stripe_payment_intent'] ?? null)
        && $order['stripe_payment_intent'] !== '';
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
 * $addressId is the customer's saved address the books are delivered to
 * (null when none was chosen). An address that is not the customer's, or no
 * address, is refused with a CheckoutException whose code is
 * CHECKOUT_ERROR_ADDRESS.
 *
 * @param array<string, mixed> $user
 */
function start_checkout(array $user, ?int $addressId): string
{
    // One checkout at a time per customer (two tabs, double clicks, two devices),
    // so concurrent requests can never leave two payable sessions behind.
    $lock = 'bookwise-checkout-' . (int) $user['id'];
    if ((int) db_value('SELECT GET_LOCK(?, 15)', [$lock]) !== 1) {
        throw new CheckoutException(t('shop.checkout.in_progress'));
    }
    try {
        return start_checkout_locked($user, $addressId);
    } finally {
        db_value('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

/**
 * @param array<string, mixed> $user
 */
function start_checkout_locked(array $user, ?int $addressId): string
{
    $userId = (int) $user['id'];

    $settled = settle_pending_orders($userId);
    if ($settled['unresolved']) {
        // An earlier session might still be payable; never open a second one alongside it.
        throw new CheckoutException(t('shop.checkout.unresolved'));
    }
    $lines = cart_lines($userId);
    if ($lines === []) {
        if ($settled['paid_session'] !== null) {
            return 'success_pay.php?session_id=' . rawurlencode($settled['paid_session']);
        }
        throw new CheckoutException(t('shop.checkout.empty_cart'));
    }
    // Stock is checked here, not reserved: a book that sells out while the
    // customer pays is still delivered (see fulfill_order()).
    $unavailable = cart_unavailable_lines($lines);
    if ($unavailable !== []) {
        $titles = array_map(static fn (array $line): string => t('shop.checkout.quoted_title', ['title' => $line['title']]), $unavailable);
        throw new CheckoutException(tn('shop.checkout.insufficient_stock', count($titles), [
            'titles' => implode(t('shop.checkout.title_separator'), $titles),
        ]));
    }

    $stripe = stripe_client();
    // Read under the checkout lock: the order gets the address as it is now.
    $address = $addressId !== null ? find_user_address($userId, $addressId) : null;
    if ($address === null) {
        throw new CheckoutException(t('shop.checkout.address_required'), CHECKOUT_ERROR_ADDRESS);
    }
    $order = create_pending_order($userId, $lines, $address);
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
        throw new CheckoutException(t('shop.checkout.start_failed'));
    }

    $amountTotal = isset($session->amount_total) ? (int) $session->amount_total : null;
    if (!is_string($session->url ?? null) || $session->url === '' || $amountTotal !== (int) $order['amount_minor']) {
        mark_order_status($orderId, 'failed');
        app_log('error', 'Stripe returned an unexpected Checkout Session', [
            'order_id' => $orderId,
            'amount_total' => $amountTotal,
            'expected' => (int) $order['amount_minor'],
        ]);
        throw new CheckoutException(t('shop.checkout.start_failed'));
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
        throw new CheckoutException(t('shop.checkout.start_failed'));
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
 * Records the sales, takes the copies out of stock (never below zero: the
 * customer has paid, so an oversold book is logged for staff and the order is
 * still fulfilled), removes the purchased copies from the cart and starts the
 * delivery ('processing', for orders with a delivery address).
 * Idempotent and safe under concurrency (the order row is locked): only the
 * call that returns 'paid' made the change, and only it emails the order
 * confirmation, after the change is committed.
 *
 * @return 'paid'|'already_paid'|'unpaid'|'mismatch'|'unknown_order'
 */
function fulfill_order(int $orderId, \Stripe\StripeObject $session): string
{
    $outcome = fulfill_order_locked($orderId, $session);
    if ($outcome === 'paid') {
        send_order_email($orderId, 'confirmation');
    }
    return $outcome;
}

/**
 * The bookkeeping of fulfill_order() in one transaction.
 *
 * @return 'paid'|'already_paid'|'unpaid'|'mismatch'|'unknown_order'
 */
function fulfill_order_locked(int $orderId, \Stripe\StripeObject $session): string
{
    return db_transaction(static function () use ($orderId, $session): string {
        $order = db_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if ($order === null) {
            return 'unknown_order';
        }
        if ($order['status'] === 'paid' || $order['status'] === 'refunded') {
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

        // Orders placed before delivery addresses existed have nothing to deliver to.
        $deliverable = db_value('SELECT 1 FROM order_addresses WHERE order_id = ?', [$orderId]) !== null;
        db_run(
            "UPDATE orders SET status = 'paid', paid_at = NOW(), stripe_payment_intent = ?, fulfilment_status = ? WHERE id = ?",
            [is_string($paymentIntent) ? $paymentIntent : null, $deliverable ? 'processing' : null, $orderId]
        );
        db_run(
            'INSERT INTO sales (book_id, user_id, order_id, quantity)
             SELECT oi.book_id, ?, oi.order_id, oi.quantity
             FROM order_items oi
             WHERE oi.order_id = ? AND oi.book_id IS NOT NULL',
            [$order['user_id'], $orderId]
        );

        // Take the copies out of stock. The book rows are locked first (in id
        // order) so the oversell check sees the stock the update changes.
        $stocked = db_all(
            'SELECT b.id, b.stock, oi.quantity
             FROM order_items oi
             JOIN books b ON b.id = oi.book_id
             WHERE oi.order_id = ?
             ORDER BY b.id
             FOR UPDATE',
            [$orderId]
        );
        foreach ($stocked as $row) {
            if ((int) $row['stock'] < (int) $row['quantity']) {
                app_log('warning', 'OVERSOLD: a paid order needs more copies than are in stock; restock or contact the customer', [
                    'order_id' => $orderId,
                    'book_id' => (int) $row['id'],
                    'stock' => (int) $row['stock'],
                    'quantity' => (int) $row['quantity'],
                ]);
            }
        }
        // Record what each line really takes (less than ordered when oversold),
        // so a refund later puts back exactly those copies.
        db_run(
            'UPDATE order_items oi
             JOIN books b ON b.id = oi.book_id
             SET oi.stock_taken = LEAST(b.stock, oi.quantity)
             WHERE oi.order_id = ?',
            [$orderId]
        );
        db_run(
            'UPDATE books b
             JOIN order_items oi ON oi.book_id = b.id AND oi.order_id = ?
             SET b.stock = b.stock - oi.stock_taken',
            [$orderId]
        );
        if (config('app.demo')) {
            // Public demo: visitors' free simulated purchases must not sell the
            // shared catalog out for everyone else.
            db_run(
                'UPDATE books b
                 JOIN order_items oi ON oi.book_id = b.id AND oi.order_id = ?
                 SET b.stock = GREATEST(b.stock, ?)',
                [$orderId, DEMO_MIN_STOCK]
            );
        }

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
    if ($order['status'] === 'paid' || $order['status'] === 'refunded') {
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
 * Refunds
 * ------------------------------------------------------------------- */

/**
 * Refunds a paid order in full through Stripe, then records it locally (see
 * apply_order_refund()). Safe to repeat: an order that is already refunded
 * is left alone, and the Stripe request carries an idempotency key, so a
 * double click never refunds twice.
 *
 * @return 'refunded'|'already_refunded'
 * @throws RefundException with a message that is safe to show to staff; nothing was changed
 */
function refund_order(int $orderId): string
{
    $order = find_order($orderId);
    if ($order === null) {
        throw new RefundException(t('admin.refund.not_found'));
    }
    if ($order['status'] === 'refunded') {
        return 'already_refunded';
    }
    if ($order['status'] !== 'paid') {
        throw new RefundException(t('admin.refund.not_paid'));
    }
    // While the simulator takes the payments, staff are not sent to Stripe.
    $simulated = payment_simulator_enabled();
    if (!order_is_refundable($order)) {
        throw new RefundException(t($simulated ? 'shop.pay.refund_no_payment' : 'admin.refund.no_payment'));
    }
    $paymentIntent = (string) $order['stripe_payment_intent'];

    try {
        $stripe = stripe_client();
        try {
            $refund = $stripe->refunds->create([
                'payment_intent' => $paymentIntent,
                'metadata' => ['order_id' => (string) $orderId],
            ], [
                'idempotency_key' => 'bookwise-refund-' . $orderId . '-' . substr(hash('sha256', $orderId . '|' . $paymentIntent . '|' . $order['amount_minor']), 0, 24),
            ]);
            $status = (string) ($refund->status ?? '');
            if (!in_array($status, ['succeeded', 'pending'], true)) {
                app_log('error', 'Stripe did not accept the refund', ['order_id' => $orderId, 'refund' => $refund->id ?? null, 'status' => $status]);
                throw new RefundException(t($simulated ? 'shop.pay.refund_failed' : 'admin.refund.failed'));
            }
            $refundId = (string) $refund->id;
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Refunded elsewhere already (e.g. in the Stripe Dashboard): record it if Stripe confirms it.
            $refundId = $e->getStripeCode() === 'charge_already_refunded'
                ? confirmed_full_refund($paymentIntent, (int) $order['amount_minor'], (string) $order['currency'])
                : null;
            if ($refundId === null && (config('app.demo') || $simulated) && $e->getStripeCode() === 'resource_missing') {
                // Demo or simulated payments: the payment simulator forgets payments when its
                // state file is lost (a new container, an ephemeral disk) or after 30 days.
                // No real money was ever taken, so record the refund here.
                $refundId = 're_demo_' . bin2hex(random_bytes(12));
                app_log('info', 'Demo: the simulator no longer knows this payment; refund recorded locally', ['order_id' => $orderId]);
            }
            if ($refundId === null) {
                throw $e;
            }
        }
    } catch (\Stripe\Exception\ApiErrorException | CheckoutException $e) {
        app_log('error', 'Stripe refund failed', [
            'order_id' => $orderId,
            'stripe_code' => $e instanceof \Stripe\Exception\ApiErrorException ? $e->getStripeCode() : null,
            'message' => $e->getMessage(),
        ]);
        throw new RefundException(t($simulated ? 'shop.pay.refund_failed' : 'admin.refund.failed'));
    }

    return apply_order_refund($orderId, $refundId, $paymentIntent) ? 'refunded' : 'already_refunded';
}

/**
 * Records a full refund of a paid order exactly once, at any stage of its
 * delivery: status refunded, refunded_at and the Stripe refund id, and the
 * order's sales rows removed (the books no longer count as sold or
 * reviewable through this order). Copies that are still in the store go
 * back in stock; once the order is shipped they have left it, so the stock
 * is left alone (see order_refund_restocks()). The delivery stage is read
 * under the same row lock that ship_order() waits for, so a refund and a
 * shipment at the same moment can never both count the copies. The
 * customer is emailed after the change is committed. Returns false when the
 * order is not (or no longer) a paid order with this payment intent.
 */
function apply_order_refund(int $orderId, string $refundId, string $paymentIntent): bool
{
    $applied = db_transaction(static function () use ($orderId, $refundId, $paymentIntent): bool {
        $order = db_one('SELECT id, status, stripe_payment_intent, fulfilment_status FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if ($order === null || $order['status'] !== 'paid' || $order['stripe_payment_intent'] !== $paymentIntent) {
            return false;
        }
        db_run(
            "UPDATE orders SET status = 'refunded', refunded_at = NOW(), stripe_refund_id = ? WHERE id = ?",
            [$refundId, $orderId]
        );
        db_run('DELETE FROM sales WHERE order_id = ?', [$orderId]);
        $restock = order_refund_restocks($order);
        if ($restock) {
            db_run(
                'UPDATE books b
                 JOIN order_items oi ON oi.book_id = b.id AND oi.order_id = ?
                 SET b.stock = b.stock + oi.stock_taken',
                [$orderId]
            );
        }
        // A refunded order of a deleted account no longer needs its delivery address.
        forget_anonymous_order_address($orderId);
        app_log('info', 'Order refunded', ['order_id' => $orderId, 'refund' => $refundId, 'restocked' => $restock]);
        return true;
    });
    if ($applied) {
        send_order_email($orderId, 'refunded');
    }
    return $applied;
}

/**
 * Asks Stripe whether the payment intent has been refunded in full: the
 * succeeded refunds in the order's currency must add up to the order amount.
 * Returns the newest such refund's id, or null.
 *
 * @throws \Stripe\Exception\ApiErrorException
 */
function confirmed_full_refund(string $paymentIntent, int $amountMinor, string $currency): ?string
{
    $refunds = stripe_client()->refunds->all(['payment_intent' => $paymentIntent, 'limit' => 100]);
    $total = 0;
    $newest = null;
    $newestCreated = -1;
    foreach ($refunds as $refund) {
        if (($refund->status ?? null) !== 'succeeded' || strtolower((string) ($refund->currency ?? '')) !== strtolower($currency)) {
            continue;
        }
        if (($refund->payment_intent ?? null) !== $paymentIntent) {
            continue;
        }
        $total += (int) ($refund->amount ?? 0);
        // Stripe lists newest first, so the first refund wins a tie on "created".
        if ((int) ($refund->created ?? 0) > $newestCreated) {
            $newestCreated = (int) ($refund->created ?? 0);
            $newest = (string) $refund->id;
        }
    }
    return $total >= $amountMinor && $amountMinor > 0 ? $newest : null;
}

/* ---------------------------------------------------------------------
 * Webhooks
 * ------------------------------------------------------------------- */

/**
 * Verifies and processes a Stripe webhook. Returns the HTTP status to send.
 * While the payment simulator takes the payments every webhook is answered
 * with 404 and nothing else: the simulator sends no webhooks, and Stripe's
 * events could not refer to its sessions. A demo therefore needs no
 * STRIPE_WEBHOOK_SECRET, and requests from visitors leave no trace in the logs.
 */
function handle_stripe_webhook(string $payload, string $signatureHeader): int
{
    if (payment_simulator_enabled()) {
        return 404;
    }

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

    if ($event->type === 'charge.refunded') {
        return handle_charge_refunded_event($event);
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
    if ($order['status'] === 'paid' || $order['status'] === 'refunded') {
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

/**
 * charge.refunded: a charge was refunded (for example in the Stripe
 * Dashboard). A full refund of a paid order's payment is recorded with the
 * same bookkeeping as refund_order(), exactly once. Like the checkout events,
 * the payload is only a notification: the refund is confirmed through the
 * Stripe API before anything changes, so a forged event cannot refund an
 * order. Partial refunds are left to staff.
 */
function handle_charge_refunded_event(\Stripe\Event $event): int
{
    $charge = $event->data->object ?? null;
    if (!$charge instanceof \Stripe\StripeObject) {
        return 200;
    }
    $paymentIntent = $charge->payment_intent ?? null;
    if ($paymentIntent instanceof \Stripe\StripeObject) {
        $paymentIntent = $paymentIntent->id ?? null;
    }
    if (!is_string($paymentIntent) || !preg_match('/^pi_[A-Za-z0-9_]{1,250}$/D', $paymentIntent)) {
        app_log('warning', 'charge.refunded without a payment intent', ['event' => $event->id]);
        return 200;
    }
    $amount = (int) ($charge->amount ?? 0);
    if ($amount <= 0 || (int) ($charge->amount_refunded ?? 0) < $amount) {
        app_log('info', 'Partial refund left for manual handling', ['event' => $event->id, 'payment_intent' => $paymentIntent]);
        return 200;
    }

    $order = db_one('SELECT id, status, currency, amount_minor FROM orders WHERE stripe_payment_intent = ?', [$paymentIntent]);
    if ($order === null) {
        app_log('warning', 'charge.refunded for a payment that is not a BookWise order', ['event' => $event->id]);
        return 200;
    }
    if ($order['status'] !== 'paid') {
        if ($order['status'] !== 'refunded') {
            app_log('warning', 'charge.refunded for an order that is not paid', ['event' => $event->id, 'order_id' => (int) $order['id'], 'status' => $order['status']]);
        }
        return 200; // Already refunded: retries and duplicates change nothing.
    }

    try {
        $refundId = confirmed_full_refund($paymentIntent, (int) $order['amount_minor'], (string) $order['currency']);
    } catch (\Stripe\Exception\ApiErrorException | CheckoutException $e) {
        app_log('error', 'Could not confirm a refund with Stripe; Stripe will retry the webhook', ['event' => $event->id, 'message' => $e->getMessage()]);
        return 503;
    }
    if ($refundId === null) {
        app_log('warning', 'charge.refunded not confirmed by the Stripe API; order unchanged', ['event' => $event->id, 'order_id' => (int) $order['id']]);
        return 200;
    }

    apply_order_refund((int) $order['id'], $refundId, $paymentIntent);
    return 200;
}
