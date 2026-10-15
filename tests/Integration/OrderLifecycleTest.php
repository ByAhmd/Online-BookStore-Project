<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\AccountHelpers;
use BookWise\Tests\Support\CheckoutTestCase;
use BookWise\Tests\Support\StockFixtures;
use CheckoutException;

/**
 * An order from checkout to delivery (src/orders.php, src/fulfilment.php):
 * the delivery address copied into the order, the fulfilment steps, refunds
 * at any step, the order emails (each sent exactly once, in the customer's
 * language), "Buy again", and what account deletion leaves behind.
 */
final class OrderLifecycleTest extends CheckoutTestCase
{
    use AccountHelpers;
    use StockFixtures;

    private const BIOLOGY = 7;      // 5.50
    private const BRIDGE = 8;       // 10.00
    private const ESABELLA = 51;    // 19.99

    /**
     * A customer whose cart holds the books, checked out and paid.
     *
     * @param array<int, int> $quantities
     * @return array{user: array<string, mixed>, order: array<string, mixed>, session: string}
     */
    private function paidOrder(array $quantities = [self::ESABELLA => 2]): array
    {
        $user = $this->customerWithCart($quantities);
        [$sessionId, $order] = $this->checkout($user);
        $this->stripe->pay($sessionId);
        self::assertSame('paid', confirm_checkout_return((int) $user['id'], $sessionId)['state']);
        return ['user' => $user, 'order' => $this->reloadOrder((int) $order['id']), 'session' => $sessionId];
    }

    /**
     * Subjects of the emails sent while $action runs (MAIL_DRIVER=log).
     *
     * @param callable(): mixed $action
     * @return list<string>
     */
    private function subjectsSentBy(callable $action): array
    {
        return array_column($this->captureMail($action), 'subject');
    }

    /* -----------------------------------------------------------------
     * Checkout and the delivery address
     * --------------------------------------------------------------- */

    public function testCheckoutCopiesTheChosenAddressIntoTheOrder(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        $userId = (int) $user['id'];
        $this->createAddress($userId);
        $chosen = $this->createAddress($userId, ['recipient_name' => 'Noura Ali', 'city' => 'Jeddah', 'short_address' => '']);

        $sessionId = self::sessionIdFromUrl(start_checkout($user, $chosen));
        $order = $this->orderForSession($sessionId);
        $copy = order_address((int) $order['id']);
        self::assertNotNull($copy);
        self::assertSame(['Noura Ali', 'Jeddah', '+966512345678', null], [$copy['recipient_name'], $copy['city'], $copy['phone'], $copy['short_address']]);
        self::assertNull($order['fulfilment_status'], 'Nothing is delivered before the payment');

        // Editing or deleting the saved address later never changes the order.
        [$edited] = validate_address_input(['city' => 'Dammam'] + self::SAMPLE_ADDRESS);
        self::assertSame('updated', save_customer_address($userId, $edited, $chosen)['status']);
        self::assertTrue(delete_customer_address($userId, $chosen));
        self::assertSame($copy, order_address((int) $order['id']));
    }

    public function testCheckoutNeedsOneOfTheCustomersOwnAddresses(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        $someoneElse = $this->createAddress((int) $this->createUser()['id']);

        foreach ([null, $someoneElse, 999999] as $addressId) {
            try {
                start_checkout($user, $addressId);
                self::fail('Expected CheckoutException');
            } catch (CheckoutException $e) {
                self::assertSame('Please choose where we should deliver your books.', $e->getMessage());
                self::assertSame(CHECKOUT_ERROR_ADDRESS, $e->getCode());
            }
        }
        self::assertSame(0, $this->countRows('orders'));
        self::assertSame(0, $this->countRows('order_addresses'));
        self::assertSame([], $this->stripe->http->requests('POST', self::CREATE_SESSION), 'No payment is started');
    }

