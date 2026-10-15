<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\AccountHelpers;
use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;

/**
 * The demo's one-click sign-in (APP_DEMO=true): "Try as a customer" gives
 * every visitor a fresh demo guest that can use the whole store but has no
 * password, "Try as an administrator (read-only)" signs in to the shared
 * viewer. Outside demo mode neither exists.
 */
final class DemoLoginTest extends HttpTestCase
{
    use AccountHelpers;

    private const CUSTOMER_WELCOME = '/^Welcome, Demo reader \d{4}! This demo account is yours to try the store with\. It is deleted after 24 hours, or earlier when the demo is reset\.$/D';
    private const ADMIN_WELCOME = 'You are signed in as a read-only demo administrator. Look around as you like: nothing can be changed.';

    private function enableDemo(array $settings = []): void
    {
        $this->configureServer($settings + ['APP_DEMO' => 'true']);
    }

    /**
     * Presses one of the demo buttons on the sign-in page; returns the POST response.
     */
    private function tryAs(HttpClient $visitor, string $as, string $from = 'index.php?form=login'): HttpResponse
    {
        $page = $visitor->get($from);
        $this->assertHtmlPage($page);
        return $visitor->submitForm($page, 'demo_login.php', [], ['as' => $as]);
    }

    /**
     * A visitor signed in as a new demo guest.
     *
     * @return array{0: HttpClient, 1: array<string, mixed>}
     */
    private function demoCustomer(): array
    {
        $visitor = $this->client();
        $before = array_map('intval', array_column(db_all('SELECT id FROM users'), 'id'));
        $this->assertRedirect($this->tryAs($visitor, 'customer'), 'Home_Page.php');
        $guest = db_one('SELECT * FROM users WHERE demo_guest_at IS NOT NULL ORDER BY id DESC LIMIT 1');
        self::assertNotNull($guest);
        self::assertNotContains((int) $guest['id'], $before, 'A new account');
        return [$visitor, $guest];
    }

    public function testTheButtonsOnlyExistInDemoMode(): void
    {
        $guest = $this->client();
        $login = $this->assertHtmlPage($guest->get('index.php?form=login'));
        self::assertFalse($login->hasForm('demo_login.php'));
        self::assertSame(0, $login->count('//p[' . HtmlDocument::hasClass('demo-banner') . ']'));
        $this->assertHtmlPage($guest->postWithToken('demo_login.php', ['as' => 'customer']), 404);
        $this->assertHtmlPage($guest->postWithToken('demo_login.php', ['as' => 'admin']), 404);
        $this->assertHtmlPage($guest->get('demo_login.php'), 404);
        self::assertSame(0, $this->countRows('users') + $this->countRows('admins'));

        $this->enableDemo();
        $page = $guest->get('index.php?form=login');
        $document = $this->assertHtmlPage($page);
        self::assertSame('Try the demo in one click', $document->text('//section[@id="demo-logins"]/h2'));
        foreach (['customer' => 'Try as a customer', 'admin' => 'Try as an administrator (read-only)'] as $as => $label) {
            self::assertTrue($document->hasForm('demo_login.php', ['as' => $as]));
            $button = $document->first('//form[@action="demo_login.php"][.//input[@name="as"][@value="' . $as . '"]]//button');
            self::assertNotNull($button);
            self::assertSame($label, HtmlDocument::normalize($button->textContent));
            $hint = $document->first('//*[@id="' . $button->getAttribute('aria-describedby') . '"]');
            self::assertNotNull($hint, "The $as button is described by its hint");
        }
        $this->assertPageText('A fresh account of your own, with a few favorites', $page);
        // Deleted by bin/demo-cleanup.php after 24 hours, or by the demo's reset (bin/demo-reset.php) before.
        $this->assertPageText('It is deleted after 24 hours, or earlier when the demo is reset.', $page);
        $this->assertPageText('Every form is switched off, so nothing can be changed.', $page);

        // The banner points guests to the buttons.
        $banner = $this->assertHtmlPage($guest->get('Home_Page.php'))->first('//p[' . HtmlDocument::hasClass('demo-banner') . ']');
        self::assertNotNull($banner);
        self::assertStringEndsWith('New here? Try the store in one click as a customer or as a read-only administrator.', HtmlDocument::normalize($banner->textContent));
        self::assertSame(['index.php?form=login#demo-logins'], array_map(static fn (\DOMElement $link): string => $link->getAttribute('href'), iterator_to_array($banner->getElementsByTagName('a'))));
        $arabic = $guest->follow($guest->get('index.php?form=login&lang=ar'));
        $this->assertHtmlPage($arabic);
        $this->assertPageText('جرّب بصفتك مشرفًا (للقراءة فقط)', $arabic);
        $this->assertPageText('زائر جديد؟ جرّب المتجر بنقرة واحدة بصفتك عميلًا أو مشرفًا للقراءة فقط.', $arabic);
        $this->assertPageText('ويُحذف الحساب بعد 24 ساعة، أو قبل ذلك إذا أُعيد المتجر التجريبي إلى حالته الأولى.', $arabic);
    }

