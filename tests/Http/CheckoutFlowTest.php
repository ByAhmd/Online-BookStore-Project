<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpTestCase;

/**
 * Cart and Stripe Checkout end to end: the real pages, the local Stripe mock
 * (hosted payment page, API) and the signed webhook endpoint.
 */
final class CheckoutFlowTest extends HttpTestCase
{
    private const BIOLOGY = 7;      // 5.50
    private const BRIDGE = 8;       // 10.00
    private const DARK = 9;         // 12.00
    private const ESABELLA = 51;    // 19.99

    private static function minor(int $bookId): int
    {
        $book = find_book($bookId);
        self::assertNotNull($book);
        return money_to_minor((string) $book['price']);
    }

    /**
     * @return array<int, int> cart line id => quantity shown in the cart page's quantity inputs
     */
    private static function cartPageQuantities(HttpClient $client): array
    {
        $document = $client->get('cart.php')->document();
        $quantities = [];
        foreach ($document->elements('//form[@action="update_cart.php"]') as $form) {
            $cartId = $document->first('.//input[@name="cart_id"]', $form)?->getAttribute('value');
            $quantity = $document->first('.//input[@name="quantity"]', $form)?->getAttribute('value');
            $quantities[(int) $cartId] = (int) $quantity;
        }
        return $quantities;
    }

