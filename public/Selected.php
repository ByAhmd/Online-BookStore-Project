<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = require_user();
$userId = (int) $user['id'];
$userEmail = (string) $user['Eaddress'];

$bookId = parse_int($_GET['id'] ?? null);
$book = $bookId !== null ? find_book($bookId) : null;
if ($book === null) {
    abort(404, t('shop.book.not_found'));
}
$bookUrl = 'Selected.php?id=' . $bookId;
$title = (string) $book['title'];

// Copies of this book already in the customer's cart.
$quantityInCart = static function () use ($userId, $bookId): int {
    foreach (cart_lines($userId) as $line) {
        if ($line['book_id'] === $bookId) {
            return $line['quantity'];
        }
    }
    return 0;
};

if (is_post()) {
    verify_csrf();

    switch (input_string($_POST, 'action', 32)) {
        case 'add_to_cart':
            $quantity = parse_int($_POST['quantity'] ?? null, 1, CART_MAX_QUANTITY);
            if ($quantity === null) {
                remember_errors(['quantity' => t('shop.book.quantity_error', ['max' => CART_MAX_QUANTITY])]);
                redirect($bookUrl . '#purchase');
            }

            // Never more than the per-title limit or the copies in stock.
            $result = cart_add_detailed($userId, $bookId, $quantity);
            $stockLimited = $result['limit'] < CART_MAX_QUANTITY;
            switch ($result['status']) {
                case 'missing':
                    abort(404, t('shop.book.not_found'));
                case 'out_of_stock':
                    flash('error', t('shop.book.out_of_stock_flash', ['title' => $title]));
                    redirect($bookUrl . '#purchase');
                case 'at_limit':
                    flash('info', $stockLimited
                        ? tn('shop.book.at_limit_stock', $result['limit'], ['title' => $title])
                        : t('shop.book.at_limit_max', ['max' => CART_MAX_QUANTITY, 'title' => $title]));
                    redirect('cart.php');
                case 'capped':
                    flash('success', $stockLimited
                        ? tn('shop.book.added_capped_stock', $result['limit'], ['added' => $result['added'], 'title' => $title])
                        : t('shop.book.added_capped_max', ['added' => $result['added'], 'title' => $title, 'max' => CART_MAX_QUANTITY]));
                    redirect('cart.php');
                default:
                    flash('success', t('shop.book.added', ['added' => $result['added'], 'title' => $title]));
                    redirect('cart.php');
            }

            // no break: every branch redirects
        case 'toggle_favorite':
            $favorited = toggle_favorite($userId, $bookId);
            flash('success', t($favorited ? 'shop.book.favorite_added' : 'shop.book.favorite_removed', ['title' => $title]));
            redirect($bookUrl . '#book');

        case 'add_review':
            $reviewUrl = $bookUrl . '#review';
            if (!user_has_purchased($userId, $bookId)) {
                flash('error', t('shop.book.review_not_purchased'));
                redirect($reviewUrl);
            }
            if (user_has_reviewed($userEmail, $bookId)) {
                flash('error', t('shop.book.review_already'));
                redirect($reviewUrl);
            }

            $rating = validate_rating($_POST['rating'] ?? null);
            // Browsers submit line breaks as CRLF but count them as one character.
            $comment = str_replace(["\r\n", "\r"], "\n", input_string($_POST, 'comment', COMMENT_MAX_LENGTH * 2));

            $errors = [];
            if ($rating === null) {
                $errors['rating'] = t('shop.book.review_rating_error');
            }
            if ($comment === '') {
                $errors['comment'] = t('shop.book.review_comment_required');
            } elseif (mb_strlen($comment) > COMMENT_MAX_LENGTH) {
                $errors['comment'] = t('shop.book.review_comment_too_long', ['max' => COMMENT_MAX_LENGTH]);
            }
            if ($errors !== []) {
                remember_input(['rating' => $rating !== null ? (string) $rating : '', 'comment' => $comment]);
                remember_errors($errors);
                redirect($reviewUrl);
            }

            if (!add_review($bookId, $userEmail, $comment, $rating)) {
                flash('error', t('shop.book.review_already'));
                redirect($reviewUrl);
            }
            flash('success', t('shop.book.review_posted'));
            redirect($bookUrl . '#reviews');

        case 'react':
            $commentId = parse_int($_POST['comment_id'] ?? null);
            $reaction = input_string($_POST, 'reaction', 16);
            if ($commentId === null) {
                abort(404, t('shop.book.review_not_found'));
            }
            if (!in_array($reaction, ['like', 'dislike'], true)) {
                abort(400, t('shop.book.unknown_reaction'));
            }
            if (!toggle_comment_reaction($userId, $bookId, $commentId, $reaction)) {
                abort(404, t('shop.book.review_not_found'));
            }
            redirect($bookUrl . '#comment-' . $commentId);

        default:
            abort(400, t('shop.book.unknown_action'));
    }
}

