<?php

declare(strict_types=1);

/**
 * Child process for BookWise\Tests\Support\IsolatedPhp: runs one foundation
 * function with BASE_PATH set to a throwaway directory and prints the outcome
 * as JSON. Parameters arrive in BOOKWISE_PROBE_* environment variables.
 * TEST TOOL ONLY; it runs a fixed list of actions, never arbitrary code.
 */

$base = getenv('BOOKWISE_PROBE_BASE');
$action = getenv('BOOKWISE_PROBE_ACTION');
$args = json_decode((string) base64_decode((string) getenv('BOOKWISE_PROBE_ARGS'), true), true);
if (!is_string($base) || !is_dir($base) || !is_string($action) || !is_array($args)) {
    fwrite(STDERR, "Missing or invalid BOOKWISE_PROBE_* parameters.\n");
    exit(2);
}

define('BASE_PATH', $base);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

app_config(true);
date_default_timezone_set((string) config('app.timezone'));

try {
    switch ($action) {
        case 'send_mail':
            $result = ['returned' => send_mail($args['to'], $args['name'], $args['subject'], $args['html'], $args['text'], $args['locale'] ?? null)];
            break;
        case 'app_log':
            app_log($args['level'], $args['message'], $args['context']);
            $result = [];
            break;
        case 'stripe_client':
            $result = ['api_base' => stripe_client()->getApiBase()];
            break;
        case 'simulated_payment':
            // A Checkout Session created (twice, with one idempotency key), paid on
            // the hosted page and refunded, all through stripe_client().
            $stripe = stripe_client();
            $params = [
                'mode' => 'payment',
                'line_items' => [['price_data' => ['currency' => 'sar', 'product_data' => ['name' => 'Probe Book'], 'unit_amount' => 1250], 'quantity' => 2]],
                'client_reference_id' => '7',
                'success_url' => absolute_url('success_pay.php') . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => absolute_url('cancel_pay.php?order=7'),
                'expires_at' => time() + 3600,
            ];
            $session = $stripe->checkout->sessions->create($params, ['idempotency_key' => 'probe-order-7']);
            $replayed = $stripe->checkout->sessions->create($params, ['idempotency_key' => 'probe-order-7']);
            $action = payment_simulator_checkout_action((string) $session->id, 'pay');
            $paid = $stripe->checkout->sessions->retrieve((string) $session->id);
            $refund = $stripe->refunds->create(['payment_intent' => (string) $paid->payment_intent]);
            $result = [
                'api_base' => $stripe->getApiBase(),
                'http_client' => get_class(\Stripe\ApiRequestor::httpClient()),
                'url' => $session->url,
                'amount_total' => $session->amount_total,
                'replayed' => $replayed->id === $session->id,
                'redirect' => $action['redirect'] ?? null,
                'payment_status' => $paid->payment_status,
                'refund' => [$refund->status, $refund->amount],
            ];
            break;
        case 'start_checkout':
            $result = ['redirect' => start_checkout($args['user'], $args['address'] ?? null)];
            break;
        case 'request_password_reset':
            request_password_reset($args['email']);
            $result = [];
            break;
        default:
            throw new InvalidArgumentException("Unknown probe action: $action");
    }
    $result = ['ok' => true] + $result;
} catch (Throwable $e) {
    $result = ['ok' => false, 'exception' => get_class($e), 'message' => $e->getMessage()];
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
