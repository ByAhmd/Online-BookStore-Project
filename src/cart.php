<?php

declare(strict_types=1);

/** Maximum copies of a single title per cart line. */
const CART_MAX_QUANTITY = 10;

/**
 * Cart lines with current catalog prices (never client-supplied values).
 *
 * @return list<array{cart_id: int, book_id: int, title: string, author: string, cover_image: string, price: string, quantity: int, unit_minor: int, line_minor: int}>
 */
function cart_lines(int $userId): array
{
    $rows = db_all(
        'SELECT c.id AS cart_id, b.id AS book_id, b.title, b.author, b.cover_image, b.price, c.quantity
         FROM cart c
         JOIN books b ON b.id = c.book_id
         WHERE c.user_id = ?
         ORDER BY c.created_at, c.id',
        [$userId]
    );

    $lines = [];
    foreach ($rows as $row) {
        $unit = money_to_minor((string) $row['price']);
        $quantity = (int) $row['quantity'];
        $lines[] = [
            'cart_id' => (int) $row['cart_id'],
            'book_id' => (int) $row['book_id'],
            'title' => (string) $row['title'],
            'author' => (string) $row['author'],
            'cover_image' => (string) $row['cover_image'],
            'price' => (string) $row['price'],
            'quantity' => $quantity,
            'unit_minor' => $unit,
            'line_minor' => $unit * $quantity,
        ];
    }
    return $lines;
}

/**
 * @param list<array{line_minor: int}> $lines
 */
function cart_total_minor(array $lines): int
{
    return array_sum(array_column($lines, 'line_minor'));
}

/**
 * Total number of copies in the cart (for the navigation badge).
 */
function cart_item_count(int $userId): int
{
    return (int) db_value('SELECT COALESCE(SUM(quantity), 0) FROM cart WHERE user_id = ?', [$userId]);
}

/**
 * Adds copies of a book, capping the line at CART_MAX_QUANTITY.
 * Returns false if the book does not exist.
 */
function cart_add(int $userId, int $bookId, int $quantity): bool
{
    $quantity = max(1, min($quantity, CART_MAX_QUANTITY));
    if (find_book($bookId) === null) {
        return false;
    }
    db_run(
        'INSERT INTO cart (user_id, book_id, quantity) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + ?, ?)',
        [$userId, $bookId, $quantity, $quantity, CART_MAX_QUANTITY]
    );
    return true;
}

/**
 * Sets the quantity of one of the user's own cart lines.
 * Returns false if the line does not belong to the user.
 */
function cart_set_quantity(int $userId, int $cartId, int $quantity): bool
{
    if ($quantity < 1 || $quantity > CART_MAX_QUANTITY) {
        return false;
    }
    $owned = db_value('SELECT 1 FROM cart WHERE id = ? AND user_id = ?', [$cartId, $userId]);
    if ($owned === null) {
        return false;
    }
    db_run('UPDATE cart SET quantity = ? WHERE id = ? AND user_id = ?', [$quantity, $cartId, $userId]);
    return true;
}

/**
 * Removes one of the user's own cart lines.
 */
function cart_remove(int $userId, int $cartId): bool
{
    return db_run('DELETE FROM cart WHERE id = ? AND user_id = ?', [$cartId, $userId])->rowCount() > 0;
}
