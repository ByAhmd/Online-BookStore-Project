<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\CheckoutTestCase;
use BookWise\Tests\Support\StockFixtures;
use RefundException;
use Stripe\Checkout\Session;

/**
 * refund_order() and apply_order_refund(): Stripe refund first, then the
 * local bookkeeping (status, refund id, sales removed, copies restocked)
 * exactly once.
 */
final class RefundTest extends CheckoutTestCase
{
    use StockFixtures;

    private const BIOLOGY = 7;      // 5.50, 24 in stock
    private const ESABELLA = 51;    // 19.99, 24 in stock
    private const REFUNDS = '#^/v1/refunds$#';

    /**
     * A customer's order for the given books, paid and fulfilled.
     *
     * @param array<int, int> $quantities book id => quantity
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [user, order]
     */
    private function paidOrder(array $quantities): array
    {
        $user = $this->customerWithCart($quantities);
        [$sessionId, $order] = $this->checkout($user);
        $this->stripe->pay($sessionId);
        self::assertSame('paid', confirm_checkout_return((int) $user['id'], $sessionId)['state']);
        return [$user, $this->reloadOrder((int) $order['id'])];
    }

    private function assertRefundFails(int $orderId, string $message): void
    {
        try {
            refund_order($orderId);
            self::fail('Expected RefundException');
        } catch (RefundException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    public function testRefundMarksTheOrderRefundedRemovesItsSalesAndRestocks(): void
    {
        $esabella = $this->stockOf(self::ESABELLA);
        $biology = $this->stockOf(self::BIOLOGY);
        [$user, $order] = $this->paidOrder([self::ESABELLA => 2, self::BIOLOGY => 1]);
        $orderId = (int) $order['id'];
        self::assertSame([$esabella - 2, $biology - 1], [$this->stockOf(self::ESABELLA), $this->stockOf(self::BIOLOGY)]);
        self::assertTrue(user_has_purchased((int) $user['id'], self::ESABELLA));

        self::assertSame('refunded', refund_order($orderId));

        $refunded = $this->reloadOrder($orderId);
        self::assertSame('refunded', $refunded['status']);
        self::assertNotNull($refunded['refunded_at']);
        self::assertNotNull($refunded['paid_at'], 'The payment date is kept');
        $stripeRefunds = $this->stripe->refunds((string) $order['stripe_payment_intent']);
        self::assertCount(1, $stripeRefunds);
        self::assertSame($stripeRefunds[0]['id'], $refunded['stripe_refund_id']);
        self::assertSame((int) $order['amount_minor'], $stripeRefunds[0]['amount'], 'The full amount is refunded');
        self::assertSame(['order_id' => (string) $orderId], $stripeRefunds[0]['metadata']);

        self::assertSame([], $this->salesForOrder($orderId), 'The books no longer count as bought through this order');
        self::assertFalse(user_has_purchased((int) $user['id'], self::ESABELLA));
        self::assertSame([$esabella, $biology], [$this->stockOf(self::ESABELLA), $this->stockOf(self::BIOLOGY)], 'The copies are back in stock');

        $request = $this->stripe->http->requests('POST', self::REFUNDS)[0];
        self::assertSame($order['stripe_payment_intent'], $request['params']['payment_intent']);
        $keys = array_values(array_filter($request['headers'], static fn (string $h): bool => stripos($h, 'Idempotency-Key:') === 0));
        self::assertCount(1, $keys);
        self::assertStringStartsWith('Idempotency-Key: bookwise-refund-' . $orderId . '-', $keys[0]);

        // The customer's order history shows the refund.
        $history = user_orders((int) $user['id']);
        self::assertSame(['refunded'], array_column($history, 'status'));
        self::assertNotNull($history[0]['refunded_at']);
    }

    public function testRefundingTwiceIsANoOp(): void
    {
        [, $order] = $this->paidOrder([self::ESABELLA => 2]);
        $orderId = (int) $order['id'];
        self::assertSame('refunded', refund_order($orderId));
        $stock = $this->stockOf(self::ESABELLA);
        $refundId = $this->reloadOrder($orderId)['stripe_refund_id'];

        self::assertSame('already_refunded', refund_order($orderId));

        self::assertCount(1, $this->stripe->http->requests('POST', self::REFUNDS), 'Stripe is not asked again');
        self::assertSame($stock, $this->stockOf(self::ESABELLA), 'Copies are restocked once');
        self::assertSame($refundId, $this->reloadOrder($orderId)['stripe_refund_id']);
        self::assertFalse(apply_order_refund($orderId, 're_test_other', (string) $order['stripe_payment_intent']), 'The bookkeeping runs once');
        self::assertSame($stock, $this->stockOf(self::ESABELLA));
    }

    public function testARefundWhoseAnswerCarriesAStripeNoticeIsRecorded(): void
    {
        [, $order] = $this->paidOrder([self::ESABELLA => 1]);
        $orderId = (int) $order['id'];
        $this->stripe->http->noticeOnNextResponse('POST', self::REFUNDS, 'A notice for test mode.');

        self::assertSame('refunded', refund_order($orderId), 'Stripe has refunded the payment, so the order is refunded');

        $stripeRefunds = $this->stripe->refunds((string) $order['stripe_payment_intent']);
        self::assertCount(1, $stripeRefunds);
        self::assertSame($stripeRefunds[0]['id'], $this->reloadOrder($orderId)['stripe_refund_id']);
    }

    public function testOnlyPaidOrdersWithAStripePaymentCanBeRefunded(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        [, $pending] = $this->checkout($user);
        $this->assertRefundFails((int) $pending['id'], 'Only paid orders can be refunded.');
        $this->assertRefundFails(999999, 'That order could not be found.');

        foreach (['canceled', 'expired', 'failed'] as $status) {
            db_run('UPDATE orders SET status = ? WHERE id = ?', [$status, $pending['id']]);
            $this->assertRefundFails((int) $pending['id'], 'Only paid orders can be refunded.');
        }

        [, $paid] = $this->paidOrder([self::BIOLOGY => 1]);
        db_run('UPDATE orders SET stripe_payment_intent = NULL WHERE id = ?', [$paid['id']]);
        $this->assertRefundFails((int) $paid['id'], 'This order has no Stripe payment to refund.');
        self::assertFalse(order_is_refundable($this->reloadOrder((int) $paid['id'])));

        self::assertSame([], $this->stripe->http->requests('POST', self::REFUNDS), 'Stripe is never called');
        self::assertSame('paid', $this->reloadOrder((int) $paid['id'])['status']);
    }

    public function testAStripeErrorChangesNothing(): void
    {
        [, $order] = $this->paidOrder([self::ESABELLA => 2]);
        $orderId = (int) $order['id'];
        $stock = $this->stockOf(self::ESABELLA);
        $sales = $this->salesForOrder($orderId);

        $this->stripe->http->failNext('POST', self::REFUNDS, 500);
        $this->assertRefundFails($orderId, 'Stripe could not refund this order right now, so nothing was changed. Please try again later or refund it in the Stripe Dashboard.');
        $this->stripe->http->failNextWithConnectionError('POST', self::REFUNDS);
        $this->assertRefundFails($orderId, 'Stripe could not refund this order right now, so nothing was changed. Please try again later or refund it in the Stripe Dashboard.');

        $after = $this->reloadOrder($orderId);
        self::assertSame(['paid', null, null], [$after['status'], $after['refunded_at'], $after['stripe_refund_id']]);
        self::assertSame($stock, $this->stockOf(self::ESABELLA));
        self::assertSame($sales, $this->salesForOrder($orderId));
        self::assertSame([], $this->stripe->refunds(), 'No money moved');

        self::assertSame('refunded', refund_order($orderId), 'Trying again later works');
    }

    public function testAPaymentStripeDoesNotKnowIsNotRefunded(): void
    {
        [, $order] = $this->paidOrder([self::ESABELLA => 1]);
        db_run('UPDATE orders SET stripe_payment_intent = ? WHERE id = ?', ['pi_test_unknown_to_stripe', $order['id']]);

        $this->assertRefundFails((int) $order['id'], 'Stripe could not refund this order right now, so nothing was changed. Please try again later or refund it in the Stripe Dashboard.');
        self::assertSame('paid', $this->reloadOrder((int) $order['id'])['status']);
    }

    public function testInDemoModeAPaymentTheSimulatorForgotIsRefundedLocally(): void
    {
        // The demo's simulator loses its payments when the server restarts.
        $stock = $this->stockOf(self::ESABELLA);
        [, $order] = $this->paidOrder([self::ESABELLA => 1]);
        $this->stripe->reset();
        $this->setEnv('APP_DEMO', 'true');

        self::assertSame('refunded', refund_order((int) $order['id']));
        $refunded = $this->reloadOrder((int) $order['id']);
        self::assertSame('refunded', $refunded['status']);
        self::assertStringStartsWith('re_demo_', (string) $refunded['stripe_refund_id']);
        self::assertSame($stock, $this->stockOf(self::ESABELLA));

        // Outside demo mode the same situation is an error (see the test above).
    }

    public function testRefundingAnOversoldOrderRestocksOnlyTheCopiesItTook(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 2, self::BIOLOGY => 1]);
        [$sessionId, $order] = $this->checkout($user);
        // Another order took the copies while this customer was paying.
        $this->setStock(self::ESABELLA, 1);
        $biology = $this->stockOf(self::BIOLOGY);
        $this->stripe->pay($sessionId);
        self::assertSame('paid', confirm_checkout_return((int) $user['id'], $sessionId)['state']);
        self::assertSame([0, $biology - 1], [$this->stockOf(self::ESABELLA), $this->stockOf(self::BIOLOGY)]);
        self::assertSame(
            [[self::BIOLOGY, 1, 1], [self::ESABELLA, 2, 1]],
            array_map(static fn (array $row): array => array_map('intval', array_values($row)), db_all('SELECT book_id, quantity, stock_taken FROM order_items WHERE order_id = ? ORDER BY book_id', [$order['id']]))
        );

        self::assertSame('refunded', refund_order((int) $order['id']));
        self::assertSame([1, $biology], [$this->stockOf(self::ESABELLA), $this->stockOf(self::BIOLOGY)], 'Only the copy that left the shelf comes back');
    }

