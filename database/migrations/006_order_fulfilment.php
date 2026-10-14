<?php

declare(strict_types=1);

/**
 * 006 — delivery and order fulfilment:
 *  - addresses: the customers' saved delivery addresses (Saudi National
 *    Address), at most five each, one of them the default;
 *  - order_addresses: the address an order is delivered to, copied when
 *    checkout starts, so later edits never change past orders;
 *  - orders.fulfilment_status and its times, carrier and tracking number:
 *    processing -> shipped -> delivered, for paid orders.
 * Orders placed before this migration have no address and no fulfilment
 * status. Every step checks the current schema first, so re-running is harmless.
 */
return static function (PDO $pdo): void {
    if (!db_table_exists('addresses')) {
        $pdo->exec(
            "CREATE TABLE addresses (
                id                INT          NOT NULL AUTO_INCREMENT,
                user_id           INT          NOT NULL,
                recipient_name    VARCHAR(100) NOT NULL,
                phone             VARCHAR(13)  NOT NULL COMMENT 'Saudi mobile number: +9665XXXXXXXX',
                city              VARCHAR(60)  NOT NULL,
                district          VARCHAR(60)  NOT NULL,
                street            VARCHAR(100) NOT NULL,
                building_number   CHAR(4)      NOT NULL,
                postal_code       CHAR(5)      NOT NULL,
                additional_number CHAR(4)      NULL DEFAULT NULL,
                short_address     CHAR(8)      NULL DEFAULT NULL COMMENT 'National Address short code: 4 letters and 4 digits',
                is_default        TINYINT(1)   NOT NULL DEFAULT 0,
                created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_addresses_user (user_id, is_default),
                CONSTRAINT fk_addresses_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    if (!db_table_exists('order_addresses')) {
        $pdo->exec(
            "CREATE TABLE order_addresses (
                order_id          INT          NOT NULL,
                recipient_name    VARCHAR(100) NOT NULL,
                phone             VARCHAR(13)  NOT NULL COMMENT 'Saudi mobile number: +9665XXXXXXXX',
                city              VARCHAR(60)  NOT NULL,
                district          VARCHAR(60)  NOT NULL,
                street            VARCHAR(100) NOT NULL,
                building_number   CHAR(4)      NOT NULL,
                postal_code       CHAR(5)      NOT NULL,
                additional_number CHAR(4)      NULL DEFAULT NULL,
                short_address     CHAR(8)      NULL DEFAULT NULL,
                PRIMARY KEY (order_id),
                CONSTRAINT fk_order_addresses_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // --- orders: fulfilment of paid orders ------------------------------
    $columns = [
        'fulfilment_status' => "ENUM('processing', 'shipped', 'delivered') NULL DEFAULT NULL COMMENT 'Delivery progress of a paid order' AFTER refunded_at",
        'shipped_at' => 'DATETIME NULL DEFAULT NULL AFTER fulfilment_status',
        'delivered_at' => 'DATETIME NULL DEFAULT NULL AFTER shipped_at',
        'carrier' => 'VARCHAR(60) NULL DEFAULT NULL AFTER delivered_at',
        'tracking_number' => 'VARCHAR(40) NULL DEFAULT NULL AFTER carrier',
    ];
    foreach ($columns as $column => $definition) {
        if (!db_column_exists('orders', $column)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN $column $definition");
        }
    }
    if (!db_index_exists('orders', 'idx_orders_fulfilment')) {
        $pdo->exec('ALTER TABLE orders ADD KEY idx_orders_fulfilment (fulfilment_status, created_at)');
    }
};
