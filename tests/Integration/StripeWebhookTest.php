<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\CheckoutTestCase;

final class StripeWebhookTest extends CheckoutTestCase
{
    private const SECRET = 'whsec_test_suite_secret';
    private const ESABELLA = 51;    // 19.99

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('STRIPE_WEBHOOK_SECRET', self::SECRET);
    }

    private static function signature(string $payload, string $secret = self::SECRET, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function payload(string $type, array $object, string $eventId = 'evt_test_webhook'): string
    {
        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'api_version' => '2024-06-20',
            'created' => time(),
            'livemode' => false,
            'type' => $type,
            'data' => ['object' => $object],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $object
     */
    private function deliver(string $type, array $object): int
    {
        $payload = self::payload($type, $object);
        return handle_stripe_webhook($payload, self::signature($payload));
    }

    /**
     * A customer with a pending order; returns [user, session id, order].
     *
     * @return array{0: array<string, mixed>, 1: string, 2: array<string, mixed>}
     */
    private function pendingCheckout(): array
    {
        $user = $this->customerWithCart([self::ESABELLA => 2]);
        [$sessionId, $order] = $this->checkout($user);
        return [$user, $sessionId, $order];
    }

    public function testCompletedEventFulfilsTheOrderOnce(): void
    {
        [$user, $sessionId, $order] = $this->pendingCheckout();
        $session = $this->stripe->pay($sessionId);

        self::assertSame(200, $this->deliver('checkout.session.completed', $session));

        $order = $this->reloadOrder((int) $order['id']);
        self::assertSame('paid', $order['status']);
        self::assertSame($session['payment_intent'], $order['stripe_payment_intent']);
        self::assertSame([self::ESABELLA => 2], $this->salesForOrder((int) $order['id']));
        self::assertSame([], cart_lines((int) $user['id']));

        self::assertSame(200, $this->deliver('checkout.session.completed', $session), 'Stripe retries are harmless');
        self::assertSame(1, $this->countRows('sales'));
        self::assertCount(
            1,
            $this->stripe->http->requests('GET', self::RETRIEVE_SESSION),
            'The session is re-fetched from the API once; retries for a paid order need no call'
        );
    }

    public function testAsyncPaymentSucceededFulfilsTheOrder(): void
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $session = $this->stripe->pay($sessionId);

        self::assertSame(200, $this->deliver('checkout.session.async_payment_succeeded', $session));
        self::assertSame('paid', $this->reloadOrder((int) $order['id'])['status']);
    }

    public function testCompletedEventForADelayedPaymentWaits(): void
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $session = $this->stripe->session($sessionId);
        $session['status'] = 'complete';

        self::assertSame(200, $this->deliver('checkout.session.completed', $session));
        self::assertSame('pending', $this->reloadOrder((int) $order['id'])['status']);
        self::assertSame(0, $this->countRows('sales'));
    }

    public function testExpiredEventExpiresAPendingOrder(): void
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $session = $this->stripe->setState($sessionId, 'expired');

        self::assertSame(200, $this->deliver('checkout.session.expired', $session));
        self::assertSame('expired', $this->reloadOrder((int) $order['id'])['status']);
    }

    public function testForgedExpiredEventForAnOpenSessionChangesNothing(): void
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $session = $this->stripe->session($sessionId);
        $session['status'] = 'expired'; // the API still reports the session as open

        self::assertSame(200, $this->deliver('checkout.session.expired', $session));
        self::assertSame('pending', $this->reloadOrder((int) $order['id'])['status']);
    }

    public function testAsyncPaymentFailedMarksTheOrderFailed(): void
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $session = $this->stripe->setState($sessionId, 'complete', 'unpaid');

        self::assertSame(200, $this->deliver('checkout.session.async_payment_failed', $session));
        self::assertSame('failed', $this->reloadOrder((int) $order['id'])['status']);
    }

    public function testLateEventsNeverDowngradeAPaidOrder(): void
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $session = $this->stripe->pay($sessionId);
        $this->deliver('checkout.session.completed', $session);

        $this->deliver('checkout.session.expired', $session);
        $this->deliver('checkout.session.async_payment_failed', $session);

        self::assertSame('paid', $this->reloadOrder((int) $order['id'])['status']);
    }

    public function testSignedEventClaimingPaymentForAnUnpaidSessionIsNotFulfilled(): void
    {
        // Simulates a leaked webhook secret: the event says "paid", Stripe's API does not.
        [, $sessionId, $order] = $this->pendingCheckout();
        $session = $this->stripe->session($sessionId);
        $session['status'] = 'complete';
        $session['payment_status'] = 'paid';
        $session['payment_intent'] = 'pi_forged';

        self::assertSame(200, $this->deliver('checkout.session.completed', $session));
        self::assertSame('pending', $this->reloadOrder((int) $order['id'])['status']);
        self::assertSame(0, $this->countRows('sales'));
    }

    public function testCapturedPaymentThatDoesNotMatchTheOrderIsFlaggedNotFulfilled(): void
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $session = $this->stripe->pay($sessionId, ['amount_total' => 100]);

        self::assertSame(200, $this->deliver('checkout.session.completed', $session));
        // Flagged for manual review (status failed), never fulfilled.
        self::assertSame('failed', $this->reloadOrder((int) $order['id'])['status']);
        self::assertSame(0, $this->countRows('sales'));
    }

    /* -----------------------------------------------------------------
     * Signature verification
     * --------------------------------------------------------------- */

    public function testWrongSecretIsRejected(): void
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $payload = self::payload('checkout.session.completed', $this->stripe->pay($sessionId));

        self::assertSame(400, handle_stripe_webhook($payload, self::signature($payload, 'whsec_attacker')));
        self::assertSame('pending', $this->reloadOrder((int) $order['id'])['status']);
    }

    public function testTamperedPayloadIsRejected(): void
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $session = $this->stripe->session($sessionId);
        $signed = self::payload('checkout.session.completed', $session);
        $session['payment_status'] = 'paid';
        $tampered = self::payload('checkout.session.completed', $session);

        self::assertSame(400, handle_stripe_webhook($tampered, self::signature($signed)));
        self::assertSame('pending', $this->reloadOrder((int) $order['id'])['status']);
    }

    public function testMissingOrMalformedSignatureHeaderIsRejected(): void
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $payload = self::payload('checkout.session.completed', $this->stripe->pay($sessionId));

        self::assertSame(400, handle_stripe_webhook($payload, ''));
        self::assertSame(400, handle_stripe_webhook($payload, 'garbage'));
        self::assertSame(400, handle_stripe_webhook($payload, 't=' . time()));
        self::assertSame('pending', $this->reloadOrder((int) $order['id'])['status']);
    }

    public function testStaleOrFutureTimestampsAreRejected(): void
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $payload = self::payload('checkout.session.completed', $this->stripe->pay($sessionId));

        self::assertSame(400, handle_stripe_webhook($payload, self::signature($payload, self::SECRET, time() - 600)), 'Replayed old event');
        self::assertSame(400, handle_stripe_webhook($payload, self::signature($payload, self::SECRET, time() + 600)));
        self::assertSame('pending', $this->reloadOrder((int) $order['id'])['status']);

        self::assertSame(200, handle_stripe_webhook($payload, self::signature($payload, self::SECRET, time() - 60)), 'Within the 5 minute tolerance');
        self::assertSame('paid', $this->reloadOrder((int) $order['id'])['status']);
    }

    public function testInvalidJsonWithAValidSignatureIsRejected(): void
    {
        $payload = '{"id": "evt_broken", ';

        self::assertSame(400, handle_stripe_webhook($payload, self::signature($payload)));
    }

    public function testMissingSecretRefusesWithServiceUnavailable(): void
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $payload = self::payload('checkout.session.completed', $this->stripe->pay($sessionId));

        $this->setEnv('STRIPE_WEBHOOK_SECRET', null);
        self::assertSame(503, handle_stripe_webhook($payload, self::signature($payload)));

        $this->setEnv('STRIPE_WEBHOOK_SECRET', '');
        self::assertSame(503, handle_stripe_webhook($payload, self::signature($payload, '')));
        self::assertSame('pending', $this->reloadOrder((int) $order['id'])['status']);
    }

    /* -----------------------------------------------------------------
     * Events this application ignores
     * --------------------------------------------------------------- */

    public function testUnknownOrderIsAcknowledgedAndIgnored(): void
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $session = $this->stripe->pay($sessionId);

        $session['client_reference_id'] = '999999';
        self::assertSame(200, $this->deliver('checkout.session.completed', $session));

        $session['client_reference_id'] = null;
        self::assertSame(200, $this->deliver('checkout.session.completed', $session));

        $session['client_reference_id'] = 'not-a-number';
        self::assertSame(200, $this->deliver('checkout.session.completed', $session));

        self::assertSame('pending', $this->reloadOrder((int) $order['id'])['status']);
        self::assertSame(0, $this->countRows('sales'));
    }

    public function testSessionThatIsNotTheOrdersCurrentSessionIsIgnored(): void
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $session = $this->stripe->pay($sessionId);
        $session['id'] = 'cs_test_from_another_checkout';

        self::assertSame(200, $this->deliver('checkout.session.completed', $session));
        self::assertSame('pending', $this->reloadOrder((int) $order['id'])['status']);
    }

    /* -----------------------------------------------------------------
     * charge.refunded
     * --------------------------------------------------------------- */

    /**
     * An order paid (and fulfilled) through the webhook.
     *
     * @return array{0: array<string, mixed>, 1: string} [order, payment intent]
     */
    private function paidThroughWebhook(): array
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $session = $this->stripe->pay($sessionId);
        self::assertSame(200, $this->deliver('checkout.session.completed', $session));
        return [$this->reloadOrder((int) $order['id']), (string) $session['payment_intent']];
    }

    /**
     * @return array<string, mixed> A Stripe Charge as sent in charge.refunded
     */
    private static function charge(string $paymentIntent, int $amount, int $refunded): array
    {
        return [
            'id' => 'ch_test_' . substr(hash('sha256', $paymentIntent), 0, 16),
            'object' => 'charge',
            'payment_intent' => $paymentIntent,
            'amount' => $amount,
            'amount_refunded' => $refunded,
            'refunded' => $refunded >= $amount,
            'currency' => 'sar',
        ];
    }

    private static function stock(int $bookId): int
    {
        return (int) db_value('SELECT stock FROM books WHERE id = ?', [$bookId]);
    }

    public function testChargeRefundedRecordsAFullRefundExactlyOnce(): void
    {
        [$order, $intent] = $this->paidThroughWebhook();
        $orderId = (int) $order['id'];
        $stock = self::stock(self::ESABELLA);
        $refund = $this->stripe->refundPayment($intent); // e.g. refunded in the Stripe Dashboard
        $charge = self::charge($intent, (int) $order['amount_minor'], (int) $order['amount_minor']);

        self::assertSame(200, $this->deliver('charge.refunded', $charge));

        $after = $this->reloadOrder($orderId);
        self::assertSame('refunded', $after['status']);
        self::assertSame($refund['id'], $after['stripe_refund_id']);
        self::assertNotNull($after['refunded_at']);
        self::assertSame([], $this->salesForOrder($orderId));
        self::assertSame($stock + 2, self::stock(self::ESABELLA), 'The copies are back in stock');
        self::assertCount(1, $this->stripe->http->requests('GET', '#^/v1/refunds$#'), 'The refund is confirmed with the API');

        // Stripe retries and duplicate events change nothing and need no API call.
        self::assertSame(200, $this->deliver('charge.refunded', $charge));
        self::assertSame(200, $this->deliver('charge.refunded', $charge));
        self::assertSame($stock + 2, self::stock(self::ESABELLA));
        self::assertCount(1, $this->stripe->http->requests('GET', '#^/v1/refunds$#'));
        self::assertSame($after, $this->reloadOrder($orderId));
    }

    public function testForgedChargeRefundedEventIsNotTrusted(): void
    {
        // Simulates a leaked webhook secret: the event claims a refund Stripe never made.
        [$order, $intent] = $this->paidThroughWebhook();
        $stock = self::stock(self::ESABELLA);

        self::assertSame(200, $this->deliver('charge.refunded', self::charge($intent, (int) $order['amount_minor'], (int) $order['amount_minor'])));

        self::assertSame('paid', $this->reloadOrder((int) $order['id'])['status']);
        self::assertSame([self::ESABELLA => 2], $this->salesForOrder((int) $order['id']));
        self::assertSame($stock, self::stock(self::ESABELLA));
        self::assertCount(1, $this->stripe->http->requests('GET', '#^/v1/refunds$#'), 'Stripe was asked, and said no');
    }

    public function testPartialRefundsAreLeftToStaff(): void
    {
        [$order, $intent] = $this->paidThroughWebhook();
        $this->stripe->refundPayment($intent, 1000);

        self::assertSame(200, $this->deliver('charge.refunded', self::charge($intent, (int) $order['amount_minor'], 1000)));

        self::assertSame('paid', $this->reloadOrder((int) $order['id'])['status']);
        self::assertSame([], $this->stripe->http->requests('GET', '#^/v1/refunds$#'));

        // A forged "full" event is still checked against Stripe's real refunds (only 10.00 of 39.98).
        self::assertSame(200, $this->deliver('charge.refunded', self::charge($intent, (int) $order['amount_minor'], (int) $order['amount_minor'])));
        self::assertSame('paid', $this->reloadOrder((int) $order['id'])['status']);
    }

    public function testChargeRefundedForUnknownPaymentsOrUnpaidOrdersIsIgnored(): void
    {
        [, , $order] = $this->pendingCheckout();

        self::assertSame(200, $this->deliver('charge.refunded', self::charge('pi_test_unknown', 1000, 1000)));
        self::assertSame(200, $this->deliver('charge.refunded', ['id' => 'ch_test_no_intent', 'object' => 'charge', 'amount' => 1000, 'amount_refunded' => 1000]));
        self::assertSame(200, $this->deliver('charge.refunded', self::charge("pi_x' OR '1'='1", 1000, 1000)));

        self::assertSame('pending', $this->reloadOrder((int) $order['id'])['status']);
        self::assertSame([], $this->stripe->http->requests('GET', '#^/v1/refunds$#'));
    }

    public function testAStripeOutageWhileConfirmingMakesStripeRetry(): void
    {
        [$order, $intent] = $this->paidThroughWebhook();
        $this->stripe->refundPayment($intent);
        $charge = self::charge($intent, (int) $order['amount_minor'], (int) $order['amount_minor']);
        $this->stripe->http->failNext('GET', '#^/v1/refunds$#', 500);

        self::assertSame(503, $this->deliver('charge.refunded', $charge));
        self::assertSame('paid', $this->reloadOrder((int) $order['id'])['status']);

        self::assertSame(200, $this->deliver('charge.refunded', $charge), 'The retry succeeds');
        self::assertSame('refunded', $this->reloadOrder((int) $order['id'])['status']);
    }

    public function testChargeRefundedNeedsAValidSignature(): void
    {
        [$order, $intent] = $this->paidThroughWebhook();
        $this->stripe->refundPayment($intent);
        $payload = self::payload('charge.refunded', self::charge($intent, (int) $order['amount_minor'], (int) $order['amount_minor']));

        self::assertSame(400, handle_stripe_webhook($payload, self::signature($payload, 'whsec_attacker')));
        self::assertSame(400, handle_stripe_webhook($payload, ''));
        self::assertSame('paid', $this->reloadOrder((int) $order['id'])['status']);
    }

    public function testAnAdminRefundAndItsWebhookAreRecordedOnce(): void
    {
        [$order, $intent] = $this->paidThroughWebhook();
        self::assertSame('refunded', refund_order((int) $order['id']));
        $stock = self::stock(self::ESABELLA);
        $refundId = $this->reloadOrder((int) $order['id'])['stripe_refund_id'];

        self::assertSame(200, $this->deliver('charge.refunded', self::charge($intent, (int) $order['amount_minor'], (int) $order['amount_minor'])));

        self::assertSame($stock, self::stock(self::ESABELLA), 'Not restocked twice');
        self::assertSame($refundId, $this->reloadOrder((int) $order['id'])['stripe_refund_id']);
        self::assertCount(1, $this->stripe->refunds());
    }

    public function testNonCheckoutEventsAreIgnoredAndCheckoutEventsSyncWithStripe(): void
    {
        [, $sessionId, $order] = $this->pendingCheckout();
        $session = $this->stripe->pay($sessionId);

        self::assertSame(200, $this->deliver('payment_intent.succeeded', [
            'id' => 'pi_test_123',
            'object' => 'payment_intent',
            'metadata' => ['order_id' => (string) $order['id']],
        ]));
        self::assertSame(200, $this->deliver('customer.created', ['id' => 'cus_123', 'object' => 'customer']));
        self::assertSame('pending', $this->reloadOrder((int) $order['id'])['status'], 'Non-checkout events are ignored');

        // Any checkout.session.* event only triggers a sync with Stripe's real state.
        self::assertSame(200, $this->deliver('checkout.session.unknown_future_event', $session));
        self::assertSame('paid', $this->reloadOrder((int) $order['id'])['status']);
    }
}