    private function webhookEvent(string $type, array $session): string
    {
        return json_encode([
            'id' => 'evt_http_' . bin2hex(random_bytes(6)),
            'object' => 'event',
            'api_version' => '2024-06-20',
            'created' => time(),
            'livemode' => false,
            'type' => $type,
            'data' => ['object' => $session],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function testCartCheckoutPaymentAndIdempotentReturn(): void
    {
        [$customer, $user] = $this->signedInCustomer('Paying Reader');
        $userId = (int) $user['id'];

        // Fill the cart through the book pages, then edit it through the cart page.
        foreach ([self::BIOLOGY => 1, self::ESABELLA => 2, self::DARK => 1] as $bookId => $quantity) {
            $this->assertRedirect($this->addToCart($customer, $bookId, $quantity), 'cart.php');
        }
        $cart = $customer->get('cart.php');
        $this->assertHtmlPage($cart);
        self::assertSame('4', $cart->document()->text('//span[' . HtmlDocument::hasClass('cart-badge') . ']'));

        $esabellaLine = $this->cartLineId($userId, self::ESABELLA);
        $updated = $customer->submitForm($cart, 'update_cart.php', ['quantity' => '3'], ['cart_id' => (string) $esabellaLine]);
        $this->assertRedirect($updated, 'cart.php');
        $cart = $customer->follow($updated);
        $this->assertFlash($cart, 'success', 'Updated “Esabella” to 3 copies.');

        foreach (['0', '11', 'x'] as $invalid) {
            $rejected = $customer->submitForm($cart, 'update_cart.php', ['quantity' => $invalid], ['cart_id' => (string) $esabellaLine]);
            $this->assertFlash($customer->follow($rejected), 'error', 'Choose a quantity between 1 and 10.');
        }

        $cart = $customer->get('cart.php');
        $removed = $customer->submitForm($cart, 'delete_from_cart.php', [], ['cart_id' => (string) $this->cartLineId($userId, self::DARK)]);
        $this->assertRedirect($removed, 'cart.php');
        $cart = $customer->follow($removed);
        $this->assertFlash($cart, 'success', 'Removed “Dark” from your cart.');
        self::assertSame([self::BIOLOGY => 1, self::ESABELLA => 3], $this->cartQuantities($userId));
        self::assertSame([$this->cartLineId($userId, self::BIOLOGY) => 1, $esabellaLine => 3], self::cartPageQuantities($customer));

        $expectedMinor = self::minor(self::BIOLOGY) + 3 * self::minor(self::ESABELLA);
        $this->assertPageText('Grand Total ' . format_minor($expectedMinor), $cart);

        // Checkout: a pending order and a Stripe session for exactly the server-side total.
        $sessionId = $this->startCheckout($customer);
        $order = $this->orderBySession($sessionId);
        $orderId = (int) $order['id'];
        self::assertSame('pending', $order['status']);
        self::assertSame($expectedMinor, (int) $order['amount_minor']);
        $session = $this->stripe->session($sessionId);
        self::assertNotNull($session);
        self::assertSame($expectedMinor, $session['amount_total']);
        self::assertSame('sar', $session['currency']);
        self::assertSame((string) $orderId, $session['client_reference_id']);
        self::assertSame($user['Eaddress'], $session['customer_email']);
        self::assertSame(self::server()->baseUrl . '/success_pay.php?session_id={CHECKOUT_SESSION_ID}', $session['success_url']);
        self::assertSame(self::server()->baseUrl . '/cancel_pay.php?order=' . $orderId, $session['cancel_url']);

        // Coming back before paying changes nothing.
        $early = $customer->get('success_pay.php?session_id=' . $sessionId);
        $this->assertHtmlPage($early);
        self::assertSame('Payment not completed', $early->document()->text('//h1'));
        self::assertSame('no-store', $early->header('Cache-Control'));
        self::assertSame('pending', $this->orderBySession($sessionId)['status']);
        self::assertSame(0, $this->countRows('sales'));
        self::assertSame([self::BIOLOGY => 1, self::ESABELLA => 3], $this->cartQuantities($userId));

        // A book added after checkout started is not part of this order.
        $this->assertRedirect($this->addToCart($customer, self::BRIDGE, 1), 'cart.php');

        // Pay on the hosted page and follow Stripe's redirect back.
        $return = $this->payOnHostedPage($sessionId);
        self::assertSame(self::server()->baseUrl . '/success_pay.php?session_id=' . $sessionId, $return->header('Location'));
        $success = $customer->follow($return);
        $document = $this->assertHtmlPage($success);
        self::assertSame('Thank you! Your payment was received.', $document->text('//h1'));
        $this->assertPageText('Order #' . $orderId . ' is confirmed', $success);
        $this->assertPageText('Total paid SAR ' . number_format($expectedMinor / 100, 2), $success);

        $paid = $this->orderBySession($sessionId);
        self::assertSame('paid', $paid['status']);
        self::assertNotNull($paid['paid_at']);
        self::assertSame($this->stripe->session($sessionId)['payment_intent'] ?? null, $paid['stripe_payment_intent']);
        self::assertSame([self::BIOLOGY => 1, self::ESABELLA => 3], $this->salesForOrder($orderId));
        self::assertSame([self::BRIDGE => 1], $this->cartQuantities($userId), 'Only the purchased lines leave the cart');

        // Reloading the success page is harmless.
        $reload = $customer->get('success_pay.php?session_id=' . $sessionId);
        self::assertSame('Thank you! Your payment was received.', $reload->document()->text('//h1'));
        self::assertSame(2, $this->countRows('sales'));
        self::assertSame([self::BIOLOGY => 1, self::ESABELLA => 3], $this->salesForOrder($orderId));

        // The purchase shows up in the order history, on the book page and for staff.
        $orders = $customer->get('profilePage.php?section=orders');
        $this->assertPageText('Order #' . $orderId, $orders);
        self::assertSame(['paid'], $orders->document()->texts('//span[' . HtmlDocument::hasClass('status-badge') . ']'));
        $this->assertPageText('Sold: 3 times', $this->bookPage($customer, self::ESABELLA));
        [$admin] = $this->signedInAdmin();
        $dashboard = $admin->get('admin_dashboard.php');
        $row = '//table[' . HtmlDocument::hasClass('orders-table') . ']//tr[th[normalize-space()="#' . $orderId . '"]]';
        self::assertSame('Paying Reader', $dashboard->document()->text($row . '//span[@class="customer-name"]'));
        self::assertSame('paid', $dashboard->document()->text($row . '//span[' . HtmlDocument::hasClass('status-badge') . ']'));
    }

    public function testEmptyCartCannotBeCheckedOut(): void
    {
        [$customer] = $this->signedInCustomer();
        $response = $customer->postWithToken('checkout.php');
        $this->assertRedirect($response, 'cart.php');
        $this->assertFlash($customer->follow($response), 'error', 'Your cart is empty.');
        self::assertSame(0, $this->countRows('orders'));
        self::assertSame([], $this->stripe->sessions());
    }

    public function testSecondCheckoutExpiresTheOpenSession(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $this->assertRedirect($this->addToCart($customer, self::BRIDGE, 2), 'cart.php');

        $first = $this->startCheckout($customer);
        $second = $this->startCheckout($customer);
        self::assertNotSame($first, $second);

        self::assertSame('expired', $this->stripe->session($first)['status'] ?? null, 'The abandoned session is expired on Stripe');
        self::assertSame('canceled', $this->orderBySession($first)['status']);
        self::assertSame('pending', $this->orderBySession($second)['status']);
        self::assertSame('open', $this->stripe->session($second)['status'] ?? null);

        // The old hosted page can no longer take money: it sends the customer to the cancel page.
        $stripeVisitor = new HttpClient($this->stripe->baseUrl);
        $response = $stripeVisitor->post('/pay/' . $first, ['action' => 'pay']);
        $this->assertRedirect($response, self::server()->baseUrl . '/cancel_pay.php?order=' . $this->orderBySession($first)['id']);
        self::assertSame('expired', $this->stripe->session($first)['status'] ?? null);
        self::assertSame([self::BRIDGE => 2], $this->cartQuantities((int) $user['id']));
    }

    public function testCancelPageChangesNothing(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $this->assertRedirect($this->addToCart($customer, self::BRIDGE, 2), 'cart.php');
        $sessionId = $this->startCheckout($customer);
        $order = $this->orderBySession($sessionId);

        $stripeVisitor = new HttpClient($this->stripe->baseUrl);
        $back = $stripeVisitor->post('/pay/' . $sessionId, ['action' => 'cancel']);
        $this->assertRedirect($back, self::server()->baseUrl . '/cancel_pay.php?order=' . $order['id']);

        $page = $customer->follow($back);
        $document = $this->assertHtmlPage($page);
        self::assertSame('Checkout canceled', $document->text('//h1'));
        $this->assertPageText('Order #' . $order['id'], $page);
        $this->assertPageText('Your cart still holds 2 books.', $page);
        self::assertSame('no-store', $page->header('Cache-Control'));

        self::assertSame($order, $this->orderBySession($sessionId), 'The cancel page is read-only');
        self::assertSame('open', $this->stripe->session($sessionId)['status'] ?? null);
        self::assertSame([self::BRIDGE => 2], $this->cartQuantities((int) $user['id']));
        self::assertSame(0, $this->countRows('sales'));
    }

    public function testTamperedAmountIsFlaggedForReviewAndNotFulfilled(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $this->assertRedirect($this->addToCart($customer, self::ESABELLA, 2), 'cart.php');
        $sessionId = $this->startCheckout($customer);
        $this->stripe->pay($sessionId, ['amount_total' => 100]);

        $this->expectAppLogError('/Checkout Session does not match order; not fulfilling/');
        $this->expectAppLogError('/MANUAL REVIEW REQUIRED: captured payment does not match order/');

        $page = $customer->get('success_pay.php?session_id=' . $sessionId);
        $this->assertHtmlPage($page);
        self::assertSame('Payment under review', $page->document()->text('//h1'));
        $this->assertNotPageText('Thank you', $page);

        $order = $this->orderBySession($sessionId);
        self::assertSame('failed', $order['status'], 'The order leaves the pending queue for manual review');
        self::assertNull($order['paid_at']);
        self::assertSame(0, $this->countRows('sales'));
        self::assertSame([self::ESABELLA => 2], $this->cartQuantities((int) $user['id']));

        // Reloading does not fulfil it either.
        self::assertSame('Payment under review', $customer->get('success_pay.php?session_id=' . $sessionId)->document()->text('//h1'));
        self::assertSame(0, $this->countRows('sales'));
    }

    public function testWrongCurrencyIsAlsoFlagged(): void
    {
        [$customer] = $this->signedInCustomer();
        $this->assertRedirect($this->addToCart($customer, self::BIOLOGY, 1), 'cart.php');
        $sessionId = $this->startCheckout($customer);
        $this->stripe->pay($sessionId, ['currency' => 'usd']);
        $this->expectAppLogError('/Checkout Session does not match order/');
        $this->expectAppLogError('/MANUAL REVIEW REQUIRED/');

        self::assertSame('Payment under review', $customer->get('success_pay.php?session_id=' . $sessionId)->document()->text('//h1'));
        self::assertSame('failed', $this->orderBySession($sessionId)['status']);
        self::assertSame(0, $this->countRows('sales'));
    }

    public function testSignedWebhookFulfilsAPendingOrder(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $this->assertRedirect($this->addToCart($customer, self::ESABELLA, 2), 'cart.php');
        $sessionId = $this->startCheckout($customer);
        $orderId = (int) $this->orderBySession($sessionId)['id'];
        $session = $this->stripe->pay($sessionId);
        $payload = $this->webhookEvent('checkout.session.completed', $session);
        $endpoint = $this->client();

        // Bad signatures are rejected without touching the order.
        $badSignatures = [
            'wrong secret' => self::stripeSignature($payload, null, 'whsec_attacker'),
            'stale timestamp' => self::stripeSignature($payload, time() - 3600),
            'garbage' => 'nonsense',
            'missing' => null,
        ];
        foreach ($badSignatures as $label => $signature) {
            $headers = ['Content-Type' => 'application/json'];
            if ($signature !== null) {
                $headers['Stripe-Signature'] = $signature;
            }
            $response = $endpoint->post('stripe_webhook.php', $payload, $headers);
            $this->assertStatus(400, $response, "Webhook with $label signature");
            self::assertSame(['received' => false], $response->json());
        }
        $tampered = str_replace('"amount_total":' . $session['amount_total'], '"amount_total":1', $payload);
        self::assertNotSame($payload, $tampered);
        $this->assertStatus(400, $endpoint->post('stripe_webhook.php', $tampered, ['Stripe-Signature' => self::stripeSignature($payload)]));
        self::assertSame('pending', $this->orderBySession($sessionId)['status']);
        self::assertSame(0, $this->countRows('sales'));

        $response = $endpoint->post('stripe_webhook.php', $payload, ['Content-Type' => 'application/json', 'Stripe-Signature' => self::stripeSignature($payload)]);
        $this->assertStatus(200, $response);
        self::assertSame(['received' => true], $response->json());
        self::assertSame([], $response->headerValues('Set-Cookie'));

        $order = $this->orderBySession($sessionId);
        self::assertSame('paid', $order['status']);
        self::assertSame($session['payment_intent'], $order['stripe_payment_intent']);
        self::assertSame([self::ESABELLA => 2], $this->salesForOrder($orderId));
        self::assertSame([], $this->cartQuantities((int) $user['id']));

        // Stripe retries are harmless, and the customer's return page agrees.
        $this->assertStatus(200, $endpoint->post('stripe_webhook.php', $payload, ['Stripe-Signature' => self::stripeSignature($payload)]));
        self::assertSame(1, $this->countRows('sales'));
        self::assertSame('Thank you! Your payment was received.', $customer->get('success_pay.php?session_id=' . $sessionId)->document()->text('//h1'));

        $this->assertStatus(405, $endpoint->get('stripe_webhook.php'));
    }

    public function testExpiredWebhookMarksTheOrderExpired(): void
    {
        [$customer] = $this->signedInCustomer();
        $this->assertRedirect($this->addToCart($customer, self::BRIDGE, 1), 'cart.php');
        $sessionId = $this->startCheckout($customer);
        // The webhook acts on Stripe's state, so the session must really be expired there.
        $session = $this->stripe->setState($sessionId, 'expired');
        $payload = $this->webhookEvent('checkout.session.expired', $session);

        $response = $this->client()->post('stripe_webhook.php', $payload, ['Stripe-Signature' => self::stripeSignature($payload)]);
        $this->assertStatus(200, $response);
        self::assertSame('expired', $this->orderBySession($sessionId)['status']);
        self::assertSame(0, $this->countRows('sales'));
    }
}
