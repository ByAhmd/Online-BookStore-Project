<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

use LogicException;
use ReflectionFunction;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

/**
 * Runs tests/Support/stripe_mock_router.php with `php -S` on a free local
 * port (one server per PHPUnit run, with its own state file so it never
 * touches a developer's mock) and points the application's Stripe client at
 * it. The server is terminated when the PHPUnit process exits.
 *
 * stripe_client() caches its StripeClient for the whole process, so the mock
 * must be started before anything calls it; start() fails loudly otherwise.
 */
final class StripeMock
{
    private static ?self $instance = null;

    /** @var resource|null */
    private $process;

    /**
     * @param resource $process
     */
    private function __construct(
        public readonly string $baseUrl,
        public readonly StripeHttpRecorder $http,
        $process,
        private readonly string $storeFile,
        private readonly string $logFile,
    ) {
        $this->process = $process;
    }

    public static function start(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $port = self::freePort();
        $storeFile = (string) tempnam(sys_get_temp_dir(), 'bookwise-stripe-mock-');
        $logFile = $storeFile . '.log';

        $env = getenv();
        $env['STRIPE_MOCK_STORE'] = $storeFile;
        $env['STRIPE_SIM_TEST_API'] = '1'; // test controls + customer emails
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-S', '127.0.0.1:' . $port, BASE_PATH . '/tests/Support/stripe_mock_router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
            $pipes,
            BASE_PATH,
            $env
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the Stripe mock server.');
        }
        fclose($pipes[0]);

        $mock = new self('http://127.0.0.1:' . $port, new StripeHttpRecorder(CurlClient::instance()), $process, $storeFile, $logFile);
        register_shutdown_function([$mock, 'stop']);
        $mock->waitUntilReady($port);
        $mock->routeApplication();

        return self::$instance = $mock;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
        // The simulator's state, plus the lock and temporary files it keeps next to it.
        foreach ([$this->storeFile, $this->storeFile . '.lock', $this->storeFile . '.tmp', $this->logFile] as $file) {
            @unlink($file);
        }
    }

    /**
     * Forgets all mock sessions and recorded requests.
     */
    public function reset(): void
    {
        $this->call('POST', '/__test/reset');
        $this->http->reset();
    }

    /**
     * Simulates the customer paying on the hosted page. $overrides may set
     * amount_total or currency to simulate a tampered/mismatching session.
     *
     * @param array<string, string|int> $overrides
     * @return array<string, mixed> The updated session
     */
    public function pay(string $sessionId, array $overrides = []): array
    {
        $response = $this->call('POST', '/__test/sessions/' . rawurlencode($sessionId) . '/pay', $overrides);
        if ($response['status'] !== 200) {
            throw new RuntimeException("Mock could not pay $sessionId: " . json_encode($response['body']));
        }
        return $response['body'];
    }

    /**
     * Sets a session's status and/or payment_status as Stripe would report them
     * (e.g. 'expired', or 'complete' + 'unpaid' for a delayed payment).
     *
     * @return array<string, mixed> The updated session
     */
    public function setState(string $sessionId, ?string $status = null, ?string $paymentStatus = null): array
    {
        $form = array_filter(['status' => $status, 'payment_status' => $paymentStatus], static fn ($v): bool => $v !== null);
        $response = $this->call('POST', '/__test/sessions/' . rawurlencode($sessionId) . '/state', $form);
        if ($response['status'] !== 200) {
            throw new RuntimeException("Mock could not update $sessionId: " . json_encode($response['body']));
        }
        return $response['body'];
    }

    /**
     * Refunds a payment directly on the mock, as staff would in the Stripe
     * Dashboard (BookWise only hears about it through a webhook).
     *
     * @return array<string, mixed> The refund
     */
    public function refundPayment(string $paymentIntent, ?int $amount = null): array
    {
        $form = ['payment_intent' => $paymentIntent];
        if ($amount !== null) {
            $form['amount'] = $amount;
        }
        $response = $this->call('POST', '/v1/refunds', $form);
        if ($response['status'] !== 200) {
            throw new RuntimeException("Mock could not refund $paymentIntent: " . json_encode($response['body']));
        }
        return $response['body'];
    }

    /**
     * Refunds the mock knows, newest first (optionally of one payment intent).
     *
     * @return list<array<string, mixed>>
     */
    public function refunds(?string $paymentIntent = null): array
    {
        $query = 'limit=100' . ($paymentIntent !== null ? '&payment_intent=' . rawurlencode($paymentIntent) : '');
        $data = $this->call('GET', '/v1/refunds?' . $query)['body']['data'] ?? [];
        return is_array($data) ? array_values($data) : [];
    }

    /**
     * @return array<string, mixed>|null The mock's stored session
     */
    public function session(string $sessionId): ?array
    {
        foreach ($this->sessions() as $session) {
            if (($session['id'] ?? null) === $sessionId) {
                return $session;
            }
        }
        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sessions(): array
    {
        $data = $this->call('GET', '/__test/sessions')['body']['data'] ?? [];
        return is_array($data) ? array_values($data) : [];
    }

    /**
     * Calls the mock directly (not through the application's Stripe client).
     *
     * @param array<string, string|int> $form
     * @return array{status: int, body: array<string, mixed>}
     */
    public function call(string $method, string $path, array $form = [], ?string $bearer = 'sk_test_phpunit'): array
    {
        $headers = "Content-Type: application/x-www-form-urlencoded\r\n";
        if ($bearer !== null) {
            $headers .= "Authorization: Bearer $bearer\r\n";
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $headers,
            'content' => http_build_query($form),
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        // The response headers come from the stream's metadata: the
        // $http_response_header variable is deprecated as of PHP 8.5.
        $stream = @fopen($this->baseUrl . $path, 'r', false, $context);
        $body = $stream !== false ? stream_get_contents($stream) : false;
        $statusLine = $stream !== false ? (string) (stream_get_meta_data($stream)['wrapper_data'][0] ?? '') : '';
        if ($stream !== false) {
            fclose($stream);
        }
        if ($body === false || !preg_match('#^HTTP/\S+ (\d{3})#', $statusLine, $m)) {
            throw new RuntimeException("Stripe mock request $method $path failed. Server log:\n" . $this->serverLog());
        }
        $decoded = json_decode($body, true);
        return ['status' => (int) $m[1], 'body' => is_array($decoded) ? $decoded : []];
    }

    private function routeApplication(): void
    {
        putenv('STRIPE_API_BASE=' . $this->baseUrl);
        $_ENV['STRIPE_API_BASE'] = $this->baseUrl;
        app_config(true);

        $cached = (new ReflectionFunction('stripe_client'))->getStaticVariables()['client'] ?? null;
        if ($cached instanceof StripeClient && $cached->getApiBase() !== $this->baseUrl) {
            throw new LogicException(
                'stripe_client() was already initialised against ' . $cached->getApiBase()
                . ' before the Stripe mock started. Start BookWise\\Tests\\Support\\StripeMock first.'
            );
        }
        ApiRequestor::setHttpClient($this->http);
    }

    private function waitUntilReady(int $port): void
    {
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                throw new RuntimeException("Stripe mock server exited early. Log:\n" . $this->serverLog());
            }
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        }
        $this->stop();
        throw new RuntimeException("Stripe mock server did not start on port $port.");
    }

    private function serverLog(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '(no log)';
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new RuntimeException("Cannot find a free port: $errstr");
        }
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);
        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
