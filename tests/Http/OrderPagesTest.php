<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\AccountHelpers;
use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;
use BookWise\Tests\Support\StockFixtures;

/**
 * After the payment: the customer's order page (status, delivery address,
 * receipt, "Buy again"), staff moving an order through processing ->
 * shipped -> delivered on admin_order.php, and the order emails, each sent
 * exactly once in the customer's language (also into the demo inbox).
 */
final class OrderPagesTest extends HttpTestCase
{
    use AccountHelpers;
    use StockFixtures;

    private const BIOLOGY = 7;      // 5.50
    private const BRIDGE = 8;       // 10.00
    private const ESABELLA = 51;    // 19.99

    /**
     * Subjects of the emails sent to the address since the test started.
     *
     * @return list<string>
     */
    private function subjectsTo(string $email): array
    {
        return array_column($this->mailsTo($email), 'subject');
    }

    /**
     * @return list<string> The order's progress as shown: labels with "(Not yet)" for the steps ahead
     */
    private static function timeline(HtmlDocument $document): array
    {
        return $document->texts('//ol[' . HtmlDocument::hasClass('order-timeline') . ']/li//p[' . HtmlDocument::hasClass('timeline-label') . ']');
    }

    private static function badge(HtmlDocument $document): string
    {
        return $document->text('//p[' . HtmlDocument::hasClass('order-meta') . ']/span[' . HtmlDocument::hasClass('status-badge') . ']');
    }

    private function adminAction(HttpClient $admin, int $orderId, string $action, array $values = []): HttpResponse
    {
        $page = $admin->get('admin_order.php?id=' . $orderId);
        $this->assertHtmlPage($page);
        return $admin->submitForm($page, 'admin_order.php?id=' . $orderId, $values, ['action' => $action]);
    }

