<?php

declare(strict_types=1);

/**
 * A small stand-in for the parts of the Stripe API that BookWise uses. The
 * automated tests use it, and so does the public demo (where no real payment
 * provider is configured). It never moves real money.
 *
 * Run alone:  php -S 127.0.0.1:12111 tests/Support/stripe_mock_router.php
 * App config: STRIPE_API_BASE=http://127.0.0.1:12111, STRIPE_SECRET_KEY=sk_test_...
 *
 * Stripe API:
 *   POST /v1/checkout/sessions                 create a session
 *   GET  /v1/checkout/sessions/{id}            retrieve a session
 *   POST /v1/checkout/sessions/{id}/expire     expire an open session
 *   POST /v1/refunds                           refund a paid session's payment intent
 *   GET  /v1/refunds/{id}                      retrieve a refund
 * Simulated hosted checkout page:
 *   GET  /pay/{id}                             shows Pay / Cancel buttons
 *   POST /pay/{id}                             action=pay|cancel, redirects back
 * Test controls (only when STRIPE_SIM_TEST_API=1):
 *   POST /__test/sessions/{id}/pay             mark paid (optional amount_total, currency overrides)
 *   POST /__test/sessions/{id}/state           set status and/or payment_status
 *   GET  /__test/sessions                      list all sessions
 *   POST /__test/reset                         delete all sessions
 *
 * Environment:
 *   STRIPE_MOCK_STORE      JSON state file (default: system temp dir)
 *   STRIPE_SIM_BASE_PATH   URL prefix when mounted inside a site, e.g. /stripe-sim
 *   STRIPE_SIM_PUBLIC_URL  public base URL used for hosted-page links (default: this host + prefix)
 *   STRIPE_SIM_KEY         if set, the exact API key callers must present
 *   STRIPE_SIM_TEST_API    "1" enables the /__test controls and keeps customer emails
 */

$storeFile = getenv('STRIPE_MOCK_STORE') ?: sys_get_temp_dir() . '/bookwise-stripe-mock.json';
$basePath = rtrim((string) (getenv('STRIPE_SIM_BASE_PATH') ?: ''), '/');
$requiredKey = (string) (getenv('STRIPE_SIM_KEY') ?: '');
$testApi = getenv('STRIPE_SIM_TEST_API') === '1';

function mock_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Request-Id: req_mock_' . bin2hex(random_bytes(6)));
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
}

function mock_error(int $status, string $message, string $code = 'resource_missing'): void
{
    mock_json($status, ['error' => ['type' => 'invalid_request_error', 'code' => $code, 'message' => $message]]);
}

/**
 * Applies Stripe's automatic expiry to an open session.
 *
 * @param array<string, mixed> $session
 * @return array<string, mixed>
 */
