<?php

declare(strict_types=1);

/**
 * A tiny stand-in for the parts of the Stripe API that BookWise uses, so the
 * checkout flow can be tested locally without network access or real keys.
 *
 * Run:  php -S 127.0.0.1:12111 tests/Support/stripe_mock_router.php
 * Then: STRIPE_API_BASE=http://127.0.0.1:12111 and any STRIPE_SECRET_KEY=sk_test_...
 *
 * Implemented API:
 *   POST /v1/checkout/sessions                 create a session
 *   GET  /v1/checkout/sessions/{id}            retrieve a session
 *   POST /v1/checkout/sessions/{id}/expire     expire an open session
 * Simulated hosted checkout page (for browser testing):
 *   GET  /pay/{id}                             shows Pay / Cancel buttons
 *   POST /pay/{id}                             action=pay|cancel, redirects back
 * Test controls (used by the automated tests):
 *   POST /__test/sessions/{id}/pay             mark paid (optional amount_total, currency overrides)
 *   POST /__test/sessions/{id}/state           set status and/or payment_status
 *   GET  /__test/sessions                      list all sessions
 *   POST /__test/reset                         delete all sessions
 *
 * State is kept in a JSON file (STRIPE_MOCK_STORE or the system temp dir).
 * This is a TEST TOOL ONLY and is never loaded by the application.
 */

$storeFile = getenv('STRIPE_MOCK_STORE') ?: sys_get_temp_dir() . '/bookwise-stripe-mock.json';

function mock_load(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function mock_save(string $file, array $sessions): void
{
    file_put_contents($file, json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

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

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = 'http://' . $_SERVER['HTTP_HOST'];
$sessions = mock_load($storeFile);

// --- Stripe API --------------------------------------------------------------
if (str_starts_with($path, '/v1/')) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer sk_test_\w+/', $auth)) {
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
        $session = [
            'id' => $id,
            'object' => 'checkout.session',
            'mode' => $_POST['mode'] ?? 'payment',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'amount_total' => $amount,
            'currency' => strtolower((string) $currency),
            'client_reference_id' => $_POST['client_reference_id'] ?? null,
            'customer_email' => $_POST['customer_email'] ?? null,
            'metadata' => $_POST['metadata'] ?? new stdClass(),
            'payment_intent' => null,
            'success_url' => $_POST['success_url'] ?? '',
            'cancel_url' => $_POST['cancel_url'] ?? '',
            'expires_at' => (int) ($_POST['expires_at'] ?? time() + 86400),
            'url' => $base . '/pay/' . $id,
            'livemode' => false,
            'line_items_count' => count($lineItems),
            'idempotency_key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
        ];
        $sessions[$id] = $session;
        mock_save($storeFile, $sessions);
        mock_json(200, $session);
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
            mock_save($storeFile, $sessions);
        }
        mock_json(200, $sessions[$id]);
        return true;
    }

    mock_error(404, "Unrecognized request URL ($method: $path).", 'url_invalid');
    return true;
}

// --- Test controls -----------------------------------------------------------
if ($path === '/__test/reset' && $method === 'POST') {
    mock_save($storeFile, []);
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
    mock_save($storeFile, $sessions);
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
    mock_save($storeFile, $sessions);
    mock_json(200, $sessions[$id]);
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
            mock_save($storeFile, $sessions);
            header('Location: ' . str_replace('{CHECKOUT_SESSION_ID}', $id, $session['success_url']), true, 303);
            return true;
        }
        header('Location: ' . $session['cancel_url'], true, 303);
        return true;
    }
    $amount = number_format($session['amount_total'] / 100, 2);
    $currency = strtoupper(htmlspecialchars($session['currency']));
    $status = htmlspecialchars($session['status']);
    echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mock Stripe Checkout</title>
<style>body{font-family:system-ui,sans-serif;max-width:480px;margin:40px auto;padding:0 16px}
.box{border:2px dashed #635bff;border-radius:12px;padding:24px}button{font-size:1rem;padding:10px 18px;margin-right:8px;border-radius:6px;border:0;cursor:pointer}
.pay{background:#635bff;color:#fff}.cancel{background:#eee}</style></head><body>
<div class="box"><p><strong>MOCK STRIPE CHECKOUT</strong> &mdash; local test double, no real payment.</p>
<p>Session: <code>{$id}</code><br>Status: {$status}</p><p style="font-size:1.6rem">{$currency} {$amount}</p>
<form method="post"><button class="pay" name="action" value="pay">Pay (simulate success)</button>
<button class="cancel" name="action" value="cancel">Cancel</button></form></div></body></html>
HTML;
    return true;
}

http_response_code(404);
echo 'Not found';
return true;
