<?php

declare(strict_types=1);

/**
 * 004 — demo_mail: the demo inbox. With APP_DEMO=true and
 * MAIL_DRIVER=database, emails are stored here instead of being sent and
 * shown on inbox.php. Rows older than 48 hours are deleted whenever a
 * message is stored or the inbox is opened, and a customer's rows when the
 * account is deleted.
 */
return static function (PDO $pdo): void {
    if (!db_table_exists('demo_mail')) {
        $pdo->exec(
            "CREATE TABLE demo_mail (
                id         INT          NOT NULL AUTO_INCREMENT,
                recipient  VARCHAR(255) NOT NULL COMMENT 'Email address the message was sent to',
                subject    VARCHAR(255) NOT NULL,
                html       MEDIUMTEXT   NOT NULL,
                text       MEDIUMTEXT   NOT NULL,
                locale     VARCHAR(5)   NOT NULL DEFAULT 'en' COMMENT 'Language the message is written in',
                created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_demo_mail_recipient_created (recipient, created_at),
                KEY idx_demo_mail_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
};
