<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;

/**
 * Selected.php: add to cart, favorites, reviews and review reactions.
 */
final class BookPageTest extends HttpTestCase
{
    private const BOOK = 13;        // The Mirror of Destiny (Mystery)
    private const OTHER_BOOK = 9;   // Dark (Mystery)

    private function act(HttpClient $client, int $bookId, string $action, array $values = []): HttpResponse
    {
        return $client->submitForm($this->bookPage($client, $bookId), 'Selected.php?id=' . $bookId, $values, ['action' => $action]);
    }

    public function testAddToCartRespectsQuantityBounds(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];
        $title = (string) find_book(self::BOOK)['title'];

        $page = $this->bookPage($customer, self::BOOK);
        $options = $page->document()->texts('//select[@id="quantity"]/option');
        self::assertSame(array_map('strval', range(1, CART_MAX_QUANTITY)), $options, 'The page offers 1..10 copies');

        foreach (['0', '11', '-1', 'abc', '2.5', ''] as $invalid) {
            $response = $this->addToCart($customer, self::BOOK, $invalid);
            $this->assertRedirect($response, 'Selected.php?id=' . self::BOOK . '#purchase', 303, 'Quantity ' . json_encode($invalid));
            $document = $customer->follow($response)->document();
            self::assertSame('Choose a quantity between 1 and 10.', $document->fieldError('quantity-error'));
            self::assertSame([], $this->cartQuantities($userId));
        }

        $response = $this->addToCart($customer, self::BOOK, 3);
        $this->assertRedirect($response, 'cart.php');
        $this->assertFlash($customer->follow($response), 'success', "Added 3 × $title to your cart.");
        self::assertSame([self::BOOK => 3], $this->cartQuantities($userId));
        $this->assertPageText('You have 3 copies in your cart.', $this->bookPage($customer, self::BOOK));

        // Adding more than fits is capped at 10 per title.
        $response = $this->addToCart($customer, self::BOOK, 9);
        $this->assertFlash($customer->follow($response), 'success', "Added 7 × $title to your cart. (Limit: 10 copies per title.)");
        self::assertSame([self::BOOK => 10], $this->cartQuantities($userId));

