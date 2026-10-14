<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\CheckoutTestCase;
use BookWise\Tests\Support\StockFixtures;
use CheckoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\Checkout\Session;

/**
 * confirm_checkout_return(), fulfill_order() and settle_pending_orders().
 */
final class OrderFulfillmentTest extends CheckoutTestCase
{
    use StockFixtures;

    private const BIOLOGY = 7;      // 5.50
    private const BRIDGE = 8;       // 10.00
    private const ESABELLA = 51;    // 19.99

    /* -----------------------------------------------------------------
     * Returning from Stripe
     * --------------------------------------------------------------- */

    public function testReturningBeforePaymentChangesNothing(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 2]);
        [$sessionId, $order] = $this->checkout($user);

        $result = confirm_checkout_return((int) $user['id'], $sessionId);

        self::assertSame('unpaid', $result['state']);
        self::assertSame('pending', $result['order']['status']);
        self::assertSame([], $this->salesForOrder((int) $order['id']));
        self::assertSame([self::ESABELLA => 2], $this->cartQuantities((int) $user['id']));
    }

    public function testPaidSessionFulfilsTheOrderExactlyOnce(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 2, self::BIOLOGY => 1]);
        [$sessionId, $order] = $this->checkout($user);
        $paid = $this->stripe->pay($sessionId);

        $result = confirm_checkout_return((int) $user['id'], $sessionId);

        self::assertSame('paid', $result['state']);
        $order = $this->reloadOrder((int) $order['id']);
        self::assertSame('paid', $order['status']);
        self::assertNotNull($order['paid_at']);
        self::assertSame($paid['payment_intent'], $order['stripe_payment_intent']);
        self::assertSame([self::BIOLOGY => 1, self::ESABELLA => 2], $this->salesForOrder((int) $order['id']));
        self::assertSame([], cart_lines((int) $user['id']), 'Purchased books leave the cart');
        self::assertTrue(user_has_purchased((int) $user['id'], self::ESABELLA));

        $retrievals = count($this->stripe->http->requests('GET', self::RETRIEVE_SESSION));
        $again = confirm_checkout_return((int) $user['id'], $sessionId);
        self::assertSame('paid', $again['state'], 'Reloading the success page is harmless');
        self::assertSame(2, $this->countRows('sales'), 'Sales are written once');
        self::assertSame($retrievals, count($this->stripe->http->requests('GET', self::RETRIEVE_SESSION)), 'A paid order needs no Stripe call');
    }

    public function testFulfilmentRemovesOnlyPurchasedBooksFromTheCart(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1, self::BIOLOGY => 2]);
        $userId = (int) $user['id'];
        [$sessionId] = $this->checkout($user);
        cart_add($userId, self::BRIDGE, 1); // added while paying
        $other = $this->customerWithCart([self::ESABELLA => 4]);
        $this->stripe->pay($sessionId);

        confirm_checkout_return($userId, $sessionId);

        self::assertSame([self::BRIDGE => 1], $this->cartQuantities($userId));
        self::assertSame([self::ESABELLA => 4], $this->cartQuantities((int) $other['id']), "Other customers' carts are untouched");
    }

    public function testAnotherCustomersSessionIsInvalid(): void
    {
        $alice = $this->customerWithCart([self::ESABELLA => 1]);
        $bob = $this->createUser();
        [$sessionId, $order] = $this->checkout($alice);
        $this->stripe->pay($sessionId);

        self::assertSame(['state' => 'invalid', 'order' => null], confirm_checkout_return((int) $bob['id'], $sessionId));
        self::assertSame('pending', $this->reloadOrder((int) $order['id'])['status']);
        self::assertSame(0, $this->countRows('sales'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidSessionIds(): iterable
    {
        yield 'empty' => [''];
        yield 'garbage' => ['abc'];
        yield 'placeholder' => ['{CHECKOUT_SESSION_ID}'];
        yield 'SQL' => ["cs_test_x' OR '1'='1"];
        yield 'unknown' => ['cs_test_doesnotexist'];
        yield 'too long' => ['cs_' . str_repeat('a', 251)];
    }

    #[DataProvider('invalidSessionIds')]
    public function testMalformedOrUnknownSessionIdsAreInvalid(string $sessionId): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        $this->checkout($user);

        self::assertSame(['state' => 'invalid', 'order' => null], confirm_checkout_return((int) $user['id'], $sessionId));
        self::assertSame('pending', db_value('SELECT status FROM orders'));
    }

    /**
     * @return iterable<string, array{0: array<string, string|int>}>
     */
    public static function tamperedPayments(): iterable
    {
        yield 'amount too low' => [['amount_total' => 1]];
        yield 'amount too high' => [['amount_total' => 999999]];
        yield 'other currency' => [['currency' => 'usd']];
    }

    /**
     * @param array<string, string|int> $overrides
     */
    #[DataProvider('tamperedPayments')]
    public function testMismatchingPaymentIsNotFulfilled(array $overrides): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        [$sessionId, $order] = $this->checkout($user);
        $this->stripe->pay($sessionId, $overrides);

        $result = confirm_checkout_return((int) $user['id'], $sessionId);
        self::assertSame('review', $result['state'], 'A captured payment that does not match is flagged, never fulfilled');
        self::assertSame((int) $order['id'], (int) $result['order']['id']);
        // Taken out of the pending queue so later checkouts do not keep re-checking it.
        self::assertSame('failed', $this->reloadOrder((int) $order['id'])['status']);
        self::assertSame(0, $this->countRows('sales'));
        self::assertSame([self::ESABELLA => 1], $this->cartQuantities((int) $user['id']));
    }

    public function testCompletedButUnpaidSessionIsProcessing(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        [$sessionId, $order] = $this->checkout($user);
        $this->stripe->http->rewriteNextResponse('GET', self::RETRIEVE_SESSION, static function (array $session): array {
            $session['status'] = 'complete';          // e.g. a delayed bank payment
            $session['payment_status'] = 'unpaid';
            return $session;
        });

        $result = confirm_checkout_return((int) $user['id'], $sessionId);

        self::assertSame('processing', $result['state']);
        self::assertSame('pending', $this->reloadOrder((int) $order['id'])['status']);
    }

    public function testStripeOutageOnReturnIsReportedAsError(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        [$sessionId, $order] = $this->checkout($user);
        $this->stripe->pay($sessionId);
        $this->stripe->http->failNext('GET', self::RETRIEVE_SESSION, 500);

        $result = confirm_checkout_return((int) $user['id'], $sessionId);

        self::assertSame('error', $result['state']);
        self::assertSame($order['id'], $result['order']['id']);
        self::assertSame('pending', $this->reloadOrder((int) $order['id'])['status']);

        self::assertSame('paid', confirm_checkout_return((int) $user['id'], $sessionId)['state'], 'Retrying later succeeds');
    }

    /* -----------------------------------------------------------------
     * fulfill_order() guards
     * --------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $overrides
     */
    private static function sessionFor(array $order, array $overrides = []): Session
    {
        return Session::constructFrom($overrides + [
            'id' => $order['stripe_session_id'],
            'object' => 'checkout.session',
            'client_reference_id' => (string) $order['id'],
            'amount_total' => (int) $order['amount_minor'],
            'currency' => 'sar',
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_direct',
        ]);
    }

    public function testFulfilOrderRejectsSessionsThatDoNotBelongToTheOrder(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        $order = $this->checkout($user)[1];
        $orderId = (int) $order['id'];

        self::assertSame('unknown_order', fulfill_order(999999, self::sessionFor($order)));
        self::assertSame('mismatch', fulfill_order($orderId, self::sessionFor($order, ['id' => 'cs_test_someone_else'])));
        self::assertSame('mismatch', fulfill_order($orderId, self::sessionFor($order, ['client_reference_id' => (string) ($orderId + 1)])));
        self::assertSame('mismatch', fulfill_order($orderId, self::sessionFor($order, ['client_reference_id' => null])));
        self::assertSame('mismatch', fulfill_order($orderId, self::sessionFor($order, ['amount_total' => null])));
        self::assertSame('mismatch', fulfill_order($orderId, self::sessionFor($order, ['currency' => 'eur'])));
        self::assertSame('unpaid', fulfill_order($orderId, self::sessionFor($order, ['payment_status' => 'unpaid'])));
        self::assertSame('unpaid', fulfill_order($orderId, self::sessionFor($order, ['payment_status' => 'no_payment_required'])));
        self::assertSame('pending', $this->reloadOrder($orderId)['status']);
        self::assertSame(0, $this->countRows('sales'));
    }

    public function testFulfilOrderAcceptsUppercaseCurrencyAndExpandedPaymentIntent(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        $order = $this->checkout($user)[1];

        $outcome = fulfill_order((int) $order['id'], self::sessionFor($order, [
            'currency' => 'SAR',
            'payment_intent' => ['id' => 'pi_test_expanded', 'object' => 'payment_intent'],
        ]));

        self::assertSame('paid', $outcome);
        self::assertSame('pi_test_expanded', $this->reloadOrder((int) $order['id'])['stripe_payment_intent']);
        self::assertSame('already_paid', fulfill_order((int) $order['id'], self::sessionFor($order)));
        self::assertSame(1, $this->countRows('sales'));
    }

    public function testMoneyCapturedForAGivenUpOrderIsHonoured(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        $order = $this->checkout($user)[1];
        mark_order_status((int) $order['id'], 'canceled');

        self::assertSame('paid', fulfill_order((int) $order['id'], self::sessionFor($order)));
        self::assertSame('paid', $this->reloadOrder((int) $order['id'])['status']);
        self::assertSame([self::ESABELLA => 1], $this->salesForOrder((int) $order['id']));
    }

    /* -----------------------------------------------------------------
     * Stock
     * --------------------------------------------------------------- */

    public function testFulfilmentTakesTheCopiesOutOfStockExactlyOnce(): void
    {
        $esabella = $this->stockOf(self::ESABELLA);
        $biology = $this->stockOf(self::BIOLOGY);
        $user = $this->customerWithCart([self::ESABELLA => 2, self::BIOLOGY => 1]);
        [$sessionId, $order] = $this->checkout($user);
        self::assertSame([$esabella, $biology], [$this->stockOf(self::ESABELLA), $this->stockOf(self::BIOLOGY)], 'Starting checkout reserves nothing');

        $this->stripe->pay($sessionId);
        self::assertSame('paid', confirm_checkout_return((int) $user['id'], $sessionId)['state']);
        self::assertSame([$esabella - 2, $biology - 1], [$this->stockOf(self::ESABELLA), $this->stockOf(self::BIOLOGY)]);

        // Reloads, retries and webhooks for the paid order take nothing more.
        confirm_checkout_return((int) $user['id'], $sessionId);
        self::assertSame('already_paid', fulfill_order((int) $order['id'], self::sessionFor($order)));
        self::assertSame([$esabella - 2, $biology - 1], [$this->stockOf(self::ESABELLA), $this->stockOf(self::BIOLOGY)]);
    }

    public function testAnOversoldBookIsStillFulfilledNeverGoesBelowZeroAndIsLogged(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 3, self::BIOLOGY => 1]);
        [$sessionId, $order] = $this->checkout($user);
        $this->setStock(self::ESABELLA, 1); // the last copies sold elsewhere while this customer paid
        $biology = $this->stockOf(self::BIOLOGY);
        $logFile = log_path('app.log');
        clearstatcache(true, $logFile);
        $logOffset = is_file($logFile) ? (int) filesize($logFile) : 0;

        $this->stripe->pay($sessionId);
        self::assertSame('paid', confirm_checkout_return((int) $user['id'], $sessionId)['state'], 'The customer has paid, so the order is fulfilled');

        self::assertSame(0, $this->stockOf(self::ESABELLA), 'Stock never goes below zero');
        self::assertSame($biology - 1, $this->stockOf(self::BIOLOGY));
        self::assertSame([self::BIOLOGY => 1, self::ESABELLA => 3], $this->salesForOrder((int) $order['id']));
        $log = (string) file_get_contents($logFile, false, null, $logOffset);
        self::assertMatchesRegularExpression('/WARNING: OVERSOLD: .*"order_id":' . (int) $order['id'] . ',"book_id":' . self::ESABELLA . ',"stock":1,"quantity":3/', $log);
        self::assertStringNotContainsString('"book_id":' . self::BIOLOGY . ',', $log, 'Books with enough copies are not reported');
    }

    public function testDeletedBooksAreSkippedWhenRecordingSales(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1, self::BIOLOGY => 1]);
        [$sessionId, $order] = $this->checkout($user);
        delete_book(self::BIOLOGY);
        $this->stripe->pay($sessionId);

        self::assertSame('paid', confirm_checkout_return((int) $user['id'], $sessionId)['state']);
        self::assertSame([self::ESABELLA => 1], $this->salesForOrder((int) $order['id']));
    }

    /* -----------------------------------------------------------------
     * Settling earlier checkouts
     * --------------------------------------------------------------- */

    public function testNewCheckoutExpiresThePreviousOpenSession(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        [$firstSession, $firstOrder] = $this->checkout($user);

        [$secondSession, $secondOrder] = $this->checkout($user);

        self::assertNotSame($firstSession, $secondSession);
        self::assertSame('canceled', $this->reloadOrder((int) $firstOrder['id'])['status']);
        self::assertSame('expired', $this->stripe->session($firstSession)['status'], 'The old session can no longer be paid');
        self::assertCount(1, $this->stripe->http->requests('POST', '#^/v1/checkout/sessions/' . $firstSession . '/expire$#'));
        self::assertSame('pending', $secondOrder['status']);
        self::assertSame('open', $this->stripe->session($secondSession)['status']);
    }

    public function testPreviouslyPaidSessionIsFulfilledInsteadOfChargingAgain(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        [$sessionId, $order] = $this->checkout($user);
        $this->stripe->pay($sessionId); // paid, but the customer never came back

        $redirect = start_checkout($user);

        self::assertSame('success_pay.php?session_id=' . $sessionId, $redirect);
        self::assertSame('paid', $this->reloadOrder((int) $order['id'])['status']);
        self::assertSame(1, $this->countRows('orders'), 'No second order');
        self::assertCount(1, $this->stripe->http->requests('POST', self::CREATE_SESSION), 'No second session');
        self::assertSame([], cart_lines((int) $user['id']));
    }

    public function testPreviouslyPaidSessionPlusNewItemsStartsANewCheckout(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        [$firstSession, $firstOrder] = $this->checkout($user);
        $this->stripe->pay($firstSession);
        cart_add((int) $user['id'], self::BRIDGE, 2);

        $secondSession = self::sessionIdFromUrl(start_checkout($user));

        self::assertSame('paid', $this->reloadOrder((int) $firstOrder['id'])['status']);
        $secondOrder = $this->orderForSession($secondSession);
        self::assertSame(2000, $secondOrder['amount_minor'], 'Only the new books are charged');
        self::assertSame([self::BRIDGE], array_column(order_items((int) $secondOrder['id']), 'book_id'));
    }

    public function testPendingOrderWithoutASessionIsMarkedFailed(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        $orphan = create_pending_order((int) $user['id'], cart_lines((int) $user['id']));

        $this->checkout($user);

        self::assertSame('failed', $this->reloadOrder((int) $orphan['id'])['status']);
    }

    public function testOtherCustomersPendingOrdersAreNotSettled(): void
    {
        $alice = $this->customerWithCart([self::ESABELLA => 1]);
        $bob = $this->customerWithCart([self::BIOLOGY => 1]);
        [$aliceSession, $aliceOrder] = $this->checkout($alice);

        $this->checkout($bob);

        self::assertSame('pending', $this->reloadOrder((int) $aliceOrder['id'])['status']);
        self::assertSame('open', $this->stripe->session($aliceSession)['status']);
    }

    public function testStripeErrorWhileSettlingLeavesThePreviousOrderPending(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        $firstOrder = $this->checkout($user)[1];
        $this->stripe->http->failNext('GET', self::RETRIEVE_SESSION, 500);

        try {
            start_checkout($user);
        } catch (CheckoutException) {
            // Refusing to continue is acceptable too.
        }

        self::assertSame('pending', $this->reloadOrder((int) $firstOrder['id'])['status'], 'Left for a webhook or a later checkout');
    }

    /**
     * Regression test for a fixed bug (src/orders.php settle_pending_orders/start_checkout): when
     * Stripe cannot be asked about the previous session (network error, 5xx),
     * the error is logged and start_checkout() still creates a second session,
     * so two sessions for the same cart are payable at once and the customer
     * can be charged twice. This contradicts the documented guarantee that "an
     * abandoned session can never be paid in addition to the new one".
     * Refusing the new checkout (CheckoutException) while a previous session's
     * state is unknown would uphold it.
     */
    public function testStripeErrorWhileSettlingNeverLeavesTwoPayableSessions(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        $this->checkout($user);
        $this->stripe->http->failNext('GET', self::RETRIEVE_SESSION, 500);

        try {
            start_checkout($user);
        } catch (CheckoutException) {
            // Refusing to continue is the safe outcome.
        }

        $open = array_filter($this->stripe->sessions(), static fn (array $s): bool => $s['status'] === 'open');
        self::assertCount(1, $open, 'At most one payable checkout session per customer');
    }

    /**
     * Regression test for a fixed bug (src/orders.php settle_pending_orders): the docblock says
     * "sessions that already expired are marked expired" (which is also what
     * the checkout.session.expired webhook does), but the code marks them
     * 'canceled', so the same Stripe state yields different order statuses
     * depending on which path saw it first.
     */
    public function testAlreadyExpiredPreviousSessionIsMarkedExpired(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        [$sessionId, $order] = $this->checkout($user);
        self::assertSame(200, $this->stripe->call('POST', '/v1/checkout/sessions/' . $sessionId . '/expire')['status']);

        $this->checkout($user);

        self::assertSame('expired', $this->reloadOrder((int) $order['id'])['status']);
    }
}
