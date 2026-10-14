<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\AccountHelpers;
use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;

/**
 * Checkout with the built-in payment simulator running in-process
 * (PAYMENT_SIMULATOR=true), as in the demo: the real pages, the simulated
 * payment page pay.php and every way a payment can end, handled by the
 * store's own return and settlement code. No request reaches the Stripe mock.
 */
final class PaymentSimulatorTest extends HttpTestCase
{
    use AccountHelpers;

    private const BIOLOGY = 7;      // 5.50
    private const BRIDGE = 8;       // 10.00
    private const ESABELLA = 51;    // 19.99

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureServer(['PAYMENT_SIMULATOR' => 'true']);
    }

    protected function assertPostConditions(): void
    {
        self::assertSame([], $this->stripe->sessions(), 'With the simulator on, nothing is sent to the Stripe mock');
        parent::assertPostConditions();
    }

    private function store(): string
    {
        return self::server()->configuration['PAYMENT_SIMULATOR_STORE'];
    }

    /**
     * The simulator's stored session.
     *
     * @return array<string, mixed>
     */
    private function simulatorSession(string $sessionId): array
    {
        $session = payment_simulator_session($sessionId, $this->store());
        self::assertNotNull($session, "The simulator does not know $sessionId");
        return $session;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
     */
    private function changeSimulatorSession(string $sessionId, callable $change): void
    {
        payment_simulator_transaction(static function (array &$state) use ($sessionId, $change): void {
            $state['sessions'][$sessionId] = $change($state['sessions'][$sessionId]);
        }, $this->store());
    }

    /**
     * Fills the cart through the book pages, submits the cart's checkout form
     * and returns the session id from the redirect to pay.php.
     *
     * @param array<int, int> $quantities book id => quantity
     */
    private function checkoutToPaymentPage(HttpClient $client, array $quantities): string
    {
        foreach ($quantities as $bookId => $quantity) {
            $this->assertRedirect($this->addToCart($client, $bookId, $quantity), 'cart.php');
        }
        // The cart does not promise Stripe's checkout.
        $cart = $client->get('cart.php');
        $this->assertPageText("Payments in this store are simulated: you will pay on the store's own payment page", $cart);
        $this->assertNotPageText('Stripe', $cart);
        $response = $this->submitCheckout($client);
        $this->assertStatus(303, $response);
        $location = (string) $response->header('Location');
        self::assertMatchesRegularExpression(
            '#^' . preg_quote(self::server()->baseUrl, '#') . '/pay\.php\?session=(cs_test_[a-f0-9]{24})$#',
            $location,
            'Checkout must send the browser to the store\'s own payment page'
        );
        return substr($location, strrpos($location, '=') + 1);
    }

    private function paymentPage(HttpClient $client, string $sessionId): HttpResponse
    {
        $page = $client->get('pay.php?session=' . $sessionId);
        $this->assertHtmlPage($page);
        self::assertSame('no-store', $page->header('Cache-Control'));
        return $page;
    }

    private function press(HttpClient $client, HttpResponse $page, string $sessionId, string $action): HttpResponse
    {
        return $client->submitForm($page, 'pay.php?session=' . $sessionId, ['action' => $action]);
    }

    private function successUrl(string $sessionId): string
    {
        return self::server()->baseUrl . '/success_pay.php?session_id=' . $sessionId;
    }

    public function testTheCustomerPaysOnTheSimulatedPageAndTheOrderIsConfirmed(): void
    {
        [$customer, $user] = $this->signedInCustomer('Simulated Reader');
        $sessionId = $this->checkoutToPaymentPage($customer, [self::ESABELLA => 2, self::BIOLOGY => 1]);
        $order = $this->orderBySession($sessionId);
        self::assertSame('pending', $order['status']);
        self::assertSame(2 * 1999 + 550, (int) $order['amount_minor']);

        $page = $this->paymentPage($customer, $sessionId);
        $document = $page->document();
        self::assertSame('Pay BookWise', $document->text('//h1'));
        self::assertStringStartsWith('Simulated payment · BookWise', $document->title());
        $this->assertPageText('No money is taken and no card details are needed.', $page);
        self::assertSame(
            ['Esabella SAR 19.99 each 2 SAR 39.98', 'Biology SAR 5.50 each 1 SAR 5.50'],
            $document->texts('//table[' . HtmlDocument::hasClass('order-table') . ']/tbody/tr')
        );
        self::assertSame('Total due SAR 45.48', $document->text('//table[' . HtmlDocument::hasClass('order-table') . ']/tfoot/tr'));
        $form = '//form[@method="post"][@action="pay.php?session=' . $sessionId . '"]';
        self::assertSame(['pay', 'decline', 'slow', 'cancel'], array_map(
            static fn (\DOMElement $button): string => $button->getAttribute('value'),
            $document->elements($form . '//button[@name="action"]')
        ));
        self::assertSame(['Pay SAR 45.48', 'Decline', 'Slow payment', 'Cancel'], $document->texts($form . '//button'));
        self::assertSame(0, $document->count('//input[@type="text" or @type="number" or @type="tel"]'), 'No card details are asked for');

        $paid = $this->press($customer, $page, $sessionId, 'pay');
        $this->assertRedirect($paid, $this->successUrl($sessionId));
        $success = $customer->follow($paid);
        $this->assertHtmlPage($success);
        self::assertSame('Thank you! Your payment was received.', $success->document()->text('//h1'));
        $this->assertPageText('Total paid SAR 45.48', $success);

        $order = $this->orderBySession($sessionId);
        $session = $this->simulatorSession($sessionId);
        self::assertSame('paid', $order['status']);
        self::assertSame(['complete', 'paid'], [$session['status'], $session['payment_status']]);
        self::assertSame($session['payment_intent'], $order['stripe_payment_intent']);
        self::assertNull($session['customer_email'], 'The simulator keeps no customer data');
        self::assertSame([self::BIOLOGY => 1, self::ESABELLA => 2], $this->salesForOrder((int) $order['id']));
        self::assertSame([], $this->cartQuantities((int) $user['id']));

        // The paid session's page only leads back to the confirmation, and paying again takes nothing.
        $after = $this->paymentPage($customer, $sessionId);
        $this->assertPageText('This payment is complete.', $after);
        self::assertSame($this->successUrl($sessionId), $after->document()->first('//a[normalize-space()="Return to the store"]')?->getAttribute('href'));
        self::assertSame(0, $after->document()->count($form));
        $this->assertRedirect($customer->postWithToken('pay.php?session=' . $sessionId, ['action' => 'pay']), $this->successUrl($sessionId));
        self::assertSame($session['payment_intent'], $this->simulatorSession($sessionId)['payment_intent']);
        self::assertSame(2, $this->countRows('sales'));
    }

    public function testADeclinedCardKeepsTheOrderUnpaidAndTheCustomerCanTryAgain(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $sessionId = $this->checkoutToPaymentPage($customer, [self::BRIDGE => 2]);

        $declined = $this->press($customer, $this->paymentPage($customer, $sessionId), $sessionId, 'decline');
        $this->assertRedirect($declined, 'pay.php?session=' . $sessionId);
        $again = $customer->follow($declined);
        $this->assertHtmlPage($again);
        $this->assertFlash($again, 'error', 'Your card was declined (simulated). You have not been charged. Try again, or cancel to return to the store.');

        $session = $this->simulatorSession($sessionId);
        self::assertSame(['open', 'unpaid', null], [$session['status'], $session['payment_status'], $session['payment_intent']]);
        self::assertSame('pending', $this->orderBySession($sessionId)['status']);
        self::assertSame(0, $this->countRows('sales'));

        // Returning to the store now: the payment did not happen.
        $unpaid = $customer->get('success_pay.php?session_id=' . $sessionId);
        $this->assertHtmlPage($unpaid);
        self::assertSame('Payment not completed', $unpaid->document()->text('//h1'));
        self::assertSame('pending', $this->orderBySession($sessionId)['status']);
        self::assertSame([self::BRIDGE => 2], $this->cartQuantities((int) $user['id']));

        // Another try on the same page succeeds.
        $paid = $this->press($customer, $again, $sessionId, 'pay');
        $this->assertRedirect($paid, $this->successUrl($sessionId));
        self::assertSame('Thank you! Your payment was received.', $customer->follow($paid)->document()->text('//h1'));
        self::assertSame('paid', $this->orderBySession($sessionId)['status']);
    }

    public function testASlowPaymentIsProcessingUntilTheBankConfirmsIt(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $sessionId = $this->checkoutToPaymentPage($customer, [self::ESABELLA => 1]);

        $slow = $this->press($customer, $this->paymentPage($customer, $sessionId), $sessionId, 'slow');
        $this->assertRedirect($slow, $this->successUrl($sessionId));
        $processing = $customer->follow($slow);
        $this->assertHtmlPage($processing);
        self::assertSame("We're confirming your payment…", $processing->document()->text('//h1'));
        $this->assertPageText('Your simulated slow payment is being confirmed, as some banks do.', $processing);
        $this->assertNotPageText('Stripe', $processing);
        self::assertSame('pending', $this->orderBySession($sessionId)['status']);
        self::assertSame(['complete', 'unpaid'], [$this->simulatorSession($sessionId)['status'], $this->simulatorSession($sessionId)['payment_status']]);
        $this->assertPageText('This payment is being confirmed.', $this->paymentPage($customer, $sessionId));

        // Meanwhile no second payable checkout is opened.
        $this->assertRedirect($this->addToCart($customer, self::BIOLOGY, 1), 'cart.php');
        $blocked = $this->submitCheckout($customer);
        $this->assertRedirect($blocked, 'cart.php');
        $this->assertFlash($customer->follow($blocked), 'error', 'We could not verify your previous checkout, so a new payment was not started. You have not been charged twice. Please try again in a few minutes.');
        self::assertSame(1, $this->countRows('orders'));

        // The bank confirms the payment; refreshing the status page fulfils the order.
        $this->changeSimulatorSession($sessionId, static function (array $session): array {
            $session['simulator']['processing_until'] = time() - 1;
            return $session;
        });
        $refreshed = $customer->get('success_pay.php?session_id=' . $sessionId);
        self::assertSame('Thank you! Your payment was received.', $refreshed->document()->text('//h1'));
        self::assertSame('paid', $this->orderBySession($sessionId)['status']);
        self::assertSame([self::BIOLOGY => 1], $this->cartQuantities((int) $user['id']), 'Only the paid book leaves the cart');
    }

    public function testCancelReturnsToTheCancelPageAndTheNextCheckoutRetiresTheSession(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $first = $this->checkoutToPaymentPage($customer, [self::BRIDGE => 2]);
        $order = $this->orderBySession($first);

        $canceled = $this->press($customer, $this->paymentPage($customer, $first), $first, 'cancel');
        $cancelUrl = self::server()->baseUrl . '/cancel_pay.php?order=' . $order['id'];
        $this->assertRedirect($canceled, $cancelUrl);
        $page = $customer->follow($canceled);
        $this->assertHtmlPage($page);
        self::assertSame('Checkout canceled', $page->document()->text('//h1'));
        $this->assertPageText('Your cart still holds 2 books.', $page);
        self::assertSame('open', $this->simulatorSession($first)['status'], 'Like on Stripe, canceling leaves the session open');
        self::assertSame('pending', $this->orderBySession($first)['status']);

        // Checking out again expires the abandoned session, so it can never be paid.
        $response = $this->submitCheckout($customer);
        $this->assertStatus(303, $response);
        $second = substr((string) $response->header('Location'), -strlen($first));
        self::assertNotSame($first, $second);
        self::assertSame('expired', $this->simulatorSession($first)['status']);
        self::assertSame('canceled', $this->orderBySession($first)['status']);
        self::assertSame('pending', $this->orderBySession($second)['status']);

        $old = $this->paymentPage($customer, $first);
        $this->assertPageText('This payment session has ended. You have not been charged.', $old);
        self::assertSame($cancelUrl, $old->document()->first('//a[normalize-space()="Return to the store"]')?->getAttribute('href'));
        $this->assertRedirect($customer->postWithToken('pay.php?session=' . $first, ['action' => 'pay']), $cancelUrl);
        self::assertSame(['expired', 'unpaid'], [$this->simulatorSession($first)['status'], $this->simulatorSession($first)['payment_status']]);
        self::assertSame([self::BRIDGE => 2], $this->cartQuantities((int) $user['id']));
    }

    public function testASessionThatExpiresOnItsOwnIsSettledAsExpired(): void
    {
        [$customer] = $this->signedInCustomer();
        $sessionId = $this->checkoutToPaymentPage($customer, [self::BIOLOGY => 1]);
        $this->changeSimulatorSession($sessionId, static fn (array $session): array => ['expires_at' => time() - 1] + $session);

        $this->assertPageText('This payment session has ended.', $this->paymentPage($customer, $sessionId));
        self::assertSame('Payment not completed', $customer->get('success_pay.php?session_id=' . $sessionId)->document()->text('//h1'));

        $response = $this->submitCheckout($customer);
        $this->assertStatus(303, $response);
        self::assertSame('expired', $this->orderBySession($sessionId)['status']);
    }

    public function testStaffRefundAPaidOrderThroughTheSimulator(): void
    {
        [$customer] = $this->signedInCustomer();
        $sessionId = $this->checkoutToPaymentPage($customer, [self::ESABELLA => 1]);
        $this->assertRedirect($this->press($customer, $this->paymentPage($customer, $sessionId), $sessionId, 'pay'), $this->successUrl($sessionId));
        $customer->get('success_pay.php?session_id=' . $sessionId);
        $orderId = (int) $this->orderBySession($sessionId)['id'];

        [$admin] = $this->signedInAdmin();
        $page = $admin->get('admin_order.php?id=' . $orderId);
        $this->assertPageText('(a simulated refund: no money moves)', $page);
        $this->assertNotPageText('Stripe', $page);
        $response = $admin->submitForm($page, 'admin_order.php?id=' . $orderId, [], ['action' => 'refund']);
        $this->assertFlash($admin->follow($response), 'success', 'Order #' . $orderId . ' was refunded.');

        $order = $this->orderBySession($sessionId);
        $refunds = payment_simulator_transaction(static fn (array &$state): array => array_values($state['refunds']), $this->store());
        self::assertSame('refunded', $order['status']);
        self::assertCount(1, $refunds);
        self::assertSame([$order['stripe_refund_id'], 1999, $order['stripe_payment_intent']], [$refunds[0]['id'], $refunds[0]['amount'], $refunds[0]['payment_intent']]);

        // A payment the simulator has forgotten (its state file was lost) is refunded in the store only:
        // no money ever moved.
        $second = $this->checkoutToPaymentPage($customer, [self::BIOLOGY => 1]);
        $this->assertRedirect($this->press($customer, $this->paymentPage($customer, $second), $second, 'pay'), $this->successUrl($second));
        $customer->get('success_pay.php?session_id=' . $second);
        $secondOrderId = (int) $this->orderBySession($second)['id'];
        unlink($this->store());

        $page = $admin->get('admin_order.php?id=' . $secondOrderId);
        $response = $admin->submitForm($page, 'admin_order.php?id=' . $secondOrderId, [], ['action' => 'refund']);
        $this->assertFlash($admin->follow($response), 'success', 'Order #' . $secondOrderId . ' was refunded.');
        self::assertStringStartsWith('re_demo_', (string) $this->orderBySession($second)['stripe_refund_id']);
    }

    public function testTheProfileAndAFailedRefundDoNotSendAnyoneToStripe(): void
    {
        [$customer] = $this->signedInCustomer();
        $profile = $customer->get('profilePage.php?section=payment');
        $this->assertHtmlPage($profile);
        $this->assertPageText("Payments in BookWise are simulated on the store's own payment page, so no card details are ever asked for or stored.", $profile);
        $this->assertNotPageText('Stripe', $profile);

        $sessionId = $this->checkoutToPaymentPage($customer, [self::BRIDGE => 1]);
        $this->assertRedirect($this->press($customer, $this->paymentPage($customer, $sessionId), $sessionId, 'pay'), $this->successUrl($sessionId));
        $customer->get('success_pay.php?session_id=' . $sessionId);
        $orderId = (int) $this->orderBySession($sessionId)['id'];

        // The simulator cannot answer (its state folder is a file), so the refund fails.
        $blocker = self::server()->runDirectory . DIRECTORY_SEPARATOR . 'simulator-blocker-' . bin2hex(random_bytes(4));
        file_put_contents($blocker, 'a file, not a folder');
        $this->configureServer(['PAYMENT_SIMULATOR_STORE' => $blocker . DIRECTORY_SEPARATOR . 'state.json']);
        $this->expectAppLogError('/ERROR: Stripe refund failed .*The payment simulator is not available/');

        [$admin] = $this->signedInAdmin();
        $page = $admin->get('admin_order.php?id=' . $orderId);
        $response = $admin->submitForm($page, 'admin_order.php?id=' . $orderId, [], ['action' => 'refund']);
        $this->assertFlash($admin->follow($response), 'error', 'The payment simulator could not refund this order right now, so nothing was changed. Please try again later.');
        self::assertSame('paid', $this->orderBySession($sessionId)['status']);
        unlink($blocker);
    }

    public function testARealStoreNeverUsesTheSimulator(): void
    {
        // bin/setup.php writes PAYMENT_SIMULATOR=true. An owner who leaves demo mode
        // (APP_DEMO=false in production) but keeps that line must not hand out free orders.
        [$customer] = $this->signedInCustomer();
        $sessionId = $this->checkoutToPaymentPage($customer, [self::BIOLOGY => 1]);
        $page = $this->paymentPage($customer, $sessionId);

        $this->configureServer(['APP_ENV' => 'production', 'APP_DEMO' => 'false', 'PAYMENT_SIMULATOR' => 'true']);
        $this->expectAppLogError('/ERROR: Refusing the payment simulator on a real store \(APP_ENV=production, APP_DEMO=false\): set PAYMENT_SIMULATOR=false/');

        $this->assertHtmlPage($customer->get('pay.php?session=' . $sessionId), 404);
        $this->assertHtmlPage($this->press($customer, $page, $sessionId, 'pay'), 404);
        $session = $this->simulatorSession($sessionId);
        self::assertSame(['open', 'unpaid'], [$session['status'], $session['payment_status']], 'Nobody can pay with the simulator');

        // Checkout is refused instead of falling back to Stripe or to the simulator.
        $cart = $customer->get('cart.php');
        $this->assertNotPageText('simulated', $cart);
        $refused = $this->submitCheckout($customer);
        $this->assertRedirect($refused, 'cart.php');
        $this->assertFlash($customer->follow($refused), 'error', 'Online payments are not available in this environment.');
        self::assertSame(1, $this->countRows('orders'));
        self::assertSame('pending', $this->orderBySession($sessionId)['status']);

        // A public demo in production keeps it.
        $this->configureServer(['APP_DEMO' => 'true']);
        $this->assertHtmlPage($customer->get('pay.php?session=' . $sessionId));
    }

    public function testStripeWebhooksAreClosedWhileTheSimulatorTakesThePayments(): void
    {
        $payload = (string) json_encode([
            'id' => 'evt_simulator_' . bin2hex(random_bytes(6)),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_unknown', 'object' => 'checkout.session', 'client_reference_id' => '1']],
        ]);
        $endpoint = $this->client();

        $signed = $endpoint->post('stripe_webhook.php', $payload, ['Content-Type' => 'application/json', 'Stripe-Signature' => self::stripeSignature($payload)]);
        $this->assertStatus(404, $signed);
        self::assertSame(['received' => false], $signed->json());
        self::assertSame([], $signed->headerValues('Set-Cookie'));

        // A demo has no webhook secret at all: visitors' requests are still answered
        // with 404, and nothing is written to the log.
        $this->configureServer(['STRIPE_WEBHOOK_SECRET' => '']);
        $this->assertStatus(404, $endpoint->post('stripe_webhook.php', $payload, ['Stripe-Signature' => 't=1,v1=forged']));
        $this->assertStatus(404, $endpoint->post('stripe_webhook.php', 'not json'));
        self::assertSame([], $this->appLogLines(), 'Webhook requests leave no trace in app.log');
    }

    public function testThePaymentPageIsTranslatedIntoArabic(): void
    {
        [$customer] = $this->signedInCustomer();
        $sessionId = $this->checkoutToPaymentPage($customer, [self::ESABELLA => 1]);

        $this->assertRedirect($customer->get('pay.php?session=' . $sessionId . '&lang=ar'), 'pay.php?session=' . $sessionId);
        $page = $this->paymentPage($customer, $sessionId);
        $document = $page->document();
        self::assertSame('ar', $document->first('/html')?->getAttribute('lang'));
        self::assertSame('rtl', $document->first('/html')?->getAttribute('dir'));
        self::assertSame('الدفع إلى BookWise', $document->text('//h1'));
        self::assertSame('المبلغ المستحق 19.99 ر.س', $document->text('//table[' . HtmlDocument::hasClass('order-table') . ']/tfoot/tr'));
        self::assertSame(['ادفع 19.99 ر.س', 'رفض البطاقة', 'دفع بطيء', 'إلغاء'], $document->texts('//form[@action="pay.php?session=' . $sessionId . '"]//button'));

        $declined = $customer->follow($this->press($customer, $page, $sessionId, 'decline'));
        $this->assertFlash($declined, 'error', 'رُفضت بطاقتك (محاكاة). لم يُخصم منك أي مبلغ. حاول مرة أخرى، أو ألغِ العملية للعودة إلى المتجر.');
    }

    public function testThePageExistsOnlyWithTheSimulatorAndGuardsItsInput(): void
    {
        [$customer] = $this->signedInCustomer();
        $sessionId = $this->checkoutToPaymentPage($customer, [self::BIOLOGY => 1]);
        $unchanged = $this->simulatorSession($sessionId);

        // Without the simulator there is no payment page at all.
        $this->configureServer(['PAYMENT_SIMULATOR' => 'false']);
        $this->assertHtmlPage($customer->get('pay.php?session=' . $sessionId), 404);
        $this->configureServer(['PAYMENT_SIMULATOR' => '', 'APP_DEMO' => 'true']);
        $this->assertHtmlPage($customer->get('pay.php?session=' . $sessionId), 200);
        $this->configureServer(['PAYMENT_SIMULATOR' => 'true', 'APP_DEMO' => 'false']);

        foreach (['pay.php', 'pay.php?session=', 'pay.php?session=cs_test_unknown', 'pay.php?session=..%2Fx', 'pay.php?session[]=x'] as $path) {
            $page = $customer->get($path);
            $this->assertHtmlPage($page, 404);
            $this->assertPageText('This payment link is not valid.', $page);
        }

        // Like Stripe's page, it needs no store sign-in; changes need the visitor's CSRF token.
        $guest = $this->client();
        $this->assertHtmlPage($guest->get('pay.php?session=' . $sessionId));
        $this->assertHtmlPage($guest->post('pay.php?session=' . $sessionId, ['action' => 'pay']), 419);
        $this->assertHtmlPage($customer->post('pay.php?session=' . $sessionId, ['action' => 'pay', '_token' => 'forged']), 419);
        $this->assertHtmlPage($customer->postWithToken('pay.php?session=' . $sessionId, ['action' => 'refund']), 400);
        $this->assertHtmlPage($customer->postWithToken('pay.php?session=' . $sessionId, ['action' => ['pay']]), 400);
        $this->assertHtmlPage($customer->request('PUT', 'pay.php?session=' . $sessionId, ['action' => 'pay']), 405);
        $this->assertHtmlPage($customer->get('pay.php?session=' . $sessionId . '&action=pay'));
        $this->assertHtmlPage($customer->postWithToken('pay.php?session=cs_test_unknown', ['action' => 'pay']), 404);
        self::assertSame($unchanged, $this->simulatorSession($sessionId), 'None of these changed the session');
        self::assertSame('pending', $this->orderBySession($sessionId)['status']);
    }
}