    public function testTheCustomerFollowsTheOrderFromPaymentToDelivery(): void
    {
        [$customer, $user] = $this->signedInCustomer('Order Reader');
        $email = (string) $user['Eaddress'];
        $this->markVerified((int) $user['id']);
        $purchase = $this->purchase($customer, [self::ESABELLA => 2, self::BIOLOGY => 1]);
        $orderId = (int) $purchase['order']['id'];
        $orderUrl = 'order.php?id=' . $orderId;

        // The confirmation page links to the order and shows the VAT it contains.
        $success = $purchase['page']->document();
        self::assertSame($orderUrl, $success->first('//a[normalize-space()="View order details"]')?->getAttribute('href'));
        self::assertSame('Includes VAT (15%) SAR 5.93', $success->text('//tr[' . HtmlDocument::hasClass('order-vat') . ']'));
        $this->assertPageText('We are preparing your books for delivery and will email you when they are on their way.', $purchase['page']);
        self::assertSame(['Your BookWise order #' . $orderId . ' is confirmed'], $this->subjectsTo($email));
        $confirmation = $this->mailsTo($email)[0]['body'];
        self::assertStringContainsString("Hello Order Reader,\n\nThank you for your order #$orderId.", $confirmation);
        self::assertStringContainsString("Delivery address\nSara Ahmed\n1234 King Fahd Road", $confirmation);
        self::assertStringContainsString(self::server()->baseUrl . '/' . $orderUrl, $confirmation);

        // The order page: progress, address and receipt.
        $page = $customer->get($orderUrl);
        $document = $this->assertHtmlPage($page);
        self::assertSame('no-store', $page->header('Cache-Control'));
        self::assertSame('Order #' . $orderId, $document->text('//h1'));
        self::assertSame('Processing', self::badge($document));
        self::assertSame(['Paid', 'Processing', 'Shipped (Not yet)', 'Delivered (Not yet)'], self::timeline($document));
        self::assertSame('Processing', $document->text('//li[@aria-current="step"]//p[' . HtmlDocument::hasClass('timeline-label') . ']'));
        self::assertSame(
            'Sara Ahmed 1234 King Fahd Road Al Olaya Riyadh 12345-6789 Short address: RRRD2929 +966 51 234 5678',
            $document->text('//section[' . HtmlDocument::hasClass('order-delivery') . ']//p[' . HtmlDocument::hasClass('address-text') . ']')
        );
        self::assertSame('Receipt', $document->text('//h2[@id="receipt-title"]'));
        self::assertSame(
            ['Esabella 2 SAR 19.99 SAR 39.98', 'Biology 1 SAR 5.50 SAR 5.50'],
            $document->texts('//table[' . HtmlDocument::hasClass('receipt-table') . ']/tbody/tr')
        );
        self::assertSame(
            ['Total before VAT SAR 39.55', 'VAT (15%) SAR 5.93', 'Delivery Free', 'Total (VAT included) SAR 45.48'],
            $document->texts('//table[' . HtmlDocument::hasClass('receipt-table') . ']/tfoot/tr')
        );
        $this->assertPageText('Prices include 15% VAT. This receipt is not a tax invoice.', $page);
        $print = $document->first('//button[@data-print]');
        self::assertSame(['Print receipt', 'Receipt for order #' . $orderId . ' · BookWise'], [HtmlDocument::normalize((string) $print?->textContent), $print?->getAttribute('data-print-title')]);
        self::assertTrue($print?->hasAttribute('hidden'), 'Shown by js/order.js, which can print');
        self::assertNotNull($document->first('//script[starts-with(@src, "js/order.js")]'));
        self::assertNotNull($document->first('//link[starts-with(@href, "css/order.css")]'));

        // Staff ship it, with a tracking number; a stale second click changes nothing.
        [$admin] = $this->signedInAdmin();
        $list = $admin->get('admin_orders.php?fulfilment=processing');
        self::assertSame(['#' . $orderId], $list->document()->texts('//table[' . HtmlDocument::hasClass('order-list-table') . ']//tbody/tr/th'));
        self::assertSame('Processing', $list->document()->text('//tbody//span[' . HtmlDocument::hasClass('fulfilment-badge') . ']'));
        $detail = $admin->get('admin_order.php?id=' . $orderId);
        $adminDocument = $this->assertHtmlPage($detail);
        $this->assertPageText('Sara Ahmed', $detail);
        self::assertSame('tel:+966512345678', $adminDocument->first('//p[' . HtmlDocument::hasClass('address-call') . ']/a')?->getAttribute('href'));
        self::assertSame(
            ['SMSA Express', 'Aramex', 'SPL (Saudi Post)', 'Naqel Express', 'J&T Express', 'DHL Express'],
            array_map(static fn (\DOMElement $option): string => $option->getAttribute('value'), $adminDocument->elements('//datalist[@id="carrier-options"]/option')),
            "Carrier suggestions in the admin's language"
        );
        $early = $admin->postWithToken('admin_order.php?id=' . $orderId, ['action' => 'deliver']);
        $this->assertFlash($admin->follow($early), 'info', 'Order #' . $orderId . ' has not been shipped, so it cannot be marked as delivered.');

        $invalid = $admin->submitForm($detail, 'admin_order.php?id=' . $orderId, ['carrier' => 'SMSA Express', 'tracking_number' => 'SA<123>'], ['action' => 'ship']);
        $this->assertRedirect($invalid, 'admin_order.php?id=' . $orderId . '#fulfilment');
        $form = $admin->follow($invalid);
        $this->assertFlash($form, 'error', 'The order was not marked as shipped. Please fix the highlighted fields.');
        self::assertSame('The tracking number may only contain letters, digits, spaces and hyphens, at most 40 characters.', $form->document()->fieldError('shipment-tracking_number-error'));
        self::assertSame('SMSA Express', $form->document()->first('//input[@id="shipment-carrier"]')?->getAttribute('value'));
        self::assertSame('processing', db_value('SELECT fulfilment_status FROM orders WHERE id = ?', [$orderId]));

        $shipped = $admin->submitForm($detail, 'admin_order.php?id=' . $orderId, ['carrier' => 'SMSA Express', 'tracking_number' => 'SA123456'], ['action' => 'ship']);
        $this->assertFlash($admin->follow($shipped), 'success', 'Order #' . $orderId . ' was marked as shipped.');
        $again = $admin->submitForm($detail, 'admin_order.php?id=' . $orderId, ['carrier' => 'Aramex', 'tracking_number' => 'X1'], ['action' => 'ship']);
        $this->assertFlash($admin->follow($again), 'info', 'Order #' . $orderId . ' is not waiting to be shipped, so nothing was changed.');
        self::assertSame(['shipped', 'SMSA Express', 'SA123456'], array_values((array) db_one('SELECT fulfilment_status, carrier, tracking_number FROM orders WHERE id = ?', [$orderId])));

        $document = $customer->get($orderUrl)->document();
        self::assertSame('Shipped', self::badge($document));
        self::assertSame(['Paid', 'Processing', 'Shipped', 'Delivered (Not yet)'], self::timeline($document));
        self::assertSame(['Carrier: SMSA Express', 'Tracking number: SA123456'], array_slice($document->texts('//li[@aria-current="step"]//p[' . HtmlDocument::hasClass('timeline-text') . ']'), 1));

        // Then delivered.
        $this->assertFlash($admin->follow($this->adminAction($admin, $orderId, 'deliver')), 'success', 'Order #' . $orderId . ' was marked as delivered.');
        $staleDeliver = $admin->postWithToken('admin_order.php?id=' . $orderId, ['action' => 'deliver']);
        $this->assertFlash($admin->follow($staleDeliver), 'info', 'Order #' . $orderId . ' was already marked as delivered, so nothing was changed.');
        self::assertSame('Delivered', self::badge($customer->get($orderUrl)->document()));
        self::assertSame(['#' . $orderId], $admin->get('admin_orders.php?fulfilment=delivered')->document()->texts('//tbody/tr/th'));
        self::assertSame([], $admin->get('admin_orders.php?fulfilment=processing')->document()->texts('//tbody/tr/th'));
        $this->assertPageText('This order has been delivered.', $admin->get('admin_order.php?id=' . $orderId));

        // A refund after delivery: the order shows as refunded, and the books, which have left the store, are not restocked.
        $stock = [$this->stockOf(self::ESABELLA), $this->stockOf(self::BIOLOGY)];
        self::assertSame(
            'Refunds the full SAR 45.48 to the customer through Stripe and removes the books from their purchase history. '
            . "The books have already been shipped, so the stock does not change. If they are returned, add the copies on the book's edit page.",
            $admin->get('admin_order.php?id=' . $orderId)->document()->text('//p[@id="refund-help"]')
        );
        $this->assertFlash($admin->follow($this->adminAction($admin, $orderId, 'refund')), 'success', 'Order #' . $orderId . ' was refunded.');
        self::assertSame($stock, [$this->stockOf(self::ESABELLA), $this->stockOf(self::BIOLOGY)]);
        $lateDeliver = $admin->postWithToken('admin_order.php?id=' . $orderId, ['action' => 'deliver']);
        $this->assertFlash($admin->follow($lateDeliver), 'info', 'Order #' . $orderId . ' was refunded, so its delivery is no longer updated.');
        $document = $customer->get($orderUrl)->document();
        self::assertSame('Refunded', self::badge($document));
        self::assertSame(['Paid', 'Processing', 'Shipped', 'Delivered', 'Refunded'], self::timeline($document));
        $this->assertPageText('This order was refunded, so its delivery is no longer updated.', $admin->get('admin_order.php?id=' . $orderId));

        // One email per event, never two.
        self::assertSame([
            'Your BookWise order #' . $orderId . ' is confirmed',
            'Your BookWise order #' . $orderId . ' is on its way',
            'Your BookWise order #' . $orderId . ' was delivered',
            'Your BookWise order #' . $orderId . ' was refunded',
        ], $this->subjectsTo($email));
        $shippedMail = $this->mailsTo($email)[1]['body'];
        self::assertStringContainsString("Good news: your order #$orderId has been shipped.\n\nCarrier: SMSA Express\nTracking number: SA123456", $shippedMail);
    }

