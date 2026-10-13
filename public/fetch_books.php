<?php

declare(strict_types=1);

/**
 * JSON catalog listing used by the Explore page.
 *
 * GET fetch_books.php?genre=<all|Favorites|genre>&q=<search>
 * Unknown genres are treated as "all", exactly like Explore_Page.php.
 */

require __DIR__ . '/../src/bootstrap.php';

// Shown when a cover file is missing: a neutral bookshelf picture.
const FETCH_COVER_FALLBACK = 'imges/header.jpeg';

if (!in_array(request_method(), ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    json_response(['error' => 'Method not allowed. Use GET.'], 405);
}

$genres = list_genres();
$genre = input_string($_GET, 'genre', 100);
if ($genre !== 'Favorites' && !in_array($genre, $genres, true)) {
    $genre = 'all';
}
$query = input_string($_GET, 'q', 100);

if ($genre === 'Favorites') {
    $user = current_user();
    if ($user === null) {
        json_response(['error' => 'Please log in as a customer to see your favorites.'], 401);
    }
    $rows = favorite_books((int) $user['id'], $query);
    $truncated = false;
} else {
    $rows = search_books($genre === 'all' ? null : $genre, $query);
    $truncated = count($rows) >= BOOK_LIST_LIMIT;
}

$books = array_map(static function (array $row): array {
    $id = (int) $row['id'];
    return [
        'id' => $id,
        'title' => (string) $row['title'],
        'author' => (string) $row['author'],
        'genre' => (string) $row['genre'],
        'price' => minor_to_decimal(money_to_minor((string) $row['price'])),
        'price_display' => format_money((string) $row['price']),
        'cover_image' => image_url((string) $row['cover_image'], FETCH_COVER_FALLBACK),
        'url' => 'Selected.php?id=' . $id,
    ];
}, $rows);

json_response([
    'books' => $books,
    'count' => count($books),
    'genre' => $genre,
    'q' => $query,
    'truncated' => $truncated,
]);
