<?php

declare(strict_types=1);

/**
 * 002 — email verification, account language, stock tracking, refunds, and
 * orders that are kept (anonymised) when a customer deletes their account.
 * Every step checks the current schema first, so re-running is harmless.
 */
return static function (PDO $pdo): void {
    // --- users --------------------------------------------------------
    if (!db_column_exists('users', 'email_verified_at')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL DEFAULT NULL');
        // Accounts created before verification existed are trusted as they are.
        $pdo->exec('UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL');
    }
    if (!db_column_exists('users', 'locale')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN locale VARCHAR(5) NOT NULL DEFAULT 'en'");
    }

    if (!db_table_exists('email_verifications')) {
        $pdo->exec(
            "CREATE TABLE email_verifications (
                id         INT       NOT NULL AUTO_INCREMENT,
                user_id    INT       NOT NULL,
                token      CHAR(64)  NOT NULL COMMENT 'SHA-256 hex digest of the emailed token',
                expiry     DATETIME  NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_email_verifications_token (token),
                KEY idx_email_verifications_user (user_id),
                CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // --- books: stock ------------------------------------------------
    if (!db_column_exists('books', 'stock')) {
        $pdo->exec('ALTER TABLE books ADD COLUMN stock INT NOT NULL DEFAULT 0 AFTER price');
        // Existing catalogs start with 20 copies of every title; adjust per book in the admin dashboard.
        $pdo->exec('UPDATE books SET stock = 20');
    }
    if (!db_constraint_exists('books', 'chk_books_stock')) {
        $pdo->exec('ALTER TABLE books ADD CONSTRAINT chk_books_stock CHECK (stock >= 0)');
    }

    // --- orders: refunds and account deletion -------------------------
    $pdo->exec(
        "ALTER TABLE orders MODIFY status ENUM('pending', 'paid', 'canceled', 'expired', 'failed', 'refunded') NOT NULL DEFAULT 'pending'"
    );
    if (!db_column_exists('orders', 'refunded_at')) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN refunded_at DATETIME NULL DEFAULT NULL AFTER paid_at');
    }
    if (!db_column_exists('orders', 'stripe_refund_id')) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN stripe_refund_id VARCHAR(255) NULL DEFAULT NULL AFTER stripe_payment_intent');
    }
    if (!db_index_exists('orders', 'idx_orders_status_created')) {
        $pdo->exec('ALTER TABLE orders ADD KEY idx_orders_status_created (status, created_at)');
    }

    // Orders are financial records: keep them (without the customer link) when an account is deleted.
    $nullable = db_value(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'user_id'"
    );
    if ($nullable !== 'YES') {
        if (db_constraint_exists('orders', 'fk_orders_user')) {
            $pdo->exec('ALTER TABLE orders DROP FOREIGN KEY fk_orders_user');
        }
        $pdo->exec('ALTER TABLE orders MODIFY user_id INT NULL DEFAULT NULL');
    }
    if (!db_constraint_exists('orders', 'fk_orders_user')) {
        $pdo->exec('ALTER TABLE orders ADD CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
    }
};