    public function testTheConfirmationIsSentOnceWhenTheWebhookAndTheReturnPageBothConfirm(): void
    {
        foreach (['webhook first', 'return page first'] as $order) {
            [$customer, $user] = $this->signedInCustomer();
            $this->assertRedirect($this->addToCart($customer, self::BRIDGE, 1), 'cart.php');
            $sessionId = $this->startCheckout($customer);
            $session = $this->stripe->pay($sessionId);
            $payload = (string) json_encode([
                'id' => 'evt_http_' . bin2hex(random_bytes(6)),
                'object' => 'event',
                'api_version' => '2024-06-20',
                'created' => time(),
                'livemode' => false,
                'type' => 'checkout.session.completed',
                'data' => ['object' => $session],
            ], JSON_UNESCAPED_SLASHES);
            $webhook = fn (): HttpResponse => $this->client()->post('stripe_webhook.php', $payload, ['Content-Type' => 'application/json', 'Stripe-Signature' => self::stripeSignature($payload)]);
            $returnPage = fn (): HttpResponse => $customer->get('success_pay.php?session_id=' . $sessionId);

            if ($order === 'webhook first') {
                $this->assertStatus(200, $webhook());
                self::assertSame('Thank you! Your payment was received.', $returnPage()->document()->text('//h1'));
            } else {
                self::assertSame('Thank you! Your payment was received.', $returnPage()->document()->text('//h1'));
                $this->assertStatus(200, $webhook());
            }
            $this->assertStatus(200, $webhook());
            $returnPage();

            $orderId = (int) $this->orderBySession($sessionId)['id'];
            self::assertSame(['Your BookWise order #' . $orderId . ' is confirmed'], $this->subjectsTo((string) $user['Eaddress']), $order);
            self::assertSame('processing', $this->orderBySession($sessionId)['fulfilment_status']);
        }
    }

