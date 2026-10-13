<?php

declare(strict_types=1);

// Stripe calls this endpoint server-to-server: no session or CSRF token, the
// Stripe-Signature header is the authentication.
define('BOOKWISE_NO_SESSION', true);

require __DIR__ . '/../src/bootstrap.php';

if (!is_post()) {
    header('Allow: POST');
    json_response(['error' => 'Method not allowed'], 405);
}

$payload = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $status = handle_stripe_webhook($payload, is_string($signature) ? $signature : '');
} catch (Throwable $e) {
    // A 5xx makes Stripe retry the delivery later.
    app_log('error', 'Stripe webhook processing failed: ' . get_class($e) . ': ' . $e->getMessage());
    $status = 500;
}

json_response(['received' => $status === 200], $status);
