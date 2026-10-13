<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\DatabaseTestCase;
use PDOException;

final class BooksTest extends DatabaseTestCase
{
    private const BIOLOGY = 7;          // Science, 5.50
    private const BRIDGE = 8;           // Mystery, 10.00
    private const ORWELL_1984 = 23;     // Crime, 10.00
    private const ESABELLA = 51;        // Romantic, 19.99

    /* -----------------------------------------------------------------
     * Catalog reads
     * --------------------------------------------------------------- */

    public function testSeedCatalogIsLoaded(): void
    {
        self::assertSame(self::SEED_BOOK_COUNT, book_count());
        self::assertCount(self::SEED_BOOK_COUNT, all_books_for_admin());
        self::assertSame(61, all_books_for_admin()[0]['id'], 'Newest id first');
    }

    public function testFindBook(): void
    {
        $book = find_book(self::BIOLOGY);

        self::assertNotNull($book);
        self::assertSame(['Biology', 'anishtain', 'Science', '5.50', 'imges/All_section/biology.png'], [
            $book['title'], $book['author'], $book['genre'], $book['price'], $book['cover_image'],
        ]);
        self::assertNull(find_book(999999));
        self::assertNull(find_book(0));
        self::assertNull(find_book(-1));
    }

    public function testListGenresIsDistinctAndSorted(): void
    {
        self::assertSame(['Crime', 'Drama', 'Fantasy', 'Historical', 'Horror', 'Mystery', 'Romantic', 'Science'], list_genres());
    }

    public function testSearchByGenre(): void
    {
        $romantic = search_books('Romantic');

        self::assertCount(16, $romantic);
        self::assertSame(['Romantic'], array_values(array_unique(array_column($romantic, 'genre'))));
        $titles = array_column($romantic, 'title');
        $sorted = $titles;
        sort($sorted, SORT_STRING | SORT_FLAG_CASE);
        self::assertSame($sorted, $titles, 'Ordered by title');
        self::assertSame([], search_books('Cookbooks'));
        self::assertCount(self::SEED_BOOK_COUNT, search_books(''), 'An empty genre means all genres');
        self::assertCount(self::SEED_BOOK_COUNT, search_books(null));
    }

    public function testSearchByTitleOrAuthorIsCaseInsensitive(): void
    {
        $love = array_column(search_books(null, 'LOVE'), 'id');
        sort($love);
        self::assertSame([46, 47, 48, 58, 60, 61], $love);

        self::assertCount(9, search_books(null, 'olivia wilson'), 'Matches authors');
        self::assertSame([51, 60, 61, 52], array_column(search_books('Romantic', 'Olivia'), 'id'), 'Genre and author combined, ordered by title');
        self::assertSame([], search_books('Science', 'love'), 'Genre and query are combined');
    }

    public function testSearchTreatsLikeWildcardsLiterally(): void
    {
        $percent = $this->insertBook(['title' => 'Discount 100% Off']);
        $underscore = $this->insertBook(['title' => 'snake_case Handbook']);
        $backslash = $this->insertBook(['title' => 'Paths C:\\Books']);

        self::assertSame([$percent], array_column(search_books(null, '%'), 'id'));
        self::assertSame([$underscore], array_column(search_books(null, '_'), 'id'));
        self::assertSame([$backslash], array_column(search_books(null, '\\'), 'id'));
        self::assertSame([$backslash], array_column(search_books(null, 'C:\\B'), 'id'));
        self::assertSame([], search_books(null, '%%%'));
    }

    public function testSearchInputIsNeverInterpretedAsSql(): void
    {
        self::assertSame([], search_books("Romantic' OR '1'='1"));
        self::assertSame([], search_books(null, "' OR 1=1 -- "));
        self::assertSame(self::SEED_BOOK_COUNT, book_count());
    }

    public function testSearchLimitIsClamped(): void
    {
        self::assertCount(5, search_books(null, '', 5));
        self::assertCount(1, search_books(null, '', 0));
        self::assertCount(1, search_books(null, '', -10));
        self::assertCount(self::SEED_BOOK_COUNT, search_books(null, '', 100000));
    }

