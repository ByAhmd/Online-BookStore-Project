<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\DatabaseTestCase;

final class CartTest extends DatabaseTestCase
{
    private const BIOLOGY = 7;      // 5.50
    private const ESABELLA = 51;    // 19.99
    private const FORBIDDEN = 58;   // 4.99

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
        $tenCents = $this->insertBook(['price' => '0.10']);
        $twentyCents = $this->insertBook(['price' => '0.20']);
        $oneFifteen = $this->insertBook(['price' => '1.15']);
        $fourThirtyFive = $this->insertBook(['price' => '4.35']);
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
}
