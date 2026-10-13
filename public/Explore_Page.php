<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// Shown when a cover file is missing: a neutral bookshelf picture.
const EXPLORE_COVER_FALLBACK = 'imges/header.jpeg';
const EXPLORE_QUERY_MAX_LENGTH = 100;

$user = require_user();

$genres = list_genres();
$genre = input_string($_GET, 'genre', 100);
if ($genre !== 'Favorites' && !in_array($genre, $genres, true)) {
    $genre = 'all';
}
$query = input_string($_GET, 'q', EXPLORE_QUERY_MAX_LENGTH);

if ($genre === 'Favorites') {
    $books = favorite_books((int) $user['id'], $query);
    $truncated = false;
} else {
    $books = search_books($genre === 'all' ? null : $genre, $query);
    $truncated = count($books) >= BOOK_LIST_LIMIT;
}
$count = count($books);

$exploreUrl = static function (string $tabGenre, string $q): string {
    $params = ['genre' => $tabGenre];
    if ($q !== '') {
        $params['q'] = $q;
    }
    return 'Explore_Page.php?' . http_build_query($params);
};

// Keep in sync with describeResults() in js/explore.js.
$scope = match ($genre) {
    'all' => 'all genres',
    'Favorites' => 'your favorites',
    default => $genre,
};
$summary = 'Showing ' . number_format($count) . ' ' . ($count === 1 ? 'book' : 'books') . ' in ' . $scope
    . ($query !== '' ? ' matching “' . $query . '”' : '') . '.';
if ($truncated) {
    $summary .= ' Only the first ' . BOOK_LIST_LIMIT . ' are shown; refine your search to narrow the list.';
}

// [value, label] pairs; a list keeps numeric-looking genre names as strings.
$tabs = [['all', 'All']];
foreach ($genres as $name) {
    $tabs[] = [$name, $name];
}
$tabs[] = ['Favorites', 'Favorites'];

page_header('Explore', ['explore.css']);
?>
    <div class="page-container explore-page" data-explore data-genre="<?= e($genre) ?>">
        <h1 class="explore-title">Discover New Reads</h1>

        <form class="explore-search" method="get" action="Explore_Page.php" role="search">
            <input type="hidden" name="genre" value="<?= e($genre) ?>">
            <label class="explore-search-label" for="explore-q">Search by title or author</label>
            <div class="explore-search-box">
                <input class="explore-search-input" id="explore-q" type="search" name="q"
                    value="<?= e($query) ?>" maxlength="<?= EXPLORE_QUERY_MAX_LENGTH ?>"
                    placeholder="e.g. Olivia Wilson" autocomplete="off" spellcheck="false">
                <button class="explore-search-button" type="submit">Search</button>
            </div>
        </form>

        <nav class="genre-tabs" aria-label="Genres">
            <ul role="list">
<?php foreach ($tabs as [$value, $label]): ?>
                <li>
                    <a class="genre-tab<?= $value === 'Favorites' ? ' genre-tab-favorites' : '' ?><?= $value === $genre ? ' is-active' : '' ?>"
                        href="<?= e($exploreUrl($value, $query)) ?>"
                        data-genre="<?= e($value) ?>"<?= $value === $genre ? ' aria-current="page"' : '' ?>>
<?php if ($value === 'Favorites'): ?>
                        <span aria-hidden="true">&#9829;</span>
<?php endif; ?>
                        <?= e($label) ?>
                    </a>
                </li>
<?php endforeach; ?>
            </ul>
        </nav>

        <section class="explore-results-section" aria-labelledby="explore-results-title">
            <h2 id="explore-results-title" class="sr-only">Results</h2>
            <p id="explore-status" class="explore-status" role="status" aria-live="polite"><?= e($summary) ?></p>

            <div id="explore-results" class="explore-results" aria-busy="false">
<?php if ($books !== []): ?>
                <ul class="explore-grid" role="list">
<?php foreach ($books as $book): ?>
                    <li class="explore-card">
                        <a class="explore-card-link" href="Selected.php?id=<?= (int) $book['id'] ?>">
                            <div class="explore-card-cover">
                                <img src="<?= e(image_url((string) $book['cover_image'], EXPLORE_COVER_FALLBACK)) ?>" alt="" width="380" height="605" loading="lazy" decoding="async">
                            </div>
                            <div class="explore-card-body">
                                <h3 class="explore-card-title" dir="auto"><?= e($book['title']) ?></h3>
                                <p class="explore-card-author" dir="auto"><?= e($book['author']) ?></p>
                            </div>
                            <span class="explore-card-price"><?= e(format_money((string) $book['price'])) ?></span>
                        </a>
                    </li>
<?php endforeach; ?>
                </ul>
<?php elseif ($query !== ''): ?>
                <div class="empty-state explore-empty">
                    <p class="explore-empty-title">No books match your search</p>
                    <p>Try a different title or author, or check the spelling.</p>
                    <a class="btn" href="<?= e($exploreUrl($genre, '')) ?>" data-genre="<?= e($genre) ?>" data-q="">Clear search</a>
                </div>
<?php elseif ($genre === 'Favorites'): ?>
                <div class="empty-state explore-empty">
                    <p class="explore-empty-title">You haven't added any favorites yet.</p>
                    <p>Open a book and add it to your favorites to find it here quickly.</p>
                    <a class="btn" href="<?= e($exploreUrl('all', '')) ?>" data-genre="all" data-q="">Browse all books</a>
                </div>
<?php else: ?>
                <div class="empty-state explore-empty">
                    <p class="explore-empty-title">No books available yet.</p>
                    <p>New titles are on their way. Please check back soon.</p>
                </div>
<?php endif; ?>
            </div>
        </section>
    </div>
<?php
page_footer(['explore.js']);