    public function testOrderPagesBelongToTheirCustomer(): void
    {
        [$alice] = $this->signedInCustomer('Alice');
        $orderId = (int) $this->purchase($alice, [self::ESABELLA => 1])['order']['id'];
        [$bob, $bobUser] = $this->signedInCustomer('Bob');

        $this->assertHtmlPage($bob->get('order.php?id=' . $orderId), 404);
        $this->assertHtmlPage($bob->postWithToken('order.php?id=' . $orderId, ['action' => 'buy_again']), 404);
        self::assertSame(0, $this->countRows('cart'), "Bob cannot buy Alice's order again");
        foreach (['order.php', 'order.php?id=abc', 'order.php?id=0', 'order.php?id=999999'] as $missing) {
            $this->assertHtmlPage($alice->get($missing), 404);
        }
        $this->assertRedirect($this->client()->get('order.php?id=' . $orderId), 'index.php?form=login');
        [$admin] = $this->signedInAdmin();
        $this->assertRedirect($admin->get('order.php?id=' . $orderId), 'admin_dashboard.php');

        // A customer's unpaid order has a summary, not a receipt.
        $this->assertRedirect($this->addToCart($bob, self::BIOLOGY, 1), 'cart.php');
        $pending = (int) $this->orderBySession($this->startCheckout($bob))['id'];
        $page = $bob->get('order.php?id=' . $pending);
        $document = $this->assertHtmlPage($page);
        self::assertSame('Pending', self::badge($document));
        self::assertSame('Order summary', $document->text('//h2[@id="receipt-title"]'));
        $this->assertPageText('This order is waiting for its payment.', $page);
        $this->assertNotPageText('This receipt is not a tax invoice.', $page);
        self::assertNull($document->first('//button[@data-print]'));
        self::assertSame(0, $document->count('//ol[' . HtmlDocument::hasClass('order-timeline') . ']'));

        // Its books are still in the cart, so they are not offered (or added) again.
        self::assertSame(0, $document->count('//form[.//input[@value="buy_again"]]'));
        $refused = $bob->postWithToken('order.php?id=' . $pending, ['action' => 'buy_again']);
        $this->assertRedirect($refused, 'order.php?id=' . $pending);
        $this->assertFlash($bob->follow($refused), 'error', 'Order #' . $pending . ' was not paid, so it cannot be bought again. Its books stay in your cart until you remove them.');
        self::assertSame([self::BIOLOGY => 1], $this->cartQuantities((int) $bobUser['id']));
        $unpaidDeliver = $admin->postWithToken('admin_order.php?id=' . $pending, ['action' => 'deliver']);
        $this->assertFlash($admin->follow($unpaidDeliver), 'info', 'Only paid orders that have been shipped can be marked as delivered. Order #' . $pending . ' was not changed.');

        // The profile's order list leads to the order pages.
        $orders = $bob->get('profilePage.php?section=orders')->document();
        self::assertSame(['order.php?id=' . $pending, 'order.php?id=' . $pending], array_map(
            static fn (\DOMElement $link): string => $link->getAttribute('href'),
            $orders->elements('//a[starts-with(@href, "order.php")]')
        ));
    }

