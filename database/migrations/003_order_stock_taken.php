<?php

declare(strict_types=1);

/**
 * 003 — order_items.stock_taken: how many copies each order line took out of
 * stock when it was paid. It is less than the quantity when the book was
 * oversold, and a refund puts back exactly this many copies.
 */
return static function (PDO $pdo): void {
    if (!db_column_exists('order_items', 'stock_taken')) {
        $pdo->exec(
            "ALTER TABLE order_items ADD COLUMN stock_taken INT NOT NULL DEFAULT 0
             COMMENT 'Copies this line took out of stock when the order was paid' AFTER quantity"
        );
        // Orders paid before this migration: assume every copy came out of stock.
        $pdo->exec(
            "UPDATE order_items oi
             JOIN orders o ON o.id = oi.order_id
             SET oi.stock_taken = oi.quantity
             WHERE o.status = 'paid'"
        );
    }
};