    public function testDemoPurchasesNeverTakeABookBelowTheDemoMinimum(): void
    {
        $this->setStock(self::ESABELLA, 4);
        // Create the (per-process) Stripe client first: in demo mode it would
        // replace the HTTP client that records these tests' Stripe requests.
        stripe_client();
        $this->setEnv('APP_DEMO', 'true');
        [, $order] = $this->paidOrder([self::ESABELLA => 3]);
        self::assertSame(DEMO_MIN_STOCK, $this->stockOf(self::ESABELLA), 'Visitors cannot sell the shared demo catalog out');
        self::assertSame(1, (int) db_value('SELECT stock_taken FROM order_items WHERE order_id = ?', [$order['id']]), 'Only one copy left the stock');

        // A refund puts back exactly the copy the order took, not the three it asked for.
        self::assertSame('refunded', refund_order((int) $order['id']));
        self::assertSame(4, $this->stockOf(self::ESABELLA));
    }

    public function testDemoPurchaseAndRefundCyclesNeverAddCopies(): void
    {
        $this->setStock(self::ESABELLA, DEMO_MIN_STOCK + 1);
        $this->setStock(self::BIOLOGY, 1); // an administrator lowered it below the demo minimum
        stripe_client();
        $this->setEnv('APP_DEMO', 'true');

        for ($cycle = 1; $cycle <= 3; $cycle++) {
            [, $order] = $this->paidOrder([self::ESABELLA => 2, self::BIOLOGY => 1]);
            self::assertSame([DEMO_MIN_STOCK, DEMO_MIN_STOCK], [$this->stockOf(self::ESABELLA), $this->stockOf(self::BIOLOGY)], 'The book below the minimum is topped up');
            self::assertSame(
                [self::BIOLOGY => 0, self::ESABELLA => 1],
                array_map('intval', array_column(db_all('SELECT book_id, stock_taken FROM order_items WHERE order_id = ? ORDER BY book_id', [$order['id']]), 'stock_taken', 'book_id'))
            );
            self::assertSame('refunded', refund_order((int) $order['id']));
            self::assertSame([DEMO_MIN_STOCK + 1, DEMO_MIN_STOCK], [$this->stockOf(self::ESABELLA), $this->stockOf(self::BIOLOGY)], "Cycle $cycle added no copies");
        }
    }

