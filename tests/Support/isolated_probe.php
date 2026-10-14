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
        case 'start_checkout':
            $result = ['redirect' => start_checkout($args['user'])];
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
