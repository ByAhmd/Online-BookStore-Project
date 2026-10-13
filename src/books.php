<?php

declare(strict_types=1);

/* ---------------------------------------------------------------------
 * Catalog queries
 * ------------------------------------------------------------------- */

const BOOK_LIST_LIMIT = 200;

/**
 * @return array<string, mixed>|null
 */
function find_book(int $id): ?array
{
    return db_one(
        'SELECT id, title, author, description, genre, price, cover_image, created_at FROM books WHERE id = ?',
        [$id]
    );
}

/**
 * Distinct genres present in the catalog, alphabetically.
 *
 * @return list<string>
 */
function list_genres(): array
{
    return array_map('strval', db_run('SELECT DISTINCT genre FROM books ORDER BY genre')->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Books filtered by an optional genre and a title/author search term.
 *
 * @return list<array<string, mixed>>
 */
function search_books(?string $genre = null, string $query = '', int $limit = BOOK_LIST_LIMIT): array
{
    $sql = 'SELECT id, title, author, genre, price, cover_image FROM books WHERE 1 = 1';
    $params = [];

    if ($genre !== null && $genre !== '') {
        $sql .= ' AND genre = ?';
        $params[] = $genre;
    }
    if ($query !== '') {
        $like = '%' . addcslashes($query, '%_\\') . '%';
        $sql .= ' AND (title LIKE ? OR author LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY title LIMIT ' . max(1, min($limit, BOOK_LIST_LIMIT));

    return db_all($sql, $params);
}

/**
 * A customer's favorite books, optionally filtered by a title/author search.
 *
 * @return list<array<string, mixed>>
 */
function favorite_books(int $userId, string $query = ''): array
{
    $sql = 'SELECT b.id, b.title, b.author, b.genre, b.price, b.cover_image
            FROM favorites f
            JOIN books b ON b.id = f.book_id
            WHERE f.user_id = ?';
    $params = [$userId];
    if ($query !== '') {
        $like = '%' . addcslashes($query, '%_\\') . '%';
        $sql .= ' AND (b.title LIKE ? OR b.author LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }
    return db_all($sql . ' ORDER BY f.created_at DESC, f.id DESC', $params);
}

/**
 * @param array<string, mixed> $book
 * @return list<array<string, mixed>>
 */
function similar_books(array $book, int $limit = 7): array
{
    return db_all(
        'SELECT id, title, author, cover_image, price FROM books
         WHERE genre = ? AND id <> ? ORDER BY RAND() LIMIT ' . max(1, min($limit, 20)),
        [$book['genre'], $book['id']]
    );
}

function book_count(): int
{
    return (int) db_value('SELECT COUNT(*) FROM books');
}

/**
 * Most purchased books (ties and unsold books ordered by newest).
 *
 * @return list<array<string, mixed>>
 */
function best_selling_books(int $limit = 6): array
{
    return db_all(
        'SELECT b.id, b.title, b.author, b.genre, b.price, b.cover_image, COALESCE(SUM(s.quantity), 0) AS sold
         FROM books b
         LEFT JOIN sales s ON s.book_id = b.id
         GROUP BY b.id, b.title, b.author, b.genre, b.price, b.cover_image, b.created_at
         ORDER BY sold DESC, b.created_at DESC, b.id DESC
         LIMIT ' . max(1, min($limit, 50))
    );
}

/**
 * Highest-rated books with at least one review, padded with newest titles.
 *
 * @return list<array<string, mixed>>
 */
function recommended_books(int $limit = 6): array
{
    $limit = max(1, min($limit, 50));
    return db_all(
        'SELECT b.id, b.title, b.author, b.genre, b.price, b.cover_image, AVG(c.rating) AS avg_rating
         FROM books b
         LEFT JOIN comments c ON c.book_id = b.id
         GROUP BY b.id, b.title, b.author, b.genre, b.price, b.cover_image, b.created_at
         ORDER BY (AVG(c.rating) IS NULL), AVG(c.rating) DESC, b.created_at DESC, b.id DESC
         LIMIT ' . $limit
    );
}

function book_sales_count(int $bookId): int
{
    return (int) db_value('SELECT COALESCE(SUM(quantity), 0) FROM sales WHERE book_id = ?', [$bookId]);
}

/**
 * @return array{average: float, count: int}
 */
function book_rating_summary(int $bookId): array
{
    $row = db_one('SELECT AVG(rating) AS average, COUNT(*) AS total FROM comments WHERE book_id = ?', [$bookId]);
    return [
        'average' => $row !== null && $row['average'] !== null ? round((float) $row['average'], 1) : 0.0,
        'count' => (int) ($row['total'] ?? 0),
    ];
}

function user_has_purchased(int $userId, int $bookId): bool
{
    return db_value('SELECT 1 FROM sales WHERE user_id = ? AND book_id = ? LIMIT 1', [$userId, $bookId]) !== null;
}

/* ---------------------------------------------------------------------
 * Favorites
 * ------------------------------------------------------------------- */

function is_favorite(int $userId, int $bookId): bool
{
    return db_value('SELECT 1 FROM favorites WHERE user_id = ? AND book_id = ?', [$userId, $bookId]) !== null;
}

/**
 * Adds or removes a favorite. Returns the new state (true = favorited).
 */
function toggle_favorite(int $userId, int $bookId): bool
{
    $removed = db_run('DELETE FROM favorites WHERE user_id = ? AND book_id = ?', [$userId, $bookId])->rowCount();
    if ($removed > 0) {
        return false;
    }
    // INSERT IGNORE keeps double-clicks from failing on the unique key. It also
    // silences foreign-key failures, so report only what was actually stored.
    $inserted = db_run('INSERT IGNORE INTO favorites (user_id, book_id) VALUES (?, ?)', [$userId, $bookId])->rowCount() > 0;
    return $inserted || is_favorite($userId, $bookId);
}

/* ---------------------------------------------------------------------
 * Reviews (comments + ratings) and reactions
 * ------------------------------------------------------------------- */

/**
 * Reviews for a book with the author's display name, reaction counts and the
 * current user's own reaction. Author emails are never returned.
 *
 * @return list<array<string, mixed>>
 */
function book_comments(int $bookId, ?int $viewerId): array
{
    return db_all(
        "SELECT c.id, c.comment, c.rating, c.created_at,
                COALESCE(u.name, 'Reader') AS author_name,
                (SELECT COUNT(*) FROM comment_interactions ci WHERE ci.comment_id = c.id AND ci.reaction_type = 'like') AS likes,
                (SELECT COUNT(*) FROM comment_interactions ci WHERE ci.comment_id = c.id AND ci.reaction_type = 'dislike') AS dislikes,
                (SELECT ci.reaction_type FROM comment_interactions ci WHERE ci.comment_id = c.id AND ci.user_id = ?) AS my_reaction
         FROM comments c
         LEFT JOIN users u ON u.Eaddress = c.username
         WHERE c.book_id = ?
         ORDER BY c.created_at DESC, c.id DESC",
        [$viewerId ?? 0, $bookId]
    );
}

function user_has_reviewed(string $email, int $bookId): bool
{
    return db_value('SELECT 1 FROM comments WHERE book_id = ? AND username = ?', [$bookId, $email]) !== null;
}

/**
 * Stores a review. Returns false if the user already reviewed the book.
 */
function add_review(int $bookId, string $email, string $comment, int $rating): bool
{
    try {
        db_run(
            'INSERT INTO comments (book_id, username, comment, rating) VALUES (?, ?, ?, ?)',
            [$bookId, $email, $comment, $rating]
        );
        return true;
    } catch (PDOException $e) {
        if (is_duplicate_key_error($e)) {
            return false;
        }
        throw $e;
    }
}

/**
 * Applies a like/dislike on a comment that belongs to $bookId. Repeating the
 * same reaction removes it; choosing the other one switches it.
 */
function toggle_comment_reaction(int $userId, int $bookId, int $commentId, string $reaction): bool
{
    if (!in_array($reaction, ['like', 'dislike'], true)) {
        return false;
    }

    return db_transaction(static function () use ($userId, $bookId, $commentId, $reaction): bool {
        $exists = db_value('SELECT 1 FROM comments WHERE id = ? AND book_id = ?', [$commentId, $bookId]);
        if ($exists === null) {
            return false;
        }
        $current = db_value(
            'SELECT reaction_type FROM comment_interactions WHERE comment_id = ? AND user_id = ? FOR UPDATE',
            [$commentId, $userId]
        );
        if ($current === $reaction) {
            db_run('DELETE FROM comment_interactions WHERE comment_id = ? AND user_id = ?', [$commentId, $userId]);
        } elseif ($current !== null) {
            db_run(
                'UPDATE comment_interactions SET reaction_type = ? WHERE comment_id = ? AND user_id = ?',
                [$reaction, $commentId, $userId]
            );
        } else {
            db_run(
                'INSERT INTO comment_interactions (comment_id, user_id, reaction_type) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE reaction_type = ?',
                [$commentId, $userId, $reaction, $reaction]
            );
        }
        return true;
    });
}

/* ---------------------------------------------------------------------
 * Admin catalog management
 * ------------------------------------------------------------------- */

/**
 * @param array<string, string> $book Output of validate_book_input()
 */
function create_book(array $book): int
{
    db_run(
        'INSERT INTO books (title, author, genre, description, price, cover_image) VALUES (?, ?, ?, ?, ?, ?)',
        [$book['title'], $book['author'], $book['genre'], $book['description'], $book['price'], $book['cover_image']]
    );
    return (int) db()->lastInsertId();
}

/**
 * @param array<string, string> $book Output of validate_book_input()
 */
function update_book(int $id, array $book): bool
{
    db_run(
        'UPDATE books SET title = ?, author = ?, genre = ?, description = ?, price = ?, cover_image = ? WHERE id = ?',
        [$book['title'], $book['author'], $book['genre'], $book['description'], $book['price'], $book['cover_image'], $id]
    );
    return find_book($id) !== null;
}

/**
 * Deletes a book. Cart lines, favorites, reviews and sales rows are removed by
 * foreign-key cascades; order history keeps its title/price snapshot.
 */
function delete_book(int $id): bool
{
    return db_run('DELETE FROM books WHERE id = ?', [$id])->rowCount() > 0;
}

/**
 * @return list<array<string, mixed>>
 */
function all_books_for_admin(): array
{
    return db_all('SELECT id, title, author, genre, price, cover_image FROM books ORDER BY id DESC');
}