    public function testTheCartIsCheckedBeforeTheAddress(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 3]);
        $this->setStock(self::ESABELLA, 1);

        try {
            start_checkout($user, null);
            self::fail('Expected CheckoutException');
        } catch (CheckoutException $e) {
            self::assertStringStartsWith('There are not enough copies of “Esabella” in stock.', $e->getMessage());
            self::assertSame(0, $e->getCode(), 'Sends the customer to the cart, not the address');
        }
    }

    /* -----------------------------------------------------------------
     * Fulfilment
     * --------------------------------------------------------------- */

    public function testAPaidOrderMovesThroughProcessingShippedAndDelivered(): void
    {
        $paid = $this->paidOrder();
        $orderId = (int) $paid['order']['id'];
        self::assertSame('processing', $paid['order']['fulfilment_status']);
        self::assertSame('processing', order_display_status($paid['order']));

        self::assertFalse(deliver_order($orderId), 'Not shipped yet');
        self::assertTrue(ship_order($orderId, 'SMSA Express', 'SA 123-456'));
        $order = $this->reloadOrder($orderId);
        self::assertSame(['shipped', 'SMSA Express', 'SA 123-456'], [$order['fulfilment_status'], $order['carrier'], $order['tracking_number']]);
        self::assertNotNull($order['shipped_at']);
        self::assertFalse(ship_order($orderId, 'Aramex', 'X'), 'Shipped once');
        self::assertSame('SMSA Express', $this->reloadOrder($orderId)['carrier']);

        self::assertTrue(deliver_order($orderId));
        $order = $this->reloadOrder($orderId);
        self::assertSame('delivered', $order['fulfilment_status']);
        self::assertNotNull($order['delivered_at']);
        self::assertFalse(deliver_order($orderId), 'Delivered once');
        self::assertFalse(ship_order($orderId), 'Never back to shipped');
        self::assertSame('paid', $order['status'], 'Fulfilment never changes the payment status');
    }

    public function testShipmentsWithoutDetailsAndUnpaidOrders(): void
    {
        $paid = $this->paidOrder();
        self::assertTrue(ship_order((int) $paid['order']['id']));
        $order = $this->reloadOrder((int) $paid['order']['id']);
        self::assertSame([null, null], [$order['carrier'], $order['tracking_number']], 'Carrier and tracking number are optional');

        $user = $this->customerWithCart([self::BIOLOGY => 1]);
        $pending = $this->checkout($user)[1];
        self::assertFalse(ship_order((int) $pending['id']));
        self::assertFalse(deliver_order((int) $pending['id']));
        self::assertNull($this->reloadOrder((int) $pending['id'])['fulfilment_status']);
    }

    public function testShipmentDetailsAreValidated(): void
    {
        self::assertSame([['carrier' => 'Aramex', 'tracking_number' => 'AR 1234-56'], []], validate_shipment_input(['carrier' => ' Aramex ', 'tracking_number' => 'AR  1234-56']));
        self::assertSame([['carrier' => '', 'tracking_number' => '123'], []], validate_shipment_input(['tracking_number' => '١٢٣']));

        [, $errors] = validate_shipment_input(['carrier' => str_repeat('c', 61), 'tracking_number' => 'ABC<script>']);
        self::assertSame([
            'carrier' => 'The carrier name must be 60 characters or fewer.',
            'tracking_number' => 'The tracking number may only contain letters, digits, spaces and hyphens, at most 40 characters.',
        ], $errors);
        [, $errors] = validate_shipment_input(['carrier' => "SMSA\nExpress", 'tracking_number' => str_repeat('1', 41)]);
        self::assertSame(['carrier', 'tracking_number'], array_keys($errors));
    }

    public function testRefundsWorkAtEveryStageAndShowAsRefunded(): void
    {
        foreach (['processing', 'shipped', 'delivered'] as $stage) {
            $this->setStock(self::BRIDGE, 10);
            $paid = $this->paidOrder([self::BRIDGE => 2]);
            $orderId = (int) $paid['order']['id'];
            self::assertSame(8, $this->stockOf(self::BRIDGE), 'The paid copies leave the stock');
            if ($stage !== 'processing') {
                self::assertTrue(ship_order($orderId, 'Aramex', 'AR1'));
            }
            if ($stage === 'delivered') {
                self::assertTrue(deliver_order($orderId));
            }
            self::assertSame(8, $this->stockOf(self::BRIDGE), 'Shipping and delivery do not change the stock');

            self::assertSame('refunded', refund_order($orderId), "Refund at $stage");
            $order = $this->reloadOrder($orderId);
            self::assertSame(['refunded', $stage], [$order['status'], $order['fulfilment_status']]);
            self::assertSame('refunded', order_display_status($order));
            self::assertSame('refunded', array_column(order_progress($order), 'step')[count(order_progress($order)) - 1]);
            self::assertSame([], $this->salesForOrder($orderId), 'No longer counted as sold');
            // Copies still in the store go back on the shelf; shipped copies have left it.
            self::assertSame($stage === 'processing' ? 10 : 8, $this->stockOf(self::BRIDGE), "Stock after a refund at $stage");
            self::assertFalse(ship_order($orderId));
            self::assertFalse(deliver_order($orderId), 'A refunded order is no longer delivered');
            self::assertSame($stage === 'processing' ? 10 : 8, $this->stockOf(self::BRIDGE), 'Refunded once');
        }

        // An order whose delivery is not tracked (placed before migration 006) is restocked as before.
        $this->setStock(self::BRIDGE, 10);
        $user = $this->customerWithCart([self::BRIDGE => 2]);
        [$sessionId, $order] = $this->checkout($user);
        db_run('DELETE FROM order_addresses WHERE order_id = ?', [$order['id']]);
        $this->stripe->pay($sessionId);
        self::assertSame('paid', confirm_checkout_return((int) $user['id'], $sessionId)['state']);
        self::assertSame(8, $this->stockOf(self::BRIDGE));
        self::assertSame('refunded', refund_order((int) $order['id']));
        self::assertSame(10, $this->stockOf(self::BRIDGE));
    }

    public function testOnlyCopiesStillInTheStoreAreRestocked(): void
    {
        self::assertTrue(order_refund_restocks(['fulfilment_status' => 'processing']));
        self::assertTrue(order_refund_restocks(['fulfilment_status' => null]), 'Untracked orders');
        self::assertFalse(order_refund_restocks(['fulfilment_status' => 'shipped']));
        self::assertFalse(order_refund_restocks(['fulfilment_status' => 'delivered']));

        // The charge.refunded webhook records the refund with the same rule.
        $this->setStock(self::BRIDGE, 10);
        $paid = $this->paidOrder([self::BRIDGE => 3]);
        $orderId = (int) $paid['order']['id'];
        self::assertTrue(ship_order($orderId));
        $order = $this->reloadOrder($orderId);
        self::assertTrue(apply_order_refund($orderId, 're_test_dashboard', (string) $order['stripe_payment_intent']));
        self::assertSame(['refunded', 7], [$this->reloadOrder($orderId)['status'], $this->stockOf(self::BRIDGE)]);
    }

    public function testOrdersPaidWithoutAnAddressAreNotTracked(): void
    {
        $paid = $this->paidOrder();
        // An order placed before deliveries were tracked: no copy of an address.
        $user = $this->customerWithCart([self::BIOLOGY => 1]);
        [$sessionId, $order] = $this->checkout($user);
        db_run('DELETE FROM order_addresses WHERE order_id = ?', [$order['id']]);
        $this->stripe->pay($sessionId);

        self::assertSame('paid', confirm_checkout_return((int) $user['id'], $sessionId)['state']);
        $order = $this->reloadOrder((int) $order['id']);
        self::assertNull($order['fulfilment_status']);
        self::assertSame('paid', order_display_status($order));
        self::assertFalse(ship_order((int) $order['id']));
        self::assertSame('processing', $paid['order']['fulfilment_status']);
    }

    public function testTheAdminListFiltersByDeliveryStage(): void
    {
        $processing = (int) $this->paidOrder()['order']['id'];
        $shipped = (int) $this->paidOrder()['order']['id'];
        ship_order($shipped);
        $delivered = (int) $this->paidOrder()['order']['id'];
        ship_order($delivered);
        deliver_order($delivered);
        $refunded = (int) $this->paidOrder()['order']['id'];
        refund_order($refunded);
        $pending = (int) $this->checkout($this->customerWithCart([self::BIOLOGY => 1]))[1]['id'];

        $ids = static fn (string $status, string $fulfilment): array => array_map('intval', array_column(admin_order_list($status, '', 1, $fulfilment)['orders'], 'id'));
        self::assertSame([$processing], $ids('', 'processing'), 'Refunded orders are not waiting to be shipped');
        self::assertSame([$shipped], $ids('', 'shipped'));
        self::assertSame([$delivered], $ids('', 'delivered'));
        self::assertSame([$pending, $refunded, $delivered, $shipped, $processing], $ids('', 'bogus'), 'Unknown stages mean all');
        self::assertSame([], $ids('refunded', 'processing'));
        self::assertSame([$delivered, $shipped, $processing], $ids('paid', ''));
        self::assertSame('delivered', array_column(admin_order_list('', '', 1, 'delivered')['orders'], 'fulfilment_status')[0]);
    }

    /* -----------------------------------------------------------------
     * Emails: each exactly once
     * --------------------------------------------------------------- */

    public function testTheConfirmationIsSentOnceHoweverOftenThePaymentIsConfirmed(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        [$sessionId, $order] = $this->checkout($user);
        $this->stripe->pay($sessionId);
        $session = \Stripe\Checkout\Session::constructFrom((array) $this->stripe->session($sessionId));

        $mails = $this->captureMail(function () use ($user, $sessionId, $order, $session): void {
            self::assertSame('paid', confirm_checkout_return((int) $user['id'], $sessionId)['state']);
            self::assertSame('paid', confirm_checkout_return((int) $user['id'], $sessionId)['state'], 'The return page reloaded');
            self::assertSame('already_paid', fulfill_order((int) $order['id'], $session), 'A webhook arriving later');
            settle_pending_orders((int) $user['id']);
        });

        self::assertCount(1, $mails);
        self::assertSame((string) $user['Eaddress'], $mails[0]['to']);
        self::assertSame('Your BookWise order #' . $order['id'] . ' is confirmed', $mails[0]['subject']);
    }

    public function testAPaymentFoundAtTheNextCheckoutIsConfirmedOnce(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        [$sessionId, $order] = $this->checkout($user);
        $this->stripe->pay($sessionId); // the customer never came back
        cart_add((int) $user['id'], self::BIOLOGY, 1);

        $subjects = $this->subjectsSentBy(function () use ($user): void {
            $this->checkout($user);
        });
        self::assertSame(['Your BookWise order #' . $order['id'] . ' is confirmed'], $subjects);
    }

    public function testShippingDeliveryAndRefundEmailsAreSentOnce(): void
    {
        $paid = $this->paidOrder();
        $orderId = (int) $paid['order']['id'];

        $subjects = $this->subjectsSentBy(static function () use ($orderId): void {
            ship_order($orderId, 'SMSA Express', 'SA123456');
            ship_order($orderId, 'SMSA Express', 'SA123456');
            deliver_order($orderId);
            deliver_order($orderId);
            refund_order($orderId);
            refund_order($orderId);
        });

        self::assertSame([
            'Your BookWise order #' . $orderId . ' is on its way',
            'Your BookWise order #' . $orderId . ' was delivered',
            'Your BookWise order #' . $orderId . ' was refunded',
        ], $subjects);

        // A charge.refunded webhook for the same refund changes nothing and sends nothing.
        $order = $this->reloadOrder($orderId);
        self::assertSame([], $this->subjectsSentBy(static function () use ($orderId, $order): void {
            self::assertFalse(apply_order_refund($orderId, 're_test_again', (string) $order['stripe_payment_intent']));
        }));
    }

    public function testEmailsAreWrittenInTheCustomersLanguageWithTheirDetailsOnceConfirmed(): void
    {
        $paid = $this->paidOrder([self::ESABELLA => 2, self::BIOLOGY => 1]);
        $user = $paid['user'];
        $orderId = (int) $paid['order']['id'];
        db_run("UPDATE users SET locale = 'ar', name = 'سارة' WHERE id = ?", [$user['id']]);

        // Unconfirmed address: no name and no delivery address (someone else may have typed them).
        $mails = $this->captureMail(static fn (): bool => send_order_email($orderId, 'confirmation'));
        self::assertCount(1, $mails);
        self::assertSame('تم تأكيد طلبك رقم ' . $orderId . ' من BookWise', $mails[0]['subject']);
        self::assertSame('', $mails[0]['name']);
        self::assertStringStartsWith("مرحبًا،\n\nشكرًا لطلبك رقم " . $orderId . '.', $mails[0]['body']);
        self::assertStringContainsString("كتبك\n2 × «Esabella»: 39.98 ر.س\n1 × «Biology»: 5.50 ر.س", $mails[0]['body']);
        self::assertStringContainsString('الإجمالي: 45.48 ر.س', $mails[0]['body']);
        self::assertStringContainsString('يشمل ضريبة القيمة المضافة (15%): 5.93 ر.س', $mails[0]['body']);
        self::assertStringNotContainsString('King Fahd Road', $mails[0]['body']);
        self::assertStringContainsString('/order.php?id=' . $orderId, $mails[0]['body']);

        $this->markVerified((int) $user['id']);
        db_run("UPDATE users SET locale = 'en' WHERE id = ?", [$user['id']]);
        $mails = $this->captureMail(static fn (): bool => send_order_email($orderId, 'confirmation'));
        self::assertSame('سارة', $mails[0]['name']);
        self::assertStringStartsWith("Hello سارة,\n\nThank you for your order #$orderId.", $mails[0]['body']);
        self::assertStringContainsString("Delivery address\nSara Ahmed\n1234 King Fahd Road\nAl Olaya\nRiyadh 12345-6789\nShort address: RRRD2929\n", $mails[0]['body']);

        self::assertFalse(send_order_email($orderId, 'bogus'));
        self::assertFalse(send_order_email(999999, 'confirmation'));
    }

    public function testOrderEmailsAppearInTheCustomersDemoInbox(): void
    {
        $this->setEnv('APP_DEMO', 'true');
        $this->setEnv('MAIL_DRIVER', 'database');
        $paid = $this->paidOrder();
        $orderId = (int) $paid['order']['id'];
        ship_order($orderId, 'Aramex', 'AR1');

        $email = normalize_email((string) $paid['user']['Eaddress']);
        self::assertSame(
            ['Your BookWise order #' . $orderId . ' is on its way', 'Your BookWise order #' . $orderId . ' is confirmed'],
            array_column(demo_inbox_messages([$email]), 'subject')
        );
        self::assertSame([], demo_inbox_messages([normalize_email((string) $this->createUser()['Eaddress'])]), 'Nobody else reads them');
    }

    /* -----------------------------------------------------------------
     * Account deletion, "Buy again"
     * --------------------------------------------------------------- */

    public function testAccountDeletionKeepsOnlyTheAddressesOfOrdersOnTheirWay(): void
    {
        $user = $this->customerWithCart([self::ESABELLA => 1]);
        $userId = (int) $user['id'];
        $orders = [];
        foreach (['delivered', 'shipped', 'processing', 'refunded'] as $stage) {
            cart_add($userId, self::ESABELLA, 1);
            [$sessionId, $order] = $this->checkout($user);
            $this->stripe->pay($sessionId);
            confirm_checkout_return($userId, $sessionId);
            $orderId = (int) $order['id'];
            if ($stage === 'shipped' || $stage === 'delivered') {
                ship_order($orderId);
            }
            if ($stage === 'delivered') {
                deliver_order($orderId);
            }
            if ($stage === 'refunded') {
                refund_order($orderId);
            }
            $orders[$stage] = $orderId;
        }
        $unpaid = (int) $this->checkout($this->customerWithCart([self::BIOLOGY => 1]))[1]['id'];

        self::assertSame('deleted', delete_customer_account($userId, self::DEFAULT_PASSWORD, '203.0.113.9')['status']);

        $kept = static fn (int $orderId): bool => order_address($orderId) !== null;
        self::assertSame(
            ['delivered' => false, 'shipped' => true, 'processing' => true, 'refunded' => false],
            array_map($kept, $orders),
            'Orders still on their way keep their address'
        );
        self::assertTrue($kept($unpaid), "Another customer's order is untouched");
        self::assertSame(0, $this->countRows('addresses', 'user_id = ?', [$userId]));

        // Once delivered (or refunded), the address of an anonymous order goes too.
        self::assertTrue(deliver_order($orders['shipped']));
        self::assertFalse($kept($orders['shipped']));
        self::assertSame('refunded', refund_order($orders['processing']));
        self::assertFalse($kept($orders['processing']));
    }

    public function testBuyAgainAddsWhatIsStillAvailable(): void
    {
        $tmp = $this->insertStockedBook(['title' => 'Short-lived Book'], 5);
        $paid = $this->paidOrder([self::ESABELLA => 2, self::BIOLOGY => 3, self::BRIDGE => 1, $tmp => 1]);
        $userId = (int) $paid['user']['id'];
        $orderId = (int) $paid['order']['id'];
        $this->setStock(self::BIOLOGY, 1);
        $this->setStock(self::BRIDGE, 0);
        self::assertTrue(delete_book($tmp));
        cart_add($userId, self::ESABELLA, 1);

        self::assertSame(['added' => 2, 'unavailable' => ['The Danger Bridge', 'Short-lived Book']], buy_again($userId, $orderId));
        self::assertSame([self::ESABELLA => 3, self::BIOLOGY => 1], $this->cartQuantities($userId), 'Ordered quantities, within the stock');

        self::assertNull(buy_again((int) $this->createUser()['id'], $orderId), "Another customer's order");
        self::assertNull(buy_again($userId, 999999));

        // A refunded order can be bought again too (its refund restocked The Danger Bridge).
        self::assertSame('refunded', refund_order($orderId));
        self::assertSame(['added' => 3, 'unavailable' => ['Short-lived Book']], buy_again($userId, $orderId));
    }

    public function testOnlyPaidOrdersCanBeBoughtAgain(): void
    {
        foreach (['paid' => true, 'refunded' => true, 'pending' => false, 'canceled' => false, 'expired' => false, 'failed' => false] as $status => $expected) {
            self::assertSame($expected, order_can_be_bought_again(['status' => $status]), $status);
        }

        // The books of an unpaid order are still in the cart: buying them again would double them.
        $user = $this->customerWithCart([self::BIOLOGY => 1]);
        $userId = (int) $user['id'];
        $pending = (int) $this->checkout($user)[1]['id'];
        self::assertNull(buy_again($userId, $pending));
        self::assertTrue(mark_order_status($pending, 'canceled'));
        self::assertNull(buy_again($userId, $pending));
        self::assertSame([self::BIOLOGY => 1], $this->cartQuantities($userId));
    }
}