$isFavorite = is_favorite($userId, $bookId);
$soldCount = book_sales_count($bookId);
$ratingSummary = book_rating_summary($bookId);
$reviews = book_comments($bookId, $userId);
$hasPurchased = user_has_purchased($userId, $bookId);
$hasReviewed = user_has_reviewed($userEmail, $bookId);
$similarBooks = similar_books($book);
$inCart = $quantityInCart();
$coverUrl = image_url((string) $book['cover_image'], '');
$stock = (int) $book['stock'];
$stockStatus = book_stock_status($stock);
$orderable = cart_quantity_limit($stock);

$quantityError = form_error('quantity');
$ratingError = form_error('rating');
$commentError = form_error('comment');
$oldRating = validate_rating(old('rating'));
$oldComment = old('comment');

$describedBy = array_filter([
    $orderable === 0 ? 'stock-note' : null,
    $quantityError !== null ? 'quantity-error' : null,
]);

// Star graphic (decorative); callers always pair it with a text equivalent.
$stars = static function (float $value): string {
    $percent = number_format(max(0.0, min(100.0, $value / 5 * 100)), 1, '.', '');
    return '<span class="stars" aria-hidden="true">'
        . '<span class="stars-fill" style="width: ' . e($percent) . '%">&#9733;&#9733;&#9733;&#9733;&#9733;</span>'
        . '&#9733;&#9733;&#9733;&#9733;&#9733;</span>';
};

$reviewDate = static function (string $timestamp): array {
    try {
        $date = new DateTimeImmutable($timestamp);
    } catch (Exception) {
        return ['', $timestamp];
    }
    return [$date->format(DATE_ATOM), display_date($date)];
};

$average = (float) $ratingSummary['average'];
$reviewCount = (int) $ratingSummary['count'];
$authorHtml = static fn (string $author): string => '<span dir="auto">' . e($author) . '</span>';

page_header($title, ['selected.css']);
?>
        <div class="page-container book-detail-page">
            <article class="book-panel" id="book" aria-labelledby="book-title">
                <div class="book-cover">
<?php if ($coverUrl !== ''): ?>
                    <img class="pic" src="<?= e($coverUrl) ?>" alt="<?= e(t('shop.book.cover_alt', ['title' => $title])) ?>" width="260" height="414">
<?php else: ?>
                    <div class="pic cover-placeholder" role="img" aria-label="<?= e(t('shop.book.no_cover')) ?>"><span aria-hidden="true">&#128214;</span></div>
