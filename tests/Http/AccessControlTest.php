<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HttpTestCase;

/**
 * Who may see what: guests, customers, administrators, and one customer
 * against another customer's cart, payments and orders (IDOR).
 */
final class AccessControlTest extends HttpTestCase
{
    public function testGuestsAreSentToLoginAndBroughtBackAfterwards(): void
    {
        $user = $this->createUser(self::uniqueEmail('intended'));

        foreach (['Selected.php?id=13', 'cart.php', 'profilePage.php?section=orders', 'Explore_Page.php?genre=Crime&q=murder'] as $intended) {
            $guest = $this->client();
            $this->assertRedirect($guest->get($intended), 'index.php?form=login', 303, "Guest GET $intended");
            $login = $this->logIn($guest, (string) $user['Eaddress'], self::DEFAULT_PASSWORD);
            $this->assertRedirect($login, $intended, 303, 'After login the customer returns to the page they asked for');
            $this->assertStatus(200, $guest->follow($login));
        }

        // A guest POST does not set a return target.
        $guest = $this->client();
        $this->assertRedirect($guest->postWithToken('update_cart.php', ['cart_id' => '1', 'quantity' => '2']), 'index.php?form=login');
        $this->assertRedirect($this->logIn($guest, (string) $user['Eaddress'], self::DEFAULT_PASSWORD), 'Home_Page.php');
    }

    public function testTheReturnTargetNeverLeavesTheSite(): void
    {
        $user = $this->createUser(self::uniqueEmail('redirect'));
        $cases = [
            'cart.php?next=https://evil.example/' => 'Home_Page.php',
            'cart.php?next=//evil.example' => 'Home_Page.php',
            'success_pay.php?session_id=cs_test_return' => 'success_pay.php?session_id=cs_test_return',
            'cart.php?x=%0D%0ASet-Cookie%3A%20evil%3D1' => 'cart.php?x=%0D%0ASet-Cookie%3A%20evil%3D1',
        ];
        foreach ($cases as $requested => $expected) {
            $guest = $this->client();
            $this->assertRedirect($guest->get($requested), 'index.php?form=login');
            $login = $this->logIn($guest, (string) $user['Eaddress'], self::DEFAULT_PASSWORD);
            $this->assertRedirect($login, $expected, 303, "Return target for $requested");
            self::assertNull($guest->cookie('evil'), 'No header injection through the return target');
        }
    }

    public function testRemovedAccountsLoseTheirSession(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        db_run('DELETE FROM users WHERE id = ?', [$user['id']]);
        $this->assertRedirect($customer->get('cart.php'), 'index.php?form=login');
        $this->assertHtmlPage($customer->get('Home_Page.php'));

        [$admin, $adminRow] = $this->signedInAdmin();
        db_run('DELETE FROM admins WHERE Eaddress = ?', [$adminRow['Eaddress']]);
        $this->assertRedirect($admin->get('admin_dashboard.php'), 'index.php?form=login');
        $this->assertRedirect($admin->postWithToken('admin_dashboard.php', ['action' => 'delete_book', 'id' => '7']), 'index.php?form=login');
        self::assertNotNull(find_book(7));
        $this->assertPageText('Create a free account', $admin->get('Home_Page.php'));
    }

    public function testCustomersCannotOpenAdminPages(): void
    {
        [$customer] = $this->signedInCustomer();

        $this->assertHtmlPage($customer->get('admin_dashboard.php'), 403);
        $this->assertHtmlPage($customer->get('edit_book.php?id=7'), 403);
        $forbidden = $customer->postWithToken('admin_dashboard.php', ['action' => 'delete_book', 'id' => '7']);
        $this->assertHtmlPage($forbidden, 403);
        $this->assertPageText('You do not have permission to access the admin area.', $forbidden);
        $this->assertHtmlPage($customer->postWithToken('edit_book.php?id=7', ['action' => 'update_book', 'title' => 'Hijacked']), 403);
        self::assertSame('Biology', db_value('SELECT title FROM books WHERE id = 7'));

        // The order pages and the refund action are staff-only too.
        db_run(
            "INSERT INTO orders (user_id, status, currency, total_amount, amount_minor, stripe_payment_intent, paid_at)
             SELECT id, 'paid', 'sar', '19.99', 1999, 'pi_test_access', NOW() FROM users LIMIT 1"
        );
        $orderId = (int) db()->lastInsertId();
        $this->assertHtmlPage($customer->get('admin_orders.php'), 403);
        $this->assertHtmlPage($customer->get('admin_order.php?id=' . $orderId), 403);
        $this->assertHtmlPage($customer->postWithToken('admin_order.php?id=' . $orderId, ['action' => 'refund']), 403);
        self::assertSame('paid', db_value('SELECT status FROM orders WHERE id = ?', [$orderId]));

        // Guests are sent to the login form instead.
        $guest = $this->client();
        $this->assertRedirect($guest->get('admin_dashboard.php'), 'index.php?form=login');
        $this->assertRedirect($guest->postWithToken('admin_dashboard.php', ['action' => 'delete_book', 'id' => '7']), 'index.php?form=login');
        self::assertNotNull(find_book(7));
        $this->assertRedirect($guest->get('admin_orders.php'), 'index.php?form=login');
        $this->assertRedirect($guest->postWithToken('admin_order.php?id=' . $orderId, ['action' => 'refund']), 'index.php?form=login');
        self::assertSame('paid', db_value('SELECT status FROM orders WHERE id = ?', [$orderId]));
        self::assertSame([], $this->stripe->refunds());
    }

