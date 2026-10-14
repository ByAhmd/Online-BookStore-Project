<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// Shown when a cover file is missing: a neutral bookshelf picture.
const EXPLORE_COVER_FALLBACK = 'imges/header.jpeg';
// Displayed cover width in the grid (.explore-grid in explore.css); explore.js uses it too.
const EXPLORE_COVER_SIZES = '(min-width: 600px) 240px, 50vw';
const EXPLORE_QUERY_MAX_LENGTH = 100;

$user = require_user();

$genres = list_genres();
$genre = input_string($_GET, 'genre', 100);
if ($genre !== 'Favorites' && !in_array($genre, $genres, true)) {
    $genre = 'all';
}
$query = input_string($_GET, 'q', EXPLORE_QUERY_MAX_LENGTH);

$listing = catalog_page(
    $genre === 'Favorites' ? (int) $user['id'] : null,
    $genre === 'all' || $genre === 'Favorites' ? null : $genre,
    $query,
    $_GET['page'] ?? null
);
$books = $listing['books'];
$pagination = $listing['pagination'];
$summary = catalog_summary($genre, $query, $pagination);

$exploreUrl = static function (string $tabGenre, string $q, int $page = 1): string {
    $params = ['genre' => $tabGenre];
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }
    return 'Explore_Page.php?' . http_build_query($params);
};

// [value, label] pairs; a list keeps numeric-looking genre names as strings.
$tabs = [['all', t('shop.explore.tab_all')]];
foreach ($genres as $name) {
    $tabs[] = [$name, genre_label($name)];
}
$tabs[] = ['Favorites', t('shop.explore.tab_favorites')];

// Strings explore.js needs (it never hard-codes interface text).
$i18n = [
    'loading' => t('shop.explore.loading'),
    'error-title' => t('shop.explore.error_title'),
    'error-text' => t('shop.explore.error_text'),
    'error-status' => t('shop.explore.error_status'),
    'retry' => t('shop.explore.retry'),
    'no-match-title' => t('shop.explore.no_match_title'),
    'no-match-text' => t('shop.explore.no_match_text'),
    'clear-search' => t('shop.explore.clear_search'),
    'no-favorites-title' => t('shop.explore.no_favorites_title'),
    'no-favorites-text' => t('shop.explore.no_favorites_text'),
    'browse-all' => t('shop.explore.browse_all'),
    'empty-title' => t('shop.explore.empty_title'),
    'empty-text' => t('shop.explore.empty_text'),
    'pager-label' => t('shop.explore.pager_label'),
    'previous' => t('shop.pager.previous'),
    'next' => t('shop.pager.next'),
    'page' => t('shop.pager.page'),
];

page_header(t('shop.explore.title'), ['explore.css']);
?>
    <div class="page-container explore-page" data-explore data-genre="<?= e($genre) ?>" data-page="<?= (int) $pagination['page'] ?>" data-cover-sizes="<?= e(EXPLORE_COVER_SIZES) ?>"
<?php foreach ($i18n as $name => $text): ?>
        data-i18n-<?= e($name) ?>="<?= e($text) ?>"
<?php endforeach; ?>
    >
        <h1 class="explore-title"><?= e(t('shop.explore.heading')) ?></h1>

        <form class="explore-search" method="get" action="Explore_Page.php" role="search">
            <input type="hidden" name="genre" value="<?= e($genre) ?>">
            <label class="explore-search-label" for="explore-q"><?= e(t('shop.explore.search_label')) ?></label>
            <div class="explore-search-box">
                <input class="explore-search-input" id="explore-q" type="search" name="q" dir="auto"
                    value="<?= e($query) ?>" maxlength="<?= EXPLORE_QUERY_MAX_LENGTH ?>"
                    placeholder="<?= e(t('shop.explore.search_placeholder')) ?>" autocomplete="off" spellcheck="false">
                <button class="explore-search-button" type="submit"><?= e(t('shop.explore.search_button')) ?></button>
            </div>
        </form>

        <nav class="genre-tabs" aria-label="<?= e(t('shop.explore.genres_label')) ?>">
            <ul role="list">