function mock_refresh(array $session): array
{
    if (($session['status'] ?? '') === 'open' && (int) ($session['expires_at'] ?? PHP_INT_MAX) < time()) {
        $session['status'] = 'expired';
    }
    return $session;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($basePath !== '') {
    if (!str_starts_with($path, $basePath . '/')) {
        http_response_code(404);
        echo 'Not found';
        return true;
    }
    $path = substr($path, strlen($basePath));
}
$publicBase = rtrim((string) (getenv('STRIPE_SIM_PUBLIC_URL') ?: (
    ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $basePath
)), '/');

// One request at a time: the JSON file is read, changed and written atomically.
$lock = fopen($storeFile . '.lock', 'c');
flock($lock, LOCK_EX);
register_shutdown_function(static function () use ($lock): void {
    flock($lock, LOCK_UN);
    fclose($lock);
});

$state = is_file($storeFile) ? json_decode((string) file_get_contents($storeFile), true) : [];
$state = is_array($state) ? $state : [];
// Older state files held only sessions at the top level.
$sessions = isset($state['sessions']) && is_array($state['sessions']) ? $state['sessions'] : (isset($state['refunds']) ? [] : $state);
$refunds = isset($state['refunds']) && is_array($state['refunds']) ? $state['refunds'] : [];
$save = static function () use (&$sessions, &$refunds, $storeFile): void {
    file_put_contents($storeFile, json_encode(['sessions' => $sessions, 'refunds' => $refunds], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
};
foreach ($sessions as $id => $session) {
    $sessions[$id] = mock_refresh($session);
}

// --- Stripe API --------------------------------------------------------------
if (str_starts_with($path, '/v1/')) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $authorised = $requiredKey !== ''
        ? hash_equals('Bearer ' . $requiredKey, $auth)
        : (bool) preg_match('/^Bearer (sk|rk)_test_\w+/', $auth);
    if (!$authorised) {
        mock_json(401, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']]);
        return true;
    }

    if ($method === 'POST' && $path === '/v1/checkout/sessions') {
        $lineItems = $_POST['line_items'] ?? [];
        if (!is_array($lineItems) || $lineItems === []) {
            mock_error(400, 'line_items is required', 'parameter_missing');
            return true;
        }
        $amount = 0;
        $currency = null;
        foreach ($lineItems as $item) {
            $unit = $item['price_data']['unit_amount'] ?? null;
            $qty = $item['quantity'] ?? null;
            if (!is_string($unit) || !ctype_digit($unit) || !is_string($qty) || !ctype_digit($qty)) {
                mock_error(400, 'Invalid integer: unit_amount/quantity', 'parameter_invalid_integer');
                return true;
            }
            $amount += (int) $unit * (int) $qty;
            $currency = $item['price_data']['currency'] ?? $currency;
        }
        $id = 'cs_test_' . bin2hex(random_bytes(12));
        $sessions[$id] = [
            'id' => $id,
            'object' => 'checkout.session',
            'mode' => $_POST['mode'] ?? 'payment',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'amount_total' => $amount,
            'currency' => strtolower((string) $currency),
            'client_reference_id' => $_POST['client_reference_id'] ?? null,
            // Kept only for the test suite; a public demo should not collect emails here.
            'customer_email' => $testApi ? ($_POST['customer_email'] ?? null) : null,
            'metadata' => $_POST['metadata'] ?? new stdClass(),
            'payment_intent' => null,
            'success_url' => $_POST['success_url'] ?? '',
            'cancel_url' => $_POST['cancel_url'] ?? '',
            'expires_at' => (int) ($_POST['expires_at'] ?? time() + 86400),
            'url' => $publicBase . '/pay/' . $id,
            'livemode' => false,
            'line_items_count' => count($lineItems),
            'idempotency_key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
        ];
        $save();
        mock_json(200, $sessions[$id]);
        return true;
    }

    if (preg_match('#^/v1/checkout/sessions/(cs_[A-Za-z0-9_]+)(/expire)?$#', $path, $m)) {
        $id = $m[1];
        if (!isset($sessions[$id])) {
            mock_error(404, "No such checkout.session: '$id'");
            return true;
        }
        if (($m[2] ?? '') === '/expire' && $method === 'POST') {
            if ($sessions[$id]['status'] !== 'open') {
                mock_error(400, 'Only Checkout Sessions with a status of open can be expired.', 'checkout_session_not_open');
                return true;
            }
            $sessions[$id]['status'] = 'expired';
            $save();
        }
        mock_json(200, $sessions[$id]);
        return true;
    }

    if ($method === 'POST' && $path === '/v1/refunds') {
        $intent = (string) ($_POST['payment_intent'] ?? '');
        $sessionId = null;
        foreach ($sessions as $id => $session) {
            if ($intent !== '' && ($session['payment_intent'] ?? null) === $intent) {
                $sessionId = $id;
                break;
            }
        }
        if ($sessionId === null) {
            mock_error(404, "No such payment_intent: '$intent'");
            return true;
        }
        $session = $sessions[$sessionId];
        if (($session['payment_status'] ?? '') !== 'paid') {
            mock_error(400, 'This PaymentIntent does not have a successful charge to refund.', 'charge_not_refundable');
            return true;
        }
        $alreadyRefunded = (int) ($session['amount_refunded'] ?? 0);
        $amount = isset($_POST['amount']) ? (int) $_POST['amount'] : (int) $session['amount_total'] - $alreadyRefunded;
        if ($amount <= 0 || $alreadyRefunded + $amount > (int) $session['amount_total']) {
            mock_error(400, 'Charge has already been refunded.', 'charge_already_refunded');
            return true;
        }
        $refundId = 're_test_' . bin2hex(random_bytes(12));
        $refunds[$refundId] = [
            'id' => $refundId,
            'object' => 'refund',
            'amount' => $amount,
            'currency' => $session['currency'],
            'payment_intent' => $intent,
            'status' => 'succeeded',
            'metadata' => $_POST['metadata'] ?? new stdClass(),
            'created' => time(),
        ];
        $sessions[$sessionId]['amount_refunded'] = $alreadyRefunded + $amount;
        $save();
        mock_json(200, $refunds[$refundId]);
        return true;
    }

    if ($method === 'GET' && preg_match('#^/v1/refunds/(re_[A-Za-z0-9_]+)$#', $path, $m)) {
        if (!isset($refunds[$m[1]])) {
            mock_error(404, "No such refund: '{$m[1]}'");
            return true;
        }
        mock_json(200, $refunds[$m[1]]);
        return true;
    }

    mock_error(404, "Unrecognized request URL ($method: $path).", 'url_invalid');
    return true;
}

// --- Test controls -----------------------------------------------------------
if (str_starts_with($path, '/__test/')) {
    if (!$testApi) {
        http_response_code(404);
        echo 'Not found';
        return true;
    }
    if ($path === '/__test/reset' && $method === 'POST') {
        $sessions = [];
        $refunds = [];
        $save();
        mock_json(200, ['ok' => true]);
        return true;
    }
    if ($path === '/__test/sessions' && $method === 'GET') {
        mock_json(200, ['data' => array_values($sessions)]);
        return true;
    }
    if (preg_match('#^/__test/sessions/(cs_[A-Za-z0-9_]+)/state$#', $path, $m) && $method === 'POST') {
        // Arbitrary status/payment_status, e.g. complete + unpaid for a delayed (async) payment.
        $id = $m[1];
        if (!isset($sessions[$id])) {
            mock_error(404, 'unknown session');
            return true;
        }
        foreach (['status', 'payment_status'] as $field) {
            if (isset($_POST[$field])) {
                $sessions[$id][$field] = (string) $_POST[$field];
            }
        }
        $save();
        mock_json(200, $sessions[$id]);
        return true;
    }
    if (preg_match('#^/__test/sessions/(cs_[A-Za-z0-9_]+)/pay$#', $path, $m) && $method === 'POST') {
        $id = $m[1];
        if (!isset($sessions[$id])) {
            mock_error(404, 'unknown session');
            return true;
        }
        $sessions[$id]['status'] = 'complete';
        $sessions[$id]['payment_status'] = 'paid';
        $sessions[$id]['payment_intent'] = 'pi_test_' . bin2hex(random_bytes(8));
        if (isset($_POST['amount_total'])) {
            $sessions[$id]['amount_total'] = (int) $_POST['amount_total'];
        }
        if (isset($_POST['currency'])) {
            $sessions[$id]['currency'] = (string) $_POST['currency'];
        }
        $save();
        mock_json(200, $sessions[$id]);
        return true;
    }
    http_response_code(404);
    echo 'Not found';
    return true;
}

// --- Simulated hosted checkout page -----------------------------------------
if (preg_match('#^/pay/(cs_[A-Za-z0-9_]+)$#', $path, $m)) {
    $id = $m[1];
    $session = $sessions[$id] ?? null;
    if ($session === null) {
        http_response_code(404);
        echo 'Unknown checkout session';
        return true;
    }
    if ($method === 'POST') {
        if (($_POST['action'] ?? '') === 'pay' && $session['status'] === 'open') {
            $sessions[$id]['status'] = 'complete';
            $sessions[$id]['payment_status'] = 'paid';
            $sessions[$id]['payment_intent'] = 'pi_test_' . bin2hex(random_bytes(8));
            $save();
            header('Location: ' . str_replace('{CHECKOUT_SESSION_ID}', $id, (string) $session['success_url']), true, 303);
            return true;
        }
        header('Location: ' . (string) $session['cancel_url'], true, 303);
        return true;
    }

    // The Pay/Cancel form redirects back to the store, so its origin must be an allowed form target.
    $store = parse_url((string) $session['success_url']);
    $storeOrigin = ($store['scheme'] ?? 'http') . '://' . ($store['host'] ?? 'localhost') . (isset($store['port']) ? ':' . $store['port'] : '');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self' $storeOrigin; frame-ancestors 'none'");
    $amount = number_format($session['amount_total'] / 100, 2);
    $currency = strtoupper(htmlspecialchars((string) $session['currency']));
    $status = htmlspecialchars((string) $session['status']);
    $open = $session['status'] === 'open';
    $buttons = $open
        ? '<button class="pay" name="action" value="pay">Pay (simulated)</button> <button class="cancel" name="action" value="cancel">Cancel</button>'
        : '<button class="cancel" name="action" value="cancel">Back to the store</button>';
    echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Simulated checkout</title>
<style>body{font-family:system-ui,sans-serif;max-width:480px;margin:40px auto;padding:0 16px;color:#1a1a1a}
.box{border:2px dashed #635bff;border-radius:12px;padding:24px}button{font-size:1rem;padding:10px 18px;margin:4px 8px 0 0;border-radius:6px;border:0;cursor:pointer}
.pay{background:#635bff;color:#fff}.cancel{background:#e8e8e8;color:#1a1a1a}button:focus-visible{outline:3px solid #0b0c0c;outline-offset:2px}</style></head><body>
<main class="box"><h1 style="font-size:1.2rem">Simulated checkout</h1>
<p>This is a stand-in for Stripe's payment page. <strong>No real payment is taken</strong> and no card details are needed.</p>
<p>Status: {$status}</p><p style="font-size:1.6rem" aria-label="Amount">{$currency} {$amount}</p>
<form method="post">{$buttons}</form></main></body></html>
HTML;
    return true;
}

http_response_code(404);
echo 'Not found';
return true;
