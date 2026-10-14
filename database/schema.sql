-- =====================================================================
-- BookWise database schema
-- Compatible with MariaDB 10.4+ and MySQL 8.0.16+ (InnoDB, utf8mb4).
--
-- Fresh install (see README.md):
--   CREATE DATABASE bookwise CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   php bin/install.php --seed          (or import this file, then seed.sql)
--
-- This file always describes the LATEST schema and records the migrations it
-- contains in schema_migrations. Databases created from an older version are
-- updated with:  php bin/migrate.php
--
-- Upgrading the original "signup" database instead? Run
--   php bin/upgrade-legacy-db.php --i-have-a-backup   (see README.md)
-- Do NOT run this file against an existing database (it intentionally fails
-- if tables exist).
--
-- Table and column names (e.g. users.Eaddress, users.pass) are kept from the
-- original project so existing databases remain compatible.
-- =====================================================================

SET NAMES utf8mb4;

-- Customer accounts. `pass` holds a password_hash() value (bcrypt by default).
CREATE TABLE users (
    id             INT          NOT NULL AUTO_INCREMENT,
    Eaddress       VARCHAR(255) NOT NULL COMMENT 'Email address (login identifier)',
    name           VARCHAR(100) NOT NULL,
    pass           VARCHAR(255) NOT NULL COMMENT 'password_hash() output',
    favorite_genre VARCHAR(100) NOT NULL DEFAULT 'Unknown',
    date_of_birth  DATE         NULL DEFAULT NULL,
    payment_method VARCHAR(50)  NULL DEFAULT NULL COMMENT 'Display preference only; card data is never stored',
    profile_image  VARCHAR(255) NULL DEFAULT 'imges/profileImage2.png',
    session_version INT         NOT NULL DEFAULT 0 COMMENT 'Incremented on password change to sign out other sessions',
    email_verified_at DATETIME  NULL DEFAULT NULL COMMENT 'Set when the address is confirmed',
    locale         VARCHAR(5)   NOT NULL DEFAULT 'en' COMMENT 'Interface/email language',
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (Eaddress)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Administrator accounts (managed with bin/create-admin.php).
CREATE TABLE admins (
    Eaddress VARCHAR(255) NOT NULL,
    name     VARCHAR(255) NULL DEFAULT NULL,
    password VARCHAR(255) NOT NULL COMMENT 'password_hash() output',
    session_version INT   NOT NULL DEFAULT 0 COMMENT 'Incremented on password change to sign out other sessions',
    PRIMARY KEY (Eaddress)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catalog. cover_image is a path relative to public/ (e.g. imges/...) or an https URL.
CREATE TABLE books (
    id          INT           NOT NULL AUTO_INCREMENT,
    title       VARCHAR(255)  NOT NULL,
    author      VARCHAR(255)  NOT NULL,
    description TEXT          NOT NULL,
    genre       VARCHAR(255)  NOT NULL,
    price       DECIMAL(10,2) NOT NULL,
    stock       INT           NOT NULL DEFAULT 0 COMMENT 'Copies available to sell',
    cover_image VARCHAR(255)  NOT NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_books_genre (genre),
    CONSTRAINT chk_books_price CHECK (price >= 0),
    CONSTRAINT chk_books_stock CHECK (stock >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per (user, book); quantity is updated in place.
CREATE TABLE cart (
    id         INT       NOT NULL AUTO_INCREMENT,
    user_id    INT       NOT NULL,
    book_id    INT       NOT NULL,
    quantity   INT       NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cart_user_book (user_id, book_id),
    KEY idx_cart_book (book_id),
    CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_book FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE,
    CONSTRAINT chk_cart_quantity CHECK (quantity >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE favorites (
    id         INT       NOT NULL AUTO_INCREMENT,
    user_id    INT       NOT NULL,
    book_id    INT       NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_favorites_user_book (user_id, book_id),
    KEY idx_favorites_book (book_id),
    CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_favorites_book FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reviews. `username` stores the author's email (original column name);
-- pages display the account name, never the email.
CREATE TABLE comments (
    id         INT          NOT NULL AUTO_INCREMENT,
    book_id    INT          NOT NULL,
    username   VARCHAR(255) NOT NULL COMMENT 'Author email (users.Eaddress)',
    comment    TEXT         NOT NULL,
    rating     TINYINT      NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_comments_book_user (book_id, username),
    KEY idx_comments_book_created (book_id, created_at),
    CONSTRAINT fk_comments_book FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE,
    CONSTRAINT chk_comments_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE comment_interactions (
    id            INT                     NOT NULL AUTO_INCREMENT,
    comment_id    INT                     NOT NULL,
    user_id       INT                     NOT NULL,
    reaction_type ENUM('like', 'dislike') NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_interactions_comment_user (comment_id, user_id),
    KEY idx_interactions_user (user_id),
    CONSTRAINT fk_interactions_comment FOREIGN KEY (comment_id) REFERENCES comments (id) ON DELETE CASCADE,
    CONSTRAINT fk_interactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email-verification tokens. Only the SHA-256 hash of the emailed token is stored.
CREATE TABLE email_verifications (
    id         INT       NOT NULL AUTO_INCREMENT,
    user_id    INT       NOT NULL,
    token      CHAR(64)  NOT NULL COMMENT 'SHA-256 hex digest of the emailed token',
    expiry     DATETIME  NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_verifications_token (token),
    KEY idx_email_verifications_user (user_id),
    CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password-reset tokens. Only the SHA-256 hash of the emailed token is stored.
CREATE TABLE reset_tokens (
    id     INT          NOT NULL AUTO_INCREMENT,
    email  VARCHAR(255) NOT NULL,
    token  CHAR(64)     NOT NULL COMMENT 'SHA-256 hex digest of the raw token',
    expiry DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reset_tokens_token (token),
    KEY idx_reset_tokens_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Checkout orders. An order becomes `paid` only after the Stripe Checkout
-- Session has been retrieved from Stripe (or delivered by a signed webhook)
-- and its amount, currency and payment status match this row. Orders are
-- kept as financial records when a customer deletes their account (user_id
-- becomes NULL).
CREATE TABLE orders (
    id                    INT           NOT NULL AUTO_INCREMENT,
    user_id               INT           NULL DEFAULT NULL,
    status                ENUM('pending', 'paid', 'canceled', 'expired', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
    currency              CHAR(3)       NOT NULL,
    total_amount          DECIMAL(10,2) NOT NULL,
    amount_minor          INT           NOT NULL COMMENT 'Total in the smallest currency unit, as sent to Stripe',
    stripe_session_id     VARCHAR(255)  NULL DEFAULT NULL,
    stripe_payment_intent VARCHAR(255)  NULL DEFAULT NULL,
    stripe_refund_id      VARCHAR(255)  NULL DEFAULT NULL,
    created_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    paid_at               DATETIME      NULL DEFAULT NULL,
    refunded_at           DATETIME      NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_stripe_session (stripe_session_id),
    KEY idx_orders_user_status (user_id, status),
    KEY idx_orders_status_created (status, created_at),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_orders_amount CHECK (amount_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Price/title snapshot taken when checkout starts, so later catalog edits or
-- deletions never change what a customer was charged for.
CREATE TABLE order_items (
    id         INT           NOT NULL AUTO_INCREMENT,
    order_id   INT           NOT NULL,
    book_id    INT           NULL DEFAULT NULL,
    title      VARCHAR(255)  NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    quantity   INT           NOT NULL,
    stock_taken INT          NOT NULL DEFAULT 0 COMMENT 'Copies this line took out of stock when the order was paid',
    PRIMARY KEY (id),
    UNIQUE KEY uq_order_items_order_book (order_id, book_id),
    KEY idx_order_items_book (book_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_book FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE SET NULL,
    CONSTRAINT chk_order_items_quantity CHECK (quantity >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchase ledger used for "sold" counts and review eligibility.
-- Rows are written once per paid order line (unique on order_id + book_id).
CREATE TABLE sales (
    id            INT      NOT NULL AUTO_INCREMENT,
    book_id       INT      NOT NULL,
    user_id       INT      NOT NULL,
    order_id      INT      NULL DEFAULT NULL,
    quantity      INT      NOT NULL DEFAULT 1,
    purchase_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_order_book (order_id, book_id),
    KEY idx_sales_book (book_id),
    KEY idx_sales_user_book (user_id, book_id),
    CONSTRAINT fk_sales_book FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed sign-in attempts, used to throttle password guessing.
CREATE TABLE login_attempts (
    id           INT          NOT NULL AUTO_INCREMENT,
    email        VARCHAR(255) NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_email_time (email, attempted_at),
    KEY idx_login_attempts_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Applied schema migrations (see src/migrations.php and bin/migrate.php).
CREATE TABLE schema_migrations (
    version    VARCHAR(100) NOT NULL,
    applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES
    ('001_initial'),
    ('002_accounts_commerce_i18n'),
    ('003_order_stock_taken');
