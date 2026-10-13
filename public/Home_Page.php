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

/**
 * One card in a horizontal book row. Administrators cannot open the shop's book
 * page (it sends them back to the dashboard), so their cards open the editor.
 *
 * @param array<string, mixed> $book
 */
$renderCard = static function (array $book, ?int $sold = null, ?float $rating = null) use ($admin): void {
    $id = (int) $book['id'];
    $href = $admin !== null ? 'edit_book.php?id=' . $id : 'Selected.php?id=' . $id;
    ?>
                <li class="home-book">
                    <a class="home-book-link" href="<?= e($href) ?>">
<?php if ($admin !== null): ?>
                        <span class="sr-only">Edit book:</span>
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
                                <span class="home-book-note"><?= e(number_format($sold)) ?> sold</span>
<?php elseif ($rating !== null): ?>
                                <span class="home-book-note"><span aria-hidden="true">&#9733;</span> <?= e(number_format($rating, 1)) ?><span class="sr-only"> out of 5 stars</span></span>
<?php endif; ?>
                            </p>
                        </div>
                    </a>
                </li>
<?php
};

page_header('Home', ['HomeCSS.css']);
?>
    <div class="home-page">
        <section class="home-hero" aria-labelledby="home-hero-title">
<?php if ($customer !== null): ?>
            <p class="home-hero-greeting">Hello, <span dir="auto"><?= e($customer['name']) ?></span>!</p>
<?php endif; ?>
            <h1 id="home-hero-title">Discover Your Next Read with <?= e(config('app.name', 'BookWise')) ?></h1>
<?php if ($totalBooks > 0): ?>
            <p class="home-hero-count"><?= e(number_format($totalBooks)) ?> <?= $totalBooks === 1 ? 'Book' : 'Books' ?> Available for You to Explore</p>
<?php else: ?>
            <p class="home-hero-count">Our shelves are being stocked. Check back soon!</p>
<?php endif; ?>
            <div class="home-hero-actions">
<?php if ($customer !== null): ?>
                <a class="pill pill-red home-cta" href="Explore_Page.php">Explore books</a>
<?php elseif ($admin !== null): ?>
                <a class="pill pill-red home-cta" href="admin_dashboard.php">Manage the catalog</a>
<?php else: ?>
                <a class="pill pill-red home-cta" href="index.php?form=signup">Create a free account</a>
                <a class="pill pill-dark home-cta" href="index.php?form=login">Log in</a>
<?php endif; ?>
            </div>
        </section>

<?php if ($genres !== [] && $admin === null): ?>
        <section class="home-section" aria-labelledby="home-genres-title">
            <h2 id="home-genres-title" class="home-section-title">Browse by genre</h2>
            <ul class="genre-chips" role="list">
<?php foreach ($genres as $genre): ?>
                <li><a class="genre-chip" href="Explore_Page.php?genre=<?= e(rawurlencode($genre)) ?>"><?= e($genre) ?></a></li>
<?php endforeach; ?>
            </ul>
        </section>
<?php endif; ?>

        <section class="home-section" aria-labelledby="home-recommended-title">
            <div class="home-section-head">
                <h2 id="home-recommended-title" class="home-section-title">Recommended Reads</h2>
<?php if ($recommended !== [] && $admin === null): ?>
                <a class="home-see-all" href="Explore_Page.php">See all books</a>
<?php endif; ?>
            </div>
<?php if ($recommended === []): ?>
            <p class="home-empty">No recommendations yet. New books are on their way.</p>
<?php else: ?>
            <ul class="books-row" role="list">
<?php foreach ($recommended as $book): ?>
<?php $renderCard($book, null, $book['avg_rating'] !== null ? round((float) $book['avg_rating'], 1) : null); ?>
<?php endforeach; ?>
            </ul>
<?php endif; ?>
        </section>

        <section class="home-section" aria-labelledby="home-loved-title">
            <h2 id="home-loved-title" class="home-section-title">Books Loved by Readers</h2>
<?php if ($bestSellers === []): ?>
            <p class="home-empty">No books in the catalog yet. Check back soon!</p>
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
