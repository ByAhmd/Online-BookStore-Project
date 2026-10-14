<?php

declare(strict_types=1);

/**
 * Upgrades a database created by the original BookWise project (originally
 * named "signup") to the current schema in database/schema.sql, keeping
 * existing accounts, books, carts, favorites, reviews and sales.
 *
 * Usage (configure DB_* in .env first, and BACK UP the database):
 *   php bin/upgrade-legacy-db.php --i-have-a-backup
 *
 * What it does (each step is skipped when already applied, so it is safe to re-run):
 *  - users:   widens columns for password_hash(), adds a unique email key, drops
 *             the stored "confirm password" column, renames Date_of_birth, and
 *             clears free-text payment_method values (they may contain card data).
 *  - cart / favorites / comments / comment_interactions: removes duplicates and
 *             orphans, then adds the unique keys and constraints the app relies on.
 *  - reset_tokens: deletes all legacy plaintext tokens (new tokens are hashed).
 *  - sales:   adds order_id / quantity columns.
 *  - creates orders, order_items and login_attempts.
 *  - replaces existing foreign keys with the documented ON DELETE behaviour,
 *    then applies the regular migrations (database/migrations).
 *
 * Existing MD5 password hashes keep working: they are upgraded to bcrypt the
 * next time each user signs in.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

if (!in_array('--i-have-a-backup', (array) ($GLOBALS['argv'] ?? []), true)) {
    fwrite(STDERR, "This script modifies your database. Back it up first (outside the project folder), e.g.:\n"
        . "  mysqldump -u <user> -p " . config('db.name') . " > ../" . config('db.name') . "-backup.sql\n"
        . "then re-run with --i-have-a-backup\n");
    exit(1);
}

$pdo = db();
$dbName = (string) config('db.name');

function say(string $message): void
{
    echo $message . PHP_EOL;
}

/**
 * Exact-case column name (information_schema comparison is case-insensitive).
 */
function column_name(string $table, string $column): ?string
{
    $name = db_value(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    );
    return is_string($name) ? $name : null;
}

function step(string $label, callable $callback): void
{
    try {
        $result = $callback();
        say(($result === false ? '  - skip  ' : '  + done  ') . $label);
    } catch (Throwable $e) {
        say('  ! FAIL  ' . $label . ': ' . $e->getMessage());
        throw $e;
    }
}

foreach (['users', 'admins', 'books', 'cart', 'favorites', 'comments', 'comment_interactions', 'reset_tokens', 'sales'] as $required) {
    if (!db_table_exists($required)) {
        fwrite(STDERR, "Table `$required` not found in `$dbName`. This does not look like a BookWise database.\n"
            . "For a fresh install use database/schema.sql instead.\n");
        exit(1);
    }
}

say("Upgrading `$dbName`...");

// Foreign keys are dropped first so columns can be altered; canonical ones are re-added at the end.
step('drop existing foreign keys (re-created at the end)', static function (): bool {
    $dropped = false;
    foreach (['cart', 'favorites', 'comments', 'comment_interactions', 'sales', 'order_items', 'orders'] as $table) {
        if (!db_table_exists($table)) {
            continue;
        }
        foreach (db_foreign_keys($table) as $fk) {
            db()->exec("ALTER TABLE `$table` DROP FOREIGN KEY `$fk`");
            $dropped = true;
        }
    }
    return $dropped;
});

/* --- users ---------------------------------------------------------- */

step('users: check for duplicate emails', static function (): bool {
    $duplicates = (int) db_value('SELECT COUNT(*) FROM (SELECT Eaddress FROM users GROUP BY Eaddress HAVING COUNT(*) > 1) d');
    if ($duplicates > 0) {
        throw new RuntimeException("$duplicates email address(es) belong to more than one account. Resolve them manually, then re-run.");
    }
    return false;
});

step('users: drop stored confirm-password column (cPass)', static function (): bool {
    if (!db_column_exists('users', 'cPass')) {
        return false;
    }
    db()->exec('ALTER TABLE users DROP COLUMN cPass');
    return true;
});

step('users: rename Date_of_birth to date_of_birth', static function (): bool {
    $current = column_name('users', 'date_of_birth');
    if ($current === null || $current === 'date_of_birth') {
        return false;
    }
    db()->exec("ALTER TABLE users CHANGE `$current` date_of_birth DATE NULL DEFAULT NULL");
    return true;
});

