<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpTestCase;

/**
 * Every HTML page reachable by a guest, a customer and an administrator:
 * expected status (or redirect), exactly one <h1>, no inline scripts, on*
 * attributes or javascript: URLs, no PHP warnings, and all local assets load.
 */
final class PageContractTest extends HttpTestCase
{
    /** Pages that require a signed-in customer. */
    private const CUSTOMER_PAGES = [
        'Explore_Page.php',
        'Explore_Page.php?genre=Mystery',
        'Explore_Page.php?genre=Favorites',
        'Selected.php?id=7',
        'cart.php',
        'profilePage.php',
        'profilePage.php?section=orders',
        'profilePage.php?section=addresses',
        'success_pay.php?session_id=cs_test_unknown',
        'cancel_pay.php',
    ];

    private const ADMIN_PAGES = ['admin_dashboard.php', 'edit_book.php?id=7', 'admin_orders.php', 'admin_order.php?id=1'];

    public function testGuestPages(): void
    {
        $guest = $this->client();

        foreach (['index.php', 'index.php?form=signup', 'index.php?form=login', 'index.php?form=reset', 'index.php?form=bogus', 'Home_Page.php', 'About_us.php'] as $path) {
            $this->assertHtmlPage($guest->get($path));
        }
        // Reset form without a valid token: the "link expired" page.
        $this->assertHtmlPage($guest->get('reset_form.php'), 404);
        $this->assertHtmlPage($guest->get('reset_form.php?token=' . str_repeat('ab', 32)), 404);

        foreach ([...self::CUSTOMER_PAGES, ...self::ADMIN_PAGES, 'checkout.php', 'order.php?id=1'] as $path) {
            $this->assertRedirect($guest->get($path), 'index.php?form=login', 303, "Guest GET $path");
        }

        // The login page the guest lands on explains why.
        $landing = $guest->follow($guest->get('cart.php'));
        $this->assertHtmlPage($landing);
        $this->assertFlash($landing, 'info', 'Please log in to continue.');

        // Error pages honour the same contract.
        $this->assertHtmlPage($guest->get('Register.php'), 405);
        $this->assertHtmlPage($guest->post('reset_password.php', ['email' => 'x@example.com']), 419);
    }

    public function testCustomerPages(): void
    {
        [$customer, $user] = $this->signedInCustomer('Page Reader');

        $this->assertRedirect($customer->get('index.php'), 'Home_Page.php');
        foreach (['Home_Page.php', 'About_us.php', ...self::CUSTOMER_PAGES] as $path) {
            $expected = str_starts_with($path, 'success_pay.php') ? 404 : 200;
            $this->assertHtmlPage($customer->get($path), $expected);
        }

        // Every book page, every genre tab and a search with and without results.
        foreach (db_all('SELECT id FROM books ORDER BY id') as $row) {
            $this->assertHtmlPage($customer->get('Selected.php?id=' . $row['id']));
        }
        foreach (list_genres() as $genre) {
            $this->assertHtmlPage($customer->get('Explore_Page.php?genre=' . rawurlencode($genre)));
        }
        $this->assertHtmlPage($customer->get('Explore_Page.php?q=Olivia'));
        $this->assertHtmlPage($customer->get('Explore_Page.php?genre=Mystery&q=zzz-no-match'));
        foreach (['2', '3', '99', '0', 'x'] as $page) {
            $this->assertHtmlPage($customer->get('Explore_Page.php?page=' . $page));
        }
        $this->assertHtmlPage($customer->get('profilePage.php?section=payment'));
        $this->assertHtmlPage($customer->get('Selected.php?id=999999'), 404);
        $this->assertHtmlPage($customer->get('success_pay.php'), 404);

        // A filled cart and a paid order (success page, order history).
        $this->assertRedirect($this->addToCart($customer, 7, 2), 'cart.php');
        $this->assertHtmlPage($customer->get('cart.php'));
        $this->assertHtmlPage($customer->get('checkout.php'));
        $this->assertRedirect($this->addToCart($customer, 51, 1), 'cart.php');
        $purchase = $this->purchase($customer, []);
        $this->assertHtmlPage($purchase['page']);
        $this->assertHtmlPage($customer->get('order.php?id=' . $purchase['order']['id']));
        $this->assertRedirect($customer->get('checkout.php'), 'cart.php', 303, 'Nothing left to check out');
        $this->assertHtmlPage($customer->get('profilePage.php?section=orders'));
        $this->assertHtmlPage($customer->get('cancel_pay.php?order=' . $purchase['order']['id']));
        $this->assertHtmlPage($customer->get('Selected.php?id=7')); // now with the review form

        foreach (self::ADMIN_PAGES as $path) {
            $this->assertHtmlPage($customer->get($path), 403);
        }
        self::assertSame((int) $user['id'], (int) $purchase['order']['user_id']);

        // Customers' storefront cards and footer lead to the shop.
        $home = $this->assertHtmlPage($customer->get('Home_Page.php'));
        $cards = $home->elements('//a[' . HtmlDocument::hasClass('home-book-link') . ']');
        self::assertNotSame([], $cards);
        foreach ($cards as $card) {
            self::assertMatchesRegularExpression('/^Selected\.php\?id=\d+$/D', $card->getAttribute('href'));
        }
        self::assertSame(['Explore', 'About Us'], $home->texts('//footer//a'));
        $error = $this->assertHtmlPage($customer->get('Selected.php?id=999999'), 404);
        self::assertSame(['Home_Page.php', 'Explore_Page.php'], self::hrefs($error, '//p[' . HtmlDocument::hasClass('error-actions') . ']/a'));
    }