<?php endif; ?>
                </div>

                <div class="book-info">
                    <div class="book-heading">
                        <h1 class="book-title" id="book-title" dir="auto"><?= e($title) ?></h1>
                        <p class="book-author"><?= t_markup('shop.book.by', ['author' => $authorHtml((string) $book['author'])]) ?></p>
                    </div>

                    <ul class="book-meta">
                        <li>
                            <span class="meta-label"><?= e(t('shop.book.genre_label')) ?></span>
                            <a class="genre-link" href="Explore_Page.php?genre=<?= e(rawurlencode((string) $book['genre'])) ?>" dir="auto"><?= e($book['genre']) ?></a>
                        </li>
                        <li><span class="meta-label"><?= e(t('shop.book.sold_label')) ?></span> <?= e(tn('shop.book.sold_times', $soldCount)) ?></li>
                        <li class="book-rating">
<?php if ($reviewCount > 0): ?>
                            <?= $stars($average) ?>
                            <span aria-hidden="true"><?= e(number_format($average, 1)) ?> / 5</span>
                            <span class="sr-only"><?= e(t('shop.book.average_sr', ['rating' => number_format($average, 1)])) ?></span>
                            <a href="#reviews"><?= e(tn('shop.book.reviews_count', $reviewCount)) ?></a>
<?php else: ?>
                            <span class="meta-label"><?= e(t('shop.book.rating_label')) ?></span> <?= e(t('shop.book.no_ratings')) ?>
<?php endif; ?>
                        </li>
                    </ul>

                    <div class="add-to-cart-box" id="purchase">
                        <p class="book-price"><span class="sr-only"><?= e(t('shop.book.price_sr')) ?></span><?= e(format_money($book['price'])) ?></p>
                        <p class="stock-badge stock-<?= e($stockStatus) ?>" id="stock-status"><?= e(book_availability($stock)) ?></p>
                        <form class="add-to-cart-form" method="post" action="<?= e($bookUrl) ?>" data-once>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_to_cart">
                            <div class="quantity-field">
                                <label for="quantity"><?= e(t('shop.book.quantity_label')) ?></label>
                                <select class="form-control" id="quantity" name="quantity"<?= $orderable === 0 ? ' disabled' : '' ?><?= $quantityError !== null ? ' aria-invalid="true"' : '' ?><?= $describedBy !== [] ? ' aria-describedby="' . e(implode(' ', $describedBy)) . '"' : '' ?>>
<?php if ($orderable === 0): ?>
                                    <option value="">0</option>
<?php endif; ?>
<?php for ($i = 1; $i <= $orderable; $i++): ?>
                                    <option value="<?= $i ?>"><?= $i ?></option>
<?php endfor; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-danger cart-button"<?= $orderable === 0 ? ' disabled aria-describedby="stock-note"' : '' ?>><?= e(t('shop.book.add_to_cart')) ?></button>
                        </form>
<?php if ($orderable === 0): ?>
                        <p class="stock-note" id="stock-note"><?= e(t('shop.book.out_of_stock_note')) ?></p>
<?php endif; ?>
<?php if ($quantityError !== null): ?>
                        <p class="field-error" id="quantity-error"><?= e($quantityError) ?></p>
<?php endif; ?>
<?php if ($inCart > 0): ?>
                        <p class="cart-note">
                            <?= e($inCart >= CART_MAX_QUANTITY
                                ? t('shop.book.in_cart_max', ['max' => CART_MAX_QUANTITY])
                                : tn('shop.book.in_cart', $inCart)) ?>
                            <a href="cart.php"><?= e(t('shop.book.view_cart')) ?></a>
                        </p>
<?php endif; ?>
                    </div>

                    <form class="favorite-form" id="favorite" method="post" action="<?= e($bookUrl) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_favorite">
                        <button type="submit" class="btn btn-danger favorite-button<?= $isFavorite ? ' is-favorite' : '' ?>">
                            <span class="favorite-icon" aria-hidden="true"><?= $isFavorite ? '&#9829;' : '&#9825;' ?></span>
                            <?= e(t($isFavorite ? 'shop.book.favorite_remove_button' : 'shop.book.favorite_add_button')) ?>
                        </button>
                    </form>

                    <section class="book-description" aria-labelledby="description-title">
                        <h2 id="description-title"><?= e(t('shop.book.description_title')) ?></h2>
                        <div class="description-box">
