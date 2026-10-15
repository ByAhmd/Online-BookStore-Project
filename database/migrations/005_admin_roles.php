<?php

declare(strict_types=1);

/**
 * 005 — administrator roles and the demo's one-click accounts.
 *  - admins.role: "owner" (may change everything; existing administrators)
 *    or "viewer" (may open every admin page but change nothing, like the
 *    demo's shared administrator).
 *  - users.demo_guest_at: set on the throwaway customers that the demo's
 *    "Try as a customer" button creates; bin/demo-cleanup.php deletes them
 *    after 24 hours.
 *  - demo_guest_creations: when, and from which network (an IPv4 address or
 *    an IPv6 /64), those customers were created, so that neither one visitor
 *    nor all of them together can create too many. Rows older than a day are
 *    deleted.
 * Every step checks the current schema first, so re-running is harmless.
 */
return static function (PDO $pdo): void {
    if (!db_column_exists('admins', 'role')) {
        $pdo->exec(
            "ALTER TABLE admins ADD COLUMN role ENUM('owner', 'viewer') NOT NULL DEFAULT 'owner'
             COMMENT 'owner: may change everything; viewer: read-only' AFTER session_version"
        );
    }

    if (!db_column_exists('users', 'demo_guest_at')) {
        $pdo->exec(
            "ALTER TABLE users ADD COLUMN demo_guest_at DATETIME NULL DEFAULT NULL
             COMMENT 'Set for throwaway demo customers, which are deleted after 24 hours' AFTER locale"
        );
    }
    if (!db_index_exists('users', 'idx_users_demo_guest')) {
        $pdo->exec('ALTER TABLE users ADD KEY idx_users_demo_guest (demo_guest_at)');
    }

    if (!db_table_exists('demo_guest_creations')) {
        $pdo->exec(
            "CREATE TABLE demo_guest_creations (
                id         INT         NOT NULL AUTO_INCREMENT,
                ip_address VARCHAR(45) NOT NULL,
                created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_demo_guest_creations_ip_time (ip_address, created_at),
                KEY idx_demo_guest_creations_time (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
};
