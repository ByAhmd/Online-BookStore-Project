<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// Shown when a cover file is missing: a neutral bookshelf picture.
const HOME_COVER_FALLBACK = 'imges/header.jpeg';

$customer = current_user();
$admin = $customer === null ? current_admin() : null;

$totalBooks = book_count();
$genres = list_genres();
$recommended = recommended_books(8);
$bestSellers = best_selling_books(8);
$appName = (string) config('app.name', 'BookWise');

/**
 * One card in a horizontal book row. Administrators cannot open the shop's book
 * page (it sends them back to the dashboard), so their cards open the editor.
 *
 * @param array<string, mixed> $book
 */
$renderCard = static function (array $book, ?int $sold = null, ?float $rating = null) use ($admin): void {
    $id = (int) $book['id'];
    $stock = (int) $book['stock'];
    $href = $admin !== null ? 'edit_book.php?id=' . $id : 'Selected.php?id=' . $id;
    ?>
                <li class="home-book">
                    <a class="home-book-link" href="<?= e($href) ?>">
<?php if ($admin !== null): ?>
                        <span class="sr-only"><?= e(t('shop.home.edit_book')) ?></span>
<?php endif; ?>
                        <div class="home-book-cover">
                            <img src="<?= e(image_url((string) $book['cover_image'], HOME_COVER_FALLBACK)) ?>" alt="" width="380" height="605" loading="lazy" decoding="async">
                        </div>
                        <div class="home-book-body">
                            <h3 class="home-book-title" dir="auto"><?= e($book['title']) ?></h3>
                            <p class="home-book-author" dir="auto"><?= e($book['author']) ?></p>
                            <p class="home-book-meta">
                                <span class="home-book-price"><?= e(format_money((string) $book['price'])) ?></span>
<?php if ($sold !== null && $sold > 0): ?>
                                <span class="home-book-note"><?= e(tn('shop.home.sold', $sold)) ?></span>
<?php elseif ($rating !== null): ?>
                                <span class="home-book-note"><span aria-hidden="true">&#9733;</span> <?= e(number_format($rating, 1)) ?><span class="sr-only"> <?= e(t('shop.home.rating_sr')) ?></span></span>
<?php endif; ?>
                            </p>
                            <p class="stock-badge stock-<?= e(book_stock_status($stock)) ?>"><?= e(book_availability($stock)) ?></p>
                        </div>
                    </a>
                </li>
<?php
};

page_header(t('shop.home.title'), ['HomeCSS.css']);
?>
    <div class="home-page">
        <section class="home-hero" aria-labelledby="home-hero-title">
<?php if ($customer !== null): ?>
            <p class="home-hero-greeting"><?= t_markup('shop.home.greeting', ['name' => '<span dir="auto">' . e($customer['name']) . '</span>']) ?></p>
<?php endif; ?>
            <h1 id="home-hero-title"><?= e(t('shop.home.hero_title', ['app' => $appName])) ?></h1>
<?php if ($totalBooks > 0): ?>
            <p class="home-hero-count"><?= e(tn('shop.home.count', $totalBooks)) ?></p>
<?php else: ?>
            <p class="home-hero-count"><?= e(t('shop.home.empty_catalog')) ?></p>
<?php endif; ?>
            <div class="home-hero-actions">
<?php if ($customer !== null): ?>
                <a class="pill pill-red home-cta" href="Explore_Page.php"><?= e(t('shop.home.cta_explore')) ?></a>
<?php elseif ($admin !== null): ?>
                <a class="pill pill-red home-cta" href="admin_dashboard.php"><?= e(t('shop.home.cta_manage')) ?></a>
<?php else: ?>
                <a class="pill pill-red home-cta" href="index.php?form=signup"><?= e(t('shop.home.cta_signup')) ?></a>
                <a class="pill pill-dark home-cta" href="index.php?form=login"><?= e(t('shop.home.cta_login')) ?></a>
<?php endif; ?>
            </div>
        </section>

<?php if ($genres !== [] && $admin === null): ?>
        <section class="home-section" aria-labelledby="home-genres-title">
            <h2 id="home-genres-title" class="home-section-title"><?= e(t('shop.home.genres_title')) ?></h2>
            <ul class="genre-chips" role="list">
<?php foreach ($genres as $genre): ?>
                <li><a class="genre-chip" href="Explore_Page.php?genre=<?= e(rawurlencode($genre)) ?>" dir="auto"><?= e(genre_label($genre)) ?></a></li>
<?php endforeach; ?>
            </ul>
        </section>
<?php endif; ?>

        <section class="home-section" aria-labelledby="home-recommended-title">
            <div class="home-section-head">
                <h2 id="home-recommended-title" class="home-section-title"><?= e(t('shop.home.recommended_title')) ?></h2>
<?php if ($recommended !== [] && $admin === null): ?>
                <a class="home-see-all" href="Explore_Page.php"><?= e(t('shop.home.see_all')) ?></a>
<?php endif; ?>
            </div>
<?php if ($recommended === []): ?>
            <p class="home-empty"><?= e(t('shop.home.recommended_empty')) ?></p>
<?php else: ?>
            <ul class="books-row" role="list">
<?php foreach ($recommended as $book): ?>
<?php $renderCard($book, null, $book['avg_rating'] !== null ? round((float) $book['avg_rating'], 1) : null); ?>
<?php endforeach; ?>
            </ul>
<?php endif; ?>
        </section>

        <section class="home-section" aria-labelledby="home-loved-title">
            <h2 id="home-loved-title" class="home-section-title"><?= e(t('shop.home.loved_title')) ?></h2>
<?php if ($bestSellers === []): ?>
            <p class="home-empty"><?= e(t('shop.home.loved_empty')) ?></p>
<?php else: ?>
            <ul class="books-row" role="list">
<?php foreach ($bestSellers as $book): ?>
<?php $renderCard($book, (int) $book['sold']); ?>
<?php endforeach; ?>
            </ul>
<?php endif; ?>
        </section>
    </div>
<?php
page_footer();
