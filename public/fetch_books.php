<?php

declare(strict_types=1);

/**
 * JSON catalog listing used by the Explore page.
 *
 * GET fetch_books.php?genre=<all|Favorites|genre>&q=<search>&page=<n>
 * Unknown genres are treated as "all" and page numbers are clamped, exactly
 * like Explore_Page.php. Texts (summary, availability) are in the visitor's
 * language.
 */

require __DIR__ . '/../src/bootstrap.php';

// Shown when a cover file is missing: a neutral bookshelf picture.
const FETCH_COVER_FALLBACK = 'imges/header.jpeg';

if (!in_array(request_method(), ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    json_response(['error' => t('shop.explore.method_not_allowed')], 405);
}

$genres = list_genres();
$genre = input_string($_GET, 'genre', 100);
if ($genre !== 'Favorites' && !in_array($genre, $genres, true)) {
    $genre = 'all';
}
$query = input_string($_GET, 'q', 100);

$favoritesOf = null;
if ($genre === 'Favorites') {
    $user = current_user();
    if ($user === null) {
        json_response(['error' => t('shop.explore.favorites_login')], 401);
    }
    $favoritesOf = (int) $user['id'];
}

$listing = catalog_page($favoritesOf, $genre === 'all' || $genre === 'Favorites' ? null : $genre, $query, $_GET['page'] ?? null);
$pagination = $listing['pagination'];

$books = array_map(static function (array $row): array {
    $id = (int) $row['id'];
    $stock = (int) $row['stock'];
    return [
        'id' => $id,
        'title' => (string) $row['title'],
        'author' => (string) $row['author'],
        'genre' => (string) $row['genre'],
        'price' => minor_to_decimal(money_to_minor((string) $row['price'])),
        'price_display' => format_money((string) $row['price']),
        'cover_image' => image_url((string) $row['cover_image'], FETCH_COVER_FALLBACK),
        'url' => 'Selected.php?id=' . $id,
        'stock_status' => book_stock_status($stock),
        'availability' => book_availability($stock),
    ];
}, $listing['books']);

json_response([
    'books' => $books,
    'count' => count($books),
    'genre' => $genre,
    'q' => $query,
    'page' => $pagination['page'],
    'per_page' => $pagination['per_page'],
    'total' => $pagination['total'],
    'pages' => $pagination['pages'],
    'summary' => catalog_summary($genre, $query, $pagination),
]);