<?php foreach ($tabs as [$value, $label]): ?>
                <li>
                    <a class="genre-tab<?= $value === 'Favorites' ? ' genre-tab-favorites' : '' ?><?= $value === $genre ? ' is-active' : '' ?>"
                        href="<?= e($exploreUrl($value, $query)) ?>"
                        data-genre="<?= e($value) ?>"<?= $value === $genre ? ' aria-current="page"' : '' ?>>
<?php if ($value === 'Favorites'): ?>
                        <span aria-hidden="true">&#9829;</span>
<?php endif; ?>
                        <span dir="auto"><?= e($label) ?></span>
                    </a>
                </li>
<?php endforeach; ?>
            </ul>
        </nav>

        <section class="explore-results-section" aria-labelledby="explore-results-title">
            <h2 id="explore-results-title" class="sr-only"><?= e(t('shop.explore.results_heading')) ?></h2>
            <p id="explore-status" class="explore-status" role="status" aria-live="polite"><?= e($summary) ?></p>

            <div id="explore-results" class="explore-results" aria-busy="false">
<?php if ($books !== []): ?>
                <ul class="explore-grid" role="list">
<?php foreach ($books as $book):
    $stock = (int) $book['stock'];
    ?>
                    <li class="explore-card">
                        <a class="explore-card-link" href="Selected.php?id=<?= (int) $book['id'] ?>">
                            <div class="explore-card-cover">
                                <?= cover_picture(image_url((string) $book['cover_image'], EXPLORE_COVER_FALLBACK), EXPLORE_COVER_SIZES, ['width' => 380, 'height' => 605, 'loading' => 'lazy', 'decoding' => 'async']) ?>
                            </div>
                            <div class="explore-card-body">
                                <h3 class="explore-card-title" dir="auto"><?= e($book['title']) ?></h3>
                                <p class="explore-card-author" dir="auto"><?= e($book['author']) ?></p>
                                <p class="stock-badge stock-<?= e(book_stock_status($stock)) ?>"><?= e(book_availability($stock)) ?></p>
                            </div>
                            <span class="explore-card-price"><?= e(format_money((string) $book['price'])) ?></span>
                        </a>
                    </li>
<?php endforeach; ?>
                </ul>
<?php elseif ($query !== ''): ?>
                <div class="empty-state explore-empty">
                    <p class="explore-empty-title"><?= e(t('shop.explore.no_match_title')) ?></p>
                    <p><?= e(t('shop.explore.no_match_text')) ?></p>
                    <a class="btn" href="<?= e($exploreUrl($genre, '')) ?>" data-genre="<?= e($genre) ?>" data-q=""><?= e(t('shop.explore.clear_search')) ?></a>
                </div>
<?php elseif ($genre === 'Favorites'): ?>
                <div class="empty-state explore-empty">
                    <p class="explore-empty-title"><?= e(t('shop.explore.no_favorites_title')) ?></p>
                    <p><?= e(t('shop.explore.no_favorites_text')) ?></p>
                    <a class="btn" href="<?= e($exploreUrl('all', '')) ?>" data-genre="all" data-q=""><?= e(t('shop.explore.browse_all')) ?></a>
                </div>
<?php else: ?>
                <div class="empty-state explore-empty">
                    <p class="explore-empty-title"><?= e(t('shop.explore.empty_title')) ?></p>
                    <p><?= e(t('shop.explore.empty_text')) ?></p>
                </div>
<?php endif; ?>
            </div>

            <div id="explore-pager" class="explore-pager">
                <?= render_pager($pagination, static fn (int $page): string => $exploreUrl($genre, $query, $page), t('shop.explore.pager_label')) ?>
            </div>
        </section>
    </div>
<?php
page_footer(['explore.js']);