        $response = $this->addToCart($customer, self::BOOK, 1);
        $this->assertRedirect($response, 'cart.php');
        $this->assertFlash($customer->follow($response), 'info', "Your cart already holds the maximum of 10 copies of \"$title\".");
        self::assertSame([self::BOOK => 10], $this->cartQuantities($userId));
        $this->assertPageText('You have the maximum of 10 copies in your cart.', $this->bookPage($customer, self::BOOK));
    }

    public function testFavoriteToggle(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $title = (string) find_book(self::BOOK)['title'];

        // The label states the action, so the button is not also a toggle (no aria-pressed).
        $button = '//form[@id="favorite"]//button';
        $state = static function (HttpResponse $page) use ($button): array {
            $element = $page->document()->first($button);
            self::assertNotNull($element);
            return [
                'aria-pressed' => $element->hasAttribute('aria-pressed'),
                'is-favorite' => in_array('is-favorite', explode(' ', $element->getAttribute('class')), true),
                'label' => $page->document()->text($button),
            ];
        };
        self::assertSame(['aria-pressed' => false, 'is-favorite' => false, 'label' => '♡ Add to Favorites'], $state($this->bookPage($customer, self::BOOK)));

        $response = $this->act($customer, self::BOOK, 'toggle_favorite');
        $this->assertRedirect($response, 'Selected.php?id=' . self::BOOK . '#book');
        $page = $customer->follow($response);
        $this->assertFlash($page, 'success', "Added \"$title\" to your favorites.");
        self::assertSame(['aria-pressed' => false, 'is-favorite' => true, 'label' => '♥ Remove from Favorites'], $state($page));
        self::assertSame('♥', $page->document()->text($button . '/span[@aria-hidden="true"]'), 'Decorative icon only');
        self::assertSame(1, $this->countRows('favorites', 'user_id = ? AND book_id = ?', [$user['id'], self::BOOK]));

        $page = $customer->follow($this->act($customer, self::BOOK, 'toggle_favorite'));
        $this->assertFlash($page, 'success', "Removed \"$title\" from your favorites.");
        self::assertSame(['aria-pressed' => false, 'is-favorite' => false, 'label' => '♡ Add to Favorites'], $state($page));
        self::assertSame(0, $this->countRows('favorites'));
    }

    public function testReviewsRequireAPurchaseValidRatingAndOnlyOneReviewPerReader(): void
    {
        [$customer, $user] = $this->signedInCustomer('Review Writer');
        $userId = (int) $user['id'];
        $bookUrl = 'Selected.php?id=' . self::BOOK;

        $page = $this->bookPage($customer, self::BOOK);
        $this->assertPageText('Purchase this book to leave a review.', $page);
        self::assertFalse($page->document()->hasForm($bookUrl, ['action' => 'add_review']));

        // Posting anyway is refused.
        $response = $customer->postWithToken($bookUrl, ['action' => 'add_review', 'rating' => '5', 'comment' => 'Never bought it']);
        $this->assertRedirect($response, $bookUrl . '#review');
        $this->assertFlash($customer->follow($response), 'error', 'Only readers who bought this book can review it.');
        self::assertSame(0, $this->countRows('comments'));

        db_run('INSERT INTO sales (book_id, user_id, quantity) VALUES (?, ?, 1)', [self::BOOK, $userId]);
        $page = $this->bookPage($customer, self::BOOK);
        self::assertTrue($page->document()->hasForm($bookUrl, ['action' => 'add_review']));

        foreach (['0', '6', '-1', 'abc', '4.5', ''] as $rating) {
            $response = $this->act($customer, self::BOOK, 'add_review', ['rating' => $rating, 'comment' => 'Kept comment']);
            $this->assertRedirect($response, $bookUrl . '#review');
            $document = $customer->follow($response)->document();
            self::assertSame('Please choose a rating from 1 to 5 stars.', $document->fieldError('rating-error'), 'Rating ' . json_encode($rating));
            self::assertSame('Kept comment', $document->text('//textarea[@id="review-comment"]'), 'The comment is kept for correction');
        }
        $response = $this->act($customer, self::BOOK, 'add_review', ['rating' => '4', 'comment' => '   ']);
        self::assertSame('Please write a few words about the book.', $customer->follow($response)->document()->fieldError('review-comment-error'));
        $response = $this->act($customer, self::BOOK, 'add_review', ['rating' => '4', 'comment' => str_repeat('x', COMMENT_MAX_LENGTH + 1)]);
        self::assertSame('Your review must be 2000 characters or fewer.', $customer->follow($response)->document()->fieldError('review-comment-error'));
        self::assertSame(0, $this->countRows('comments'));

        $response = $this->act($customer, self::BOOK, 'add_review', ['rating' => '4', 'comment' => "A gripping read.\r\nSecond line <b>bold?</b>"]);
        $this->assertRedirect($response, $bookUrl . '#reviews');
        $page = $customer->follow($response);
        $this->assertHtmlPage($page);
        $this->assertFlash($page, 'success', 'Thanks! Your review has been posted.');
        $comment = db_one('SELECT * FROM comments WHERE book_id = ?', [self::BOOK]);
        self::assertNotNull($comment);
        self::assertSame(4, (int) $comment['rating']);
        self::assertSame("A gripping read.\nSecond line <b>bold?</b>", $comment['comment']);
        self::assertSame('A gripping read. Second line <b>bold?</b>', $page->document()->text('//p[contains(@class, "review-text")]'), 'Review text is escaped');
        self::assertSame('Review Writer', $page->document()->text('//p[contains(@class, "review-author")]'));
        $this->assertPageText("You've already reviewed this book.", $page);
        $this->assertPageText('4.0 / 5', $page);

        // A second review is refused.
        $response = $customer->postWithToken($bookUrl, ['action' => 'add_review', 'rating' => '1', 'comment' => 'Changed my mind']);
        $this->assertFlash($customer->follow($response), 'error', "You've already reviewed this book.");
        self::assertSame(1, $this->countRows('comments'));

        // The reviewer's email is never shown, to them or to other readers.
        [$reader] = $this->signedInCustomer();
        foreach ([$page, $this->bookPage($reader, self::BOOK), $reader->get('Home_Page.php')] as $html) {
            self::assertStringNotContainsString((string) $user['Eaddress'], $html->body);
            self::assertStringNotContainsString(e((string) $user['Eaddress']), $html->body);
        }
    }

    public function testReviewReactionsToggleAndStayOnTheirBook(): void
    {
        $author = $this->createUser(self::uniqueEmail('author'), 'Author Reader');
        db_run('INSERT INTO comments (book_id, username, comment, rating) VALUES (?, ?, ?, ?)', [self::BOOK, $author['Eaddress'], 'Loved it.', 5]);
        $commentId = (int) db()->lastInsertId();
        [$customer, $user] = $this->signedInCustomer();
        $bookUrl = 'Selected.php?id=' . self::BOOK;

        $reaction = fn (): ?string => db_value('SELECT reaction_type FROM comment_interactions WHERE comment_id = ? AND user_id = ?', [$commentId, $user['id']]);
        $react = function (string $type) use ($customer, $bookUrl, $commentId): HttpResponse {
            $response = $this->act($customer, self::BOOK, 'react', ['reaction' => $type]);
            $this->assertRedirect($response, $bookUrl . '#comment-' . $commentId);
            return $customer->follow($response);
        };
        $counts = static fn (HttpResponse $page): array => $page->document()->texts('//li[@id="comment-' . $commentId . '"]//span[@class="reaction-count"]');

        $page = $react('like');
        self::assertSame('like', $reaction());
        self::assertSame(['1', '0'], $counts($page));
        self::assertSame('true', $page->document()->first('//li[@id="comment-' . $commentId . '"]//button[@value="like"]')?->getAttribute('aria-pressed'));

        self::assertSame(['0', '0'], $counts($react('like')), 'The same reaction again removes it');
        self::assertNull($reaction());

        $react('dislike');
        self::assertSame('dislike', $reaction());
        self::assertSame(['1', '0'], $counts($react('like')), 'The other reaction switches it');
        self::assertSame('like', $reaction());
        self::assertSame(1, $this->countRows('comment_interactions'));

        // A comment id from another book, an unknown id or an unknown reaction is rejected.
        $this->assertHtmlPage($customer->postWithToken('Selected.php?id=' . self::OTHER_BOOK, ['action' => 'react', 'comment_id' => (string) $commentId, 'reaction' => 'dislike']), 404);
        $this->assertHtmlPage($customer->postWithToken($bookUrl, ['action' => 'react', 'comment_id' => '999999', 'reaction' => 'like']), 404);
        $this->assertHtmlPage($customer->postWithToken($bookUrl, ['action' => 'react', 'comment_id' => 'abc', 'reaction' => 'like']), 404);
        $this->assertHtmlPage($customer->postWithToken($bookUrl, ['action' => 'react', 'comment_id' => (string) $commentId, 'reaction' => 'love']), 400);
        $this->assertHtmlPage($customer->postWithToken($bookUrl, ['action' => 'explode']), 400);
        self::assertSame('like', $reaction());
        self::assertSame(1, $this->countRows('comment_interactions'));
    }
}
