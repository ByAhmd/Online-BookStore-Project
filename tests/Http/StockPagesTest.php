<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;
use BookWise\Tests\Support\StockFixtures;

/**
 * Stock as customers see it: availability on cards and the book page, the
 * quantity selector, capped adds and updates, and a cart that cannot be
 * checked out while a line asks for more copies than are left.
 */
final class StockPagesTest extends HttpTestCase
{
    use StockFixtures;

    private const BOOK = 13;        // The Mirror of Destiny, 23.00
    private const ESABELLA = 51;    // 19.99

    private static function availability(HttpResponse $page): string
    {
        return $page->document()->text('//*[@id="stock-status"]');
    }

    /**
     * @return list<string>
     */
    private static function quantityOptions(HttpResponse $page): array
    {
        return $page->document()->texts('//select[@id="quantity"]/option');
    }

    public function testTheBookPageShowsAvailabilityAndOffersOnlyWhatIsInStock(): void
    {
        [$customer] = $this->signedInCustomer();

        $this->setStock(self::BOOK, 50);
        $page = $this->bookPage($customer, self::BOOK);
        self::assertSame('In stock', self::availability($page));
        self::assertSame(array_map('strval', range(1, CART_MAX_QUANTITY)), self::quantityOptions($page));

        $this->setStock(self::BOOK, 3);
        $page = $this->bookPage($customer, self::BOOK);
        self::assertSame('Only 3 left', self::availability($page));
        self::assertSame(['1', '2', '3'], self::quantityOptions($page));
        self::assertTrue($page->document()->hasForm('Selected.php?id=' . self::BOOK, ['action' => 'add_to_cart']));

        $this->setStock(self::BOOK, 1);
        $page = $this->bookPage($customer, self::BOOK);
        self::assertSame('Only 1 left', self::availability($page));
        self::assertSame(['1'], self::quantityOptions($page));

        $this->setStock(self::BOOK, 0);
        $page = $this->bookPage($customer, self::BOOK);
        $document = $this->assertHtmlPage($page);
        self::assertSame('Out of stock', self::availability($page));
        self::assertTrue($document->first('//select[@id="quantity"]')?->hasAttribute('disabled'));
        self::assertTrue($document->first('//button[' . HtmlDocument::hasClass('cart-button') . ']')?->hasAttribute('disabled'));
        self::assertSame('This book is out of stock right now. Please check back later.', $document->text('//*[@id="stock-note"]'));
        self::assertSame('stock-note', $document->first('//select[@id="quantity"]')?->getAttribute('aria-describedby'));
    }

    public function testAddingMoreThanIsInStockIsCapped(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];
        $title = (string) find_book(self::BOOK)['title'];
        $this->setStock(self::BOOK, 4);

        $this->assertFlash($customer->follow($this->addToCart($customer, self::BOOK, 3)), 'success', "Added 3 × $title to your cart.");
        // The page no longer offers 3 more, but a crafted request is capped all the same.
        $response = $customer->postWithToken('Selected.php?id=' . self::BOOK, ['action' => 'add_to_cart', 'quantity' => '3']);
        $this->assertRedirect($response, 'cart.php');
        $this->assertFlash($customer->follow($response), 'success', "Added 1 × $title to your cart. (Only 4 copies are available.)");
        self::assertSame([self::BOOK => 4], $this->cartQuantities($userId));