<?php if (trim((string) $book['description']) !== ''): ?>
                            <p dir="auto"><?= nl2br(e($book['description'])) ?></p>
<?php else: ?>
                            <p><?= e(t('shop.book.no_description')) ?></p>
<?php endif; ?>
                        </div>
                    </section>
                </div>
            </article>

            <div class="rating-comments-section">
                <section class="rating" id="review" aria-labelledby="rate-title">
                    <h2 id="rate-title"><?= e(t('shop.book.rate_title')) ?></h2>
<?php if (!$hasPurchased): ?>
                    <p class="review-note"><?= e(t('shop.book.purchase_to_review')) ?></p>
<?php elseif ($hasReviewed): ?>
                    <p class="review-note"><?= e(t('shop.book.review_already')) ?></p>
<?php else: ?>
                    <form class="review-form" method="post" action="<?= e($bookUrl) ?>" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_review">
<?php if ($ratingError !== null || $commentError !== null): ?>
                        <p class="form-alert" role="alert"><?= e(t('shop.book.form_alert')) ?></p>
<?php endif; ?>

                        <fieldset class="star-rating" role="radiogroup" aria-labelledby="rating-legend"<?= $ratingError !== null ? ' aria-invalid="true" aria-describedby="rating-error"' : '' ?>>
                            <legend id="rating-legend"><?= e(t('shop.book.your_rating')) ?></legend>
                            <div class="star-options">
<?php for ($i = 1; $i <= 5; $i++): ?>
                                <input class="sr-only" type="radio" id="rating-<?= $i ?>" name="rating" value="<?= $i ?>" required<?= $oldRating === $i ? ' checked' : '' ?>>
                                <label class="star" for="rating-<?= $i ?>"><span aria-hidden="true">&#9733;</span><span class="sr-only"><?= e(tn('shop.book.stars', $i)) ?></span></label>
<?php endfor; ?>
                            </div>
                            <p class="star-rating-value" id="rating-value" data-i18n-rating="<?= e(t('shop.book.rating_value')) ?>"></p>
<?php if ($ratingError !== null): ?>
                            <p class="field-error" id="rating-error"><?= e($ratingError) ?></p>
<?php endif; ?>
                        </fieldset>

                        <div class="form-field">
                            <label for="review-comment"><?= e(t('shop.book.your_review')) ?></label>
                            <textarea class="form-control" id="review-comment" name="comment" rows="5" maxlength="<?= COMMENT_MAX_LENGTH ?>" required dir="auto" placeholder="<?= e(t('shop.book.review_placeholder')) ?>" aria-describedby="review-comment-hint<?= $commentError !== null ? ' review-comment-error' : '' ?>"<?= $commentError !== null ? ' aria-invalid="true"' : '' ?>><?= e($oldComment) ?></textarea>
                            <p class="form-hint" id="review-comment-hint" data-max="<?= COMMENT_MAX_LENGTH ?>" data-i18n-counter="<?= e(t('shop.book.review_counter')) ?>"><?= e(t('shop.book.review_hint', ['max' => number_format(COMMENT_MAX_LENGTH)])) ?></p>
<?php if ($commentError !== null): ?>
                            <p class="field-error" id="review-comment-error"><?= e($commentError) ?></p>
<?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-danger review-submit"><?= e(t('shop.book.submit_review')) ?></button>
                    </form>
<?php endif; ?>
                </section>

                <section class="comments-display" id="reviews" aria-labelledby="reviews-title">
                    <h2 id="reviews-title"><?= e(t('shop.book.reviews_title')) ?> <span class="review-total">(<?= count($reviews) ?>)</span></h2>
<?php if ($reviews === []): ?>
                    <p class="empty-state reviews-empty"><?= e(t('shop.book.no_reviews')) ?></p>
