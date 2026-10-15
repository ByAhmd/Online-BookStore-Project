<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use PaymentSimulatorHttpClient;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;
use Stripe\StripeClient;

/**
 * The payment simulator (src/payment_simulator.php) against a throwaway state
 * file: its Stripe API answers, the hosted page's buttons, time-based changes
 * and the stripe-php client that calls it in-process.
 */
final class PaymentSimulatorTest extends TestCase
{
    private string $store;
    private ?ClientInterface $previousHttpClient = null;
    private ?bool $previousTelemetry = null;

    protected function setUp(): void
    {
        $this->store = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bookwise-simulator-' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR . 'state.json';
    }

    protected function tearDown(): void
    {
        if ($this->previousHttpClient !== null) {
            ApiRequestor::setHttpClient($this->previousHttpClient);
        }
        if ($this->previousTelemetry !== null) {
            Stripe::setEnableTelemetry($this->previousTelemetry);
        }
        // The state file, its .lock file and whatever a test left next to them.
        foreach (glob(dirname($this->store) . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir(dirname($this->store));
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function api(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        [$status, $body] = payment_simulator_api($method, $path, $params, $idempotencyKey, [
            'store' => $this->store,
            'hosted_page_url' => static fn (string $id): string => 'https://shop.example/pay.php?session=' . $id,
        ]);
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return [$status, $decoded];
    }

    /**
     * Form parameters of a session for $quantity × 12.50 SAR, as Stripe receives them.
     *
     * @return array<string, mixed>
     */
    private static function sessionParams(string $quantity = '2', string $unitAmount = '1250'): array
    {
        return [
            'mode' => 'payment',
            'line_items' => [
                ['price_data' => ['currency' => 'sar', 'product_data' => ['name' => 'Esabella'], 'unit_amount' => $unitAmount], 'quantity' => $quantity],
                ['price_data' => ['currency' => 'sar', 'product_data' => ['name' => 'كتاب'], 'unit_amount' => '550'], 'quantity' => '1'],
            ],
            'client_reference_id' => '42',
            'customer_email' => 'reader@example.com',
            'metadata' => ['order_id' => '42'],
            'success_url' => 'https://shop.example/success_pay.php?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'https://shop.example/cancel_pay.php?order=42',
            'expires_at' => (string) (time() + 3600),
        ];
    }

    /**
     * @return array<string, mixed> The new session as the API returned it
     */
    private function createSession(): array
    {
        [$status, $session] = $this->api('POST', '/v1/checkout/sessions', self::sessionParams());
        self::assertSame(200, $status, json_encode($session) ?: '');
        return $session;
    }

    /**
     * Rewrites a stored session, e.g. to move its times into the past.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    private function changeSession(string $id, callable $change): void
    {
        payment_simulator_transaction(static function (array &$state) use ($id, $change): void {
            $state['sessions'][$id] = $change($state['sessions'][$id]);
        }, $this->store);
    }

    public function testCreatesAStripeShapedSessionAndKeepsTheLineItemsForThePaymentPage(): void
    {
        $session = $this->createSession();

        self::assertMatchesRegularExpression('/^cs_test_[a-f0-9]{24}$/', $session['id']);
        self::assertSame('checkout.session', $session['object']);
        self::assertSame(['open', 'unpaid'], [$session['status'], $session['payment_status']]);
        self::assertSame(2 * 1250 + 550, $session['amount_total']);
        self::assertSame('sar', $session['currency']);
        self::assertSame('42', $session['client_reference_id']);
        self::assertSame(['order_id' => '42'], $session['metadata']);
        self::assertNull($session['payment_intent']);
        self::assertNull($session['customer_email'], 'The simulator keeps no email addresses unless a test asks for them');
        self::assertSame('https://shop.example/pay.php?session=' . $session['id'], $session['url']);
        self::assertFalse($session['livemode']);
        self::assertArrayNotHasKey('simulator', $session, 'Internal fields are not part of the API object');

        [$status, $retrieved] = $this->api('GET', '/v1/checkout/sessions/' . $session['id']);
        self::assertSame([200, $session], [$status, $retrieved]);

        $stored = payment_simulator_session($session['id'], $this->store);
        self::assertNotNull($stored);
        self::assertSame([
            ['name' => 'Esabella', 'quantity' => 2, 'unit_amount' => 1250],
            ['name' => 'كتاب', 'quantity' => 1, 'unit_amount' => 550],
        ], $stored['simulator']['line_items']);
    }

    public function testEmptyMetadataIsAJsonObjectLikeOnStripe(): void
    {
        $params = self::sessionParams();
        unset($params['metadata']);
        [, $body] = payment_simulator_api('POST', '/v1/checkout/sessions', $params, null, ['store' => $this->store]);

        self::assertStringContainsString('"metadata":{}', $body);
    }

    public function testRefusesInvalidSessionsLikeStripe(): void
    {
        $missing = self::sessionParams();
        unset($missing['line_items']);
        $mixedCurrencies = self::sessionParams();
        $mixedCurrencies['line_items'][1]['price_data']['currency'] = 'usd';
        // Removed in API version 2026-09-30.endive, which stripe-php 22 pins.
        $paymentMethodTypes = self::sessionParams() + ['payment_method_types' => ['card']];

        $cases = [
            'no line items' => [$missing, 'parameter_missing'],
            'decimal amount' => [self::sessionParams('2', '12.50'), 'parameter_invalid_integer'],
            'zero quantity' => [self::sessionParams('0'), 'parameter_invalid_integer'],
            'two currencies' => [$mixedCurrencies, 'parameter_invalid_string'],
            'payment_method_types' => [$paymentMethodTypes, 'parameter_unknown'],
        ];
        foreach ($cases as $label => [$params, $code]) {
            [$status, $body] = $this->api('POST', '/v1/checkout/sessions', $params);
            self::assertSame(400, $status, $label);
            self::assertSame($code, $body['error']['code'] ?? null, $label);
            self::assertSame('invalid_request_error', $body['error']['type'] ?? null, $label);
        }
        self::assertSame([], payment_simulator_transaction(static fn (array &$state): array => $state['sessions'], $this->store));
    }

    public function testUnknownSessionsAndUrlsAre404(): void
    {
        [$status, $body] = $this->api('GET', '/v1/checkout/sessions/cs_test_unknown');
        self::assertSame([404, 'resource_missing'], [$status, $body['error']['code']]);
        self::assertSame("No such checkout.session: 'cs_test_unknown'", $body['error']['message']);

        [$status, $body] = $this->api('POST', '/v1/checkout/sessions/cs_test_unknown/expire');
        self::assertSame([404, 'resource_missing'], [$status, $body['error']['code']]);

        [$status, $body] = $this->api('GET', '/v1/customers');
        self::assertSame([404, 'url_invalid'], [$status, $body['error']['code']]);
        [$status] = $this->api('GET', '/v1/checkout/sessions/' . $this->createSession()['id'] . '/expire');
        self::assertSame(404, $status, 'Expiring needs a POST');
    }

    public function testOpenSessionsCanBeExpiredOnce(): void
    {
        $id = $this->createSession()['id'];

        [$status, $expired] = $this->api('POST', "/v1/checkout/sessions/$id/expire");
        self::assertSame([200, 'expired', 'unpaid'], [$status, $expired['status'], $expired['payment_status']]);

        [$status, $body] = $this->api('POST', "/v1/checkout/sessions/$id/expire");
        self::assertSame([400, 'checkout_session_not_open'], [$status, $body['error']['code']]);
    }

    public function testOpenSessionsExpireOnTheirOwnAtExpiresAt(): void
    {
        $id = $this->createSession()['id'];
        $this->changeSession($id, static fn (array $session): array => ['expires_at' => time() - 1] + $session);

        self::assertSame('expired', $this->api('GET', "/v1/checkout/sessions/$id")[1]['status']);
        self::assertSame(['outcome' => 'closed', 'redirect' => 'https://shop.example/cancel_pay.php?order=42'], payment_simulator_checkout_action($id, 'pay', $this->store), 'An expired session takes no payment');
        self::assertSame('unpaid', $this->api('GET', "/v1/checkout/sessions/$id")[1]['payment_status']);
    }

    public function testPayCompletesTheSessionAndReturnsToTheStore(): void
    {
        $id = $this->createSession()['id'];

        self::assertSame(
            ['outcome' => 'paid', 'redirect' => 'https://shop.example/success_pay.php?session_id=' . $id],
            payment_simulator_checkout_action($id, 'pay', $this->store)
        );
        $session = $this->api('GET', "/v1/checkout/sessions/$id")[1];
        self::assertSame(['complete', 'paid'], [$session['status'], $session['payment_status']]);
        self::assertMatchesRegularExpression('/^pi_test_[a-f0-9]{24}$/', $session['payment_intent']);

        // Paying again is impossible; the customer is sent to the confirmation.
        self::assertSame(
            ['outcome' => 'closed', 'redirect' => 'https://shop.example/success_pay.php?session_id=' . $id],
            payment_simulator_checkout_action($id, 'pay', $this->store)
        );
        self::assertSame($session['payment_intent'], $this->api('GET', "/v1/checkout/sessions/$id")[1]['payment_intent']);
    }

    public function testADeclinedCardLeavesTheSessionOpenAndUnpaid(): void
    {
        $id = $this->createSession()['id'];

        self::assertSame(['outcome' => 'declined', 'redirect' => null], payment_simulator_checkout_action($id, 'decline', $this->store));
        $session = $this->api('GET', "/v1/checkout/sessions/$id")[1];
        self::assertSame(['open', 'unpaid', null], [$session['status'], $session['payment_status'], $session['payment_intent']]);

        self::assertSame('paid', payment_simulator_checkout_action($id, 'pay', $this->store)['outcome'] ?? null, 'The customer can try again');
    }

    public function testASlowPaymentIsProcessingThenSucceeds(): void
    {
        $id = $this->createSession()['id'];

        self::assertSame(
            ['outcome' => 'processing', 'redirect' => 'https://shop.example/success_pay.php?session_id=' . $id],
            payment_simulator_checkout_action($id, 'slow', $this->store)
        );
        $session = $this->api('GET', "/v1/checkout/sessions/$id")[1];
        self::assertSame(['complete', 'unpaid'], [$session['status'], $session['payment_status']], 'Stripe reports a delayed payment as complete but unpaid');
        self::assertNotNull($session['payment_intent']);
        [$status, $refund] = $this->api('POST', '/v1/refunds', ['payment_intent' => $session['payment_intent']]);
        self::assertSame([400, 'charge_not_refundable'], [$status, $refund['error']['code']], 'Nothing to refund yet');

        $stored = payment_simulator_session($id, $this->store);
        self::assertNotNull($stored);
        $until = $stored['simulator']['processing_until'];
        self::assertGreaterThanOrEqual(time() + PAYMENT_SIMULATOR_PROCESSING_SECONDS - 1, $until);
        self::assertLessThanOrEqual(time() + PAYMENT_SIMULATOR_PROCESSING_SECONDS, $until);

        $this->changeSession($id, static function (array $session): array {
            $session['simulator']['processing_until'] = time() - 1;
            return $session;
        });
        $settled = $this->api('GET', "/v1/checkout/sessions/$id")[1];
        self::assertSame(['complete', 'paid', $session['payment_intent']], [$settled['status'], $settled['payment_status'], $settled['payment_intent']]);
    }

    public function testCancelReturnsToTheStoreAndKeepsTheSessionOpen(): void
    {
        $id = $this->createSession()['id'];

        self::assertSame(
            ['outcome' => 'canceled', 'redirect' => 'https://shop.example/cancel_pay.php?order=42'],
            payment_simulator_checkout_action($id, 'cancel', $this->store)
        );
        self::assertSame('open', $this->api('GET', "/v1/checkout/sessions/$id")[1]['status'], 'Like Stripe: the store expires it later');
    }

    public function testThePageActionsRejectUnknownSessionsAndActions(): void
    {
        self::assertNull(payment_simulator_checkout_action('cs_test_unknown', 'pay', $this->store));
        self::assertNull(payment_simulator_session('cs_test_unknown', $this->store));

        $this->expectException(\InvalidArgumentException::class);
        payment_simulator_checkout_action($this->createSession()['id'], 'refund', $this->store);
    }

    public function testARepeatedIdempotencyKeyReplaysTheFirstAnswer(): void
    {
        [, $first] = $this->api('POST', '/v1/checkout/sessions', self::sessionParams(), 'order-42');
        [$status, $again] = $this->api('POST', '/v1/checkout/sessions', self::sessionParams(), 'order-42');
        self::assertSame([200, $first], [$status, $again]);
        self::assertCount(1, payment_simulator_transaction(static fn (array &$state): array => $state['sessions'], $this->store));

        [$status, $body] = $this->api('POST', '/v1/checkout/sessions', self::sessionParams('3'), 'order-42');
        self::assertSame([400, 'idempotency_error', 'idempotency_key_in_use'], [$status, $body['error']['type'], $body['error']['code']]);

        [, $other] = $this->api('POST', '/v1/checkout/sessions', self::sessionParams(), 'order-43');
        self::assertNotSame($first['id'], $other['id']);

        // Stripe forgets keys after 24 hours.
        payment_simulator_transaction(static function (array &$state): void {
            $state['idempotency']['order-42']['created'] = time() - PAYMENT_SIMULATOR_IDEMPOTENCY_SECONDS - 1;
        }, $this->store);
        [, $later] = $this->api('POST', '/v1/checkout/sessions', self::sessionParams(), 'order-42');
        self::assertNotSame($first['id'], $later['id']);
    }

    public function testRefundsArePaidAtMostOnceAndListedNewestFirst(): void
    {
        $id = $this->createSession()['id'];
        payment_simulator_checkout_action($id, 'pay', $this->store);
        $intent = (string) $this->api('GET', "/v1/checkout/sessions/$id")[1]['payment_intent'];

        [$status, $partial] = $this->api('POST', '/v1/refunds', ['payment_intent' => $intent, 'amount' => '500']);
        self::assertSame([200, 'refund', 500, 'sar', $intent, 'succeeded'], [$status, $partial['object'], $partial['amount'], $partial['currency'], $partial['payment_intent'], $partial['status']]);
        self::assertMatchesRegularExpression('/^re_test_[a-f0-9]{24}$/', $partial['id']);

        [$status, $rest] = $this->api('POST', '/v1/refunds', ['payment_intent' => $intent, 'metadata' => ['order_id' => '42']], 'refund-42');
        self::assertSame([200, 2550], [$status, $rest['amount']], 'Without an amount the rest is refunded');
        self::assertSame(['order_id' => '42'], $rest['metadata']);
        self::assertSame([200, $rest], $this->api('POST', '/v1/refunds', ['payment_intent' => $intent, 'metadata' => ['order_id' => '42']], 'refund-42'), 'A retried refund is not paid twice');

        [$status, $body] = $this->api('POST', '/v1/refunds', ['payment_intent' => $intent]);
        self::assertSame([400, 'charge_already_refunded'], [$status, $body['error']['code']]);
        [$status, $body] = $this->api('POST', '/v1/refunds', ['payment_intent' => 'pi_test_unknown']);
        self::assertSame([404, 'resource_missing'], [$status, $body['error']['code']]);
        [$status, $body] = $this->api('POST', '/v1/refunds', ['payment_intent' => $intent, 'amount' => '1.5']);
        self::assertSame([400, 'parameter_invalid_integer'], [$status, $body['error']['code']]);

        [$status, $list] = $this->api('GET', '/v1/refunds', ['payment_intent' => $intent, 'limit' => '100']);
        self::assertSame([200, 'list', false], [$status, $list['object'], $list['has_more']]);
        self::assertSame([$rest['id'], $partial['id']], array_column($list['data'], 'id'));
        self::assertSame([], $this->api('GET', '/v1/refunds', ['payment_intent' => 'pi_test_other'])[1]['data']);
        self::assertTrue($this->api('GET', '/v1/refunds', ['limit' => '1'])[1]['has_more']);

        self::assertSame([200, $partial], $this->api('GET', '/v1/refunds/' . $partial['id']));
        self::assertSame(404, $this->api('GET', '/v1/refunds/re_test_unknown')[0]);
    }

    public function testOldRecordsAreForgotten(): void
    {
        $id = $this->createSession()['id'];
        $this->changeSession($id, static fn (array $session): array => ['created' => time() - PAYMENT_SIMULATOR_RETENTION_SECONDS - 1] + $session);

        self::assertSame(404, $this->api('GET', "/v1/checkout/sessions/$id")[0]);
    }

    public function testAnUnreadableStateFileIsMovedAsideAndTheSimulatorStartsAfresh(): void
    {
        mkdir(dirname($this->store), 0700, true);
        file_put_contents($this->store, '{not json');
        $log = log_path('app.log');
        clearstatcache();
        $logSize = is_file($log) ? (int) filesize($log) : 0;

        $id = $this->createSession()['id'];

        $state = json_decode((string) file_get_contents($this->store), true);
        self::assertSame([$id], array_keys($state['sessions']));
        $aside = glob($this->store . '.unreadable-*') ?: [];
        self::assertCount(1, $aside, 'The unreadable file is kept, not overwritten');
        self::assertSame('{not json', file_get_contents($aside[0]));
        self::assertStringContainsString(
            'WARNING: The payment simulator could not read its state file; it was moved aside',
            (string) file_get_contents($log, false, null, $logSize)
        );
    }

    public function testAFailedSaveIsReportedAndKeepsThePreviousState(): void
    {
        $id = $this->createSession()['id'];
        $before = (string) file_get_contents($this->store);
        // The temporary file the state is written to cannot be created.
        mkdir($this->store . '.tmp');

        try {
            payment_simulator_checkout_action($id, 'pay', $this->store);
            self::fail('A failed save must not look like a saved payment');
        } catch (\RuntimeException $e) {
            self::assertSame("The payment simulator cannot save {$this->store}", $e->getMessage());
        }
        self::assertSame($before, file_get_contents($this->store), 'The previous state is intact');
        self::assertSame('open', payment_simulator_session($id, $this->store)['status'] ?? null);

        // stripe-php sees a connection problem, so the store retries or reports it.
        $client = new PaymentSimulatorHttpClient($this->store);
        $this->expectException(ApiConnectionException::class);
        $client->request('post', PAYMENT_SIMULATOR_API_BASE . "/v1/checkout/sessions/$id/expire", [], [], false);
    }

    public function testAMissingStoreDirectoryIsReportedAsAConnectionProblem(): void
    {
        $blocker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bookwise-simulator-file-' . bin2hex(random_bytes(6));
        file_put_contents($blocker, 'a file, not a directory');
        try {
            $client = new PaymentSimulatorHttpClient($blocker . DIRECTORY_SEPARATOR . 'state.json');
            $this->expectException(ApiConnectionException::class);
            $this->expectExceptionMessage('The payment simulator is not available');
            $client->request('get', PAYMENT_SIMULATOR_API_BASE . '/v1/refunds', [], [], false);
        } finally {
            @unlink($blocker);
        }
    }

    public function testThePaymentPageHasTextForEveryButtonAndEnding(): void
    {
        // pay.php builds these keys at runtime.
        foreach (['en', 'ar'] as $locale) {
            foreach (PAYMENT_SIMULATOR_ACTIONS as $action) {
                self::assertNotNull(translation_entry("shop.pay.$action", $locale), "shop.pay.$action ($locale)");
                self::assertNotNull(translation_entry("shop.pay.{$action}_hint", $locale), "shop.pay.{$action}_hint ($locale)");
            }
            foreach (['paid', 'processing', 'expired'] as $state) {
                self::assertNotNull(translation_entry("shop.pay.closed.$state", $locale), "shop.pay.closed.$state ($locale)");
            }
        }
    }

    public function testStripePhpTalksToTheSimulatorInProcess(): void
    {
        $this->previousHttpClient = ApiRequestor::httpClient();
        ApiRequestor::setHttpClient(new PaymentSimulatorHttpClient($this->store));
        // As in stripe_client(): no telemetry ID file in the user's configuration folder.
        $this->previousTelemetry = Stripe::getEnableTelemetry();
        Stripe::setEnableTelemetry(false);
        $stripe = new StripeClient(['api_key' => PAYMENT_SIMULATOR_API_KEY, 'api_base' => PAYMENT_SIMULATOR_API_BASE]);

        // Native integers and nested arrays are encoded as they would be on the wire.
        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [['price_data' => ['currency' => 'sar', 'product_data' => ['name' => 'Esabella'], 'unit_amount' => 1999], 'quantity' => 3]],
            'client_reference_id' => '9',
            'metadata' => ['order_id' => '9'],
            'success_url' => 'https://shop.example/success_pay.php?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'https://shop.example/cancel_pay.php?order=9',
            'expires_at' => time() + 3600,
        ], ['idempotency_key' => 'bookwise-order-9']);
        self::assertSame(5997, $session->amount_total);
        self::assertSame('9', $session->metadata->order_id);
        self::assertSame(config('app.url') . '/pay.php?session=' . $session->id, $session->url, 'The default payment page is pay.php of APP_URL');

        $retrieved = $stripe->checkout->sessions->retrieve($session->id);
        self::assertSame('open', $retrieved->status);

        try {
            $stripe->checkout->sessions->retrieve('cs_test_unknown');
            self::fail('Expected an InvalidRequestException');
        } catch (InvalidRequestException $e) {
            self::assertSame([404, 'resource_missing'], [$e->getHttpStatus(), $e->getStripeCode()]);
        }

        try {
            $stripe->checkout->sessions->create(['line_items' => [['price_data' => ['currency' => 'sar', 'unit_amount' => 1], 'quantity' => 1]]], ['idempotency_key' => 'bookwise-order-9']);
            self::fail('Expected an IdempotencyException');
        } catch (IdempotencyException $e) {
            self::assertSame(400, $e->getHttpStatus());
        }

        payment_simulator_checkout_action($session->id, 'pay', $this->store);
        $paid = $stripe->checkout->sessions->retrieve($session->id);
        $refund = $stripe->refunds->create(['payment_intent' => $paid->payment_intent]);
        self::assertSame(['succeeded', 5997], [$refund->status, $refund->amount]);
        $refunds = $stripe->refunds->all(['payment_intent' => $paid->payment_intent, 'limit' => 100]);
        self::assertSame([$refund->id], array_map(static fn ($r): string => $r->id, $refunds->data));

        $expired = $stripe->checkout->sessions->expire($stripe->checkout->sessions->create([
            'line_items' => [['price_data' => ['currency' => 'sar', 'unit_amount' => 500], 'quantity' => 1]],
        ])->id);
        self::assertSame('expired', $expired->status);
    }
}