        $response = $customer->postWithToken('Selected.php?id=' . self::BOOK, ['action' => 'add_to_cart', 'quantity' => '1']);
        $this->assertRedirect($response, 'cart.php');
        $this->assertFlash($customer->follow($response), 'info', "Your cart already holds all 4 available copies of “{$title}”.");
        self::assertSame([self::BOOK => 4], $this->cartQuantities($userId));
    }

    public function testASoldOutBookCannotBeAdded(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $title = (string) find_book(self::BOOK)['title'];
        $this->setStock(self::BOOK, 0);

        $response = $customer->postWithToken('Selected.php?id=' . self::BOOK, ['action' => 'add_to_cart', 'quantity' => '1']);
        $this->assertRedirect($response, 'Selected.php?id=' . self::BOOK . '#purchase');
        $this->assertFlash($customer->follow($response), 'error', "Sorry, “{$title}” is out of stock.");
        self::assertSame([], $this->cartQuantities((int) $user['id']));
    }

    public function testTheCartBlocksCheckoutUntilEveryLineFitsTheStock(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];
        $this->assertRedirect($this->addToCart($customer, self::ESABELLA, 5), 'cart.php');
        $this->assertRedirect($this->addToCart($customer, 7, 1), 'cart.php');
        $this->setStock(self::ESABELLA, 2); // sold to someone else meanwhile
        $line = $this->cartLineId($userId, self::ESABELLA);

        $cart = $customer->get('cart.php');
        $document = $this->assertHtmlPage($cart);
        self::assertSame(
            'Some books in your cart are no longer available in the quantity you chose. Update or remove them to check out.',
            $document->text('//*[@id="cart-stock-alert"]')
        );
        self::assertSame('Only 2 copies are left. Lower the quantity to check out.', $document->text('//*[@id="stock-problem-' . $line . '"]'));
        $input = $document->first('//input[@id="qty-' . $line . '"]');
        self::assertSame(['2', 'true', 'stock-problem-' . $line], [$input?->getAttribute('max'), $input?->getAttribute('aria-invalid'), $input?->getAttribute('aria-describedby')]);
        self::assertNull($document->first('//a[@href="checkout.php"]'), 'No way to the checkout page while a line is short');
        self::assertTrue($document->first('//button[' . HtmlDocument::hasClass('btn-pay') . ']')?->hasAttribute('disabled'));
        $this->assertRedirect($customer->get('checkout.php'), 'cart.php', 303, 'The checkout page sends the customer back to the cart');

        // Posting to checkout anyway is refused, naming the book; nothing is charged.
        $refused = $customer->postWithToken('checkout.php');
        $this->assertRedirect($refused, 'cart.php');
        $this->assertFlash($customer->follow($refused), 'error', 'There are not enough copies of “Esabella” in stock. Please lower the quantity or remove the book from your cart, then try again.');
        self::assertSame(0, $this->countRows('orders'));
        self::assertSame([], $this->stripe->sessions());

        // Asking for more than is left sets the quantity to what is left.
        $capped = $customer->submitForm($cart, 'update_cart.php', ['quantity' => '5'], ['cart_id' => (string) $line]);
        $this->assertRedirect($capped, 'cart.php');
        $cart = $customer->follow($capped);
        $this->assertFlash($cart, 'info', 'Only 2 copies of “Esabella” are available, so the quantity was set to 2.');
        self::assertSame([7 => 1, self::ESABELLA => 2], $this->cartQuantities($userId));
        self::assertNotNull($cart->document()->first('//a[@href="checkout.php"]'));
        $this->assertPageText('Only 2 left', $cart);

        $sessionId = $this->startCheckout($customer);
        self::assertSame(2 * 1999 + 550, (int) $this->orderBySession($sessionId)['amount_minor']);
    }

    public function testASoldOutLineCanOnlyBeRemoved(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];
        $this->assertRedirect($this->addToCart($customer, self::ESABELLA, 2), 'cart.php');
        $this->setStock(self::ESABELLA, 0);
        $line = $this->cartLineId($userId, self::ESABELLA);

        $cart = $customer->get('cart.php');
        $document = $this->assertHtmlPage($cart);
        self::assertSame('This book is out of stock. Remove it to check out.', $document->text('//*[@id="stock-problem-' . $line . '"]'));
        self::assertFalse($document->hasForm('update_cart.php', ['cart_id' => (string) $line]), 'No quantity form for a sold-out book');
        self::assertNull($document->first('//a[@href="checkout.php"]'));

        $update = $customer->postWithToken('update_cart.php', ['cart_id' => (string) $line, 'quantity' => '1']);
        $this->assertFlash($customer->follow($update), 'error', '“Esabella” is out of stock. Please remove it from your cart.');
        self::assertSame([self::ESABELLA => 2], $this->cartQuantities($userId));

        $removed = $customer->submitForm($customer->get('cart.php'), 'delete_from_cart.php', [], ['cart_id' => (string) $line]);
        $this->assertFlash($customer->follow($removed), 'success', 'Removed “Esabella” from your cart.');
        self::assertSame([], $this->cartQuantities($userId));
    }

    public function testCardsShowAvailability(): void
    {
        // The newest books lead "Books Loved by Readers" while nothing has sold.
        $this->setStock(61, 0);
        $this->setStock(60, 2);
        $this->setStock(59, 40);
        $badge = static fn (HttpResponse $page, int $id, string $linkClass): string => $page->document()->text(
            '(//a[' . HtmlDocument::hasClass($linkClass) . '][@href="Selected.php?id=' . $id . '"])[1]//*[' . HtmlDocument::hasClass('stock-badge') . ']'
        );

        [$customer] = $this->signedInCustomer();
        $home = $customer->get('Home_Page.php');
        $this->assertHtmlPage($home);
        self::assertSame(['Out of stock', 'Only 2 left', 'In stock'], [$badge($home, 61, 'home-book-link'), $badge($home, 60, 'home-book-link'), $badge($home, 59, 'home-book-link')]);

        $explore = $customer->get('Explore_Page.php?genre=Romantic');
        self::assertSame(['Out of stock', 'Only 2 left', 'In stock'], [$badge($explore, 61, 'explore-card-link'), $badge($explore, 60, 'explore-card-link'), $badge($explore, 59, 'explore-card-link')]);
        self::assertSame(1, $explore->document()->count('//*[' . HtmlDocument::hasClass('stock-out') . ']'));

        $json = array_column($customer->get('fetch_books.php?genre=Romantic')->json()['books'], null, 'id');
        self::assertSame(['out', 'Out of stock'], [$json[61]['stock_status'], $json[61]['availability']]);
        self::assertSame(['low', 'Only 2 left'], [$json[60]['stock_status'], $json[60]['availability']]);
        self::assertSame(['in', 'In stock'], [$json[59]['stock_status'], $json[59]['availability']]);
        self::assertArrayNotHasKey('stock', $json[59], 'Exact stock levels are not published');
    }

    public function testAPurchaseTakesTheCopiesOutOfStock(): void
    {
        [$customer] = $this->signedInCustomer();
        $before = $this->stockOf(self::ESABELLA);

        $this->purchase($customer, [self::ESABELLA => 3]);

        self::assertSame($before - 3, $this->stockOf(self::ESABELLA));
        [$admin] = $this->signedInAdmin();
        $row = '//table[' . HtmlDocument::hasClass('books-table') . ']//tr[th//span[normalize-space()="Esabella"]]';
        self::assertSame((string) ($before - 3), $admin->get('admin_dashboard.php?page=1')->document()->text($row . '/td[' . HtmlDocument::hasClass('stock-cell') . ']'));
    }
}