    public function testTheButtonsAreFormsWithACsrfToken(): void
    {
        $this->enableDemo();
        $visitor = $this->client();
        $visitor->get('index.php?form=login');

        $this->assertHtmlPage($visitor->get('demo_login.php?as=customer'), 405);
        foreach (['customer', 'admin'] as $as) {
            $this->assertHtmlPage($visitor->post('demo_login.php', ['as' => $as]), 419);
            $this->assertHtmlPage($visitor->post('demo_login.php', ['as' => $as, '_token' => $this->client()->csrfToken()]), 419);
        }
        $this->assertHtmlPage($visitor->postWithToken('demo_login.php', ['as' => 'owner']), 400);
        self::assertSame(0, $this->countRows('users') + $this->countRows('admins') + $this->countRows('demo_guest_creations'));
        self::assertFalse($this->isSignedInCustomer($visitor));
    }

    public function testTryAsACustomerGivesEveryVisitorAFreshAccount(): void
    {
        $this->enableDemo(['REQUIRE_EMAIL_VERIFICATION' => 'true', 'MAIL_DRIVER' => 'database']);

        $visitor = $this->client();
        $visitor->get('index.php?form=login');
        $sessionBefore = $visitor->cookie(self::SESSION_COOKIE);
        $response = $this->tryAs($visitor, 'customer');
        $this->assertRedirect($response, 'Home_Page.php');
        self::assertNotSame($sessionBefore, $visitor->cookie(self::SESSION_COOKIE), 'A new session, as at every sign-in');

        $home = $visitor->follow($response);
        $this->assertHtmlPage($home);
        $flashes = $home->document()->flashes();
        self::assertCount(1, $flashes);
        self::assertSame('success', $flashes[0]['type']);
        self::assertMatchesRegularExpression(self::CUSTOMER_WELCOME, $flashes[0]['message']);
        self::assertSame(0, $home->document()->count('//p[' . HtmlDocument::hasClass('demo-banner') . ']//a'), 'No invitation once signed in');

        $guest = db_one('SELECT * FROM users WHERE demo_guest_at IS NOT NULL');
        self::assertNotNull($guest);
        self::assertMatchesRegularExpression('/^reader-[a-f0-9]{24}@demo\.invalid$/D', (string) $guest['Eaddress']);
        self::assertNotNull($guest['email_verified_at'], 'Signed in at once, even with email confirmation on');
        self::assertTrue($this->isSignedInCustomer($visitor));
        self::assertSame([['ip_address' => '127.0.0.1']], db_all('SELECT ip_address FROM demo_guest_creations'));

        // Its favorites are filled, and its inbox is its own.
        $favorites = $visitor->get('Explore_Page.php?genre=Favorites');
        $this->assertHtmlPage($favorites);
        self::assertCount(DEMO_GUEST_FAVORITES, $favorites->document()->elements('//li[' . HtmlDocument::hasClass('explore-card') . ']'));
        $inbox = $this->assertHtmlPage($visitor->get('inbox.php'));
        self::assertSame([(string) $guest['Eaddress']], $inbox->texts('//ul[' . HtmlDocument::hasClass('inbox-addresses') . ']/li'));

        // Another visitor gets another account.
        $other = $this->client();
        $this->assertRedirect($this->tryAs($other, 'customer'), 'Home_Page.php');
        self::assertSame(2, $this->countRows('users', 'demo_guest_at IS NOT NULL'));
        self::assertNotSame(
            $this->assertHtmlPage($visitor->get('profilePage.php'))->first('//input[@id="email"]')?->getAttribute('value'),
            $this->assertHtmlPage($other->get('profilePage.php'))->first('//input[@id="email"]')?->getAttribute('value')
        );
    }