    public function testBuyAgainPutsTheAvailableBooksBackInTheCart(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];
        $orderId = (int) $this->purchase($customer, [self::ESABELLA => 2, self::BRIDGE => 1])['order']['id'];
        self::assertSame([], $this->cartQuantities($userId));
        $this->setStock(self::BRIDGE, 0);

        $page = $customer->get('order.php?id=' . $orderId);
        self::assertSame('Adds the books of this order that are still available to your cart.', $page->document()->text('//p[@id="buy-again-hint"]'));
        $response = $customer->submitForm($page, 'order.php?id=' . $orderId, [], ['action' => 'buy_again']);
        $this->assertRedirect($response, 'cart.php');
        $cart = $customer->follow($response);
        $this->assertFlash($cart, 'success', 'Added 1 book from order #' . $orderId . ' to your cart.');
        $this->assertFlash($cart, 'error', 'This book is no longer available: “The Danger Bridge”.');
        self::assertSame([self::ESABELLA => 2], $this->cartQuantities($userId));

        // Only a POST with the token, and only this action.
        $this->assertHtmlPage($customer->get('order.php?id=' . $orderId . '&action=buy_again'));
        $this->assertHtmlPage($customer->post('order.php?id=' . $orderId, ['action' => 'buy_again']), 419);
        $this->assertHtmlPage($customer->postWithToken('order.php?id=' . $orderId, ['action' => 'refund']), 400);
        self::assertSame([self::ESABELLA => 2], $this->cartQuantities($userId));
    }

    public function testOrderEmailsReachTheDemoInboxInTheCustomersLanguage(): void
    {
        $this->configureServer(['APP_DEMO' => 'true', 'MAIL_DRIVER' => 'database']);
        [$customer, $user] = $this->signedInCustomer('Arabic Buyer');
        $this->markVerified((int) $user['id']);
        db_run("UPDATE users SET locale = 'ar' WHERE id = ?", [$user['id']]);
        $orderId = (int) $this->purchase($customer, [self::ESABELLA => 1])['order']['id'];
        [$admin] = $this->signedInAdmin();
        $this->adminAction($admin, $orderId, 'ship', ['carrier' => 'SMSA Express', 'tracking_number' => 'SA123456']);

        $inbox = $customer->get('inbox.php');
        $document = $this->assertHtmlPage($inbox);
        self::assertSame(
            ['طلبك رقم ' . $orderId . ' من BookWise في الطريق إليك', 'تم تأكيد طلبك رقم ' . $orderId . ' من BookWise'],
            $document->texts('//ul[' . HtmlDocument::hasClass('inbox-list') . ']//*[' . HtmlDocument::hasClass('inbox-item-subject') . ']')
        );
        $confirmation = $document->elements('//ul[' . HtmlDocument::hasClass('inbox-list') . ']/li/a')[1]->getAttribute('href');
        $message = $customer->get($confirmation);
        $messageDocument = $this->assertHtmlPage($message);
        $body = $messageDocument->first('//div[' . HtmlDocument::hasClass('inbox-message-body') . ']');
        self::assertSame(['ar', 'rtl'], [$body?->getAttribute('lang'), $body?->getAttribute('dir')]);
        $this->assertPageText('مرحبًا Arabic Buyer،', $message);
        $this->assertPageText('عنوان التوصيل Sara Ahmed 1234 King Fahd Road', $message);
        self::assertSame(
            [self::server()->baseUrl . '/order.php?id=' . $orderId],
            array_map(static fn (\DOMElement $link): string => $link->getAttribute('href'), $messageDocument->elements('//div[' . HtmlDocument::hasClass('inbox-message-body') . ']//a'))
        );

        // Nobody else reads them.
        [$other] = $this->signedInCustomer();
        $this->assertNotPageText('BookWise في الطريق إليك', $other->get('inbox.php'));
        $this->assertHtmlPage($other->get($confirmation), 404);
    }
}
