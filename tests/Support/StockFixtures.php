<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

/**
 * Stock helpers for DatabaseTestCase subclasses. DatabaseTestCase::insertBook()
 * leaves books.stock at its column default (0, i.e. sold out), so tests that
 * put an inserted book in a cart give it copies first.
 */
trait StockFixtures
{
    /**
     * Inserts a book through insertBook() and gives it $stock copies.
     *
     * @param array<string, string> $overrides
     */
    protected function insertStockedBook(array $overrides = [], int $stock = 20): int
    {
        $id = $this->insertBook($overrides);
        $this->setStock($id, $stock);
        return $id;
    }

    protected function setStock(int $bookId, int $stock): void
    {
        db_run('UPDATE books SET stock = ? WHERE id = ?', [$stock, $bookId]);
    }

    protected function stockOf(int $bookId): int
    {
        $stock = db_value('SELECT stock FROM books WHERE id = ?', [$bookId]);
        self::assertNotNull($stock, "Book $bookId does not exist");
        return (int) $stock;
    }
}