    public function testADemoCustomerCanShopPayAndReview(): void
    {
        $this->enableDemo();
        [$visitor, $guest] = $this->demoCustomer();

        // A made-up delivery address is ready, so checkout needs no typing.
        $addresses = db_all('SELECT city, phone, is_default FROM addresses WHERE user_id = ?', [$guest['id']]);
        self::assertSame([['city' => 'Riyadh', 'phone' => '+966500000000', 'is_default' => 1]], array_map(
            static fn (array $row): array => ['city' => $row['city'], 'phone' => $row['phone'], 'is_default' => (int) $row['is_default']],
            $addresses
        ));

        // purchase() keeps the checkout page's preselected address.
        $purchase = $this->purchase($visitor, [51 => 1]);
        self::assertSame((int) $guest['id'], (int) $purchase['order']['user_id']);
        self::assertSame('Riyadh', db_value('SELECT city FROM order_addresses WHERE order_id = ?', [$purchase['order']['id']]));

        $book = $this->bookPage($visitor, 51);
        $response = $visitor->submitForm($book, 'Selected.php?id=51', ['rating' => '5', 'comment' => 'Tried in one click.'], ['action' => 'add_review']);
        $this->assertRedirect($response, 'Selected.php?id=51#reviews');
        self::assertSame(1, $this->countRows('comments', 'username = ?', [$guest['Eaddress']]));
        $this->assertPageText((string) $guest['name'], $visitor->get('Selected.php?id=51'));
    }

    public function testLogInToBuyThenTryAsACustomerComesBackToTheBook(): void
    {
        $this->enableDemo();
        $visitor = $this->client();
        $book = $visitor->get('Selected.php?id=13');
        $this->assertHtmlPage($book);

        $response = $this->tryAs($visitor, 'customer', 'index.php?form=login&return=' . rawurlencode('Selected.php?id=13'));

        $this->assertRedirect($response, 'Selected.php?id=13');
        self::assertTrue($visitor->follow($response)->document()->hasForm('Selected.php?id=13', ['action' => 'add_to_cart']));
    }

    public function testDemoCustomersHaveNoPasswordButMayDeleteTheirAccount(): void
    {
        $this->enableDemo(['MAIL_DRIVER' => 'database']);
        [$visitor, $guest] = $this->demoCustomer();
        $userId = (int) $guest['id'];

        // No password to change.
        $security = $visitor->get('profilePage.php?section=security');
        $document = $this->assertHtmlPage($security);
        self::assertFalse($document->hasForm('profilePage.php', ['action' => 'change_password']));
        $this->assertPageText('You signed in with the demo button, so this account has no password and none can be set.', $security);
        self::assertSame('This demo account has a made-up address, which cannot be changed.', $document->text('//*[@id="email-hint"]'));

        $change = $visitor->postWithToken('profilePage.php', [
            'action' => 'change_password',
            'current_password' => 'anything-at-all',
            'new_password' => 'my-own-password-1',
            'new_password_confirmation' => 'my-own-password-1',
        ]);
        $this->assertRedirect($change, 'profilePage.php?section=security');
        $this->assertFlash($visitor->follow($change), 'error', 'Demo accounts have no password, so none can be set or changed.');
        self::assertSame($guest['pass'], db_value('SELECT pass FROM users WHERE id = ?', [$userId]));
        self::assertSame(0, $this->countRows('login_attempts'), 'Not a failed sign-in either');

        // Nor through a password reset.
        $reset = $visitor->postWithToken('reset_password.php', ['email' => (string) $guest['Eaddress']]);
        $this->assertRedirect($reset, 'index.php?form=reset');
        self::assertSame(0, $this->countRows('reset_tokens') + $this->countRows('demo_mail'));

        // Deleting the account needs only the confirmation.
        $delete = $visitor->get('profilePage.php?section=delete');
        $form = $this->assertHtmlPage($delete)->form('profilePage.php', ['action' => 'delete_account']);
        self::assertArrayNotHasKey('delete_password', $form->fields);
        $this->assertPageText('Demo accounts are deleted automatically 24 hours after they were created. You can also delete this one now; no password is needed.', $delete);
        // Unlike other accounts, its canceled and expired checkouts go with it (delete_demo_guest()).
        self::assertSame(
            'Your order records (books, amounts, dates and payment references) are kept for accounting, anonymised: they are no longer linked to this account. Checkouts that were canceled or expired are deleted with the account, since no money was paid for them.',
            $this->assertHtmlPage($delete)->text('//p[' . HtmlDocument::hasClass('delete-kept') . ']')
        );

        $unconfirmed = $visitor->submitForm($delete, 'profilePage.php', [], ['action' => 'delete_account']);
        $this->assertRedirect($unconfirmed, 'profilePage.php?section=delete');
        $this->assertFlash($visitor->follow($unconfirmed), 'error', 'Your account was not deleted.');
        self::assertNotNull(db_value('SELECT 1 FROM users WHERE id = ?', [$userId]));

        $deleted = $visitor->submitForm($visitor->get('profilePage.php?section=delete'), 'profilePage.php', ['confirm_delete' => 'yes'], ['action' => 'delete_account']);
        $this->assertRedirect($deleted, 'Home_Page.php');
        $this->assertFlash($visitor->follow($deleted), 'success', 'Your account has been deleted. Thank you for reading with us.');
        self::assertNull(db_value('SELECT 1 FROM users WHERE id = ?', [$userId]));
        self::assertSame(0, $this->countRows('favorites'));
        self::assertFalse($this->isSignedInCustomer($visitor));
    }