    public function testSimilarBooksShareTheGenreAndExcludeTheBook(): void
    {
        $book = find_book(self::BRIDGE);
        $similar = similar_books($book, 50);

        self::assertCount(13, $similar, '14 Mystery books minus the book itself');
        self::assertNotContains(self::BRIDGE, array_column($similar, 'id'));
        foreach ($similar as $row) {
            self::assertSame('Mystery', find_book($row['id'])['genre']);
        }
        self::assertCount(7, similar_books($book));
    }

    /* -----------------------------------------------------------------
     * Sales and ratings
     * --------------------------------------------------------------- */

    public function testBestSellingBooksAreOrderedBySoldCopies(): void
    {
        $user = (int) $this->createUser()['id'];
        db_run('INSERT INTO sales (book_id, user_id, quantity) VALUES (?, ?, 3), (?, ?, 1), (?, ?, 2)', [
            self::BIOLOGY, $user, self::BRIDGE, $user, self::BRIDGE, $user,
        ]);

        $top = best_selling_books(3);

        self::assertSame([self::BRIDGE, self::BIOLOGY], [$top[0]['id'], $top[1]['id']], 'Bridge sold 3 copies over two rows; Biology 3 in one row (tie broken by newest)');
        self::assertSame(3, (int) $top[0]['sold']);
        self::assertSame(0, (int) $top[2]['sold']);
        self::assertSame(3, book_sales_count(self::BIOLOGY));
        self::assertSame(0, book_sales_count(self::ESABELLA));
        self::assertTrue(user_has_purchased($user, self::BIOLOGY));
        self::assertFalse(user_has_purchased($user, self::ESABELLA));
    }

    public function testRecommendedBooksPreferHighestAverageRating(): void
    {
        add_review(self::BIOLOGY, 'a@example.com', 'Great', 5);
        add_review(self::BIOLOGY, 'b@example.com', 'Good', 4);
        add_review(self::BRIDGE, 'a@example.com', 'Perfect', 5);
        add_review(self::ORWELL_1984, 'a@example.com', 'Meh', 2);

        $recommended = recommended_books(4);

        self::assertSame([self::BRIDGE, self::BIOLOGY, self::ORWELL_1984], array_slice(array_column($recommended, 'id'), 0, 3));
        self::assertEqualsWithDelta(4.5, (float) $recommended[1]['avg_rating'], 0.0001);
        self::assertNull($recommended[3]['avg_rating'], 'Padded with unrated books');
        self::assertSame(['average' => 4.5, 'count' => 2], book_rating_summary(self::BIOLOGY));
        self::assertSame(['average' => 0.0, 'count' => 0], book_rating_summary(self::ESABELLA));
    }

    /* -----------------------------------------------------------------
     * Favorites
     * --------------------------------------------------------------- */

    public function testToggleFavorite(): void
    {
        $user = (int) $this->createUser()['id'];

        self::assertFalse(is_favorite($user, self::BIOLOGY));
        self::assertTrue(toggle_favorite($user, self::BIOLOGY));
        self::assertTrue(is_favorite($user, self::BIOLOGY));
        self::assertSame([self::BIOLOGY], array_column(favorite_books($user), 'id'));

        self::assertFalse(toggle_favorite($user, self::BIOLOGY));
        self::assertFalse(is_favorite($user, self::BIOLOGY));
        self::assertSame([], favorite_books($user));
    }

    public function testFavoritesAreUniqueAndPerUser(): void
    {
        $alice = (int) $this->createUser()['id'];
        $bob = (int) $this->createUser()['id'];
        toggle_favorite($alice, self::BIOLOGY);
        toggle_favorite($alice, self::BRIDGE);
        toggle_favorite($bob, self::BIOLOGY);

        self::assertSame([self::BRIDGE, self::BIOLOGY], array_column(favorite_books($alice), 'id'), 'Most recent first');
        self::assertSame([self::BIOLOGY], array_column(favorite_books($bob), 'id'));

        $this->expectException(PDOException::class);
        db_run('INSERT INTO favorites (user_id, book_id) VALUES (?, ?)', [$alice, self::BIOLOGY]);
    }

