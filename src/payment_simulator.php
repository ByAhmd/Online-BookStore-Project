<?php

declare(strict_types=1);

/**
 * The built-in payment simulator: a stand-in for the parts of the Stripe API
 * that BookWise uses, so the store can take pretend payments without a Stripe
 * account. It never moves real money and never sees card details.
 *
 * With PAYMENT_SIMULATOR on (the default when APP_DEMO=true), stripe_client()
 * hands every Stripe request to PaymentSimulatorHttpClient, which answers it
 * in-process with payment_simulator_api(): no network, no second server.
 * Customers "pay" on public/pay.php, the simulated hosted checkout page.
 * tests/Support/stripe_mock_router.php serves the same API over HTTP for the
 * test suites (STRIPE_API_BASE).
 *
 * Stripe API subset (form-encoded parameters, JSON answers):
 *   POST /v1/checkout/sessions               create a session
 *   GET  /v1/checkout/sessions/{id}          retrieve a session
 *   POST /v1/checkout/sessions/{id}/expire   expire an open session
 *   POST /v1/refunds                         refund a paid session's payment intent
 *   GET  /v1/refunds?payment_intent=pi_...   list refunds, newest first
 *   GET  /v1/refunds/{id}                    retrieve a refund
 * As on Stripe, open sessions expire at their expires_at, and a POST that
 * repeats an Idempotency-Key replays the first answer (or is refused when its
 * parameters differ).
 *
 * The state is one JSON file (PAYMENT_SIMULATOR_STORE, default
 * storage/payment-simulator.json) that is read and written under an exclusive
 * lock, so concurrent requests never lose each other's changes.
 */

/** Placeholder API key: the simulator needs none, but stripe-php requires one. */
const PAYMENT_SIMULATOR_API_KEY = 'sk_test_payment_simulator';
/** API base of the simulated client. ".invalid" never resolves, so nothing can leave the server. */
const PAYMENT_SIMULATOR_API_BASE = 'https://payment-simulator.invalid';
/** Buttons of the hosted payment page (see payment_simulator_checkout_action()). */
const PAYMENT_SIMULATOR_ACTIONS = ['pay', 'decline', 'slow', 'cancel'];
/** A "slow payment" is processing for this long, then succeeds. */
const PAYMENT_SIMULATOR_PROCESSING_SECONDS = 15;
/** Sessions and refunds older than this are forgotten, so the state file stays small. */
const PAYMENT_SIMULATOR_RETENTION_SECONDS = 30 * 24 * 60 * 60;
/** Stripe keeps idempotency keys for 24 hours. */
const PAYMENT_SIMULATOR_IDEMPOTENCY_SECONDS = 24 * 60 * 60;

/**
 * True when payments go to the simulator instead of Stripe.
 */
function payment_simulator_enabled(): bool
{
    return (bool) config('stripe.simulator');
}

/**
 * Absolute path of the simulator's state file (PAYMENT_SIMULATOR_STORE;
 * relative paths start at the project root).
 */
function payment_simulator_store_path(): string
{
    $path = (string) config('stripe.simulator_store', 'storage/payment-simulator.json');
    if (!preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path)) {
        $path = base_path($path);
    }
    return $path;
}

/* ---------------------------------------------------------------------
 * State
 * ------------------------------------------------------------------- */

/**
 * Runs $change on the simulator's state under an exclusive lock and saves the
 * state if $change modified it. Before $change sees the state, open sessions
 * past their expires_at are expired, slow payments past their processing time
 * succeed, and records past their retention are dropped.
 *
 * The lock is held on a separate file ($store.lock), so the state can be saved
 * atomically (see payment_simulator_save()): a failed save, for example on a
 * full disk, throws and leaves the previous state as it was.
 *
 * @template T
 * @param callable(array{sessions: array<string, array<string, mixed>>, refunds: array<string, array<string, mixed>>, idempotency: array<string, array<string, mixed>>}): T $change
 *        Receives the state by reference.
 * @param string|null $store State file; default payment_simulator_store_path()
 * @return T
 * @throws RuntimeException when the state file cannot be created, locked, read or saved
 */