    public function testTryAsAnAdministratorSignsInToTheSharedReadOnlyAdministrator(): void
    {
        $this->enableDemo();

        $first = $this->client();
        $response = $this->tryAs($first, 'admin');
        $this->assertRedirect($response, 'admin_dashboard.php');
        $dashboard = $first->follow($response);
        $document = $this->assertHtmlPage($dashboard);
        $this->assertFlash($dashboard, 'success', self::ADMIN_WELCOME);
        self::assertSame('Signed in as Demo administrator', $document->text('//p[' . HtmlDocument::hasClass('admin-welcome') . ']'));
        self::assertNotSame('', $document->text('//p[' . HtmlDocument::hasClass('read-only-note') . ']'));
        self::assertSame([['Eaddress' => DEMO_VIEWER_ADMIN_EMAIL, 'role' => ADMIN_ROLE_VIEWER]], db_all('SELECT Eaddress, role FROM admins'));

        // Everybody shares it; nothing can be changed through it.
        $second = $this->client();
        $this->assertRedirect($this->tryAs($second, 'admin'), 'admin_dashboard.php');
        self::assertSame(1, $this->countRows('admins'));
        $refused = $second->postWithToken('admin_dashboard.php', ['action' => 'delete_book', 'id' => '7']);
        $this->assertHtmlPage($refused, 403);
        self::assertNotNull(find_book(7));

        // Logging out one visitor leaves the other signed in.
        $this->assertRedirect($first->postWithToken('logout.php'), 'index.php?form=login');
        $this->assertStatus(200, $second->get('admin_dashboard.php'));
        $this->assertRedirect($first->get('admin_dashboard.php'), 'index.php?form=login');

        // Should the account ever be made an owner, the next demo sign-in makes it a viewer again.
        db_run('UPDATE admins SET role = ? WHERE Eaddress = ?', [ADMIN_ROLE_OWNER, DEMO_VIEWER_ADMIN_EMAIL]);
        $this->assertRedirect($this->tryAs($this->client(), 'admin'), 'admin_dashboard.php');
        $this->assertHtmlPage($second->postWithToken('admin_dashboard.php', ['action' => 'delete_book', 'id' => '7']), 403);
        self::assertNotNull(find_book(7));
    }

    public function testDemoCustomersAreLimitedPerAddress(): void
    {
        $this->enableDemo();
        for ($i = 1; $i <= DEMO_GUEST_RATE_LIMIT; $i++) {
            $this->assertRedirect($this->tryAs($this->client(), 'customer'), 'Home_Page.php', 303, "Demo customer $i");
        }

        $visitor = $this->client();
        $refused = $this->tryAs($visitor, 'customer');
        $this->assertRedirect($refused, 'index.php?form=login');
        $this->assertFlash(
            $visitor->follow($refused),
            'error',
            'Too many demo accounts were started from your network in the last hour. Please try again later, or sign up for an account of your own.'
        );
        self::assertFalse($this->isSignedInCustomer($visitor));
        self::assertSame(DEMO_GUEST_RATE_LIMIT, $this->countRows('users'));
        self::assertNotSame([], array_filter($this->appLogLines(), static fn (string $line): bool => str_contains($line, 'WARNING: Demo guest creation blocked by throttling')));

        // The read-only administrator is not limited: it creates nothing.
        $this->assertRedirect($this->tryAs($visitor, 'admin'), 'admin_dashboard.php');
    }