    public function testAdministratorsAreKeptOutOfCustomerPages(): void
    {
        [$admin] = $this->signedInAdmin();

        foreach (['Explore_Page.php', 'Selected.php?id=7', 'cart.php', 'profilePage.php', 'success_pay.php?session_id=cs_test_x', 'cancel_pay.php'] as $page) {
            $this->assertRedirect($admin->get($page), 'admin_dashboard.php', 303, "Admin GET $page");
        }
        $this->assertRedirect($admin->postWithToken('Selected.php?id=7', ['action' => 'add_to_cart', 'quantity' => '1']), 'admin_dashboard.php');
        $this->assertRedirect($admin->postWithToken('checkout.php'), 'admin_dashboard.php');
        self::assertSame(0, $this->countRows('cart'));
        self::assertSame(0, $this->countRows('orders'));

        $favorites = $admin->get('fetch_books.php?genre=Favorites');
        $this->assertStatus(401, $favorites);
    }

    public function testCustomersCannotChangeOrRemoveAnotherCustomersCartLines(): void
    {
        [$alice, $aliceUser] = $this->signedInCustomer('Alice');
        [$bob, $bobUser] = $this->signedInCustomer('Bob');
        $this->assertRedirect($this->addToCart($alice, 7, 2), 'cart.php');
        $this->assertRedirect($this->addToCart($bob, 8, 1), 'cart.php');
        $aliceLine = $this->cartLineId((int) $aliceUser['id'], 7);

        $update = $bob->postWithToken('update_cart.php', ['cart_id' => (string) $aliceLine, 'quantity' => '9']);
        $this->assertRedirect($update, 'cart.php');
        $this->assertFlash($bob->follow($update), 'error', 'That item is no longer in your cart.');

        $delete = $bob->postWithToken('delete_from_cart.php', ['cart_id' => (string) $aliceLine]);
        $this->assertRedirect($delete, 'cart.php');
        $bobCart = $bob->follow($delete);
        $this->assertFlash($bobCart, 'error', 'That item is no longer in your cart.');
        $this->assertNotPageText('Biology', $bobCart);

        self::assertSame([7 => 2], $this->cartQuantities((int) $aliceUser['id']));
        self::assertSame([8 => 1], $this->cartQuantities((int) $bobUser['id']));
    }

    public function testCustomersCannotSeeAnotherCustomersPaymentsOrOrders(): void
    {
        [$alice, $aliceUser] = $this->signedInCustomer('Alice Payer');
        [$bob] = $this->signedInCustomer('Bob Snooper');

        $paid = $this->purchase($alice, [51 => 1]);
        $paidOrderId = (int) $paid['order']['id'];
        $this->assertRedirect($this->addToCart($alice, 7, 1), 'cart.php');
        $openSession = $this->startCheckout($alice);
        $openOrder = $this->orderBySession($openSession);
        $this->stripe->pay($openSession); // paid on Stripe, not yet confirmed by BookWise

        // Bob replays Alice's Stripe return URLs.
        foreach ([$paid['session'], $openSession] as $sessionId) {
            $page = $bob->get('success_pay.php?session_id=' . $sessionId);
            $this->assertHtmlPage($page, 404);
            self::assertSame('Payment not found', $page->document()->text('//h1'));
            $this->assertNotPageText('#' . $paidOrderId, $page);
            $this->assertNotPageText('Esabella', $page);
        }
        self::assertSame('pending', $this->orderBySession($openSession)['status'], "Bob's visit must not touch Alice's order");

        // Bob's order history and cancel page do not reveal Alice's orders.
        $orders = $bob->get('profilePage.php?section=orders');
        $this->assertHtmlPage($orders);
        $this->assertPageText("You haven't placed any orders yet.", $orders);
        $this->assertNotPageText('Order #' . $paidOrderId, $orders);
        foreach ([$paidOrderId, (int) $openOrder['id']] as $orderId) {
            $cancel = $bob->get('cancel_pay.php?order=' . $orderId);
            $this->assertHtmlPage($cancel);
            $this->assertNotPageText('#' . $orderId, $cancel);
            self::assertSame('Checkout canceled', $cancel->document()->text('//h1'));
        }
        self::assertStringNotContainsString((string) $aliceUser['Eaddress'], $orders->body . $cancel->body);

        // Alice herself sees both.
        $aliceReturn = $alice->get('success_pay.php?session_id=' . $openSession);
        $this->assertStatus(200, $aliceReturn);
        self::assertSame('paid', $this->orderBySession($openSession)['status']);
        $aliceOrders = $alice->get('profilePage.php?section=orders');
        $this->assertPageText('Order #' . $paidOrderId, $aliceOrders);
        $this->assertPageText('Order #' . $openOrder['id'], $aliceOrders);
        $alreadyPaid = $alice->get('cancel_pay.php?order=' . $paidOrderId);
        self::assertSame('Order #' . $paidOrderId . ' is already paid', $alreadyPaid->document()->text('//h1'));
    }
}
