<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;

/**
 * The admin dashboard: add, edit and delete books, recent orders, and that
 * admin-entered text is escaped everywhere it is shown.
 */
final class AdminTest extends HttpTestCase
{
    /**
     * @return array<string, string>
     */
    private static function validBook(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'The Testing Chronicles',
            'author' => 'Ada Tester',
            'genre' => 'Mystery',
            'description' => "A book about tests.\nSecond paragraph.",
            'price' => '24.5',
            'stock' => '12',
            'cover_image' => 'imges/All_section/dark.png',
        ];
    }

    /**
     * @param array<string, string> $values
     */
    private function addBook(HttpClient $admin, array $values): HttpResponse
    {
        $dashboard = $admin->get('admin_dashboard.php');
        $this->assertStatus(200, $dashboard);
        return $admin->submitForm($dashboard, 'admin_dashboard.php', $values, ['action' => 'add_book']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function catalog(): array
    {
        return db_all('SELECT id, title, author, genre, description, price, cover_image FROM books ORDER BY id');
    }

    public function testAddBook(): void
    {
        [$admin] = $this->signedInAdmin();
        $before = $this->countRows('books');

        $response = $this->addBook($admin, self::validBook());
        $this->assertRedirect($response, 'admin_dashboard.php');
        $dashboard = $admin->follow($response);
        $this->assertHtmlPage($dashboard);
        $this->assertFlash($dashboard, 'success', '“The Testing Chronicles” was added.');

        $book = db_one('SELECT * FROM books WHERE title = ?', ['The Testing Chronicles']);
        self::assertNotNull($book);
        self::assertSame(['Ada Tester', 'Mystery', '24.50', 'imges/All_section/dark.png'], [$book['author'], $book['genre'], $book['price'], $book['cover_image']]);
        self::assertSame("A book about tests.\nSecond paragraph.", $book['description']);
        self::assertSame($before + 1, $this->countRows('books'));
        self::assertSame(['The Testing Chronicles'], $dashboard->document()->texts('//table[' . HtmlDocument::hasClass('books-table') . ']//tbody/tr[1]/th'));

        // https covers are accepted too; the new book is live in the store.
        $this->assertRedirect($this->addBook($admin, self::validBook(['title' => 'Remote Cover', 'cover_image' => 'https://images.example.com/cover.png'])), 'admin_dashboard.php');
        [$customer] = $this->signedInCustomer();
        $page = $this->bookPage($customer, (int) $book['id']);
        $this->assertHtmlPage($page);
        self::assertSame('The Testing Chronicles', $page->document()->text('//h1'));
        $this->assertPageText('SAR 24.50', $page);
    }

    /**
     * A flash message is written in the page's language and takes the page's
     * direction, also when it starts with a title in the other script: an
     * Arabic title must not turn an English sentence right to left (as
     * dir="auto" on the paragraph did, which put the full stop first). The
     * title is isolated in a <bdi> element instead.
     */
    public function testAFlashMessageIsolatesTheTitleItQuotes(): void
    {
        [$admin] = $this->signedInAdmin();
        $dashboard = $admin->follow($this->addBook($admin, self::validBook(['title' => 'ألف ليلة وليلة'])));
        $this->assertFlash($dashboard, 'success', '“ألف ليلة وليلة” was added.');

        $flash = '//div[' . HtmlDocument::hasClass('flash-success') . ']/p';
        $paragraph = $dashboard->document()->first($flash);
        self::assertNotNull($paragraph);
        self::assertFalse($paragraph->hasAttribute('dir'), 'The message takes the direction of the page');
        self::assertSame(['ألف ليلة وليلة'], $dashboard->document()->texts($flash . '/bdi'));
    }

    public function testInvalidBookShowsFieldErrorsAndAddsNothing(): void
    {
        [$admin] = $this->signedInAdmin();
        $catalog = self::catalog();

        $cases = [
            [['price' => 'abc'], 'price', 'Price must be a number with at most two decimals.'],
            [['price' => '12.345'], 'price', 'Price must be a number with at most two decimals.'],
            [['price' => '-5'], 'price', 'Price must be a number with at most two decimals.'],
            [['price' => '0.50'], 'price', 'Price must be between 1.00 and 10000.00.'],
            [['price' => '10000.01'], 'price', 'Price must be between 1.00 and 10000.00.'],
            [['cover_image' => 'http://images.example.com/cover.png'], 'cover_image', 'Cover image URLs must start with https:// and be valid.'],
            [['cover_image' => 'javascript:alert(1)'], 'cover_image', 'Cover image URLs must start with https:// and be valid.'],
            [['cover_image' => '../../src/env.php'], 'cover_image', 'Cover image must be a path like "imges/All_section/book.png" or an https:// URL.'],
            [['cover_image' => 'imges/All_section/missing.png'], 'cover_image', 'No image exists at "imges/All_section/missing.png" (paths are case-sensitive).'],
            [['cover_image' => ''], 'cover_image', 'Cover image is required.'],
            [['title' => ''], 'title', 'Title is required.'],
            [['genre' => 'All'], 'genre', '"All" is reserved. Please choose a different Genre name.'],
        ];
        foreach ($cases as [$override, $field, $message]) {
            $response = $this->addBook($admin, self::validBook($override));
            $this->assertRedirect($response, 'admin_dashboard.php#add-book', 303, json_encode($override));
            $dashboard = $admin->follow($response);
            $document = $this->assertHtmlPage($dashboard);
            self::assertSame($message, $document->fieldError("book-$field-error"), json_encode($override));
            self::assertSame('true', $document->first('//*[@id="book-' . ($field === 'cover_image' ? 'cover' : $field) . '"]')?->getAttribute('aria-invalid'));
            $this->assertFlash($dashboard, 'error', 'The book was not added. Please fix the highlighted fields.');
            // The other values are kept for correction.
            self::assertSame('Ada Tester', $document->first('//input[@id="book-author"]')?->getAttribute('value'));
            self::assertSame($catalog, self::catalog(), 'Nothing is stored: ' . json_encode($override));
        }
    }

    public function testEditBook(): void
    {
        [$admin] = $this->signedInAdmin();
        $form = $admin->get('edit_book.php?id=7');
        $document = $this->assertHtmlPage($form);
        $fields = $document->form('edit_book.php?id=7', ['action' => 'update_book'])->fields;
        self::assertSame(['Biology', 'anishtain', 'Science', '5.50', 'imges/All_section/biology.png'], [$fields['title'], $fields['author'], $fields['genre'], $fields['price'], $fields['cover_image']]);

        $response = $admin->submitForm($form, 'edit_book.php?id=7', ['title' => 'Biology (2nd edition)', 'price' => '7'], ['action' => 'update_book']);
        $this->assertRedirect($response, 'admin_dashboard.php#manage-books');
        $this->assertFlash($admin->follow($response), 'success', '“Biology (2nd edition)” was updated.');
        $book = find_book(7);
        self::assertSame(['Biology (2nd edition)', '7.00', 'anishtain'], [$book['title'], $book['price'], $book['author']]);

        // Invalid edits re-render the form with 422 and change nothing.
        $form = $admin->get('edit_book.php?id=7');
        $invalid = $admin->submitForm($form, 'edit_book.php?id=7', ['price' => 'free', 'cover_image' => 'http://x.example/c.png'], ['action' => 'update_book']);
        $document = $this->assertHtmlPage($invalid, 422);
        self::assertSame('Price must be a number with at most two decimals.', $document->fieldError('book-price-error'));
        self::assertSame('Cover image URLs must start with https:// and be valid.', $document->fieldError('book-cover_image-error'));
        self::assertSame('free', $document->first('//input[@id="book-price"]')?->getAttribute('value'));
        $this->assertFlash($invalid, 'error', 'The changes were not saved. Please fix the highlighted fields.');
        self::assertSame($book, find_book(7));

        $this->assertHtmlPage($admin->postWithToken('edit_book.php?id=7', ['action' => 'something_else']), 400);
        $this->assertHtmlPage($admin->postWithToken('edit_book.php?id=999999', ['action' => 'update_book'] + self::validBook()), 404);
        self::assertSame($book, find_book(7));
    }

    public function testDeleteOnlyByPost(): void
    {
        [$admin] = $this->signedInAdmin();
        $id = $this->insertBook(['title' => 'Doomed Book']);

        // Links with ?delete= (as in the original project) do nothing.
        foreach (["admin_dashboard.php?delete=$id", "admin_dashboard.php?action=delete_book&id=$id", "edit_book.php?id=$id&delete=1"] as $url) {
            $this->assertStatus(200, $admin->get($url));
            self::assertNotNull(find_book($id), "GET $url must not delete");
        }

        $dashboard = $admin->get('admin_dashboard.php');
        $response = $admin->submitForm($dashboard, 'admin_dashboard.php', [], ['action' => 'delete_book', 'id' => (string) $id]);
        $this->assertRedirect($response, 'admin_dashboard.php#manage-books');
        $this->assertFlash($admin->follow($response), 'success', '“Doomed Book” was deleted.');
        self::assertNull(find_book($id));

        $again = $admin->postWithToken('admin_dashboard.php', ['action' => 'delete_book', 'id' => (string) $id]);
        $this->assertFlash($admin->follow($again), 'error', 'That book could not be found. It may already have been deleted.');
        $this->assertHtmlPage($admin->postWithToken('admin_dashboard.php', ['action' => 'delete_book', 'id' => 'abc']), 404);
        $this->assertHtmlPage($admin->postWithToken('admin_dashboard.php', ['action' => 'drop_table']), 400);
        $this->assertHtmlPage($admin->get("edit_book.php?id=$id"), 404);
    }

    public function testRecentOrdersShowThePaidOrder(): void
    {
        [$admin] = $this->signedInAdmin();
        $empty = $admin->get('admin_dashboard.php');
        $this->assertPageText('No orders yet.', $empty);

        [$customer, $user] = $this->signedInCustomer('Order Owner');
        $purchase = $this->purchase($customer, [51 => 2]);
        $orderId = (int) $purchase['order']['id'];

        $dashboard = $admin->get('admin_dashboard.php');
        $document = $this->assertHtmlPage($dashboard);
        $row = '//table[' . HtmlDocument::hasClass('orders-table') . ']//tbody/tr[th[normalize-space()="#' . $orderId . '"]]';
        self::assertSame(1, $document->count($row));
        self::assertSame('Order Owner', $document->text($row . '//span[@class="customer-name"]'));
        self::assertSame((string) $user['Eaddress'], $document->text($row . '//span[@class="customer-email"]'));
        self::assertSame(['#' . $orderId, 'Order Owner ' . $user['Eaddress'], '2', 'SAR 39.98', 'Paid'], array_slice($document->texts($row . '/*'), 0, 5));
        self::assertSame(['Books', '55', 'Genres', '8', 'Paid orders', '1', 'Revenue', 'SAR 39.98'], $document->texts('//section[' . HtmlDocument::hasClass('admin-stats') . ']//p'));

        // Stored orders keep their own currency; revenue is never summed across currencies.
        db_run('UPDATE orders SET currency = ? WHERE id = ?', ['usd', $orderId]);
        $this->purchase($customer, [51 => 1]);
        $document = $this->assertHtmlPage($admin->get('admin_dashboard.php'));
        self::assertSame(['2', 'USD 39.98', 'Paid'], array_slice($document->texts($row . '/*'), 2, 3));
        self::assertSame(['Books', '55', 'Genres', '8', 'Paid orders', '2', 'Revenue', 'SAR 19.99 USD 39.98'], $document->texts('//section[' . HtmlDocument::hasClass('admin-stats') . ']//p'));
        self::assertSame(['SAR 19.99', 'USD 39.98'], $document->texts('//span[' . HtmlDocument::hasClass('stat-figure') . ']'));
    }

    public function testDeletingAPurchasedBookKeepsTheOrderHistory(): void
    {
        [$customer] = $this->signedInCustomer('History Keeper');
        $purchase = $this->purchase($customer, [51 => 2]);
        $orderId = (int) $purchase['order']['id'];

        [$admin] = $this->signedInAdmin();
        $response = $admin->submitForm($admin->get('admin_dashboard.php'), 'admin_dashboard.php', [], ['action' => 'delete_book', 'id' => '51']);
        $this->assertRedirect($response, 'admin_dashboard.php#manage-books');
        self::assertNull(find_book(51));

        $orders = $customer->get('profilePage.php?section=orders');
        $document = $this->assertHtmlPage($orders);
        self::assertSame(['Esabella × 2'], $document->texts('//ul[@class="order-items"]/li'));
        self::assertSame(0, $document->count('//ul[@class="order-items"]//a'), 'No link to the deleted book');

        $success = $customer->get('success_pay.php?session_id=' . $purchase['session']);
        $this->assertHtmlPage($success);
        self::assertSame('Thank you! Your payment was received.', $success->document()->text('//h1'));
        $this->assertPageText('Esabella', $success);
        $this->assertPageText('Total paid SAR 39.98', $success);

        $row = '//table[' . HtmlDocument::hasClass('orders-table') . ']//tbody/tr[th[normalize-space()="#' . $orderId . '"]]';
        $cells = $admin->get('admin_dashboard.php')->document()->texts($row . '/*');
        self::assertCount(7, $cells, 'The admin still sees the order');
        self::assertSame('#' . $orderId, $cells[0]);
        self::assertStringStartsWith('History Keeper ', $cells[1]);
        self::assertSame(['2', 'SAR 39.98', 'Paid'], array_slice($cells, 2, 3));
    }

    public function testBookTextIsEscapedEverywhere(): void
    {
        [$admin] = $this->signedInAdmin();
        $marker = bin2hex(random_bytes(4));
        $title = '<script>alert("xss-' . $marker . '")</script><img src=x onerror=alert(1)>';
        $author = '"><svg onload=alert(2)>';
        $description = "<iframe src=javascript:alert(3)></iframe>\n' onmouseover='alert(4)";

        $this->assertRedirect($this->addBook($admin, self::validBook(['title' => $title, 'author' => $author, 'description' => $description])), 'admin_dashboard.php');
        $id = (int) db_value('SELECT id FROM books WHERE title = ?', [$title]);
        self::assertGreaterThan(0, $id, 'The title is stored verbatim');

        [$customer] = $this->signedInCustomer();
        $this->assertRedirect($this->addToCart($customer, $id, 1), 'cart.php');
        db_run('INSERT INTO favorites (user_id, book_id) SELECT id, ? FROM users', [$id]);

        $pages = [
            'admin dashboard' => $admin->get('admin_dashboard.php'),
            'admin edit page' => $admin->get('edit_book.php?id=' . $id),
            'book page' => $customer->get('Selected.php?id=' . $id),
            'explore page' => $customer->get('Explore_Page.php?genre=Mystery'),
            'explore search' => $customer->get('Explore_Page.php?q=' . rawurlencode('xss-' . $marker)),
            'favorites tab' => $customer->get('Explore_Page.php?genre=Favorites'),
            'cart' => $customer->get('cart.php'),
            'home page' => $customer->get('Home_Page.php'),
        ];
        foreach ($pages as $label => $page) {
            $document = $this->assertHtmlPage($page); // no inline script, no on* handler, no javascript: URL
            self::assertStringNotContainsString('<script>alert("xss-' . $marker, $page->body, "Raw payload on the $label");
            self::assertStringNotContainsString('<svg onload', $page->body, "Raw payload on the $label");
            self::assertStringNotContainsString('<iframe', $page->body, "Raw payload on the $label");
            self::assertStringContainsString('xss-' . $marker, $document->text() . implode('', array_map(
                static fn ($element): string => $element->getAttribute('value') . $element->getAttribute('data-confirm'),
                $document->elements('//input | //form')
            )), "The $label shows the title as text");
            self::assertSame(0, $document->count('//svg | //iframe | //img[@src="x"]'), "Injected elements on the $label");
        }
        self::assertSame($title, $pages['book page']->document()->text('//h1'));
        self::assertStringContainsString('&lt;script&gt;alert(&quot;xss-' . $marker . '&quot;)&lt;/script&gt;', $pages['admin dashboard']->body);

        // The JSON API returns the raw title, safely typed as JSON.
        $json = $customer->get('fetch_books.php?q=' . rawurlencode('xss-' . $marker));
        self::assertStringStartsWith('application/json', $json->contentType());
        self::assertSame([$title], array_column($json->json()['books'], 'title'));
    }
}
