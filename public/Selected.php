<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = require_user();
$userId = (int) $user['id'];
$userEmail = (string) $user['Eaddress'];

$bookId = parse_int($_GET['id'] ?? null);
$book = $bookId !== null ? find_book($bookId) : null;
if ($book === null) {
    abort(404, 'Book not found.');
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
                remember_errors(['quantity' => 'Choose a quantity between 1 and ' . CART_MAX_QUANTITY . '.']);
                redirect($bookUrl . '#purchase');
            }

            $room = CART_MAX_QUANTITY - $quantityInCart();
            if ($room <= 0) {
                flash('info', sprintf('Your cart already holds the maximum of %d copies of "%s".', CART_MAX_QUANTITY, $title));
                redirect('cart.php');
            }
            if (!cart_add($userId, $bookId, $quantity)) {
                abort(404, 'Book not found.');
            }

            $added = min($quantity, $room);
            $message = sprintf('Added %d × %s to your cart.', $added, $title);
            if ($added < $quantity) {
                $message .= sprintf(' (Limit: %d copies per title.)', CART_MAX_QUANTITY);
            }
            flash('success', $message);
            redirect('cart.php');

        case 'toggle_favorite':
            $favorited = toggle_favorite($userId, $bookId);
            flash('success', $favorited
                ? sprintf('Added "%s" to your favorites.', $title)
                : sprintf('Removed "%s" from your favorites.', $title));
            redirect($bookUrl . '#book');

        case 'add_review':
            $reviewUrl = $bookUrl . '#review';
            if (!user_has_purchased($userId, $bookId)) {
                flash('error', 'Only readers who bought this book can review it.');
                redirect($reviewUrl);
            }
            if (user_has_reviewed($userEmail, $bookId)) {
                flash('error', "You've already reviewed this book.");
                redirect($reviewUrl);
            }

            $rating = validate_rating($_POST['rating'] ?? null);
            // Browsers submit line breaks as CRLF but count them as one character.
            $comment = str_replace(["\r\n", "\r"], "\n", input_string($_POST, 'comment', COMMENT_MAX_LENGTH * 2));

            $errors = [];
            if ($rating === null) {
                $errors['rating'] = 'Please choose a rating from 1 to 5 stars.';
            }
            if ($comment === '') {
                $errors['comment'] = 'Please write a few words about the book.';
            } elseif (mb_strlen($comment) > COMMENT_MAX_LENGTH) {
                $errors['comment'] = 'Your review must be ' . COMMENT_MAX_LENGTH . ' characters or fewer.';
            }
            if ($errors !== []) {
                remember_input(['rating' => $rating !== null ? (string) $rating : '', 'comment' => $comment]);
                remember_errors($errors);
                redirect($reviewUrl);
            }

            if (!add_review($bookId, $userEmail, $comment, $rating)) {
                flash('error', "You've already reviewed this book.");
                redirect($reviewUrl);
            }
            flash('success', 'Thanks! Your review has been posted.');
            redirect($bookUrl . '#reviews');

        case 'react':
            $commentId = parse_int($_POST['comment_id'] ?? null);
            $reaction = input_string($_POST, 'reaction', 16);
            if ($commentId === null) {
                abort(404, 'Review not found.');
            }
            if (!in_array($reaction, ['like', 'dislike'], true)) {
                abort(400, 'Unknown reaction.');
            }
            if (!toggle_comment_reaction($userId, $bookId, $commentId, $reaction)) {
                abort(404, 'Review not found.');
            }
            redirect($bookUrl . '#comment-' . $commentId);

        default:
            abort(400, 'Unknown action.');
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

$quantityError = form_error('quantity');
$ratingError = form_error('rating');
$commentError = form_error('comment');
$oldRating = validate_rating(old('rating'));
$oldComment = old('comment');

$plural = static fn (int $count, string $one, string $many): string => $count === 1 ? $one : $many;

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
    return [$date->format(DATE_ATOM), $date->format('M j, Y')];
};

$average = (float) $ratingSummary['average'];
$reviewCount = (int) $ratingSummary['count'];

page_header($title, ['selected.css']);
?>
        <div class="page-container book-detail-page">
            <article class="book-panel" id="book" aria-labelledby="book-title">
                <div class="book-cover">
<?php if ($coverUrl !== ''): ?>
                    <img class="pic" src="<?= e($coverUrl) ?>" alt="Cover of <?= e($title) ?>" width="260" height="414">
