<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\DatabaseTestCase;
use BookWise\Tests\Support\StockFixtures;

final class CartTest extends DatabaseTestCase
{
    use StockFixtures;

    private const BIOLOGY = 7;      // 5.50, 24 in stock
    private const ESABELLA = 51;    // 19.99, 24 in stock
    private const FORBIDDEN = 58;   // 4.99, 17 in stock

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int) $this->createUser()['id'];
    }

    public function testAddCreatesALineWithCatalogPrices(): void
    {
        self::assertTrue(cart_add($this->userId, self::ESABELLA, 2));

        self::assertSame([[
            'cart_id' => (int) db_value('SELECT id FROM cart'),
            'book_id' => self::ESABELLA,
            'title' => 'Esabella',
            'author' => 'Olivia Wilson',
            'cover_image' => 'imges/Romantic_Section/6.png',
            'price' => '19.99',
            'quantity' => 2,
            'unit_minor' => 1999,
            'line_minor' => 3998,
            'stock' => 24,
            'limit' => CART_MAX_QUANTITY,
            'available' => true,
        ]], cart_lines($this->userId));
    }

    public function testAddingTheSameBookMergesAndCapsAtTen(): void
    {
        cart_add($this->userId, self::BIOLOGY, 3);
        cart_add($this->userId, self::BIOLOGY, 4);
        self::assertSame(1, $this->countRows('cart'));
        self::assertSame(7, cart_lines($this->userId)[0]['quantity']);

        cart_add($this->userId, self::BIOLOGY, 5);
        self::assertSame(CART_MAX_QUANTITY, cart_lines($this->userId)[0]['quantity']);

        cart_add($this->userId, self::BIOLOGY, 1);
        self::assertSame(CART_MAX_QUANTITY, cart_lines($this->userId)[0]['quantity']);
    }

    public function testAddClampsTheRequestedQuantity(): void
    {
        cart_add($this->userId, self::BIOLOGY, 50);
        cart_add($this->userId, self::ESABELLA, 0);
        cart_add($this->userId, self::FORBIDDEN, -3);

        self::assertSame([self::BIOLOGY => 10, self::ESABELLA => 1, self::FORBIDDEN => 1], array_column(cart_lines($this->userId), 'quantity', 'book_id'));
    }

    public function testAddingAMissingBookFails(): void
    {
        self::assertFalse(cart_add($this->userId, 999999, 1));
        self::assertSame(0, $this->countRows('cart'));
    }

    public function testSetQuantityBounds(): void
    {
        cart_add($this->userId, self::BIOLOGY, 2);
        $cartId = cart_lines($this->userId)[0]['cart_id'];

        self::assertFalse(cart_set_quantity($this->userId, $cartId, 0));
        self::assertFalse(cart_set_quantity($this->userId, $cartId, -1));
        self::assertFalse(cart_set_quantity($this->userId, $cartId, CART_MAX_QUANTITY + 1));
        self::assertSame(2, cart_lines($this->userId)[0]['quantity'], 'Rejected values change nothing');

        self::assertTrue(cart_set_quantity($this->userId, $cartId, 1));
        self::assertSame(1, cart_lines($this->userId)[0]['quantity']);
        self::assertTrue(cart_set_quantity($this->userId, $cartId, CART_MAX_QUANTITY));
        self::assertSame(CART_MAX_QUANTITY, cart_lines($this->userId)[0]['quantity']);
    }

    public function testSetQuantityEnforcesOwnership(): void
    {
        $intruder = (int) $this->createUser()['id'];
        cart_add($this->userId, self::BIOLOGY, 2);
        $cartId = cart_lines($this->userId)[0]['cart_id'];

        self::assertFalse(cart_set_quantity($intruder, $cartId, 9));
        self::assertFalse(cart_set_quantity($this->userId, $cartId + 1000, 3));
        self::assertSame(2, cart_lines($this->userId)[0]['quantity']);
    }

    public function testRemoveEnforcesOwnership(): void
    {
        $intruder = (int) $this->createUser()['id'];
        cart_add($this->userId, self::BIOLOGY, 2);
        $cartId = cart_lines($this->userId)[0]['cart_id'];

        self::assertFalse(cart_remove($intruder, $cartId));
        self::assertSame(1, $this->countRows('cart'));

        self::assertTrue(cart_remove($this->userId, $cartId));
        self::assertSame([], cart_lines($this->userId));
        self::assertFalse(cart_remove($this->userId, $cartId), 'Already removed');
    }

    public function testCartsAreIsolatedPerUser(): void
    {
        $other = (int) $this->createUser()['id'];
        cart_add($this->userId, self::BIOLOGY, 2);
        cart_add($other, self::ESABELLA, 1);

        self::assertSame([self::BIOLOGY], array_column(cart_lines($this->userId), 'book_id'));
        self::assertSame([self::ESABELLA], array_column(cart_lines($other), 'book_id'));
        self::assertSame(2, cart_item_count($this->userId));
        self::assertSame(0, cart_item_count((int) $this->createUser()['id']));
    }

    public function testTotalsAreExactIntegerMinorUnits(): void
    {
        $tenCents = $this->insertStockedBook(['price' => '0.10']);
        $twentyCents = $this->insertStockedBook(['price' => '0.20']);
        $oneFifteen = $this->insertStockedBook(['price' => '1.15']);
        $fourThirtyFive = $this->insertStockedBook(['price' => '4.35']);
        cart_add($this->userId, $tenCents, 3);       // 0.1 * 3 = 0.30000000000000004 in floats
        cart_add($this->userId, $twentyCents, 1);
        cart_add($this->userId, $oneFifteen, 1);     // 1.15 * 100 = 114.99999999999999
        cart_add($this->userId, $fourThirtyFive, 1); // 4.35 * 100 = 434.99999999999994
        cart_add($this->userId, self::ESABELLA, 3);  // 19.99 * 3 = 59.97

        $lines = cart_lines($this->userId);
        $byBook = array_column($lines, null, 'book_id');

        self::assertSame([10, 30], [$byBook[$tenCents]['unit_minor'], $byBook[$tenCents]['line_minor']]);
        self::assertSame(115, $byBook[$oneFifteen]['unit_minor']);
        self::assertSame(435, $byBook[$fourThirtyFive]['unit_minor']);
        self::assertSame(5997, $byBook[self::ESABELLA]['line_minor']);
        self::assertSame(30 + 20 + 115 + 435 + 5997, cart_total_minor($lines));
        self::assertSame('65.97', minor_to_decimal(cart_total_minor($lines)));
        self::assertSame(9, cart_item_count($this->userId));
        foreach ($lines as $line) {
            self::assertIsInt($line['unit_minor']);
            self::assertIsInt($line['line_minor']);
        }
    }

    public function testEmptyCart(): void
    {
        self::assertSame([], cart_lines($this->userId));
        self::assertSame(0, cart_total_minor([]));
        self::assertSame(0, cart_item_count($this->userId));
    }

    public function testCartReflectsCurrentCatalogPrices(): void
    {
        cart_add($this->userId, self::BIOLOGY, 2);
        db_run("UPDATE books SET price = '6.25' WHERE id = ?", [self::BIOLOGY]);

        self::assertSame(1250, cart_total_minor(cart_lines($this->userId)));
    }

    /* -----------------------------------------------------------------
     * Stock
     * --------------------------------------------------------------- */

    public function testTheCartLimitIsTheLowerOfStockAndTheTitleLimit(): void
    {
        self::assertSame(0, cart_quantity_limit(0));
        self::assertSame(0, cart_quantity_limit(-3));
        self::assertSame(3, cart_quantity_limit(3));
        self::assertSame(CART_MAX_QUANTITY, cart_quantity_limit(CART_MAX_QUANTITY));
        self::assertSame(CART_MAX_QUANTITY, cart_quantity_limit(500));
    }

    public function testAddNeverGoesBeyondTheStock(): void
    {
        $this->setStock(self::BIOLOGY, 4);

        self::assertSame(['status' => 'added', 'added' => 3, 'quantity' => 3, 'limit' => 4], cart_add_detailed($this->userId, self::BIOLOGY, 3));
        self::assertSame(['status' => 'capped', 'added' => 1, 'quantity' => 4, 'limit' => 4], cart_add_detailed($this->userId, self::BIOLOGY, 5));
        self::assertSame(['status' => 'at_limit', 'added' => 0, 'quantity' => 4, 'limit' => 4], cart_add_detailed($this->userId, self::BIOLOGY, 1));
        self::assertTrue(cart_add($this->userId, self::BIOLOGY, 1), 'The book is in the cart, at its limit');
        self::assertSame([self::BIOLOGY => 4], array_column(cart_lines($this->userId), 'quantity', 'book_id'));
    }

    public function testTheTitleLimitStillAppliesWhenThereIsPlentyOfStock(): void
    {
        $this->setStock(self::BIOLOGY, 500);

        self::assertSame(['status' => 'added', 'added' => 6, 'quantity' => 6, 'limit' => CART_MAX_QUANTITY], cart_add_detailed($this->userId, self::BIOLOGY, 6));
        self::assertSame(['status' => 'capped', 'added' => 4, 'quantity' => CART_MAX_QUANTITY, 'limit' => CART_MAX_QUANTITY], cart_add_detailed($this->userId, self::BIOLOGY, 6));
        self::assertSame('added', cart_add_detailed((int) $this->createUser()['id'], self::BIOLOGY, 50)['status'], 'Requests are clamped to 1..10 first');
    }

    public function testSoldOutBooksCannotBeAdded(): void
    {
        $this->setStock(self::ESABELLA, 0);

        self::assertSame('out_of_stock', cart_add_detailed($this->userId, self::ESABELLA, 1)['status']);
        self::assertFalse(cart_add($this->userId, self::ESABELLA, 1));
        self::assertSame(0, $this->countRows('cart'));
        self::assertSame('missing', cart_add_detailed($this->userId, 999999, 1)['status']);
    }

    public function testSetQuantityIsCappedAtTheStock(): void
    {
        cart_add($this->userId, self::BIOLOGY, 2);
        $cartId = cart_lines($this->userId)[0]['cart_id'];
        $this->setStock(self::BIOLOGY, 5);

        self::assertSame(['status' => 'capped', 'quantity' => 5, 'limit' => 5, 'title' => 'Biology'], cart_update_quantity($this->userId, $cartId, 8));
        self::assertSame(5, cart_lines($this->userId)[0]['quantity']);
        self::assertSame('updated', cart_update_quantity($this->userId, $cartId, 3)['status']);
        self::assertSame('invalid', cart_update_quantity($this->userId, $cartId, 0)['status']);
        self::assertSame('missing', cart_update_quantity((int) $this->createUser()['id'], $cartId, 1)['status'], 'Another customer cannot touch the line');
        self::assertSame(3, cart_lines($this->userId)[0]['quantity']);

        $this->setStock(self::BIOLOGY, 0);
        self::assertSame('out_of_stock', cart_update_quantity($this->userId, $cartId, 1)['status']);
        self::assertFalse(cart_set_quantity($this->userId, $cartId, 1));
        self::assertSame(3, cart_lines($this->userId)[0]['quantity'], 'Nothing changes for a sold-out book');
    }

    public function testLinesBeyondTheCurrentStockAreFlagged(): void
    {
        cart_add($this->userId, self::BIOLOGY, 6);
        cart_add($this->userId, self::ESABELLA, 2);
        $this->setStock(self::BIOLOGY, 4); // copies sold to someone else meanwhile

        $lines = array_column(cart_lines($this->userId), null, 'book_id');
        self::assertFalse($lines[self::BIOLOGY]['available']);
        self::assertSame(4, $lines[self::BIOLOGY]['limit']);
        self::assertTrue($lines[self::ESABELLA]['available']);
        self::assertSame([self::BIOLOGY], array_column(cart_unavailable_lines(cart_lines($this->userId)), 'book_id'));

        // Adding more never makes it worse, and never silently lowers the line either.
        self::assertSame('at_limit', cart_add_detailed($this->userId, self::BIOLOGY, 1)['status']);
        self::assertSame(6, array_column(cart_lines($this->userId), 'quantity', 'book_id')[self::BIOLOGY]);
    }
}
