<?php

declare(strict_types=1);

/**
 * The application's payment simulator (src/payment_simulator.php) served over
 * HTTP as a stand-in for the Stripe API. The integration and HTTP test suites
 * start it (BookWise\Tests\Support\StripeMock) and point the application at it
 * with STRIPE_API_BASE, so they can record and tamper with the requests.
 * TEST TOOL ONLY: a deployed store uses the same simulator in-process
 * (PAYMENT_SIMULATOR) and never needs this router.
 *
 * Run alone:  php -S 127.0.0.1:12111 tests/Support/stripe_mock_router.php
 * App config: PAYMENT_SIMULATOR=false, STRIPE_API_BASE=http://127.0.0.1:12111, STRIPE_SECRET_KEY=sk_test_...
 *
 * Routes:
 *   /v1/...           the simulator's Stripe API (see src/payment_simulator.php);
 *                     callers must send "Authorization: Bearer sk_test_..." (or rk_test_)
 *   GET  /pay/{id}    minimal hosted checkout page
 *   POST /pay/{id}    action=pay|decline|slow|cancel, redirects back like public/pay.php
 * Test controls (only when STRIPE_SIM_TEST_API=1):
 *   POST /__test/sessions/{id}/pay      mark paid (optional amount_total, currency overrides)
 *   POST /__test/sessions/{id}/state    set status and/or payment_status
 *   GET  /__test/sessions               list all sessions as stored
 *   POST /__test/reset                  forget all sessions, refunds and idempotency keys
 *
 * Environment:
 *   STRIPE_MOCK_STORE      JSON state file (default: in the system temp directory)
 *   STRIPE_SIM_TEST_API    "1" enables the /__test controls and keeps customer emails
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require BASE_PATH . '/vendor/autoload.php';

$store = getenv('STRIPE_MOCK_STORE') ?: sys_get_temp_dir() . '/bookwise-stripe-mock.json';
$testApi = getenv('STRIPE_SIM_TEST_API') === '1';

$method = strtoupper((string) $_SERVER['REQUEST_METHOD']);
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$publicBase = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

/**
 * @param array<string, mixed>|string $body Array, or JSON already encoded
 */
function mock_json(int $status, array|string $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Request-Id: req_mock_' . bin2hex(random_bytes(6)));
    echo is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES);
}

function mock_not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
}

// --- Stripe API --------------------------------------------------------------
if (str_starts_with($path, '/v1/')) {
    if (!preg_match('/^Bearer (sk|rk)_test_\w+/', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''))) {
        mock_json(401, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']]);
        return true;
    }
    [$status, $body] = payment_simulator_api(
        $method,
        $path,
        $method === 'GET' ? $_GET : $_POST,
        isset($_SERVER['HTTP_IDEMPOTENCY_KEY']) ? (string) $_SERVER['HTTP_IDEMPOTENCY_KEY'] : null,
        [
            'store' => $store,
            'hosted_page_url' => static fn (string $id): string => $publicBase . '/pay/' . $id,
            // Kept only for the test suites; the store's own simulator keeps no emails.
            'keep_customer_email' => $testApi,
        ]
    );
    mock_json($status, $body);
    return true;
}

// --- Test controls -----------------------------------------------------------
if (str_starts_with($path, '/__test/')) {
    if (!$testApi) {
        mock_not_found();
        return true;
    }
    if ($path === '/__test/reset' && $method === 'POST') {
        payment_simulator_transaction(static function (array &$state): void {
            $state = ['sessions' => [], 'refunds' => [], 'idempotency' => []];
        }, $store);
        mock_json(200, ['ok' => true]);
        return true;
    }
    if ($path === '/__test/sessions' && $method === 'GET') {
        $sessions = payment_simulator_transaction(static fn (array &$state): array => array_values($state['sessions']), $store);
        mock_json(200, ['data' => $sessions]);
        return true;
    }
    if (preg_match('#^/__test/sessions/(cs_[A-Za-z0-9_]+)/(pay|state)$#D', $path, $m) && $method === 'POST') {
        // pay: the customer paid (amount_total/currency overrides simulate a tampered session).
        // state: arbitrary status/payment_status, e.g. complete + unpaid for a delayed payment.
        $session = payment_simulator_transaction(static function (array &$state) use ($m): ?array {
            if (!isset($state['sessions'][$m[1]])) {
                return null;
            }
            $session = &$state['sessions'][$m[1]];
            if ($m[2] === 'pay') {
                payment_simulator_capture($session, true);
                if (isset($_POST['amount_total'])) {
                    $session['amount_total'] = (int) $_POST['amount_total'];
                }
                if (isset($_POST['currency'])) {
                    $session['currency'] = (string) $_POST['currency'];
                }
            } else {
                foreach (['status', 'payment_status'] as $field) {
                    if (isset($_POST[$field])) {
                        $session[$field] = (string) $_POST[$field];
                    }
                }
            }
            return payment_simulator_session_object($session);
        }, $store);
        if ($session === null) {
            mock_json(404, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'unknown session']]);
        } else {
            mock_json(200, $session);
        }
        return true;
    }
    mock_not_found();
    return true;
}

// --- Simulated hosted checkout page -----------------------------------------
if (preg_match('#^/pay/(cs_[A-Za-z0-9_]+)$#D', $path, $m)) {
    if ($method === 'POST') {
        $action = in_array($_POST['action'] ?? null, PAYMENT_SIMULATOR_ACTIONS, true) ? (string) $_POST['action'] : 'cancel';
        $result = payment_simulator_checkout_action($m[1], $action, $store);
        if ($result === null) {
            mock_not_found();
        } else {
            header('Location: ' . ($result['redirect'] ?? $publicBase . $path), true, 303);
        }
        return true;
    }

    $session = payment_simulator_session($m[1], $store);
    if ($session === null) {
        mock_not_found();
        return true;
    }
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; form-action 'self' *; frame-ancestors 'none'");
    $amount = number_format((int) $session['amount_total'] / 100, 2) . ' ' . strtoupper((string) $session['currency']);
    $buttons = '';
    foreach (PAYMENT_SIMULATOR_ACTIONS as $action) {
        $buttons .= '<button name="action" value="' . $action . '">' . ucfirst($action) . '</button> ';
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Simulated checkout</title></head><body>'
        . '<h1>Simulated checkout</h1><p>Status: ' . htmlspecialchars((string) $session['status']) . '</p>'
        . '<p>Amount: ' . htmlspecialchars($amount) . '</p>'
        . '<form method="post" action="">' . $buttons . '</form></body></html>';
    return true;
}

mock_not_found();
return true;
