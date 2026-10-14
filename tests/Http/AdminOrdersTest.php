<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;
use BookWise\Tests\Support\StockFixtures;

/**
 * admin_orders.php (filters, search, pagination) and admin_order.php
 * (details and the refund action).
 */
final class AdminOrdersTest extends HttpTestCase
{
    use StockFixtures;

    private const ESABELLA = 51; // 19.99

    private int $minute = 0;

    /**
     * Inserts an order directly; each one is a minute newer than the last.
     */
    private function insertOrder(?int $userId, string $status, int $amountMinor = 1999): int
    {
        $created = (new \DateTimeImmutable('2026-01-01 10:00:00'))->modify('+' . $this->minute++ . ' minutes')->format('Y-m-d H:i:s');
        db_run(
            'INSERT INTO orders (user_id, status, currency, total_amount, amount_minor, created_at, paid_at, stripe_payment_intent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId, $status, 'sar', minor_to_decimal($amountMinor), $amountMinor, $created,
                $status === 'paid' ? $created : null,
                $status === 'paid' ? 'pi_test_inserted_' . $this->minute : null,
            ]
        );
        $id = (int) db()->lastInsertId();
        db_run('INSERT INTO order_items (order_id, book_id, title, unit_price, quantity) VALUES (?, ?, ?, ?, ?)', [$id, self::ESABELLA, 'Esabella', minor_to_decimal($amountMinor), 1]);
        return $id;
    }

    /**
     * @return list<int> Order numbers listed on the page, in order
     */
    private static function listedIds(HttpResponse $page): array
    {
        return array_map(
            static fn (string $text): int => (int) ltrim($text, '#'),
            $page->document()->texts('//table[' . HtmlDocument::hasClass('order-list-table') . ']//tbody/tr/th')
        );
    }

    private static function nextPageHref(HttpResponse $page): ?string
    {
        return $page->document()->first('//nav[' . HtmlDocument::hasClass('pager') . ']//a[' . HtmlDocument::hasClass('pager-next') . ']')?->getAttribute('href');
    }

    public function testListFiltersSearchesAndPaginates(): void
    {
        $alice = $this->createUser('alice.lister@example.com', 'Alice Lister');
        $bob = $this->createUser('bob.buyer@example.org', 'Bob Buyer');
        $aliceOrders = [];
        for ($i = 0; $i < 23; $i++) {
            $aliceOrders[] = $this->insertOrder((int) $alice['id'], 'paid');
        }
        $bobOrders = [$this->insertOrder((int) $bob['id'], 'pending'), $this->insertOrder((int) $bob['id'], 'pending', 5000)];
        $orphan = $this->insertOrder(null, 'refunded');
        $newestFirst = array_reverse([...$aliceOrders, ...$bobOrders, $orphan]);
        [$admin] = $this->signedInAdmin();

        // 26 orders, 20 per page, newest first.
        $first = $admin->get('admin_orders.php');
        $document = $this->assertHtmlPage($first);
        self::assertSame('Orders', $document->text('//h1'));
        self::assertSame(array_slice($newestFirst, 0, 20), self::listedIds($first));
        self::assertSame('Showing 1–20 of 26 orders.', $document->text('//p[' . HtmlDocument::hasClass('table-summary') . ']'));
        self::assertSame('admin_orders.php?page=2', self::nextPageHref($first));
        $second = $admin->get('admin_orders.php?page=2');
        self::assertSame(array_slice($newestFirst, 20), self::listedIds($second));
        self::assertNull(self::nextPageHref($second));
        self::assertSame(self::listedIds($second), self::listedIds($admin->get('admin_orders.php?page=99')), 'Past the end shows the last page');
        self::assertSame(self::listedIds($first), self::listedIds($admin->get('admin_orders.php?page=-1')));
        self::assertSame(self::listedIds($first), self::listedIds($admin->get('admin_orders.php?page=abc')));

        // A row: number (linked), date, customer, items, total and status label.
        $row = '//table[' . HtmlDocument::hasClass('order-list-table') . ']//tbody/tr[th[normalize-space()="#' . $bobOrders[1] . '"]]';
        $cells = $document->texts($row . '/*');
        self::assertSame('#' . $bobOrders[1], $cells[0]);
        self::assertSame('Jan 1, 2026, 10:24', $cells[1]);
        self::assertSame('Bob Buyer bob.buyer@example.org', $cells[2]);
        self::assertSame(['1', 'SAR 50.00', 'Pending'], array_slice($cells, 3));
        self::assertSame('admin_order.php?id=' . $bobOrders[1], $document->first($row . '/th/a')?->getAttribute('href'));
        $orphanRow = '//tbody/tr[th[normalize-space()="#' . $orphan . '"]]';
        self::assertSame('Deleted account', $document->text($orphanRow . '/td[2]'));
        self::assertSame('Refunded', $document->text($orphanRow . '//span[' . HtmlDocument::hasClass('status-badge') . ']'));

        // Status filter (the pager keeps it).
        $paid = $admin->get('admin_orders.php?status=paid');
        self::assertSame(array_slice(array_reverse($aliceOrders), 0, 20), self::listedIds($paid));
        self::assertSame('admin_orders.php?status=paid&page=2', self::nextPageHref($paid));
        self::assertSame('paid', $paid->document()->first('//select[@id="order-status"]/option[@selected]')?->getAttribute('value'));
        self::assertSame([$bobOrders[1], $bobOrders[0]], self::listedIds($admin->get('admin_orders.php?status=pending')));
        self::assertSame([$orphan], self::listedIds($admin->get('admin_orders.php?status=refunded')));
        self::assertSame(self::listedIds($first), self::listedIds($admin->get('admin_orders.php?status=bogus')), 'Unknown statuses mean all');

        // Search by order number (with or without #) or by part of the email address.
        self::assertSame([$aliceOrders[3]], self::listedIds($admin->get('admin_orders.php?q=' . rawurlencode('#' . $aliceOrders[3]))));
        self::assertSame([$aliceOrders[3]], self::listedIds($admin->get('admin_orders.php?q=' . $aliceOrders[3])));
        self::assertSame([$bobOrders[1], $bobOrders[0]], self::listedIds($admin->get('admin_orders.php?q=bob.buyer')));
        self::assertSame([$bobOrders[1], $bobOrders[0]], self::listedIds($admin->get('admin_orders.php?q=EXAMPLE.ORG')), 'Case-insensitive');
        self::assertSame(array_slice(array_reverse($aliceOrders), 0, 20), self::listedIds($admin->get('admin_orders.php?q=alice')));
        foreach (['%', '_', "' OR 1=1 -- ", 'nobody@example.net'] as $query) {
            $none = $admin->get('admin_orders.php?q=' . rawurlencode($query));
            $this->assertHtmlPage($none);
            self::assertSame([], self::listedIds($none), "Search for $query");
            $this->assertPageText('No orders match these filters.', $none);
        }
        $combined = $admin->get('admin_orders.php?status=paid&q=bob');
        self::assertSame([], self::listedIds($combined));
        self::assertSame('admin_orders.php', $combined->document()->first('//a[normalize-space()="Clear filters"]')?->getAttribute('href'));
    }

    public function testEmptyOrderList(): void
    {
        [$admin] = $this->signedInAdmin();
        $page = $admin->get('admin_orders.php');
        $this->assertHtmlPage($page);
        $this->assertPageText('No orders yet.', $page);
        self::assertSame(0, $page->document()->count('//nav[' . HtmlDocument::hasClass('pager') . ']'));
    }

    public function testOrderDetailPage(): void
    {
        [$customer, $user] = $this->signedInCustomer('Detail Reader');
        $purchase = $this->purchase($customer, [self::ESABELLA => 2, 7 => 1]);
        $order = $purchase['order'];
        $orderId = (int) $order['id'];
        [$admin] = $this->signedInAdmin();

        // The dashboard's recent orders link to the detail page.
        $dashboard = $admin->get('admin_dashboard.php');
        self::assertSame('admin_order.php?id=' . $orderId, $dashboard->document()->first('//table[' . HtmlDocument::hasClass('orders-table') . ']//th/a')?->getAttribute('href'));

        $page = $admin->get('admin_order.php?id=' . $orderId);
        $document = $this->assertHtmlPage($page);
        self::assertSame('Order #' . $orderId, $document->text('//h1'));
        $items = $document->elements('//table[' . HtmlDocument::hasClass('order-items-table') . ']/tbody/tr');
        self::assertSame(
            [['Esabella', 'SAR 19.99', '2', 'SAR 39.98'], ['Biology', 'SAR 5.50', '1', 'SAR 5.50']],
            array_map(static fn (\DOMElement $row): array => array_map(
                static fn (\DOMElement $cell): string => HtmlDocument::normalize($cell->textContent),
                $document->elements('./*', $row)
            ), $items)
        );
        self::assertSame('Order total SAR 45.48', $document->text('//table[' . HtmlDocument::hasClass('order-items-table') . ']/tfoot/tr'));
        $this->assertPageText('Detail Reader', $page);
        $this->assertPageText((string) $user['Eaddress'], $page);
        self::assertSame(
            [$purchase['session'], (string) $order['stripe_payment_intent']],
            $document->texts('//code[' . HtmlDocument::hasClass('stripe-ref') . ']')
        );
        self::assertSame('—', $document->text('//dl[' . HtmlDocument::hasClass('order-facts') . ']/dt[normalize-space()="Refunded"]/following-sibling::dd[1]'), 'Not refunded');
        self::assertSame(2, $document->count('//dl[' . HtmlDocument::hasClass('order-facts') . ']//time'), 'Created and paid timestamps');
        $form = $document->first('//form[@action="admin_order.php?id=' . $orderId . '"]');
        self::assertNotNull($form, 'Paid orders can be refunded');
        self::assertSame('Refund order #' . $orderId . ' in full (SAR 45.48)? This cannot be undone.', $form->getAttribute('data-confirm'));

        foreach (['admin_order.php?id=999999', 'admin_order.php?id=abc', 'admin_order.php', 'admin_order.php?id=0'] as $missing) {
            $this->assertHtmlPage($admin->get($missing), 404);
        }
    }

    public function testRefundFromTheOrderPage(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $stock = $this->stockOf(self::ESABELLA);
        $purchase = $this->purchase($customer, [self::ESABELLA => 2]);
        $orderId = (int) $purchase['order']['id'];
        self::assertSame($stock - 2, $this->stockOf(self::ESABELLA));
        [$admin] = $this->signedInAdmin();

        $page = $admin->get('admin_order.php?id=' . $orderId);
        $response = $admin->submitForm($page, 'admin_order.php?id=' . $orderId, [], ['action' => 'refund']);
        $this->assertRedirect($response, 'admin_order.php?id=' . $orderId);
        $after = $admin->follow($response);
        $document = $this->assertHtmlPage($after);
        $this->assertFlash($after, 'success', 'Order #' . $orderId . ' was refunded.');
        self::assertSame(['Refunded', 'Refunded'], $document->texts('//span[' . HtmlDocument::hasClass('status-badge') . ']'));
        $this->assertPageText('This order has been refunded.', $after);
        self::assertSame(0, $document->count('//form[@action="admin_order.php?id=' . $orderId . '"]'), 'No second refund button');
        self::assertSame(3, $document->count('//code[' . HtmlDocument::hasClass('stripe-ref') . ']'), 'The refund id is shown');

        $order = $this->orderBySession($purchase['session']);
        self::assertSame('refunded', $order['status']);
        self::assertSame($stock, $this->stockOf(self::ESABELLA), 'Copies are back in stock');
        self::assertSame([], $this->salesForOrder($orderId));
        self::assertCount(1, $this->stripe->refunds((string) $order['stripe_payment_intent']));

        // A second click (stale page) is a harmless no-op.
        $again = $admin->submitForm($page, 'admin_order.php?id=' . $orderId, [], ['action' => 'refund']);
        $this->assertFlash($admin->follow($again), 'info', 'Order #' . $orderId . ' was already refunded.');
        self::assertCount(1, $this->stripe->refunds());
        self::assertSame($stock, $this->stockOf(self::ESABELLA));

        // The customer sees the refund in their history and can no longer review the book.
        $history = $customer->get('profilePage.php?section=orders');
        self::assertSame(['refunded'], array_map('strtolower', $history->document()->texts('//span[' . HtmlDocument::hasClass('status-badge') . ']')));
        $this->assertPageText('Purchase this book to leave a review.', $this->bookPage($customer, self::ESABELLA));
        $success = $customer->get('success_pay.php?session_id=' . $purchase['session']);
        $this->assertPageText('This order has since been refunded.', $success);
        self::assertSame('refunded', db_value('SELECT status FROM orders WHERE id = ?', [$orderId]), 'Revisiting the success page changes nothing');
        self::assertSame($stock, $this->stockOf(self::ESABELLA));
    }

    public function testOnlyPaidOrdersCanBeRefunded(): void
    {
        $user = $this->createUser();
        $pending = $this->insertOrder((int) $user['id'], 'pending');
        [$admin] = $this->signedInAdmin();

        $page = $admin->get('admin_order.php?id=' . $pending);
        $this->assertHtmlPage($page);
        $this->assertPageText('Only paid orders can be refunded.', $page);
        self::assertSame(0, $page->document()->count('//form[@action="admin_order.php?id=' . $pending . '"]'));

        $forced = $admin->postWithToken('admin_order.php?id=' . $pending, ['action' => 'refund']);
        $this->assertRedirect($forced, 'admin_order.php?id=' . $pending);
        $this->assertFlash($admin->follow($forced), 'error', 'Only paid orders can be refunded.');
        self::assertSame('pending', db_value('SELECT status FROM orders WHERE id = ?', [$pending]));
        self::assertSame([], $this->stripe->refunds());

        $this->assertHtmlPage($admin->postWithToken('admin_order.php?id=' . $pending, ['action' => 'cancel_everything']), 400);
        $this->assertHtmlPage($admin->postWithToken('admin_order.php?id=999999', ['action' => 'refund']), 404);
    }

    public function testAStripeFailureIsReportedAndChangesNothing(): void
    {
        [$customer] = $this->signedInCustomer();
        $purchase = $this->purchase($customer, [self::ESABELLA => 1]);
        $orderId = (int) $purchase['order']['id'];
        // A payment Stripe does not know: the refund request fails.
        db_run('UPDATE orders SET stripe_payment_intent = ? WHERE id = ?', ['pi_test_unknown_to_stripe', $orderId]);
        $stock = $this->stockOf(self::ESABELLA);
        [$admin] = $this->signedInAdmin();
        $this->expectAppLogError('/Stripe refund failed/');

        $response = $admin->submitForm($admin->get('admin_order.php?id=' . $orderId), 'admin_order.php?id=' . $orderId, [], ['action' => 'refund']);
        $this->assertFlash($admin->follow($response), 'error', 'Stripe could not refund this order right now, so nothing was changed. Please try again later or refund it in the Stripe Dashboard.');

        self::assertSame('paid', db_value('SELECT status FROM orders WHERE id = ?', [$orderId]));
        self::assertSame($stock, $this->stockOf(self::ESABELLA));
        self::assertSame([self::ESABELLA => 1], $this->salesForOrder($orderId));
    }

    public function testOnlyAdministratorsReachTheOrderPages(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $purchase = $this->purchase($customer, [self::ESABELLA => 1]);
        $orderId = (int) $purchase['order']['id'];
        $guest = $this->client();

        foreach (['admin_orders.php', 'admin_orders.php?status=paid', 'admin_order.php?id=' . $orderId] as $url) {
            $this->assertRedirect($guest->get($url), 'index.php?form=login', 303, "Guest GET $url");
            $this->assertHtmlPage($customer->get($url), 403);
        }
        $this->assertRedirect($guest->postWithToken('admin_order.php?id=' . $orderId, ['action' => 'refund']), 'index.php?form=login');
        $this->assertHtmlPage($customer->postWithToken('admin_order.php?id=' . $orderId, ['action' => 'refund']), 403);
        self::assertSame('paid', db_value('SELECT status FROM orders WHERE id = ?', [$orderId]));
        self::assertSame([], $this->stripe->refunds());

        [$admin] = $this->signedInAdmin();
        $this->assertRedirect($admin->get('profilePage.php'), 'admin_dashboard.php', 303, 'Administrators stay out of customer pages');
        self::assertSame((int) $user['id'], (int) $purchase['order']['user_id']);
    }

    public function testRefundNeedsTheCsrfTokenAndAPost(): void
    {
        [$customer] = $this->signedInCustomer();
        $purchase = $this->purchase($customer, [self::ESABELLA => 1]);
        $orderId = (int) $purchase['order']['id'];
        [$admin] = $this->signedInAdmin();
        $admin->get('admin_order.php?id=' . $orderId);

        foreach ([[], ['_token' => str_repeat('a', 64)], ['_token' => $this->client()->csrfToken()]] as $token) {
            $response = $admin->post('admin_order.php?id=' . $orderId, $token + ['action' => 'refund']);
            $this->assertHtmlPage($response, 419);
        }
        self::assertSame('paid', db_value('SELECT status FROM orders WHERE id = ?', [$orderId]));
        self::assertSame([], $this->stripe->refunds());

        // A link (GET) never refunds.
        $this->assertHtmlPage($admin->get('admin_order.php?id=' . $orderId . '&action=refund'));
        self::assertSame('paid', db_value('SELECT status FROM orders WHERE id = ?', [$orderId]));
        self::assertSame([], $this->stripe->refunds());
    }

    /**
     * Refunding reaches Stripe from the admin page only; other verbs are refused.
     */
    public function testOtherMethodsAreRefused(): void
    {
        [$admin] = $this->signedInAdmin();
        $post = $admin->postWithToken('admin_orders.php', ['status' => 'paid']);
        $this->assertHtmlPage($post, 405);
        self::assertSame('GET, HEAD', $post->header('Allow'));
        $this->assertHtmlPage($admin->request('PUT', 'admin_order.php?id=1'), 405);
        $this->assertHtmlPage($admin->request('DELETE', 'admin_order.php?id=1'), 405);
    }
}