    public function testARefundAlreadyMadeInTheStripeDashboardIsRecorded(): void
    {
        [, $order] = $this->paidOrder([self::ESABELLA => 2]);
        $dashboardRefund = $this->stripe->refundPayment((string) $order['stripe_payment_intent']);

        self::assertSame('refunded', refund_order((int) $order['id']));

        self::assertSame($dashboardRefund['id'], $this->reloadOrder((int) $order['id'])['stripe_refund_id']);
        self::assertCount(1, $this->stripe->refunds(), 'No second refund');
    }

    public function testAPartialDashboardRefundIsNotTakenForAFullOne(): void
    {
        [, $order] = $this->paidOrder([self::ESABELLA => 2]);
        $this->stripe->refundPayment((string) $order['stripe_payment_intent'], 500);

        self::assertSame('refunded', refund_order((int) $order['id']), 'The rest is refunded');
        $refunds = $this->stripe->refunds((string) $order['stripe_payment_intent']);
        self::assertSame([(int) $order['amount_minor'] - 500, 500], array_column($refunds, 'amount'));
        self::assertNull(confirmed_full_refund((string) $order['stripe_payment_intent'], (int) $order['amount_minor'] + 1, 'sar'));
        self::assertSame($refunds[0]['id'], confirmed_full_refund((string) $order['stripe_payment_intent'], (int) $order['amount_minor'], 'sar'));
        self::assertNull(confirmed_full_refund((string) $order['stripe_payment_intent'], (int) $order['amount_minor'], 'usd'), 'Other currencies do not count');
    }

