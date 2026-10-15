<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\CheckoutTestCase;
use BookWise\Tests\Support\IsolatedPhp;
use BookWise\Tests\Support\StockFixtures;
use CheckoutException;

final class CheckoutTest extends CheckoutTestCase
{
    use StockFixtures;

    private const BIOLOGY = 7;      // 5.50, 24 in stock
    private const ESABELLA = 51;    // 19.99, 24 in stock
    private const FORBIDDEN = 58;   // 4.99, 17 in stock

    public function testStartCheckoutCreatesAPendingOrderAndAMatchingSession(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 3, self::BIOLOGY => 1]);
        $expectedMinor = 3 * 1999 + 550;

        $url = start_checkout($user, $this->deliveryAddressId($user));

        self::assertStringStartsWith($this->stripe->baseUrl . '/pay/cs_test_', $url, 'The browser is sent to the hosted page');
        $sessionId = self::sessionIdFromUrl($url);
        $order = $this->orderForSession($sessionId);
        self::assertSame((int) $user['id'], $order['user_id']);
        self::assertSame('pending', $order['status']);
        self::assertSame('sar', $order['currency']);
        self::assertSame($expectedMinor, $order['amount_minor']);
        self::assertSame('65.47', $order['total_amount']);
        self::assertNull($order['paid_at']);
        self::assertSame([
            ['book_id' => self::ESABELLA, 'title' => 'Esabella', 'unit_price' => '19.99', 'quantity' => 3],
            ['book_id' => self::BIOLOGY, 'title' => 'Biology', 'unit_price' => '5.50', 'quantity' => 1],
        ], order_items((int) $order['id']));

        $session = $this->stripe->session($sessionId);
        self::assertNotNull($session);
        self::assertSame($expectedMinor, $session['amount_total']);
        self::assertSame('sar', $session['currency']);
        self::assertSame((string) $order['id'], $session['client_reference_id']);
        self::assertSame($user['Eaddress'], $session['customer_email']);
        self::assertCount(2, $session['simulator']['line_items']);
        self::assertSame([self::ESABELLA => 3, self::BIOLOGY => 1], $this->cartQuantities((int) $user['id']), 'The cart is kept until payment is confirmed');
    }

    public function testSessionRequestUsesIntegerMinorUnitsAndSafeRedirects(): void
    {
        $this->setEnv('APP_URL', 'https://shop.example.com/');
        $tenCents = $this->insertStockedBook(['title' => 'Float Trap', 'price' => '0.10']);
        $user = $this->customerWithCart([$tenCents => 3, self::ESABELLA => 1]);

        $order = $this->checkout($user)[1];

        $requests = $this->stripe->http->requests('POST', self::CREATE_SESSION);
        self::assertCount(1, $requests);
        $params = $requests[0]['params'];
        self::assertSame('payment', $params['mode']);
        self::assertSame(['card'], $params['allowed_payment_method_types']);
        self::assertArrayNotHasKey('payment_method_types', $params, 'Removed in the API version stripe-php pins');
        self::assertSame((string) $order['id'], $params['client_reference_id']);
        self::assertSame(['order_id' => (string) $order['id'], 'user_id' => (string) $user['id']], $params['metadata']);
        self::assertSame($params['metadata'], $params['payment_intent_data']['metadata']);
        self::assertSame('https://shop.example.com/success_pay.php?session_id={CHECKOUT_SESSION_ID}', $params['success_url']);
        self::assertSame('https://shop.example.com/cancel_pay.php?order=' . $order['id'], $params['cancel_url']);
        self::assertIsInt($params['expires_at']);
        self::assertGreaterThan(time() + 1800, $params['expires_at'], 'Stripe requires at least 30 minutes');
        self::assertLessThanOrEqual(time() + 86400, $params['expires_at']);

        self::assertSame([
            ['price_data' => ['currency' => 'sar', 'product_data' => ['name' => 'Float Trap'], 'unit_amount' => 10], 'quantity' => 3],
            ['price_data' => ['currency' => 'sar', 'product_data' => ['name' => 'Esabella'], 'unit_amount' => 1999], 'quantity' => 1],
        ], $params['line_items'], 'unit_amount and quantity are integers in minor units');
        self::assertSame(2029, $order['amount_minor']);

        $idempotency = array_values(array_filter($requests[0]['headers'], static fn (string $h): bool => stripos($h, 'Idempotency-Key:') === 0));
        self::assertCount(1, $idempotency);
        self::assertStringStartsWith('Idempotency-Key: bookwise-order-' . $order['id'] . '-', $idempotency[0]);
    }

    public function testAStripeNoticeIsLoggedAndTheCheckoutGoesOn(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        $logFile = log_path('app.log');
        clearstatcache(true, $logFile);
        $logOffset = is_file($logFile) ? (int) filesize($logFile) : 0;
        // stripe-php raises this header as a PHP warning, after Stripe has created the session.
        $this->stripe->http->noticeOnNextResponse('POST', self::CREATE_SESSION, 'A notice for test mode.');

        [$sessionId, $order] = $this->checkout($user);

        self::assertSame('pending', $order['status'], 'The order keeps its session');
        self::assertNotNull($this->stripe->session($sessionId));
        $log = (string) file_get_contents($logFile, false, null, $logOffset);
        self::assertStringContainsString('WARNING: Stripe notice {"path":"/v1/checkout/sessions","notice":"A notice for test mode."}', $log);

        // The next answers carry no notice and log nothing.
        $this->stripe->pay($sessionId);
        self::assertSame('paid', confirm_checkout_return((int) $user['id'], $sessionId)['state']);
        self::assertSame(1, substr_count((string) file_get_contents($logFile, false, null, $logOffset), 'Stripe notice'));
    }

    public function testLongTitlesAreTruncatedForStripe(): void
    {
        $book = $this->insertStockedBook(['title' => str_repeat('L', 255)]);
        $user = $this->customerWithCart([$book => 1]);

        $this->checkout($user);

        $name = $this->stripe->http->requests('POST', self::CREATE_SESSION)[0]['params']['line_items'][0]['price_data']['product_data']['name'];
        self::assertSame(250, mb_strlen($name));
    }

    public function testEmptyCartCannotCheckOut(): void
    {
        $user = $this->createUser();

        try {
            start_checkout($user, $this->deliveryAddressId($user));
            self::fail('Expected CheckoutException');
        } catch (CheckoutException $e) {
            self::assertSame('Your cart is empty.', $e->getMessage());
        }
        self::assertSame(0, $this->countRows('orders'));
        self::assertSame([], $this->stripe->http->requests());
    }

    public function testStripeErrorMarksTheOrderFailedAndKeepsTheCart(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        $this->stripe->http->failNext('POST', self::CREATE_SESSION, 500);

        try {
            start_checkout($user, $this->deliveryAddressId($user));
            self::fail('Expected CheckoutException');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('Your card has not been charged', $e->getMessage());
            self::assertStringNotContainsString('Injected', $e->getMessage(), 'Provider errors are not shown to customers');
        }

        $order = db_one('SELECT * FROM orders');
        self::assertSame('failed', $order['status']);
        self::assertNull($order['stripe_session_id']);
        self::assertSame([self::ESABELLA => 1], $this->cartQuantities((int) $user['id']));
    }

    public function testStripeConnectionFailureIsHandledTheSameWay(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        $this->stripe->http->failNextWithConnectionError('POST', self::CREATE_SESSION);

        $this->expectException(CheckoutException::class);
        try {
            start_checkout($user, $this->deliveryAddressId($user));
        } finally {
            self::assertSame('failed', db_value('SELECT status FROM orders'));
        }
    }

    public function testUnexpectedSessionAmountIsRejected(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        $this->stripe->http->rewriteNextResponse('POST', self::CREATE_SESSION, static function (array $session): array {
            $session['amount_total'] = 1;
            return $session;
        });

        try {
            start_checkout($user, $this->deliveryAddressId($user));
            self::fail('Expected CheckoutException');
        } catch (CheckoutException) {
            // expected
        }
        $order = db_one('SELECT * FROM orders');
        self::assertSame('failed', $order['status']);
        self::assertNull($order['stripe_session_id'], 'The mismatching session is never linked to the order');
    }

    public function testSessionWithoutUrlIsRejected(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        $this->stripe->http->rewriteNextResponse('POST', self::CREATE_SESSION, static function (array $session): array {
            $session['url'] = null;
            return $session;
        });

        $this->expectException(CheckoutException::class);
        try {
            start_checkout($user, $this->deliveryAddressId($user));
        } finally {
            self::assertSame('failed', db_value('SELECT status FROM orders'));
        }
    }

    public function testFreeBooksCannotBeCheckedOut(): void
    {
        $free = $this->insertStockedBook(['price' => '0.00']);
        $user = $this->customerWithCart([$free => 2]);

        try {
            start_checkout($user, $this->deliveryAddressId($user));
            self::fail('Expected CheckoutException');
        } catch (CheckoutException $e) {
            self::assertSame('The minimum order total is SAR 2.00. Please add another book.', $e->getMessage());
        }
        self::assertSame(0, $this->countRows('orders'));
    }

    public function testTotalsBelowStripesMinimumChargeAreRefusedBeforeCallingStripe(): void
    {
        $cheap = $this->insertStockedBook(['price' => '1.99']);
        $user = $this->customerWithCart([$cheap => 1]);

        try {
            start_checkout($user, $this->deliveryAddressId($user));
            self::fail('Expected CheckoutException');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('minimum order total', $e->getMessage());
        }
        self::assertSame(0, $this->countRows('orders'));

        cart_set_quantity((int) $user['id'], cart_lines((int) $user['id'])[0]['cart_id'], 2); // 3.98
        self::assertStringStartsWith('http', start_checkout($user, $this->deliveryAddressId($user)));
    }

    public function testOutOfRangeQuantitiesAreRejectedBeforeCharging(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        db_run('UPDATE cart SET quantity = 11');

        try {
            start_checkout($user, $this->deliveryAddressId($user));
            self::fail('Expected CheckoutException');
        } catch (CheckoutException $e) {
            self::assertStringContainsString('between 1 and 10', $e->getMessage());
        }
        self::assertSame(0, $this->countRows('orders'));
        self::assertSame([], $this->stripe->http->requests('POST', self::CREATE_SESSION));
    }

    public function testStockIsCheckedForEveryLineBeforeCharging(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 3, self::BIOLOGY => 2, self::FORBIDDEN => 1]);
        $this->setStock(self::ESABELLA, 2);
        $this->setStock(self::BIOLOGY, 0);

        try {
            start_checkout($user, $this->deliveryAddressId($user));
            self::fail('Expected CheckoutException');
        } catch (CheckoutException $e) {
            self::assertSame(
                'There are not enough copies of these books in stock: “Esabella”, “Biology”. Please lower the quantities or remove the books from your cart, then try again.',
                $e->getMessage()
            );
        }
        self::assertSame(0, $this->countRows('orders'), 'No order is created');
        self::assertSame([], $this->stripe->http->requests('POST', self::CREATE_SESSION), 'Stripe is never asked to charge');

        // One book short names just that book; with enough copies checkout goes ahead.
        $this->setStock(self::BIOLOGY, 5);
        try {
            start_checkout($user, $this->deliveryAddressId($user));
            self::fail('Expected CheckoutException');
        } catch (CheckoutException $e) {
            self::assertSame(
                'There are not enough copies of “Esabella” in stock. Please lower the quantity or remove the book from your cart, then try again.',
                $e->getMessage()
            );
        }
        $this->setStock(self::ESABELLA, 3);
        self::assertSame(3 * 1999 + 2 * 550 + 499, (int) $this->checkout($user)[1]['amount_minor']);
    }

    public function testStockMessagesAreTranslated(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 3]);
        $this->setStock(self::ESABELLA, 1);

        use_locale('ar');
        try {
            start_checkout($user, $this->deliveryAddressId($user));
            self::fail('Expected CheckoutException');
        } catch (CheckoutException $e) {
            self::assertSame(
                'لا تتوفر نسخ كافية من «Esabella» في المخزون. يُرجى تقليل الكمية أو إزالة الكتاب من سلتك ثم المحاولة مرة أخرى.',
                $e->getMessage()
            );
        } finally {
            use_locale(null);
        }
    }

    public function testLiveKeyIsRefusedOutsideProductionBeforeAnyOrderIsCreated(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);

        $run = IsolatedPhp::run('start_checkout', ['user' => $user, 'address' => $this->deliveryAddressId($user)], ['APP_ENV' => 'local', 'STRIPE_SECRET_KEY' => 'sk_live_not_a_real_key']);

        self::assertSame(['ok' => false, 'exception' => 'CheckoutException', 'message' => 'Online payments are not available in this environment.'], $run['result']);
        self::assertSame(0, $this->countRows('orders'));
        self::assertSame([], $this->stripe->sessions());
    }

    /* -----------------------------------------------------------------
     * Order queries
     * --------------------------------------------------------------- */

    public function testOrderQueriesAreScopedToTheOwner(): void
    {
        $alice = $this->customerWithCart([self::ESABELLA => 2, self::FORBIDDEN => 1]);
        $bob = $this->customerWithCart([self::BIOLOGY => 1]);
        $aliceOrder = $this->checkout($alice)[1];
        $bobOrder = $this->checkout($bob)[1];

        self::assertNotNull(find_user_order((int) $aliceOrder['id'], (int) $alice['id']));
        self::assertNull(find_user_order((int) $aliceOrder['id'], (int) $bob['id']));
        self::assertNull(find_order(999999));

        $orders = user_orders((int) $alice['id']);
        self::assertCount(1, $orders);
        self::assertSame($aliceOrder['id'], $orders[0]['id']);
        self::assertSame([self::ESABELLA, self::FORBIDDEN], array_column($orders[0]['items'], 'book_id'));
        self::assertSame([], user_orders((int) $this->createUser()['id']));

        self::assertSame([$bobOrder['id'], $aliceOrder['id']], array_column(recent_orders(), 'id'), 'Newest first');
        $recent = array_column(recent_orders(), null, 'id');
        self::assertSame(3, (int) $recent[$aliceOrder['id']]['item_count']);
        self::assertSame($alice['Eaddress'], $recent[$aliceOrder['id']]['customer_email']);
        self::assertSame('Test Reader', $recent[$aliceOrder['id']]['customer_name']);
    }

    public function testMarkOrderStatusOnlyMovesFromAllowedStates(): void
    {
        $user = $this->createUser();
        $order = create_pending_order((int) $user['id'], [
            ['book_id' => self::ESABELLA, 'title' => 'Esabella', 'price' => '19.99', 'quantity' => 1, 'line_minor' => 1999],
        ], $this->deliveryAddress($user));
        $id = (int) $order['id'];

        self::assertFalse(mark_order_status($id, 'refunded'), 'Unknown status');
        self::assertFalse(mark_order_status($id, 'failed', []));
        self::assertFalse(mark_order_status($id, 'canceled', ['paid']));
        self::assertTrue(mark_order_status($id, 'canceled'));
        self::assertFalse(mark_order_status($id, 'expired'), 'No longer pending');
        self::assertSame('canceled', find_order($id)['status']);
    }
}
