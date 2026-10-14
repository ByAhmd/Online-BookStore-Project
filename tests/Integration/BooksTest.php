<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\DatabaseTestCase;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

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

    /* -----------------------------------------------------------------
     * Pagination and stock
     * --------------------------------------------------------------- */

    public function testCatalogPagesSplitTheOrderedListWithoutGapsOrRepeats(): void
    {
        $all = array_column(search_books(), 'id');
        self::assertSame(self::SEED_BOOK_COUNT, count_books());

        $pages = [];
        foreach ([1, 2, 3] as $page) {
            $listing = catalog_page(null, null, '', (string) $page);
            self::assertSame(['page' => $page, 'pages' => 3, 'per_page' => CATALOG_PAGE_SIZE, 'total' => self::SEED_BOOK_COUNT, 'offset' => ($page - 1) * CATALOG_PAGE_SIZE], $listing['pagination']);
            $pages[] = array_column($listing['books'], 'id');
        }
        self::assertSame([24, 24, 7], array_map('count', $pages));
        self::assertSame($all, array_merge(...$pages));

        self::assertSame(16, count_books('Romantic'));
        self::assertSame(count(search_books(null, 'olivia')), count_books(null, 'olivia'));
        self::assertSame(1, catalog_page(null, 'Romantic', '', '5')['pagination']['pages']);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: int}>
     */
    public static function requestedPages(): iterable
    {
        yield 'missing' => [null, 1];
        yield 'first' => ['1', 1];
        yield 'middle' => ['2', 2];
        yield 'last' => ['3', 3];
        yield 'past the end' => ['4', 3];
        yield 'far past the end' => ['99999999999999999999', 3];
        yield 'zero' => ['0', 1];
        yield 'negative' => ['-2', 1];
        yield 'plus sign' => ['+2', 2];
        yield 'padded' => [' 02 ', 2];
        yield 'decimal' => ['2.5', 1];
        yield 'text' => ['two', 1];
        yield 'array' => [['2'], 1];
        yield 'integer' => [2, 2];
        yield 'integer past the end' => [40, 3];
    }

    #[DataProvider('requestedPages')]
    public function testPageNumbersAreClampedToTheValidRange(mixed $requested, int $expected): void
    {
        self::assertSame($expected, paginate(55, 24, $requested)['page']);
    }

    public function testPaginationOfAnEmptyListHasOnePage(): void
    {
        self::assertSame(['page' => 1, 'pages' => 1, 'per_page' => 20, 'total' => 0, 'offset' => 0], paginate(0, 20, '7'));
        self::assertSame([], catalog_page(null, null, 'no-such-book-anywhere', '3')['books']);
    }

    public function testPagerItemsKeepTheEndsAndTheNeighbourhood(): void
    {
        self::assertSame([1], pager_items(1, 1));
        self::assertSame([1, 2, 3], pager_items(2, 3));
        self::assertSame([1, 2, 3, null, 10], pager_items(1, 10));
        self::assertSame([1, null, 4, 5, 6, 7, 8, null, 10], pager_items(6, 10));
        self::assertSame([1, null, 8, 9, 10], pager_items(10, 10));
    }

    public function testFavoritesArePaginatedToo(): void
    {
        $user = (int) $this->createUser()['id'];
        foreach ([7, 8, 9] as $bookId) {
            toggle_favorite($user, $bookId);
        }
        self::assertSame(3, count_favorite_books($user));
        $page = catalog_page($user, null, '', '2', 2);
        self::assertSame(2, $page['pagination']['page']);
        self::assertCount(1, $page['books']);
    }

    public function testStockStatusAndAvailabilityLabels(): void
    {
        self::assertSame(['out', 'out', 'low', 'low', 'in', 'in'], array_map('book_stock_status', [-1, 0, 1, STOCK_LOW_THRESHOLD, STOCK_LOW_THRESHOLD + 1, 1000]));
        self::assertSame('Out of stock', book_availability(0));
        self::assertSame('Only 1 left', book_availability(1));
        self::assertSame('Only 5 left', book_availability(5));
        self::assertSame('In stock', book_availability(6));

        use_locale('ar');
        try {
            self::assertSame('نفدت الكمية', book_availability(0));
            self::assertSame('تبقّت نسخة واحدة فقط', book_availability(1));
            self::assertSame('تبقّت نسختان فقط', book_availability(2));
            self::assertSame('تبقّت 4 نسخ فقط', book_availability(4));
            self::assertSame('متوفر', book_availability(40));
        } finally {
            use_locale(null);
        }
        self::assertSame(24, find_book(self::BIOLOGY)['stock'], 'The seed gives every book some copies');
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
            'stock' => '7',
            'cover_image' => 'imges/All_section/biology.png',
        ]);
        self::assertSame([], $errors);

        $id = create_book($clean);
        self::assertGreaterThan(61, $id);
        self::assertSame(['A Brand New Book', '12.50', 7], [find_book($id)['title'], find_book($id)['price'], find_book($id)['stock']]);
        self::assertSame(self::SEED_BOOK_COUNT + 1, book_count());

        $clean['title'] = 'Renamed Book';
        $clean['stock'] = '0';
        self::assertTrue(update_book($id, $clean));
        self::assertSame(['Renamed Book', 0], [find_book($id)['title'], find_book($id)['stock']]);
        self::assertFalse(update_book(999999, $clean), 'Unknown book');
        self::assertSame(self::SEED_BOOK_COUNT + 1, book_count());

        // With the stock the form showed, the admin's change is applied as a
        // difference: copies sold meanwhile (7 shown, 5 left now) are kept.
        db_run('UPDATE books SET stock = 5 WHERE id = ?', [$id]);
        $clean['stock'] = '7';
        self::assertTrue(update_book($id, $clean, 7));
        self::assertSame(5, find_book($id)['stock'], 'Unchanged stock field: the sale is not undone');
        $clean['stock'] = '17';
        self::assertTrue(update_book($id, $clean, 7));
        self::assertSame(15, find_book($id)['stock'], '+10 restocked on top of the current stock');
        $clean['stock'] = '0';
        self::assertTrue(update_book($id, $clean, 30));
        self::assertSame(0, find_book($id)['stock'], 'Never below zero');
        $clean['stock'] = (string) BOOK_STOCK_MAX;
        db_run('UPDATE books SET stock = ? WHERE id = ?', [BOOK_STOCK_MAX - 1, $id]);
        self::assertTrue(update_book($id, $clean, 0));
        self::assertSame(BOOK_STOCK_MAX, find_book($id)['stock'], 'Never above the maximum');
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