    public function testRefundingAnOrderWhoseBookWasDeletedRestocksTheOthers(): void
    {
        [, $order] = $this->paidOrder([self::ESABELLA => 1, self::BIOLOGY => 2]);
        $biology = $this->stockOf(self::BIOLOGY);
        delete_book(self::ESABELLA);

        self::assertSame('refunded', refund_order((int) $order['id']));
        self::assertSame($biology + 2, $this->stockOf(self::BIOLOGY));
        self::assertSame([], $this->salesForOrder((int) $order['id']));
    }

    public function testLateCheckoutEventsAndReturnsNeverReviveARefundedOrder(): void
    {
        $this->setEnv('STRIPE_WEBHOOK_SECRET', 'whsec_refund_suite');
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        [$sessionId, $order] = $this->checkout($user);
        $session = $this->stripe->pay($sessionId);
        self::assertSame('paid', confirm_checkout_return((int) $user['id'], $sessionId)['state']);
        self::assertSame('refunded', refund_order((int) $order['id']));
        $stock = $this->stockOf(self::ESABELLA);

        $payload = json_encode([
            'id' => 'evt_late', 'object' => 'event', 'api_version' => '2024-06-20', 'created' => time(), 'livemode' => false,
            'type' => 'checkout.session.completed', 'data' => ['object' => $session],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, 'whsec_refund_suite');
        self::assertSame(200, handle_stripe_webhook($payload, $signature));
        self::assertSame('paid', confirm_checkout_return((int) $user['id'], $sessionId)['state'], 'The success page still finds the order');
        self::assertSame('already_paid', fulfill_order((int) $order['id'], Session::constructFrom((array) $this->stripe->session($sessionId))));

        self::assertSame('refunded', $this->reloadOrder((int) $order['id'])['status']);
        self::assertSame([], $this->salesForOrder((int) $order['id']));
        self::assertSame($stock, $this->stockOf(self::ESABELLA));
    }
}
