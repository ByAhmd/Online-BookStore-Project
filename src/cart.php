<?php

declare(strict_types=1);

/** Maximum copies of a single title per cart line. */
const CART_MAX_QUANTITY = 10;

/**
 * Most copies of a book a customer can have in the cart right now: the
 * per-title limit or the copies in stock, whichever is lower (0 = sold out).
 */
function cart_quantity_limit(int $stock): int
{
    return max(0, min(CART_MAX_QUANTITY, $stock));
}

/**
 * Cart lines with current catalog prices and stock (never client-supplied
 * values). 'available' is false when the line asks for more copies than are
 * in stock; such a cart cannot be checked out until the line is fixed.
 *
 * @return list<array{cart_id: int, book_id: int, title: string, author: string, cover_image: string, price: string, quantity: int, unit_minor: int, line_minor: int, stock: int, limit: int, available: bool}>
 */
function cart_lines(int $userId): array
{
    $rows = db_all(
        'SELECT c.id AS cart_id, b.id AS book_id, b.title, b.author, b.cover_image, b.price, b.stock, c.quantity
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
        $stock = (int) $row['stock'];
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
            'stock' => $stock,
            'limit' => cart_quantity_limit($stock),
            'available' => $quantity <= $stock,
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
 * Lines that ask for more copies than are in stock.
 *
 * @template T of array{available: bool}
 * @param list<T> $lines
 * @return list<T>
 */
function cart_unavailable_lines(array $lines): array
{
    $unavailable = [];
    foreach ($lines as $index => $line) {
        if (!$line['available']) {
            // $lines[$index] keeps the caller's line type; the check above
            // narrows $line to array{available: false} for static analysis.
            $unavailable[] = $lines[$index];
        }
    }
    return $unavailable;
}

/**
 * Total number of copies in the cart (for the navigation badge).
 */
function cart_item_count(int $userId): int
{
    return (int) db_value('SELECT COALESCE(SUM(quantity), 0) FROM cart WHERE user_id = ?', [$userId]);
}

/**
 * Adds copies of a book without ever going beyond cart_quantity_limit()
 * (the per-title limit and the copies in stock). An existing line that
 * already holds the limit (or more, after the stock went down) is left alone.
 *
 * status: 'added' (all requested copies), 'capped' (fewer, up to the limit),
 * 'at_limit' (none: the line already holds the limit), 'out_of_stock' or
 * 'missing' (no such book). 'quantity' is the line's quantity afterwards.
 *
 * @return array{status: 'added'|'capped'|'at_limit'|'out_of_stock'|'missing', added: int, quantity: int, limit: int}
 */
function cart_add_detailed(int $userId, int $bookId, int $quantity): array
{
    $quantity = max(1, min($quantity, CART_MAX_QUANTITY));

    $stock = db_value('SELECT stock FROM books WHERE id = ?', [$bookId]);
    if ($stock === null) {
        return ['status' => 'missing', 'added' => 0, 'quantity' => 0, 'limit' => 0];
    }
    $limit = cart_quantity_limit((int) $stock);
    $current = (int) (db_value('SELECT quantity FROM cart WHERE user_id = ? AND book_id = ?', [$userId, $bookId]) ?? 0);

    if ($limit === 0) {
        return ['status' => 'out_of_stock', 'added' => 0, 'quantity' => $current, 'limit' => 0];
    }
    if ($current >= $limit) {
        return ['status' => 'at_limit', 'added' => 0, 'quantity' => $current, 'limit' => $limit];
    }

    // One atomic statement that can never go beyond the limit, even when two
    // requests for the same customer and book race (the unique key turns the
    // second insert into an update). The reported counts assume no race.
    $target = min($current + $quantity, $limit);
    db_run(
        'INSERT INTO cart (user_id, book_id, quantity) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = GREATEST(quantity, LEAST(quantity + ?, ?))',
        [$userId, $bookId, $target, $quantity, $limit]
    );
    $added = $target - $current;
    return ['status' => $added < $quantity ? 'capped' : 'added', 'added' => $added, 'quantity' => $target, 'limit' => $limit];
}

/**
 * Adds copies of a book (see cart_add_detailed()). Returns false if the book
 * does not exist or is out of stock.
 */
function cart_add(int $userId, int $bookId, int $quantity): bool
{
    return in_array(cart_add_detailed($userId, $bookId, $quantity)['status'], ['added', 'capped', 'at_limit'], true);
}

/**
 * Sets the quantity of one of the customer's own cart lines, never above the
 * copies in stock.
 *
 * status: 'updated', 'capped' (set to the stock limit instead), 'invalid'
 * (outside 1..CART_MAX_QUANTITY, nothing changed), 'out_of_stock' (nothing
 * changed) or 'missing' (not the customer's line).
 *
 * @return array{status: 'updated'|'capped'|'invalid'|'out_of_stock'|'missing', quantity: int, limit: int, title: string}
 */
function cart_update_quantity(int $userId, int $cartId, int $quantity): array
{
    return db_transaction(static function () use ($userId, $cartId, $quantity): array {
        $line = db_one(
            'SELECT c.quantity, b.stock, b.title
             FROM cart c JOIN books b ON b.id = c.book_id
             WHERE c.id = ? AND c.user_id = ? FOR UPDATE',
            [$cartId, $userId]
        );
        if ($line === null) {
            return ['status' => 'missing', 'quantity' => 0, 'limit' => 0, 'title' => ''];
        }
        $current = (int) $line['quantity'];
        $limit = cart_quantity_limit((int) $line['stock']);
        $title = (string) $line['title'];

        if ($quantity < 1 || $quantity > CART_MAX_QUANTITY) {
            return ['status' => 'invalid', 'quantity' => $current, 'limit' => $limit, 'title' => $title];
        }
        if ($limit === 0) {
            return ['status' => 'out_of_stock', 'quantity' => $current, 'limit' => 0, 'title' => $title];
        }
        $target = min($quantity, $limit);
        db_run('UPDATE cart SET quantity = ? WHERE id = ? AND user_id = ?', [$target, $cartId, $userId]);
        return ['status' => $target < $quantity ? 'capped' : 'updated', 'quantity' => $target, 'limit' => $limit, 'title' => $title];
    });
}

/**
 * Sets the quantity of one of the user's own cart lines (see
 * cart_update_quantity()). Returns false if nothing could be set.
 */
function cart_set_quantity(int $userId, int $cartId, int $quantity): bool
{
    return in_array(cart_update_quantity($userId, $cartId, $quantity)['status'], ['updated', 'capped'], true);
}

/**
 * Removes one of the user's own cart lines.
 */
function cart_remove(int $userId, int $cartId): bool
{
    return db_run('DELETE FROM cart WHERE id = ? AND user_id = ?', [$cartId, $userId])->rowCount() > 0;
}