    public function testAdminPages(): void
    {
        [$admin] = $this->signedInAdmin();

        $this->assertRedirect($admin->get('index.php'), 'admin_dashboard.php');
        $customer = $this->createUser();
        db_run(
            "INSERT INTO orders (user_id, status, currency, total_amount, amount_minor, stripe_session_id, stripe_payment_intent, paid_at)
             VALUES (?, 'paid', 'sar', '19.99', 1999, 'cs_test_contract', 'pi_test_contract', NOW())",
            [$customer['id']]
        );
        $orderId = (int) db()->lastInsertId();
        db_run("INSERT INTO order_items (order_id, book_id, title, unit_price, quantity) VALUES (?, 51, 'Esabella', '19.99', 1)", [$orderId]);
        foreach ([
            'admin_dashboard.php', 'admin_dashboard.php?page=2', 'admin_dashboard.php?page=3', 'edit_book.php?id=7', 'edit_book.php?id=61',
            'admin_orders.php', 'admin_orders.php?status=paid&q=' . $orderId, 'admin_orders.php?status=refunded', 'admin_order.php?id=' . $orderId,
            'Home_Page.php', 'About_us.php',
        ] as $path) {
            $this->assertHtmlPage($admin->get($path));
        }
        $this->assertHtmlPage($admin->get('edit_book.php?id=999999'), 404);
        $this->assertHtmlPage($admin->get('edit_book.php'), 404);
        $this->assertHtmlPage($admin->get('admin_order.php?id=999999'), 404);

        foreach (self::CUSTOMER_PAGES as $path) {
            $this->assertRedirect($admin->get($path), 'admin_dashboard.php', 303, "Admin GET $path");
        }
        $dashboard = $admin->follow($admin->get('cart.php'));
        $this->assertHtmlPage($dashboard);
        $this->assertFlash($dashboard, 'info', 'Administrator accounts manage the catalog. Sign in with a customer account to shop.');

        // Shop pages send administrators back to the dashboard, so the storefront,
        // footer and error pages never link there: book cards open the editor.
        $home = $this->assertHtmlPage($admin->get('Home_Page.php'));
        $cards = self::hrefs($home, '//a[' . HtmlDocument::hasClass('home-book-link') . ']');
        self::assertNotSame([], $cards);
        foreach ($cards as $href) {
            self::assertMatchesRegularExpression('/^edit_book\.php\?id=\d+$/D', $href);
            $this->assertHtmlPage($admin->get($href));
        }
        self::assertSame(0, $home->count('//a[starts-with(@href, "Selected.php") or starts-with(@href, "Explore_Page.php")]'));
        self::assertSame(['Dashboard', 'About Us'], $home->texts('//footer//a'));
        $error = $this->assertHtmlPage($admin->get('edit_book.php?id=999999'), 404);
        self::assertSame(['admin_dashboard.php', 'Home_Page.php'], self::hrefs($error, '//p[' . HtmlDocument::hasClass('error-actions') . ']/a'));
    }

    /**
     * @return list<string>
     */
    private static function hrefs(HtmlDocument $document, string $expression): array
    {
        return array_values(array_map(static fn (\DOMElement $link): string => $link->getAttribute('href'), $document->elements($expression)));
    }
}