    public function testFavoritingAMissingBookStoresNothing(): void
    {
        $user = (int) $this->createUser()['id'];

        try {
            toggle_favorite($user, 999999);
        } catch (PDOException) {
            // Either outcome is acceptable as long as no dangling row is stored.
        }

        self::assertSame(0, $this->countRows('favorites'));
        self::assertFalse(is_favorite($user, 999999));
    }

    /**
     * Regression test for a fixed bug (src/books.php toggle_favorite): INSERT IGNORE turns the
     * foreign-key failure for a non-existent book into a warning, so the
     * function returns true ("now favorited") although nothing was stored. A
     * page that trusts the return value shows "Added to favorites" for any
     * posted book id. It should check the insert's rowCount() (or the book).
     */
    public function testFavoritingAMissingBookDoesNotReportSuccess(): void
    {
        $user = (int) $this->createUser()['id'];

        try {
            $state = toggle_favorite($user, 999999);
        } catch (PDOException) {
            $state = false;
        }

        self::assertFalse($state);
    }

    /* -----------------------------------------------------------------
     * Admin catalog management
     * --------------------------------------------------------------- */

    public function testCreateAndUpdateBook(): void
    {
        [$clean, $errors] = validate_book_input([
            'title' => 'A Brand New Book',
            'author' => 'New Author',
            'genre' => 'Science',
            'description' => 'Fresh.',
            'price' => '12.5',
            'cover_image' => 'imges/All_section/biology.png',
        ]);
        self::assertSame([], $errors);

        $id = create_book($clean);
        self::assertGreaterThan(61, $id);
        self::assertSame(['A Brand New Book', '12.50'], [find_book($id)['title'], find_book($id)['price']]);
        self::assertSame(self::SEED_BOOK_COUNT + 1, book_count());

        $clean['title'] = 'Renamed Book';
        self::assertTrue(update_book($id, $clean));
        self::assertSame('Renamed Book', find_book($id)['title']);
        self::assertFalse(update_book(999999, $clean), 'Unknown book');
        self::assertSame(self::SEED_BOOK_COUNT + 1, book_count());
    }

    public function testDeleteBookCascadesButKeepsOrderHistory(): void
    {
        $user = $this->createUser();
        $userId = (int) $user['id'];
        $other = (int) $this->createUser()['id'];
        cart_add($userId, self::ESABELLA, 2);
        toggle_favorite($userId, self::ESABELLA);
        add_review(self::ESABELLA, (string) $user['Eaddress'], 'Loved it', 5);
        $commentId = (int) db_value('SELECT id FROM comments WHERE book_id = ?', [self::ESABELLA]);
        toggle_comment_reaction($other, self::ESABELLA, $commentId, 'like');

        $order = create_pending_order($userId, cart_lines($userId));
        db_run('INSERT INTO sales (book_id, user_id, order_id, quantity) VALUES (?, ?, ?, 2)', [self::ESABELLA, $userId, $order['id']]);

        self::assertTrue(delete_book(self::ESABELLA));

        self::assertNull(find_book(self::ESABELLA));
        foreach (['cart', 'favorites', 'comments', 'comment_interactions', 'sales'] as $table) {
            self::assertSame(0, $this->countRows($table), "$table rows are removed with the book");
        }
        self::assertSame(
            [['book_id' => null, 'title' => 'Esabella', 'unit_price' => '19.99', 'quantity' => 2]],
            order_items((int) $order['id']),
            'Order lines keep their title/price snapshot'
        );
        self::assertNotNull(find_order((int) $order['id']));
        self::assertFalse(delete_book(self::ESABELLA), 'Already deleted');
    }
}
