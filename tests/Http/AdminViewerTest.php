<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HtmlForm;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Read-only administrators (role "viewer", like the demo's shared
 * administrator) open every admin page but change nothing. The data-driven
 * test takes every action form an owner sees on each admin page, posts it as
 * a viewer (with the viewer's own valid CSRF token) and proves that nothing
 * in the database, the uploads or at Stripe changed. Forms added to these
 * pages later are covered automatically.
 */
final class AdminViewerTest extends HttpTestCase
{
    private const NOTE = "You are signed in as a read-only demo administrator. You can open every page, but the forms are switched off and nothing can be changed. Customers' names and email addresses, and payment references, are shortened.";
    private const REFUSED = 'You are signed in as a read-only demo administrator, so nothing can be changed. You can still open every admin page.';
    private const CUSTOMER_NAME = 'Sara Ali';
    // The digits let the tests check that a viewer's search never matches inside addresses.
    private const CUSTOMER_EMAIL = 'sara.ali4821@example.com';

    /**
     * An administrator with the viewer role, signed in through the login form.
     *
     * @return array{0: HttpClient, 1: array{Eaddress: string, name: string}}
     */
    private function signedInViewer(): array
    {
        $viewer = $this->createAdmin(self::uniqueEmail('viewer'), 'Read Only');
        db_run('UPDATE admins SET role = ? WHERE Eaddress = ?', [ADMIN_ROLE_VIEWER, $viewer['Eaddress']]);
        $client = $this->client();
        $this->assertRedirect($this->logIn($client, $viewer['Eaddress'], self::DEFAULT_PASSWORD), 'admin_dashboard.php');
        return [$client, $viewer];
    }

    /**
     * A customer who bought a book (a paid, refundable order) and started a
     * second checkout (a pending order), through the real pages.
     *
     * @return array{paid: int, pending: int}
     */
    private function orders(): array
    {
        $this->createUser(self::CUSTOMER_EMAIL, self::CUSTOMER_NAME);
        $customer = $this->client();
        $this->assertRedirect($this->logIn($customer, self::CUSTOMER_EMAIL, self::DEFAULT_PASSWORD), 'Home_Page.php');
        $paid = (int) $this->purchase($customer, [51 => 1])['order']['id'];
        $this->assertRedirect($this->addToCart($customer, 7, 1), 'cart.php');
        $pending = (int) $this->orderBySession($this->startCheckout($customer))['id'];
        return ['paid' => $paid, 'pending' => $pending];
    }

    /**
     * Everything an admin action could change: every table (checksums), the
     * uploaded covers and the refunds sent to Stripe.
     *
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $state = [];
        foreach (db_all('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME') as $row) {
            $table = (string) $row['name'];
            $state[$table] = db_one('CHECKSUM TABLE `' . str_replace('`', '``', $table) . '`')['Checksum'] ?? null;
        }
        $state['uploaded covers'] = glob(BASE_PATH . '/public/uploads/covers/*') ?: [];
        $state['Stripe refunds'] = $this->stripe->refunds();
        return $state;
    }

    /**
     * Admin pages with the number of action forms an owner sees on them at
     * least; {paid} and {pending} are replaced with order ids.
     *
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function adminPages(): iterable
    {
        yield 'dashboard: add a book, delete each book' => ['admin_dashboard.php', 1 + ADMIN_PAGE_SIZE];
        yield 'last page of the dashboard' => ['admin_dashboard.php?page=3', 1 + 55 - 2 * ADMIN_PAGE_SIZE];
        yield 'book editor, cover upload included' => ['edit_book.php?id=7', 1];
        yield 'order list' => ['admin_orders.php', 0];
        yield 'paid order: refund' => ['admin_order.php?id={paid}', 1];
        yield 'pending order' => ['admin_order.php?id={pending}', 0];
    }

    #[DataProvider('adminPages')]
    public function testAViewerOpensThePageButNoFormOnItChangesAnything(string $path, int $minimumForms): void
    {
        $orders = $this->orders();
        $path = strtr($path, ['{paid}' => (string) $orders['paid'], '{pending}' => (string) $orders['pending']]);
        [$owner] = $this->signedInAdmin('Store Owner');
        [$viewer] = $this->signedInViewer();

        // Every action form an owner can use on this page.
        $forms = array_values(array_filter(
            $this->assertHtmlPage($owner->get($path))->forms(),
            static fn (HtmlForm $form): bool => $form->method === 'POST' && $form->action !== 'logout.php'
        ));
        self::assertGreaterThanOrEqual($minimumForms, count($forms), "Action forms on $path");

        // The viewer sees the page, with the note and every control of those forms switched off.
        $document = $this->assertHtmlPage($viewer->get($path));
        self::assertSame(self::NOTE, $document->text('//p[' . HtmlDocument::hasClass('read-only-note') . ']'));
        $enabled = $document->elements(
            '//form[translate(@method, "post", "POST") = "POST"][not(@action = "logout.php")]'
            . '//*[self::input[not(@type = "hidden")] or self::button or self::select or self::textarea][not(@disabled)]'
        );
        self::assertSame([], array_map(static fn (\DOMElement $control): string => $control->getAttribute('name') ?: $control->nodeName, $enabled), "Controls a viewer could use on $path");

        $before = $this->state();
        foreach ($forms as $form) {
            $target = $form->action === '' ? $path : $form->action;
            $files = [];
            foreach ($form->fileFields as $field) {
                $files[$field] = ['path' => BASE_PATH . '/public/imges/All_section/biology.png', 'filename' => 'cover.png', 'type' => 'image/png'];
            }
            // With the viewer's own, valid CSRF token: only the role stops the request.
            $response = $viewer->request('POST', $target, ['_token' => $viewer->csrfToken()] + $form->fields, [], $files);
            $where = "POST $target " . json_encode($form->fields, JSON_UNESCAPED_SLASHES) . ' as a viewer';
            $this->assertHtmlPage($response, 403);
            self::assertSame('Access denied', $response->document()->text('//h1'), $where);
            $this->assertPageText(self::REFUSED, $response);
            self::assertSame($before, $this->state(), "$where must change nothing");
        }

        // The same requests are real actions for an owner (the last one without a file, to keep uploads out).
        $plain = array_values(array_filter($forms, static fn (HtmlForm $form): bool => $form->fileFields === []));
        if ($plain !== []) {
            $form = $plain[count($plain) - 1];
            $response = $owner->request('POST', $form->action === '' ? $path : $form->action, ['_token' => $owner->csrfToken()] + $form->fields);
            $this->assertStatus(303, $response, 'An owner may send ' . json_encode($form->fields));
            self::assertNotSame($before, $this->state(), 'and it changes something');
        }
    }

    public function testAViewerSeesCustomersShortenedAndSearchesByOrderNumberOnly(): void
    {
        $orders = $this->orders();
        [$owner] = $this->signedInAdmin();
        [$viewer] = $this->signedInViewer();

        $pages = ['admin_dashboard.php', 'admin_orders.php', 'admin_order.php?id=' . $orders['paid']];
        foreach ($pages as $path) {
            $ownerPage = $owner->get($path);
            $this->assertPageText(self::CUSTOMER_NAME, $ownerPage);
            self::assertStringContainsString(self::CUSTOMER_EMAIL, str_replace('<wbr>', '', $ownerPage->body));

            $viewerPage = $viewer->get($path);
            $this->assertHtmlPage($viewerPage);
            $this->assertPageText('S…', $viewerPage);
            $this->assertPageText('s…@….com', $viewerPage);
            $this->assertNotPageText(self::CUSTOMER_NAME, $viewerPage);
            self::assertStringNotContainsString('sara.ali', $viewerPage->body, "No part of the address on $path");
            self::assertStringNotContainsString('mailto:', $viewerPage->body);
        }

        // Searching by email would tell which addresses have orders.
        $byEmail = $viewer->get('admin_orders.php?q=' . rawurlencode('sara'));
        $this->assertPageText('No orders match these filters.', $byEmail);
        $this->assertPageText('Order number', $byEmail);
        $byNumber = $viewer->get('admin_orders.php?q=' . $orders['paid']);
        self::assertSame(['#' . $orders['paid']], $byNumber->document()->texts('//table[' . HtmlDocument::hasClass('order-list-table') . ']//tbody/tr/th'));
        $this->assertPageText('S…', $byNumber);
        // A number matches the order number only, never digits inside the addresses.
        self::assertNotContains(4821, $orders);
        $byDigits = $viewer->get('admin_orders.php?q=4821');
        $this->assertPageText('No orders match these filters.', $byDigits);
        self::assertSame([], $viewer->get('admin_orders.php?q=' . rawurlencode('#4821'))->document()->texts('//table[' . HtmlDocument::hasClass('order-list-table') . ']//tbody/tr/th'));
        // Owners search by email as before (newest order first), digits included.
        foreach (['sara', '4821'] as $query) {
            self::assertSame(
                ['#' . $orders['pending'], '#' . $orders['paid']],
                $owner->get('admin_orders.php?q=' . $query)->document()->texts('//table[' . HtmlDocument::hasClass('order-list-table') . ']//tbody/tr/th'),
                "Owner search for $query"
            );
        }
    }

    public function testAViewerSeesOnlyThePrefixOfPaymentReferences(): void
    {
        // A checkout session id is the key to its payment page (pay.php needs no sign-in).
        $orders = $this->orders();
        [$owner] = $this->signedInAdmin();
        [$viewer] = $this->signedInViewer();
        $this->assertRedirect($owner->postWithToken('admin_order.php?id=' . $orders['paid'], ['action' => 'refund']), 'admin_order.php?id=' . $orders['paid']);
        $references = static fn (HtmlDocument $page): array => $page->texts('//code[' . HtmlDocument::hasClass('stripe-ref') . ']');

        foreach ($orders as $id) {
            $row = db_one('SELECT stripe_session_id, stripe_payment_intent, stripe_refund_id FROM orders WHERE id = ?', [$id]);
            self::assertNotNull($row);
            $full = array_values(array_filter($row, static fn (mixed $value): bool => is_string($value) && $value !== ''));
            self::assertSame($full, $references($this->assertHtmlPage($owner->get('admin_order.php?id=' . $id))), 'Owners see the references in full');

            $page = $viewer->get('admin_order.php?id=' . $id);
            self::assertSame(array_map('masked_reference', $full), $references($this->assertHtmlPage($page)));
            foreach ($full as $reference) {
                self::assertStringNotContainsString(substr($reference, (int) strrpos($reference, '_') + 1), $page->body, "No part of $reference for a viewer");
            }
        }
        self::assertSame(['cs_test_…', 'pi_test_…', 're_test_…'], $references($this->assertHtmlPage($viewer->get('admin_order.php?id=' . $orders['paid']))));
        self::assertSame(['cs_test_…'], $references($this->assertHtmlPage($viewer->get('admin_order.php?id=' . $orders['pending']))));
    }

    public function testAViewerLooksAroundButIsKeptOutOfTheShopLikeEveryAdministrator(): void
    {
        [$viewer, $row] = $this->signedInViewer();

        $dashboard = $this->assertHtmlPage($viewer->get('admin_dashboard.php'));
        self::assertSame('Signed in as Read Only', $dashboard->text('//p[' . HtmlDocument::hasClass('admin-welcome') . ']'));
        $firstTitle = $dashboard->text('//table[' . HtmlDocument::hasClass('books-table') . ']//tbody/tr[1]/th');
        self::assertSame("View “{$firstTitle}”", HtmlDocument::normalize($dashboard->first('//a[' . HtmlDocument::hasClass('action-edit') . ']')?->textContent ?? ''));

        $home = $this->assertHtmlPage($viewer->get('Home_Page.php'));
        self::assertSame(self::NOTE, $home->text('//p[' . HtmlDocument::hasClass('read-only-note') . ']'));
        foreach (['Explore_Page.php', 'Selected.php?id=7', 'cart.php'] as $page) {
            $this->assertRedirect($viewer->get($page), 'admin_dashboard.php', 303, "Viewer GET $page");
        }

        // Owners see no note, and every form enabled.
        [$owner] = $this->signedInAdmin();
        $ownerDashboard = $this->assertHtmlPage($owner->get('admin_dashboard.php'));
        self::assertSame(0, $ownerDashboard->count('//p[' . HtmlDocument::hasClass('read-only-note') . ']'));
        self::assertSame(0, $ownerDashboard->count('//form//*[@disabled]'));
        self::assertSame("Edit “{$firstTitle}”", HtmlDocument::normalize($ownerDashboard->first('//a[' . HtmlDocument::hasClass('action-edit') . ']')?->textContent ?? ''));

        // The refusal is translated too.
        $viewer->follow($viewer->get('admin_dashboard.php?lang=ar'));
        $arabic = $viewer->postWithToken('admin_dashboard.php', ['action' => 'delete_book', 'id' => '7']);
        $this->assertHtmlPage($arabic, 403);
        $this->assertPageText('سجّلت الدخول بصفتك مشرفًا تجريبيًا للقراءة فقط، لذا لا يمكن تغيير أي شيء.', $arabic);
        self::assertNotNull(find_book(7));
        self::assertSame(ADMIN_ROLE_VIEWER, db_value('SELECT role FROM admins WHERE Eaddress = ?', [$row['Eaddress']]));
    }
}