<?php else: ?>
                    <div class="pic cover-placeholder" role="img" aria-label="No cover image available"><span aria-hidden="true">&#128214;</span></div>
<?php endif; ?>
                </div>

                <div class="book-info">
                    <div class="book-heading">
                        <h1 class="book-title" id="book-title" dir="auto"><?= e($title) ?></h1>
                        <p class="book-author">by <span dir="auto"><?= e($book['author']) ?></span></p>
                    </div>

                    <ul class="book-meta">
                        <li>
                            <span class="meta-label">Genre:</span>
                            <a class="genre-link" href="Explore_Page.php?genre=<?= e(rawurlencode((string) $book['genre'])) ?>"><?= e($book['genre']) ?></a>
                        </li>
                        <li><span class="meta-label">Sold:</span> <?= $soldCount ?> <?= $plural($soldCount, 'time', 'times') ?></li>
                        <li class="book-rating">
<?php if ($reviewCount > 0): ?>
                            <?= $stars($average) ?>
                            <span aria-hidden="true"><?= e(number_format($average, 1)) ?> / 5</span>
                            <span class="sr-only">Average rating <?= e(number_format($average, 1)) ?> out of 5,</span>
                            <a href="#reviews"><?= $reviewCount ?> <?= $plural($reviewCount, 'review', 'reviews') ?></a>
<?php else: ?>
                            <span class="meta-label">Rating:</span> No ratings yet
<?php endif; ?>
                        </li>
                    </ul>

                    <div class="add-to-cart-box" id="purchase">
                        <p class="book-price"><span class="sr-only">Price: </span><?= e(format_money($book['price'])) ?></p>
                        <form class="add-to-cart-form" method="post" action="<?= e($bookUrl) ?>" data-once>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_to_cart">
                            <div class="quantity-field">
                                <label for="quantity">Quantity</label>
                                <select class="form-control" id="quantity" name="quantity"<?= $quantityError !== null ? ' aria-invalid="true" aria-describedby="quantity-error"' : '' ?>>
<?php for ($i = 1; $i <= CART_MAX_QUANTITY; $i++): ?>
                                    <option value="<?= $i ?>"><?= $i ?></option>
<?php endfor; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-danger cart-button">Add to cart</button>
                        </form>
<?php if ($quantityError !== null): ?>
                        <p class="field-error" id="quantity-error"><?= e($quantityError) ?></p>
<?php endif; ?>
<?php if ($inCart > 0): ?>
                        <p class="cart-note">
                            <?= $inCart >= CART_MAX_QUANTITY
                                ? 'You have the maximum of ' . CART_MAX_QUANTITY . ' copies in your cart.'
                                : 'You have ' . $inCart . ' ' . $plural($inCart, 'copy', 'copies') . ' in your cart.' ?>
                            <a href="cart.php">View cart</a>
                        </p>
<?php endif; ?>
                    </div>

                    <form class="favorite-form" id="favorite" method="post" action="<?= e($bookUrl) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_favorite">
                        <button type="submit" class="btn btn-danger favorite-button<?= $isFavorite ? ' is-favorite' : '' ?>">
                            <span class="favorite-icon" aria-hidden="true"><?= $isFavorite ? '&#9829;' : '&#9825;' ?></span>
                            <?= $isFavorite ? 'Remove from Favorites' : 'Add to Favorites' ?>
                        </button>
                    </form>

                    <section class="book-description" aria-labelledby="description-title">
                        <h2 id="description-title">Description</h2>
                        <div class="description-box">
<?php if (trim((string) $book['description']) !== ''): ?>
                            <p dir="auto"><?= nl2br(e($book['description'])) ?></p>
<?php else: ?>
                            <p>No description available.</p>
<?php endif; ?>
                        </div>
                    </section>
                </div>
            </article>

            <div class="rating-comments-section">
                <section class="rating" id="review" aria-labelledby="rate-title">
                    <h2 id="rate-title">Rate this Book</h2>
<?php if (!$hasPurchased): ?>
                    <p class="review-note">Purchase this book to leave a review.</p>
<?php elseif ($hasReviewed): ?>
                    <p class="review-note">You've already reviewed this book.</p>
<?php else: ?>
                    <form class="review-form" method="post" action="<?= e($bookUrl) ?>" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_review">
<?php if ($ratingError !== null || $commentError !== null): ?>
                        <p class="form-alert" role="alert">Please fix the highlighted fields and submit your review again.</p>