function payment_simulator_transaction(callable $change, ?string $store = null): mixed
{
    $store ??= payment_simulator_store_path();
    $directory = dirname($store);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("The payment simulator cannot create the directory $directory");
    }
    $lock = @fopen($store . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException("The payment simulator cannot open $store.lock");
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException("The payment simulator cannot lock $store.lock");
        }
        [$saved, $decoded] = payment_simulator_load($store);
        $state = payment_simulator_normalize_state($decoded);
        payment_simulator_refresh($state, time());

        $result = $change($state);

        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if ($encoded !== $saved) {
            payment_simulator_save($store, $encoded);
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Reads the saved state: the file's contents ('' when there is none yet) and
 * their decoded JSON. A file that does not hold JSON (edited by hand, for
 * example) is never overwritten: it is moved aside to
 * $store.unreadable-<date>-<time> and the simulator starts afresh.
 *
 * @return array{0: string, 1: array<mixed>}
 * @throws RuntimeException when the file cannot be read or moved aside
 */
function payment_simulator_load(string $store): array
{
    if (!file_exists($store)) {
        return ['', []];
    }
    $saved = @file_get_contents($store);
    if ($saved === false) {
        throw new RuntimeException("The payment simulator cannot read $store");
    }
    $decoded = $saved === '' ? [] : json_decode($saved, true);
    if (is_array($decoded)) {
        return [$saved, $decoded];
    }

    $aside = $store . '.unreadable-' . date('Ymd-His');
    if (!@rename($store, $aside)) {
        throw new RuntimeException("The payment simulator cannot read $store, nor move it aside");
    }
    app_log('warning', 'The payment simulator could not read its state file; it was moved aside and the simulator starts afresh', ['file' => basename($aside)]);
    return ['', []];
}

/**
 * Saves the state atomically: it is written to $store.tmp, which then
 * replaces $store. A failed or short write never leaves a partial state file.
 * The caller holds the lock, so one temporary file is enough.
 *
 * @throws RuntimeException when the state cannot be saved; the previous state is kept
 */
function payment_simulator_save(string $store, string $encoded): void
{
    $temporary = $store . '.tmp';
    if (@file_put_contents($temporary, $encoded) !== strlen($encoded) || !@rename($temporary, $store)) {
        @unlink($temporary);
        throw new RuntimeException("The payment simulator cannot save $store");
    }
}

/**
 * @return array{sessions: array<string, array<string, mixed>>, refunds: array<string, array<string, mixed>>, idempotency: array<string, array<string, mixed>>}
 */
function payment_simulator_normalize_state(mixed $state): array
{
    $state = is_array($state) ? $state : [];
    $normalized = ['sessions' => [], 'refunds' => [], 'idempotency' => []];
    foreach (array_keys($normalized) as $section) {
        foreach (is_array($state[$section] ?? null) ? $state[$section] : [] as $key => $record) {
            if (is_string($key) && is_array($record)) {
                $normalized[$section][$key] = $record;
            }
        }
    }
    return $normalized;
}

/**
 * Applies what happens with time: Stripe's automatic expiry of open sessions,
 * the end of slow payments, and the retention limits.
 *
 * @param array{sessions: array<string, array<string, mixed>>, refunds: array<string, array<string, mixed>>, idempotency: array<string, array<string, mixed>>} $state
 */
function payment_simulator_refresh(array &$state, int $now): void
{
    foreach (array_keys($state['sessions']) as $id) {
        $session = &$state['sessions'][$id];
        if (($session['status'] ?? null) === 'open' && (int) ($session['expires_at'] ?? PHP_INT_MAX) < $now) {
            $session['status'] = 'expired';
        }
        $processingUntil = $session['simulator']['processing_until'] ?? null;
        if (
            is_int($processingUntil) && $processingUntil <= $now
            && ($session['status'] ?? null) === 'complete' && ($session['payment_status'] ?? null) === 'unpaid'
        ) {
            $session['payment_status'] = 'paid';
            $session['simulator']['processing_until'] = null;
        }
        $created = (int) ($session['created'] ?? $now);
        unset($session);
        if ($created < $now - PAYMENT_SIMULATOR_RETENTION_SECONDS) {
            unset($state['sessions'][$id]);
        }
    }
    foreach ($state['refunds'] as $id => $refund) {
        if ((int) ($refund['created'] ?? $now) < $now - PAYMENT_SIMULATOR_RETENTION_SECONDS) {
            unset($state['refunds'][$id]);
        }
    }
    foreach ($state['idempotency'] as $key => $entry) {
        if ((int) ($entry['created'] ?? $now) < $now - PAYMENT_SIMULATOR_IDEMPOTENCY_SECONDS) {
            unset($state['idempotency'][$key]);
        }
    }
}

/* ---------------------------------------------------------------------
 * Stripe API
 * ------------------------------------------------------------------- */

/**
 * Answers one Stripe API request.
 *
 * @param array<string, mixed> $params Query (GET) or form (POST) parameters as Stripe receives them: strings and nested arrays
 * @param array{store?: string|null, hosted_page_url?: callable(string): string, keep_customer_email?: bool} $options
 *        hosted_page_url builds the payment page address of a session (default: public/pay.php of APP_URL);
 *        keep_customer_email stores customer_email (only the test suites need it; the store keeps no emails here)
 * @return array{0: int, 1: string} HTTP status and JSON body
 * @throws RuntimeException when the state file cannot be used
 */
function payment_simulator_api(string $method, string $path, array $params, ?string $idempotencyKey = null, array $options = []): array
{
    $method = strtoupper($method);
    $idempotencyKey = trim((string) $idempotencyKey);

    return payment_simulator_transaction(static function (array &$state) use ($method, $path, $params, $idempotencyKey, $options): array {
        if ($method !== 'POST' || $idempotencyKey === '') {
            return payment_simulator_encode(payment_simulator_route($state, $method, $path, $params, $options));
        }

        // Like Stripe: retrying with the same key replays the first answer.
        $fingerprint = hash('sha256', $method . ' ' . $path . ' ' . json_encode($params));
        $previous = $state['idempotency'][$idempotencyKey] ?? null;
        if ($previous !== null) {
            if (($previous['fingerprint'] ?? null) !== $fingerprint) {
                return payment_simulator_encode(payment_simulator_error(
                    400,
                    "Keys for idempotent requests can only be used with the same parameters they were first used with. Try using a key other than '$idempotencyKey' if you meant to execute a different request.",
                    'idempotency_key_in_use',
                    'idempotency_error'
                ));
            }
            return [(int) $previous['status'], (string) $previous['body']];
        }
        $response = payment_simulator_encode(payment_simulator_route($state, $method, $path, $params, $options));
        $state['idempotency'][$idempotencyKey] = [
            'fingerprint' => $fingerprint,
            'status' => $response[0],
            'body' => $response[1],
            'created' => time(),
        ];
        return $response;
    }, $options['store'] ?? null);
}

/**
 * @param array{0: int, 1: array<string, mixed>} $response
 * @return array{0: int, 1: string}
 */
function payment_simulator_encode(array $response): array
{
    return [$response[0], json_encode($response[1], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)];
}

/**
 * @param array{sessions: array<string, array<string, mixed>>, refunds: array<string, array<string, mixed>>, idempotency: array<string, array<string, mixed>>} $state
 * @param array<string, mixed> $params
 * @param array{store?: string|null, hosted_page_url?: callable(string): string, keep_customer_email?: bool} $options
 * @return array{0: int, 1: array<string, mixed>}
 */
function payment_simulator_route(array &$state, string $method, string $path, array $params, array $options): array
{
    if ($method === 'POST' && $path === '/v1/checkout/sessions') {
        return payment_simulator_create_session($state, $params, $options);
    }

    if (preg_match('#^/v1/checkout/sessions/(cs_[A-Za-z0-9_]+)(/expire)?$#D', $path, $m)) {
        $id = $m[1];
        $expire = ($m[2] ?? '') === '/expire';
        if ($method !== ($expire ? 'POST' : 'GET')) {
            return payment_simulator_error(404, "Unrecognized request URL ($method: $path).", 'url_invalid');
        }
        if (!isset($state['sessions'][$id])) {
            return payment_simulator_error(404, "No such checkout.session: '$id'", 'resource_missing');
        }
        if ($expire) {
            if ($state['sessions'][$id]['status'] !== 'open') {
                return payment_simulator_error(400, 'Only Checkout Sessions with a status of open can be expired.', 'checkout_session_not_open');
            }
            $state['sessions'][$id]['status'] = 'expired';
        }
        return [200, payment_simulator_session_object($state['sessions'][$id])];
    }

    if ($method === 'POST' && $path === '/v1/refunds') {
        return payment_simulator_create_refund($state, $params);
    }

    if ($method === 'GET' && $path === '/v1/refunds') {
        $intent = isset($params['payment_intent']) && is_string($params['payment_intent']) ? $params['payment_intent'] : null;
        $limit = max(1, min(100, (int) ($params['limit'] ?? 10)));
        $matching = array_values(array_filter(
            $state['refunds'],
            static fn (array $refund): bool => $intent === null || ($refund['payment_intent'] ?? null) === $intent
        ));
        // Newest first; refunds created in the same second keep their creation order reversed.
        $matching = array_reverse($matching);
        usort($matching, static fn (array $a, array $b): int => (int) $b['created'] <=> (int) $a['created']);
        return [200, [
            'object' => 'list',
            'url' => '/v1/refunds',
            'has_more' => count($matching) > $limit,
            'data' => array_map('payment_simulator_refund_object', array_slice($matching, 0, $limit)),
        ]];
    }

    if ($method === 'GET' && preg_match('#^/v1/refunds/(re_[A-Za-z0-9_]+)$#D', $path, $m)) {
        if (!isset($state['refunds'][$m[1]])) {
            return payment_simulator_error(404, "No such refund: '{$m[1]}'", 'resource_missing');
        }
        return [200, payment_simulator_refund_object($state['refunds'][$m[1]])];
    }

    return payment_simulator_error(404, "Unrecognized request URL ($method: $path).", 'url_invalid');
}

/**
 * POST /v1/checkout/sessions: the line items must have integer amounts and
 * quantities in one currency; the session total is computed from them.
 *
 * @param array{sessions: array<string, array<string, mixed>>, refunds: array<string, array<string, mixed>>, idempotency: array<string, array<string, mixed>>} $state
 * @param array<string, mixed> $params
 * @param array{store?: string|null, hosted_page_url?: callable(string): string, keep_customer_email?: bool} $options
 * @return array{0: int, 1: array<string, mixed>}
 */
function payment_simulator_create_session(array &$state, array $params, array $options): array
{
    $lineItems = $params['line_items'] ?? null;
    if (!is_array($lineItems) || $lineItems === []) {
        return payment_simulator_error(400, 'Missing required param: line_items.', 'parameter_missing');
    }

    $items = [];
    $amount = 0;
    $currency = null;
    foreach ($lineItems as $item) {
        $priceData = is_array($item) && is_array($item['price_data'] ?? null) ? $item['price_data'] : [];
        $unit = $priceData['unit_amount'] ?? null;
        $quantity = is_array($item) ? ($item['quantity'] ?? null) : null;
        if (!is_string($unit) || !ctype_digit($unit) || !is_string($quantity) || !ctype_digit($quantity) || (int) $quantity < 1) {
            return payment_simulator_error(400, 'Invalid integer: unit_amount and quantity must be whole numbers.', 'parameter_invalid_integer');
        }
        $itemCurrency = strtolower((string) ($priceData['currency'] ?? ''));
        if (!preg_match('/^[a-z]{3}$/D', $itemCurrency) || ($currency !== null && $itemCurrency !== $currency)) {
            return payment_simulator_error(400, 'Every line item needs the same three-letter currency.', 'parameter_invalid_string');
        }
        $currency = $itemCurrency;
        $name = $priceData['product_data']['name'] ?? '';
        $items[] = ['name' => is_string($name) ? $name : '', 'quantity' => (int) $quantity, 'unit_amount' => (int) $unit];
        $amount += (int) $unit * (int) $quantity;
    }

    $id = 'cs_test_' . bin2hex(random_bytes(12));
    $hostedPageUrl = $options['hosted_page_url'] ?? static fn (string $sessionId): string => absolute_url('pay.php?session=' . rawurlencode($sessionId));
    $expiresAt = $params['expires_at'] ?? null;
    $metadata = $params['metadata'] ?? [];
    $state['sessions'][$id] = [
        'id' => $id,
        'object' => 'checkout.session',
        'mode' => is_string($params['mode'] ?? null) ? $params['mode'] : 'payment',
        'status' => 'open',
        'payment_status' => 'unpaid',
        'amount_subtotal' => $amount,
        'amount_total' => $amount,
        'currency' => $currency,
        'client_reference_id' => is_string($params['client_reference_id'] ?? null) ? $params['client_reference_id'] : null,
        'customer_email' => !empty($options['keep_customer_email']) && is_string($params['customer_email'] ?? null) ? $params['customer_email'] : null,
        'metadata' => is_array($metadata) ? $metadata : [],
        'payment_intent' => null,
        'success_url' => is_string($params['success_url'] ?? null) ? $params['success_url'] : '',
        'cancel_url' => is_string($params['cancel_url'] ?? null) ? $params['cancel_url'] : '',
        'created' => time(),
        'expires_at' => is_string($expiresAt) && ctype_digit($expiresAt) ? (int) $expiresAt : time() + 86400,
        'url' => $hostedPageUrl($id),
        'livemode' => false,
        // Not part of Stripe's object: what the hosted page shows, and the end of a slow payment.
        'simulator' => ['line_items' => $items, 'processing_until' => null],
    ];
    return [200, payment_simulator_session_object($state['sessions'][$id])];
}

/**
 * POST /v1/refunds: refunds a paid session's payment intent, in full by
 * default or by `amount`, never beyond what was paid.
 *
 * @param array{sessions: array<string, array<string, mixed>>, refunds: array<string, array<string, mixed>>, idempotency: array<string, array<string, mixed>>} $state
 * @param array<string, mixed> $params
 * @return array{0: int, 1: array<string, mixed>}
 */
function payment_simulator_create_refund(array &$state, array $params): array
{
    $intent = is_string($params['payment_intent'] ?? null) ? $params['payment_intent'] : '';
    $sessionId = null;
    foreach ($state['sessions'] as $id => $session) {
        if ($intent !== '' && ($session['payment_intent'] ?? null) === $intent) {
            $sessionId = $id;
            break;
        }
    }
    if ($sessionId === null) {
        return payment_simulator_error(404, "No such payment_intent: '$intent'", 'resource_missing');
    }
    $session = $state['sessions'][$sessionId];
    if (($session['payment_status'] ?? null) !== 'paid') {
        return payment_simulator_error(400, 'This PaymentIntent does not have a successful charge to refund.', 'charge_not_refundable');
    }

    $alreadyRefunded = (int) ($session['amount_refunded'] ?? 0);
    $requested = $params['amount'] ?? null;
    if ($requested !== null && (!is_string($requested) || !ctype_digit($requested))) {
        return payment_simulator_error(400, 'Invalid integer: amount must be a whole number.', 'parameter_invalid_integer');
    }
    $amount = $requested !== null ? (int) $requested : (int) $session['amount_total'] - $alreadyRefunded;
    if ($amount <= 0 || $alreadyRefunded + $amount > (int) $session['amount_total']) {
        return payment_simulator_error(400, 'Charge has already been refunded.', 'charge_already_refunded');
    }

    $refundId = 're_test_' . bin2hex(random_bytes(12));
    $metadata = $params['metadata'] ?? [];
    $state['refunds'][$refundId] = [
        'id' => $refundId,
        'object' => 'refund',
        'amount' => $amount,
        'currency' => $session['currency'],
        'payment_intent' => $intent,
        'status' => 'succeeded',
        'metadata' => is_array($metadata) ? $metadata : [],
        'created' => time(),
    ];
    $state['sessions'][$sessionId]['amount_refunded'] = $alreadyRefunded + $amount;
    return [200, payment_simulator_refund_object($state['refunds'][$refundId])];
}

/**
 * A stored session as Stripe's API returns it.
 *
 * @param array<string, mixed> $session
 * @return array<string, mixed>
 */
function payment_simulator_session_object(array $session): array
{
    unset($session['simulator']);
    // Stripe returns metadata as a JSON object, also when it is empty.
    $session['metadata'] = (object) (is_array($session['metadata'] ?? null) ? $session['metadata'] : []);
    return $session;
}

/**
 * @param array<string, mixed> $refund
 * @return array<string, mixed>
 */
function payment_simulator_refund_object(array $refund): array
{
    $refund['metadata'] = (object) (is_array($refund['metadata'] ?? null) ? $refund['metadata'] : []);
    return $refund;
}

/**
 * A Stripe-style error answer.
 *
 * @return array{0: int, 1: array<string, mixed>}
 */
function payment_simulator_error(int $status, string $message, string $code, string $type = 'invalid_request_error'): array
{
    return [$status, ['error' => ['type' => $type, 'code' => $code, 'message' => $message]]];
}

/* ---------------------------------------------------------------------
 * Hosted payment page
 * ------------------------------------------------------------------- */

/**
 * A stored session (with its line items under "simulator"), or null.
 *
 * @return array<string, mixed>|null
 * @throws RuntimeException when the state file cannot be used
 */
function payment_simulator_session(string $sessionId, ?string $store = null): ?array
{
    return payment_simulator_transaction(
        static fn (array &$state): ?array => $state['sessions'][$sessionId] ?? null,
        $store
    );
}

/**
 * Applies a button of the hosted payment page:
 *  - pay: the payment succeeds (complete, paid) and the customer returns to success_url;
 *  - decline: the card is declined. Nothing is charged and the session stays
 *    open and unpaid, so the customer can try again or cancel (no redirect);
 *  - slow: the payment is accepted but still processing (complete, unpaid),
 *    like a delayed payment method; it succeeds PAYMENT_SIMULATOR_PROCESSING_SECONDS
 *    later. The customer returns to success_url;
 *  - cancel: back to cancel_url. The session stays open until it expires or
 *    the store expires it.
 * A session that is no longer open takes no payment: the customer is sent back
 * to the store (success_url once it is complete, cancel_url otherwise).
 *
 * @return array{outcome: 'paid'|'declined'|'processing'|'canceled'|'closed', redirect: string|null}|null
 *         null for an unknown session
 * @throws InvalidArgumentException for an action outside PAYMENT_SIMULATOR_ACTIONS
 * @throws RuntimeException when the state file cannot be used
 */
function payment_simulator_checkout_action(string $sessionId, string $action, ?string $store = null): ?array
{
    if (!in_array($action, PAYMENT_SIMULATOR_ACTIONS, true)) {
        throw new InvalidArgumentException("Unknown payment simulator action: $action");
    }

    return payment_simulator_transaction(static function (array &$state) use ($sessionId, $action): ?array {
        if (!isset($state['sessions'][$sessionId])) {
            return null;
        }
        $session = &$state['sessions'][$sessionId];
        $success = str_replace('{CHECKOUT_SESSION_ID}', $sessionId, (string) $session['success_url']);
        $cancel = (string) $session['cancel_url'];

        if ($session['status'] !== 'open') {
            return ['outcome' => 'closed', 'redirect' => $session['status'] === 'complete' ? $success : $cancel];
        }
        switch ($action) {
            case 'pay':
                payment_simulator_capture($session, true);
                return ['outcome' => 'paid', 'redirect' => $success];
            case 'slow':
                payment_simulator_capture($session, false);
                return ['outcome' => 'processing', 'redirect' => $success];
            case 'decline':
                return ['outcome' => 'declined', 'redirect' => null];
            default:
                return ['outcome' => 'canceled', 'redirect' => $cancel];
        }
    }, $store);
}

/**
 * Completes a session's payment: paid at once, or still processing until
 * PAYMENT_SIMULATOR_PROCESSING_SECONDS have passed.
 *
 * @param array<string, mixed> $session
 */
function payment_simulator_capture(array &$session, bool $paid): void
{
    $session['status'] = 'complete';
    $session['payment_status'] = $paid ? 'paid' : 'unpaid';
    $session['payment_intent'] = 'pi_test_' . bin2hex(random_bytes(12));
    $simulator = is_array($session['simulator'] ?? null) ? $session['simulator'] : [];
    $simulator['processing_until'] = $paid ? null : time() + PAYMENT_SIMULATOR_PROCESSING_SECONDS;
    $session['simulator'] = $simulator;
}

/* ---------------------------------------------------------------------
 * stripe-php client
 * ------------------------------------------------------------------- */

/**
 * The HTTP client stripe_client() installs in stripe-php while the simulator
 * is on: every request is answered in-process by payment_simulator_api(),
 * exactly as the Stripe API would answer it. Nothing goes over the network,
 * so no second server, worker or loopback request is involved.
 */
final class PaymentSimulatorHttpClient implements \Stripe\HttpClient\ClientInterface
{
    /**
     * @param string|null $store State file; default PAYMENT_SIMULATOR_STORE
     */
    public function __construct(private readonly ?string $store = null)
    {
    }

    /**
     * @param 'delete'|'get'|'post' $method
     * @param string $absUrl
     * @param array<int, string> $headers
     * @param array<mixed> $params
     * @param bool $hasFile
     * @param 'v1'|'v2' $apiMode
     * @return array{0: string, 1: int, 2: \Stripe\Util\CaseInsensitiveArray}
     * @throws \Stripe\Exception\ApiConnectionException when the simulator cannot answer
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1')
    {
        if ($apiMode !== 'v1' || $hasFile) {
            throw \Stripe\Exception\ApiConnectionException::factory('The payment simulator only answers form-encoded v1 requests.');
        }
        // Encode and decode the parameters as they would travel over HTTP, so
        // the simulator sees exactly what Stripe would: strings in nested arrays.
        parse_str(\Stripe\Util\Util::encodeParameters(\Stripe\Util\Util::objectsToIds($params)), $form);
        $idempotencyKey = null;
        foreach ($headers as $header) {
            if (stripos((string) $header, 'Idempotency-Key:') === 0) {
                $idempotencyKey = trim(substr((string) $header, strlen('Idempotency-Key:')));
            }
        }

        try {
            [$status, $body] = payment_simulator_api(
                (string) $method,
                (string) parse_url((string) $absUrl, PHP_URL_PATH),
                $form,
                $idempotencyKey,
                ['store' => $this->store]
            );
        } catch (RuntimeException | JsonException $e) {
            throw \Stripe\Exception\ApiConnectionException::factory('The payment simulator is not available: ' . $e->getMessage());
        }

        return [$body, $status, new \Stripe\Util\CaseInsensitiveArray([
            'content-type' => 'application/json',
            'request-id' => 'req_sim_' . bin2hex(random_bytes(8)),
        ])];
    }
}