    public function testDemoCustomersAreLimitedForAllVisitorsTogether(): void
    {
        $this->enableDemo();
        // Visitors from many other networks started demo accounts within the hour.
        for ($i = 1; $i <= DEMO_GUEST_RATE_LIMIT_TOTAL; $i++) {
            db_run('INSERT INTO demo_guest_creations (ip_address, created_at) VALUES (?, NOW() - INTERVAL 10 MINUTE)', ['2001:db8:' . dechex($i) . '::/64']);
        }

        $visitor = $this->client();
        $refused = $this->tryAs($visitor, 'customer');
        $this->assertRedirect($refused, 'index.php?form=login');
        $this->assertFlash(
            $visitor->follow($refused),
            'error',
            'Many visitors have started demo accounts in the last hour. Please try again later, or sign up for an account of your own.'
        );
        self::assertFalse($this->isSignedInCustomer($visitor));
        self::assertSame(0, $this->countRows('users'));
        self::assertNotSame([], array_filter($this->appLogLines(), static fn (string $line): bool => str_contains($line, 'WARNING: Demo guest creation blocked by the limit for all visitors')));

        $visitor->follow($visitor->get('index.php?form=login&lang=ar'));
        $this->assertFlash(
            $visitor->follow($this->tryAs($visitor, 'customer')),
            'error',
            'أنشأ كثير من الزوار حسابات تجريبية خلال الساعة الماضية. يُرجى المحاولة لاحقًا، أو إنشاء حساب خاص بك.'
        );
    }

    public function testTheReadOnlyAdministratorNeverSeesTheKeyToACustomersPaymentPage(): void
    {
        // As on the alwaysdata demo: payments go through the simulator, whose page
        // (pay.php) needs no sign-in because the session id in its link is the key.
        $this->enableDemo(['PAYMENT_SIMULATOR' => 'true']);
        [$customer] = $this->signedInCustomer('Paying Reader');
        $this->assertRedirect($this->addToCart($customer, 51, 1), 'cart.php');
        $checkout = $this->submitCheckout($customer);
        $this->assertStatus(303, $checkout);
        self::assertSame(1, preg_match('#/pay\.php\?session=(cs_test_[A-Za-z0-9]+)$#D', (string) $checkout->header('Location'), $match));
        $sessionId = $match[1];
        $order = $this->orderBySession($sessionId);
        self::assertSame('pending', $order['status']);

        $visitor = $this->client();
        $this->assertRedirect($this->tryAs($visitor, 'admin'), 'admin_dashboard.php');
        $pages = ['admin_dashboard.php', 'admin_orders.php', 'admin_orders.php?status=pending', 'admin_orders.php?q=' . (int) $order['id'], 'admin_order.php?id=' . (int) $order['id']];
        foreach ($pages as $path) {
            $page = $visitor->get($path);
            $this->assertHtmlPage($page);
            self::assertStringNotContainsString(substr($sessionId, strlen('cs_test_')), $page->body, "No part of the session id on $path");
        }
        $detail = $this->assertHtmlPage($visitor->get('admin_order.php?id=' . (int) $order['id']));
        self::assertSame(['cs_test_…'], $detail->texts('//code[' . HtmlDocument::hasClass('stripe-ref') . ']'), 'Only the prefix of the session id');
        // The delivery address is reduced to its city: no recipient, street, number or phone.
        $addressText = $detail->text('//p[' . HtmlDocument::hasClass('address-text') . ']');
        self::assertSame('Delivery in Riyadh (the full address is hidden from read-only administrators).', $addressText);
        foreach (['Sara Ahmed', 'King Fahd Road', 'Al Olaya', '1234', '512345678', 'RRRD2929'] as $private) {
            self::assertStringNotContainsString($private, $detail->text('//main'), "No $private for a viewer");
        }
        self::assertNull($detail->first('//a[starts-with(@href, "tel:")]'), 'No phone link for a viewer');

        // Nothing the viewer saw lets it finish the customer's payment.
        $state = payment_simulator_session($sessionId, self::server()->configuration['PAYMENT_SIMULATOR_STORE']);
        self::assertSame(['open', 'unpaid'], [$state['status'] ?? null, $state['payment_status'] ?? null]);
        self::assertSame('pending', $this->orderBySession($sessionId)['status']);
    }
}
