<?php

declare(strict_types=1);

/* ---------------------------------------------------------------------
 * Catalog queries
 * ------------------------------------------------------------------- */

/** Most rows a single catalog query returns. */
const BOOK_LIST_LIMIT = 200;
/** Books per page on the Explore page (and in fetch_books.php). */
const CATALOG_PAGE_SIZE = 24;
/** Rows per page in the admin books and orders tables. */
const ADMIN_PAGE_SIZE = 20;
/** At or below this many copies a book shows "Only N left". */
const STOCK_LOW_THRESHOLD = 5;

/**
 * @return array<string, mixed>|null
 */
function find_book(int $id): ?array
{
    return db_one(
        'SELECT id, title, author, description, genre, price, stock, cover_image, created_at FROM books WHERE id = ?',
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
 * WHERE clause (starting with " AND", or empty) for a genre and a title/author search.
 *
 * @return array{0: string, 1: list<string>}
 */
function book_filter_sql(?string $genre, string $query, string $alias = ''): array
{
    $sql = '';
    $params = [];
    if ($genre !== null && $genre !== '') {
        $sql .= " AND {$alias}genre = ?";
        $params[] = $genre;
    }
    if ($query !== '') {
        // LIKE wildcards in the search term are matched literally.
        $like = '%' . addcslashes($query, '%_\\') . '%';
        $sql .= " AND ({$alias}title LIKE ? OR {$alias}author LIKE ?)";
        $params[] = $like;
        $params[] = $like;
    }
    return [$sql, $params];
}

/**
 * Books filtered by an optional genre and a title/author search, by title.
 *
 * @return list<array<string, mixed>>
 */
function search_books(?string $genre = null, string $query = '', int $limit = BOOK_LIST_LIMIT, int $offset = 0): array
{
    [$where, $params] = book_filter_sql($genre, $query);
    return db_all(
        'SELECT id, title, author, genre, price, stock, cover_image FROM books WHERE 1 = 1' . $where
        . ' ORDER BY title, id LIMIT ' . max(1, min($limit, BOOK_LIST_LIMIT)) . ' OFFSET ' . max(0, $offset),
        $params
    );
}

function count_books(?string $genre = null, string $query = ''): int
{
    [$where, $params] = book_filter_sql($genre, $query);
    return (int) db_value('SELECT COUNT(*) FROM books WHERE 1 = 1' . $where, $params);
}

/**
 * A customer's favorite books, newest favorite first, optionally filtered by
 * a title/author search.
 *
 * @return list<array<string, mixed>>
 */
function favorite_books(int $userId, string $query = '', int $limit = BOOK_LIST_LIMIT, int $offset = 0): array
{
    [$where, $params] = book_filter_sql(null, $query, 'b.');
    return db_all(
        'SELECT b.id, b.title, b.author, b.genre, b.price, b.stock, b.cover_image
         FROM favorites f
         JOIN books b ON b.id = f.book_id
         WHERE f.user_id = ?' . $where . '
         ORDER BY f.created_at DESC, f.id DESC
         LIMIT ' . max(1, min($limit, BOOK_LIST_LIMIT)) . ' OFFSET ' . max(0, $offset),
        array_merge([$userId], $params)
    );
}

function count_favorite_books(int $userId, string $query = ''): int
{
    [$where, $params] = book_filter_sql(null, $query, 'b.');
    return (int) db_value(
        'SELECT COUNT(*) FROM favorites f JOIN books b ON b.id = f.book_id WHERE f.user_id = ?' . $where,
        array_merge([$userId], $params)
    );
}

/**
 * One page of the Explore listing: a genre (null = all) or, when
 * $favoritesOf is set, that customer's favorites.
 *
 * @return array{books: list<array<string, mixed>>, pagination: array{page: int, pages: int, per_page: int, total: int, offset: int}}
 */
function catalog_page(?int $favoritesOf, ?string $genre, string $query, mixed $page, int $perPage = CATALOG_PAGE_SIZE): array
{
    if ($favoritesOf !== null) {
        $pagination = paginate(count_favorite_books($favoritesOf, $query), $perPage, $page);
        $books = favorite_books($favoritesOf, $query, $pagination['per_page'], $pagination['offset']);
    } else {
        $pagination = paginate(count_books($genre, $query), $perPage, $page);
        $books = search_books($genre, $query, $pagination['per_page'], $pagination['offset']);
    }
    return ['books' => $books, 'pagination' => $pagination];
}

/**
 * The Explore status line, e.g. "Showing 1–24 of 55 books in all genres."
 * $genre is "all", "Favorites" or a catalog genre.
 *
 * @param array{page: int, pages: int, per_page: int, total: int, offset: int} $pagination
 */
function catalog_summary(string $genre, string $query, array $pagination): string
{
    $scope = match ($genre) {
        'all' => t('shop.explore.scope_all'),
        'Favorites' => t('shop.explore.scope_favorites'),
        default => $genre,
    };
    $replace = ['scope' => $scope, 'q' => $query];
    if ($pagination['pages'] > 1) {
        $replace['from'] = $pagination['offset'] + 1;
        $replace['to'] = min($pagination['offset'] + $pagination['per_page'], $pagination['total']);
        $key = $query !== '' ? 'shop.explore.summary_range_query' : 'shop.explore.summary_range';
    } else {
        $key = $query !== '' ? 'shop.explore.summary_query' : 'shop.explore.summary';
    }
    return tn($key, $pagination['total'], $replace);
}

/**
 * @param array<string, mixed> $book
 * @return list<array<string, mixed>>
 */
function similar_books(array $book, int $limit = 7): array
{
    return db_all(
        'SELECT id, title, author, cover_image, price, stock FROM books
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
        'SELECT b.id, b.title, b.author, b.genre, b.price, b.stock, b.cover_image, COALESCE(SUM(s.quantity), 0) AS sold
         FROM books b
         LEFT JOIN sales s ON s.book_id = b.id
         GROUP BY b.id, b.title, b.author, b.genre, b.price, b.stock, b.cover_image, b.created_at
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
        'SELECT b.id, b.title, b.author, b.genre, b.price, b.stock, b.cover_image, AVG(c.rating) AS avg_rating
         FROM books b
         LEFT JOIN comments c ON c.book_id = b.id
         GROUP BY b.id, b.title, b.author, b.genre, b.price, b.stock, b.cover_image, b.created_at
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
 * Stock
 * ------------------------------------------------------------------- */

/**
 * @return 'in'|'low'|'out'
 */
function book_stock_status(int $stock): string
{
    if ($stock <= 0) {
        return 'out';
    }
    return $stock <= STOCK_LOW_THRESHOLD ? 'low' : 'in';
}

/**
 * "In stock", "Only N left" or "Out of stock" in the visitor's language.
 */
function book_availability(int $stock): string
{
    return match (book_stock_status($stock)) {
        'out' => t('shop.stock.out'),
        'low' => tn('shop.stock.low', $stock),
        default => t('shop.stock.in'),
    };
}

/* ---------------------------------------------------------------------
 * Pagination and dates (shared by the catalog and admin pages)
 * ------------------------------------------------------------------- */

/**
 * Pagination for $total rows. $requested is the raw ?page= value: anything
 * that is not a page number means page 1, and numbers outside the range are
 * clamped to the first or last page.
 *
 * @return array{page: int, pages: int, per_page: int, total: int, offset: int}
 */
function paginate(int $total, int $perPage, mixed $requested = 1): array
{
    $perPage = max(1, $perPage);
    $total = max(0, $total);
    $pages = max(1, (int) ceil($total / $perPage));

    $page = 1;
    if (is_int($requested)) {
        $page = $requested;
    } elseif (is_string($requested) && preg_match('/^\s*([+-]?)(\d+)\s*$/D', $requested, $m)) {
        $digits = ltrim($m[2], '0');
        $page = $m[1] === '-' ? 0 : (strlen($digits) > 9 ? PHP_INT_MAX : (int) $digits);
    }
    $page = max(1, min($page, $pages));

    return ['page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'total' => $total, 'offset' => ($page - 1) * $perPage];
}

/**
 * Page numbers to link in a pager: the first and last page and two pages on
 * each side of the current one; null marks a gap. Mirrored in js/explore.js.
 *
 * @return list<int|null>
 */
function pager_items(int $page, int $pages): array
{
    $items = [];
    $previous = 0;
    for ($i = 1; $i <= $pages; $i++) {
        if ($i === 1 || $i === $pages || abs($i - $page) <= 2) {
            if ($i - $previous > 1) {
                $items[] = null;
            }
            $items[] = $i;
            $previous = $i;
        }
    }
    return $items;
}

/**
 * Previous/next and page-number links (no output for a single page). Works
 * without JavaScript; links carry data-page for scripts that enhance them.
 *
 * @param array{page: int, pages: int, per_page: int, total: int, offset: int} $pagination
 * @param callable(int): string $url URL of a page number
 */
function render_pager(array $pagination, callable $url, string $label): string
{
    $page = $pagination['page'];
    $pages = $pagination['pages'];
    if ($pages <= 1) {
        return '';
    }

    $edge = static function (string $class, string $text, ?int $target, string $rel) use ($url): string {
        if ($target === null) {
            return '<li><span class="pager-link ' . $class . ' is-disabled" aria-disabled="true">' . e($text) . '</span></li>';
        }
        return '<li><a class="pager-link ' . $class . '" href="' . e($url($target)) . '" rel="' . $rel . '" data-page="' . $target . '">' . e($text) . '</a></li>';
    };

    $html = '<nav class="pager" aria-label="' . e($label) . '"><ul class="pager-list">';
    $html .= $edge('pager-prev', t('shop.pager.previous'), $page > 1 ? $page - 1 : null, 'prev');
    foreach (pager_items($page, $pages) as $item) {
        if ($item === null) {
            $html .= '<li><span class="pager-gap" aria-hidden="true">&hellip;</span></li>';
            continue;
        }
        $current = $item === $page ? ' aria-current="page"' : '';
        $html .= '<li><a class="pager-link pager-number" href="' . e($url($item)) . '" data-page="' . $item . '"'
            . ' aria-label="' . e(t('shop.pager.page', ['page' => $item])) . '"' . $current . '>' . $item . '</a></li>';
    }
    $html .= $edge('pager-next', t('shop.pager.next'), $page < $pages ? $page + 1 : null, 'next');
    return $html . '</ul></nav>';
}

/**
 * t() for messages raised deep in domain code (exception messages), which
 * must never surface as bare translation keys. When BASE_PATH has no lang/
 * directory (a CLI tool or test process may run with a temporary BASE_PATH),
 * the English text that ships next to this file is used instead.
 *
 * @param array<string, string|int|float> $replace
 */
function t_safe(string $key, array $replace = []): string
{
    $text = t($key, $replace);
    $dot = strpos($key, '.');
    if ($text !== $key || $dot === false || !preg_match('/^[a-z_]+$/D', substr($key, 0, $dot))) {
        return $text;
    }

    static $english = [];
    $namespace = substr($key, 0, $dot);
    if (!array_key_exists($namespace, $english)) {
        $file = dirname(__DIR__) . '/lang/en/' . $namespace . '.php';
        $data = is_file($file) ? require $file : [];
        $english[$namespace] = is_array($data) ? $data : [];
    }
    $value = $english[$namespace];
    foreach (explode('.', substr($key, $dot + 1)) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $key;
        }
        $value = $value[$segment];
    }
    if (is_array($value)) {
        $value = $value['other'] ?? reset($value);
    }
    return is_string($value) ? translation_replace($value, $replace) : $key;
}

/**
 * Escaped translation with some placeholders filled with ready-made HTML,
 * e.g. t_markup('shop.book.by', ['author' => '<span dir="auto">' . e($author) . '</span>']).
 * $replace values are plain text and escaped like the rest of the string.
 *
 * @param array<string, string> $html Placeholder => trusted, already-escaped HTML
 * @param array<string, string|int|float> $replace Placeholder => plain text
 */
function t_markup(string $key, array $html, array $replace = []): string
{
    // Private-use characters stand in for the HTML while the text is escaped.
    $tokens = [];
    $index = 0;
    foreach ($html as $name => $markup) {
        $token = "\u{E000}" . $index++ . "\u{E001}";
        $tokens[$token] = $markup;
        $replace[$name] = $token;
    }
    return strtr(e(t($key, $replace)), $tokens);
}

/**
 * A date (optionally with the time) in the visitor's language, e.g.
 * "Oct 14, 2026" or "14 أكتوبر 2026". Returns '' for empty values.
 */
function display_date(DateTimeInterface|string|null $value, bool $withTime = false): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if (is_string($value)) {
        try {
            $value = new DateTimeImmutable($value);
        } catch (Exception) {
            return $value; // not a date: shown as stored
        }
    }
    $date = $value;
    $text = t('shop.date.format', [
        'day' => $date->format('j'),
        'month' => t('shop.date.months.' . $date->format('n')),
        'year' => $date->format('Y'),
    ]);
    return $withTime ? t('shop.date.with_time', ['date' => $text, 'time' => $date->format('H:i')]) : $text;
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
 * current user's own reaction. Author emails are never returned; reviews of
 * deleted accounts have a NULL author_name (shown as an anonymous reader).
 *
 * @return list<array<string, mixed>>
 */
function book_comments(int $bookId, ?int $viewerId): array
{
    return db_all(
        "SELECT c.id, c.comment, c.rating, c.created_at,
                u.name AS author_name,
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
        'INSERT INTO books (title, author, genre, description, price, stock, cover_image) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$book['title'], $book['author'], $book['genre'], $book['description'], $book['price'], (int) $book['stock'], $book['cover_image']]
    );
    return (int) db()->lastInsertId();
}

/**
 * Updates a book. When the cover changes, the previous uploaded cover file
 * (if any, and if no other book uses it) is deleted.
 *
 * @param array<string, string> $book Output of validate_book_input()
 */
function update_book(int $id, array $book): bool
{
    $previousCover = db_value('SELECT cover_image FROM books WHERE id = ?', [$id]);
    db_run(
        'UPDATE books SET title = ?, author = ?, genre = ?, description = ?, price = ?, stock = ?, cover_image = ? WHERE id = ?',
        [$book['title'], $book['author'], $book['genre'], $book['description'], $book['price'], (int) $book['stock'], $book['cover_image'], $id]
    );
    if ($previousCover === null || find_book($id) === null) {
        return false;
    }
    if ($previousCover !== $book['cover_image']) {
        release_book_cover((string) $previousCover);
    }
    return true;
}

/**
 * Deletes a book. Cart lines, favorites, reviews and sales rows are removed by
 * foreign-key cascades; order history keeps its title/price snapshot. Its
 * uploaded cover file is deleted too.
 */
function delete_book(int $id): bool
{
    $cover = db_value('SELECT cover_image FROM books WHERE id = ?', [$id]);
    $deleted = db_run('DELETE FROM books WHERE id = ?', [$id])->rowCount() > 0;
    if ($deleted && is_string($cover)) {
        release_book_cover($cover);
    }
    return $deleted;
}

/**
 * Deletes an uploaded cover file (uploads/covers/<generated name>) once no
 * book refers to it any more. Catalog images and URLs are never touched.
 */
function release_book_cover(?string $path): void
{
    if (!is_generated_upload($path, COVER_DIR)) {
        return;
    }
    if (db_value('SELECT 1 FROM books WHERE cover_image = ? LIMIT 1', [$path]) !== null) {
        return;
    }
    delete_uploaded_cover($path);
}

/**
 * Books for the admin table, newest first; all of them unless a page is given.
 *
 * @return list<array<string, mixed>>
 */
function all_books_for_admin(?int $limit = null, int $offset = 0): array
{
    $sql = 'SELECT id, title, author, genre, price, stock, cover_image FROM books ORDER BY id DESC';
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
    }
    return db_all($sql);
}