step('users: clear free-text payment methods (may contain card data)', static function (): bool {
    $placeholders = implode(', ', array_fill(0, count(PAYMENT_PREFERENCES), '?'));
    $count = db_run(
        "UPDATE users SET payment_method = NULL WHERE payment_method IS NOT NULL AND payment_method NOT IN ($placeholders)",
        PAYMENT_PREFERENCES
    )->rowCount();
    return $count > 0;
});

step('users: widen columns for password_hash() and long emails', static function (): void {
    db()->exec("ALTER TABLE users
        MODIFY Eaddress VARCHAR(255) NOT NULL,
        MODIFY name VARCHAR(100) NOT NULL,
        MODIFY pass VARCHAR(255) NOT NULL,
        MODIFY payment_method VARCHAR(50) NULL DEFAULT NULL");
});

step('users: unique email key', static function (): bool {
    if (db_index_exists('users', 'uq_users_email')) {
        return false;
    }
    db()->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_email (Eaddress)');
    return true;
});

step('users/admins: session_version column (password changes sign out other sessions)', static function (): bool {
    $changed = false;
    foreach (['users', 'admins'] as $table) {
        if (!db_column_exists($table, 'session_version')) {
            db()->exec("ALTER TABLE `$table` ADD COLUMN session_version INT NOT NULL DEFAULT 0");
            $changed = true;
        }
    }
    return $changed;
});

step('admins: required password column for password_hash()', static function (): void {
    $missing = (int) db_value("SELECT COUNT(*) FROM admins WHERE password IS NULL OR password = ''");
    if ($missing > 0) {
        throw new RuntimeException("$missing administrator(s) have no password. Set one with bin/create-admin.php <email>, then re-run.");
    }
    db()->exec('ALTER TABLE admins MODIFY password VARCHAR(255) NOT NULL');
});

/* --- books ---------------------------------------------------------- */

step('books: genre index', static function (): bool {
    if (db_index_exists('books', 'idx_books_genre')) {
        return false;
    }
    db()->exec('ALTER TABLE books ADD KEY idx_books_genre (genre)');
    return true;
});

step('books: non-negative price check', static function (): bool {
    if (db_constraint_exists('books', 'chk_books_price')) {
        return false;
    }
    $negative = (int) db_value('SELECT COUNT(*) FROM books WHERE price < 0');
    if ($negative > 0) {
        throw new RuntimeException("$negative book(s) have a negative price. Fix them, then re-run.");
    }
    db()->exec('ALTER TABLE books ADD CONSTRAINT chk_books_price CHECK (price >= 0)');
    return true;
});

/* --- cart ----------------------------------------------------------- */

step('cart: remove orphans and merge duplicate lines', static function (): void {
    db()->exec('DELETE c FROM cart c LEFT JOIN users u ON u.id = c.user_id LEFT JOIN books b ON b.id = c.book_id WHERE u.id IS NULL OR b.id IS NULL');
    db()->exec('UPDATE cart SET quantity = 1 WHERE quantity IS NULL OR quantity < 1');
    $groups = db_all('SELECT user_id, book_id, MIN(id) AS keep_id, LEAST(SUM(quantity), ' . CART_MAX_QUANTITY . ') AS qty
                      FROM cart GROUP BY user_id, book_id HAVING COUNT(*) > 1');
    foreach ($groups as $g) {
        db_run('UPDATE cart SET quantity = ? WHERE id = ?', [$g['qty'], $g['keep_id']]);
        db_run('DELETE FROM cart WHERE user_id = ? AND book_id = ? AND id <> ?', [$g['user_id'], $g['book_id'], $g['keep_id']]);
    }
    db()->exec('UPDATE cart SET quantity = ' . CART_MAX_QUANTITY . ' WHERE quantity > ' . CART_MAX_QUANTITY);
    db()->exec('ALTER TABLE cart MODIFY quantity INT NOT NULL DEFAULT 1');
});

step('cart: unique (user_id, book_id) and quantity check', static function (): void {
    if (!db_index_exists('cart', 'uq_cart_user_book')) {
        db()->exec('ALTER TABLE cart ADD UNIQUE KEY uq_cart_user_book (user_id, book_id)');
    }
    if (!db_constraint_exists('cart', 'chk_cart_quantity')) {
        db()->exec('ALTER TABLE cart ADD CONSTRAINT chk_cart_quantity CHECK (quantity >= 1)');
    }
});

/* --- favorites ------------------------------------------------------ */

step('favorites: remove orphans and duplicates, add unique key', static function (): void {
    db()->exec('DELETE f FROM favorites f LEFT JOIN users u ON u.id = f.user_id LEFT JOIN books b ON b.id = f.book_id WHERE u.id IS NULL OR b.id IS NULL');
    db()->exec('DELETE f1 FROM favorites f1 JOIN favorites f2 ON f1.user_id = f2.user_id AND f1.book_id = f2.book_id AND f1.id > f2.id');
    if (!db_index_exists('favorites', 'uq_favorites_user_book')) {
        db()->exec('ALTER TABLE favorites ADD UNIQUE KEY uq_favorites_user_book (user_id, book_id)');
    }
});

/* --- comments ------------------------------------------------------- */

step('comments: remove invalid rows and duplicates, clamp ratings to 1-5', static function (): void {
    db()->exec('DELETE c FROM comments c LEFT JOIN books b ON b.id = c.book_id WHERE b.id IS NULL OR c.username IS NULL OR c.comment IS NULL');
    db()->exec('UPDATE comments SET rating = LEAST(GREATEST(COALESCE(rating, 1), 1), 5)');
    db()->exec('DELETE c1 FROM comments c1 JOIN comments c2 ON c1.book_id = c2.book_id AND c1.username = c2.username AND c1.id > c2.id');
    db()->exec('ALTER TABLE comments
        MODIFY book_id INT NOT NULL,
        MODIFY username VARCHAR(255) NOT NULL,
        MODIFY comment TEXT NOT NULL,
        MODIFY rating TINYINT NOT NULL');
});

step('comments: unique review per user/book, index, rating check', static function (): void {
    if (!db_index_exists('comments', 'uq_comments_book_user')) {
        db()->exec('ALTER TABLE comments ADD UNIQUE KEY uq_comments_book_user (book_id, username)');
    }
    if (!db_index_exists('comments', 'idx_comments_book_created')) {
        db()->exec('ALTER TABLE comments ADD KEY idx_comments_book_created (book_id, created_at)');
    }
    if (!db_constraint_exists('comments', 'chk_comments_rating')) {
        db()->exec('ALTER TABLE comments ADD CONSTRAINT chk_comments_rating CHECK (rating BETWEEN 1 AND 5)');
    }
});

step('comment_interactions: remove orphans, empty and duplicate reactions; unique key', static function (): void {
    db()->exec('DELETE ci FROM comment_interactions ci
        LEFT JOIN comments c ON c.id = ci.comment_id
        LEFT JOIN users u ON u.id = ci.user_id
        WHERE c.id IS NULL OR u.id IS NULL OR ci.reaction_type IS NULL');
    // Keep each user's latest reaction per review.
    db()->exec('DELETE ci1 FROM comment_interactions ci1
        JOIN comment_interactions ci2 ON ci1.comment_id = ci2.comment_id AND ci1.user_id = ci2.user_id AND ci1.id < ci2.id');
    db()->exec("ALTER TABLE comment_interactions MODIFY reaction_type ENUM('like', 'dislike') NOT NULL");
    if (!db_index_exists('comment_interactions', 'uq_interactions_comment_user')) {
        db()->exec('ALTER TABLE comment_interactions ADD UNIQUE KEY uq_interactions_comment_user (comment_id, user_id)');
    }
    if (!db_index_exists('comment_interactions', 'idx_interactions_user')) {
        db()->exec('ALTER TABLE comment_interactions ADD KEY idx_interactions_user (user_id)');
    }
});

/* --- reset tokens --------------------------------------------------- */

step('reset_tokens: delete legacy plaintext tokens, hash-sized column, indexes', static function (): void {
    db()->exec('DELETE FROM reset_tokens');
    db()->exec('ALTER TABLE reset_tokens MODIFY id INT NOT NULL AUTO_INCREMENT, MODIFY token CHAR(64) NOT NULL');
    if (!db_index_exists('reset_tokens', 'uq_reset_tokens_token')) {
        db()->exec('ALTER TABLE reset_tokens ADD UNIQUE KEY uq_reset_tokens_token (token)');
    }
    if (!db_index_exists('reset_tokens', 'idx_reset_tokens_email')) {
        db()->exec('ALTER TABLE reset_tokens ADD KEY idx_reset_tokens_email (email)');
    }
});

/* --- new tables ----------------------------------------------------- */

step('create orders, order_items, login_attempts', static function (): bool {
    $created = false;
    $schema = (string) file_get_contents(base_path('database/schema.sql'));
    foreach (['orders', 'order_items', 'login_attempts'] as $table) {
        if (db_table_exists($table)) {
            continue;
        }
        if (!preg_match('/CREATE TABLE ' . $table . ' \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $m)) {
            throw new RuntimeException("Definition of $table not found in database/schema.sql");
        }
        // Foreign keys are added in the final step.
        $ddl = preg_replace('/,\s*CONSTRAINT fk_\w+ FOREIGN KEY[^,\n]*/', '', $m[0]);
        db()->exec((string) $ddl);
        $created = true;
    }
    return $created;
});

/* --- sales ---------------------------------------------------------- */

step('sales: add order_id and quantity columns', static function (): bool {
    $changed = false;
    db()->exec('DELETE s FROM sales s LEFT JOIN users u ON u.id = s.user_id LEFT JOIN books b ON b.id = s.book_id WHERE u.id IS NULL OR b.id IS NULL');
    if (!db_column_exists('sales', 'order_id')) {
        db()->exec('ALTER TABLE sales ADD COLUMN order_id INT NULL DEFAULT NULL AFTER user_id');
        $changed = true;
    }
    if (!db_column_exists('sales', 'quantity')) {
        db()->exec('ALTER TABLE sales ADD COLUMN quantity INT NOT NULL DEFAULT 1 AFTER order_id');
        $changed = true;
    }
    db()->exec('UPDATE sales SET purchase_date = CURRENT_TIMESTAMP WHERE purchase_date IS NULL');
    db()->exec('ALTER TABLE sales MODIFY purchase_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
    if (!db_index_exists('sales', 'uq_sales_order_book')) {
        db()->exec('ALTER TABLE sales ADD UNIQUE KEY uq_sales_order_book (order_id, book_id)');
        $changed = true;
    }
    if (!db_index_exists('sales', 'idx_sales_user_book')) {
        db()->exec('ALTER TABLE sales ADD KEY idx_sales_user_book (user_id, book_id)');
        $changed = true;
    }
    return $changed;
});

/* --- foreign keys --------------------------------------------------- */

step('add foreign keys with documented ON DELETE behaviour', static function (): void {
    $fks = [
        ['cart', 'fk_cart_user', 'user_id', 'users (id)', 'CASCADE'],
        ['cart', 'fk_cart_book', 'book_id', 'books (id)', 'CASCADE'],
        ['favorites', 'fk_favorites_user', 'user_id', 'users (id)', 'CASCADE'],
        ['favorites', 'fk_favorites_book', 'book_id', 'books (id)', 'CASCADE'],
        ['comments', 'fk_comments_book', 'book_id', 'books (id)', 'CASCADE'],
        ['comment_interactions', 'fk_interactions_comment', 'comment_id', 'comments (id)', 'CASCADE'],
        ['comment_interactions', 'fk_interactions_user', 'user_id', 'users (id)', 'CASCADE'],
        ['orders', 'fk_orders_user', 'user_id', 'users (id)', 'SET NULL'],
        ['order_items', 'fk_order_items_order', 'order_id', 'orders (id)', 'CASCADE'],
        ['order_items', 'fk_order_items_book', 'book_id', 'books (id)', 'SET NULL'],
        ['sales', 'fk_sales_book', 'book_id', 'books (id)', 'CASCADE'],
        ['sales', 'fk_sales_user', 'user_id', 'users (id)', 'CASCADE'],
        ['sales', 'fk_sales_order', 'order_id', 'orders (id)', 'SET NULL'],
    ];
    // ON DELETE SET NULL needs a nullable column. Orders created by an earlier
    // version of this script have user_id NOT NULL (migration 002 changes it
    // too, but it only runs in the next step).
    if (db_value("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'user_id'") === 'NO') {
        db()->exec('ALTER TABLE orders MODIFY user_id INT NULL DEFAULT NULL');
    }
    foreach ($fks as [$table, $name, $column, $ref, $onDelete]) {
        if (!db_constraint_exists($table, $name)) {
            db()->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` FOREIGN KEY (`$column`) REFERENCES $ref ON DELETE $onDelete");
        }
    }
});

step('apply schema migrations (database/migrations)', static function (): bool {
    return run_migrations(static fn (string $line) => say('          ' . $line)) !== [];
});

say('Upgrade complete. Existing MD5 passwords will be re-hashed automatically at next sign-in.');
say('Create an administrator with: php bin/create-admin.php <email> "<name>"');