<?php endif; ?>

                        <fieldset class="star-rating" role="radiogroup" aria-labelledby="rating-legend"<?= $ratingError !== null ? ' aria-invalid="true" aria-describedby="rating-error"' : '' ?>>
                            <legend id="rating-legend">Your rating</legend>
                            <div class="star-options">
<?php for ($i = 1; $i <= 5; $i++): ?>
                                <input class="sr-only" type="radio" id="rating-<?= $i ?>" name="rating" value="<?= $i ?>" required<?= $oldRating === $i ? ' checked' : '' ?>>
                                <label class="star" for="rating-<?= $i ?>"><span aria-hidden="true">&#9733;</span><span class="sr-only"><?= $i ?> <?= $plural($i, 'star', 'stars') ?></span></label>
<?php endfor; ?>
                            </div>
                            <p class="star-rating-value" id="rating-value"></p>
<?php if ($ratingError !== null): ?>
                            <p class="field-error" id="rating-error"><?= e($ratingError) ?></p>
<?php endif; ?>
                        </fieldset>

                        <div class="form-field">
                            <label for="review-comment">Your review</label>
                            <textarea class="form-control" id="review-comment" name="comment" rows="5" maxlength="<?= COMMENT_MAX_LENGTH ?>" required placeholder="What did you think of this book?" aria-describedby="review-comment-hint<?= $commentError !== null ? ' review-comment-error' : '' ?>"<?= $commentError !== null ? ' aria-invalid="true"' : '' ?>><?= e($oldComment) ?></textarea>
                            <p class="form-hint" id="review-comment-hint" data-max="<?= COMMENT_MAX_LENGTH ?>">Up to <?= number_format(COMMENT_MAX_LENGTH) ?> characters.</p>
<?php if ($commentError !== null): ?>
                            <p class="field-error" id="review-comment-error"><?= e($commentError) ?></p>
<?php endif; ?>
                        </div>

                        <button type="submit" class="btn btn-danger review-submit">Submit review</button>
                    </form>
<?php endif; ?>
                </section>

                <section class="comments-display" id="reviews" aria-labelledby="reviews-title">
                    <h2 id="reviews-title">Reviews <span class="review-total">(<?= count($reviews) ?>)</span></h2>
<?php if ($reviews === []): ?>
                    <p class="empty-state reviews-empty">No reviews yet.</p>
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
                                <p class="review-author" dir="auto"><?= e($review['author_name']) ?></p>
                                <time class="review-date" datetime="<?= e($isoDate) ?>"><?= e($displayDate) ?></time>
                            </div>
                            <p class="review-rating">
                                <?= $stars((float) $rating) ?>
                                <span class="sr-only">Rated <?= $rating ?> out of 5 stars</span>
                            </p>
                            <p class="review-text" dir="auto"><?= nl2br(e($review['comment'])) ?></p>
                            <form class="review-reactions" method="post" action="<?= e($bookUrl) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="react">
                                <input type="hidden" name="comment_id" value="<?= $commentId ?>">
                                <button type="submit" class="reaction-button" name="reaction" value="like" aria-pressed="<?= $review['my_reaction'] === 'like' ? 'true' : 'false' ?>">
                                    <span aria-hidden="true">&#128077;</span>
                                    <span class="sr-only">Like this review,</span>
                                    <span class="reaction-count"><?= $likes ?></span>
                                    <span class="sr-only"><?= $plural($likes, 'like', 'likes') ?></span>
                                </button>
                                <button type="submit" class="reaction-button" name="reaction" value="dislike" aria-pressed="<?= $review['my_reaction'] === 'dislike' ? 'true' : 'false' ?>">
                                    <span aria-hidden="true">&#128078;</span>
                                    <span class="sr-only">Dislike this review,</span>
                                    <span class="reaction-count"><?= $dislikes ?></span>
                                    <span class="sr-only"><?= $plural($dislikes, 'dislike', 'dislikes') ?></span>
                                </button>
                            </form>
                        </li>
<?php endforeach; ?>
                    </ul>
<?php endif; ?>
                </section>
            </div>

            <section class="similar-books" aria-labelledby="similar-title">
                <h2 id="similar-title">Similar Books</h2>
<?php if ($similarBooks === []): ?>
                <p class="empty-state">No other <?= e($book['genre']) ?> books yet. <a href="Explore_Page.php">Explore the catalog</a></p>
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
                            <p>by <span dir="auto"><?= e($similar['author']) ?></span></p>
                        </a>
                    </li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
            </section>
        </div>
<?php
page_footer(['selected.js']);