<?php else: ?>
                    <ul class="review-list">
<?php foreach ($reviews as $review): ?>
<?php
    $commentId = (int) $review['id'];
    $rating = (int) $review['rating'];
    $likes = (int) $review['likes'];
    $dislikes = (int) $review['dislikes'];
    [$isoDate, $displayDate] = $reviewDate((string) $review['created_at']);
?>
                        <li class="review" id="comment-<?= $commentId ?>">
                            <div class="review-header">
                                <p class="review-author" dir="auto"><?= e($review['author_name'] ?? t('shop.book.anonymous')) ?></p>
                                <time class="review-date" datetime="<?= e($isoDate) ?>"><?= e($displayDate) ?></time>
                            </div>
                            <p class="review-rating">
                                <?= $stars((float) $rating) ?>
                                <span class="sr-only"><?= e(t('shop.book.rated_sr', ['rating' => $rating])) ?></span>
                            </p>
                            <p class="review-text" dir="auto"><?= nl2br(e($review['comment'])) ?></p>
                            <form class="review-reactions" method="post" action="<?= e($bookUrl) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="react">
                                <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                                <button type="submit" class="reaction-button" name="reaction" value="like" aria-pressed="<?= $review['my_reaction'] === 'like' ? 'true' : 'false' ?>">
                                    <span aria-hidden="true">&#128077;</span>
                                    <span class="sr-only"><?= e(t('shop.book.like_sr')) ?></span>
                                    <span class="reaction-count"><?= $likes ?></span>
                                    <span class="sr-only"><?= e(tn('shop.book.likes_word', $likes)) ?></span>
                                </button>
                                <button type="submit" class="reaction-button" name="reaction" value="dislike" aria-pressed="<?= $review['my_reaction'] === 'dislike' ? 'true' : 'false' ?>">
                                    <span aria-hidden="true">&#128078;</span>
                                    <span class="sr-only"><?= e(t('shop.book.dislike_sr')) ?></span>
                                    <span class="reaction-count"><?= $dislikes ?></span>
                                    <span class="sr-only"><?= e(tn('shop.book.dislikes_word', $dislikes)) ?></span>
                                </button>
                            </form>
                        </li>
<?php endforeach; ?>
                    </ul>
<?php endif; ?>
                </section>
            </div>

            <section class="similar-books" aria-labelledby="similar-title">
                <h2 id="similar-title"><?= e(t('shop.book.similar_title')) ?></h2>
<?php if ($similarBooks === []): ?>
                <p class="empty-state"><?= t_markup('shop.book.no_similar', ['genre' => '<span dir="auto">' . e($book['genre']) . '</span>']) ?> <a href="Explore_Page.php"><?= e(t('shop.book.explore_catalog')) ?></a></p>
<?php else: ?>
                <ul class="similar-books-container">
<?php foreach ($similarBooks as $similar): ?>
<?php $similarCover = image_url((string) $similar['cover_image'], ''); ?>
                    <li class="book-item">
                        <a class="book-link" href="Selected.php?id=<?= (int) $similar['id'] ?>">
<?php if ($similarCover !== ''): ?>
                            <img class="book-cover" src="<?= e($similarCover) ?>" alt="" width="150" height="239" loading="lazy">
<?php else: ?>
                            <span class="book-cover cover-placeholder" aria-hidden="true">&#128214;</span>
<?php endif; ?>
                            <h3 dir="auto"><?= e($similar['title']) ?></h3>
                            <p><?= t_markup('shop.book.by', ['author' => $authorHtml((string) $similar['author'])]) ?></p>
                            <p class="stock-badge stock-<?= e(book_stock_status((int) $similar['stock'])) ?>"><?= e(book_availability((int) $similar['stock'])) ?></p>
                        </a>
                    </li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
            </section>
        </div>
<?php
page_footer(['selected.js']);
