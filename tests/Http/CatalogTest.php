<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;

/**
 * Home page, Explore page (genre tabs, search) and the fetch_books.php JSON API.
 */
final class CatalogTest extends HttpTestCase
{
    /**
     * Expected books computed in PHP (not with SQL LIKE), sorted by title like the pages.
     *
     * @return list<int> book ids
     */
    private static function expectedIds(?string $genre, string $query): array
    {
        $books = array_filter(
            db_all('SELECT id, title, author, genre FROM books'),
            static fn (array $book): bool => ($genre === null || $book['genre'] === $genre)
                && ($query === '' || mb_stripos((string) $book['title'], $query) !== false || mb_stripos((string) $book['author'], $query) !== false)
        );
        usort($books, static fn (array $a, array $b): int => [strtolower((string) $a['title']), (int) $a['id']] <=> [strtolower((string) $b['title']), (int) $b['id']]);
        return array_map(static fn (array $book): int => (int) $book['id'], array_values($books));
    }

    /**
     * @return list<int> book ids of the result cards, in page order
     */
    private static function resultIds(HttpResponse $page): array
    {
        $ids = [];
        foreach ($page->document()->elements('//ul[' . HtmlDocument::hasClass('explore-grid') . ']//a[' . HtmlDocument::hasClass('explore-card-link') . ']') as $link) {
            self::assertSame(1, preg_match('/^Selected\.php\?id=(\d+)$/', $link->getAttribute('href'), $match));
            $ids[] = (int) $match[1];
        }
        return $ids;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);
        return $ids;
    }

    public function testHomePageShowsTheRealBookCount(): void
    {
        $guest = $this->client();
        $home = $guest->get('Home_Page.php');
        $this->assertHtmlPage($home);
        $this->assertPageText(self::SEED_BOOK_COUNT . ' Books Available for You to Explore', $home);
        self::assertSame(count(list_genres()), $home->document()->count('//a[' . HtmlDocument::hasClass('genre-chip') . ']'));

        $this->insertBook(['title' => 'Count Me In']);
        $this->assertPageText((self::SEED_BOOK_COUNT + 1) . ' Books Available for You to Explore', $guest->get('Home_Page.php'));

        db_run('SET FOREIGN_KEY_CHECKS = 0');
        try {
            db_run('DELETE FROM books');
        } finally {
            db_run('SET FOREIGN_KEY_CHECKS = 1');
        }
        $empty = $guest->get('Home_Page.php');
        $this->assertHtmlPage($empty);
        $this->assertPageText('Our shelves are being stocked. Check back soon!', $empty);
    }

    public function testExploreGenreTabs(): void
    {
        [$customer] = $this->signedInCustomer();

        $all = $customer->get('Explore_Page.php');
        $document = $this->assertHtmlPage($all);
        self::assertSame(self::expectedIds(null, ''), self::resultIds($all));
        self::assertSame('Showing ' . self::SEED_BOOK_COUNT . ' books in all genres.', $document->text('//*[@id="explore-status"]'));
        $tabs = $document->elements('//nav[' . HtmlDocument::hasClass('genre-tabs') . ']//a');
        self::assertSame(['all', ...list_genres(), 'Favorites'], array_map(static fn ($a): string => $a->getAttribute('data-genre'), $tabs));
        self::assertSame(['All'], $document->texts('//nav[' . HtmlDocument::hasClass('genre-tabs') . ']//a[@aria-current="page"]'));

        foreach (list_genres() as $genre) {
            $page = $customer->get('Explore_Page.php?genre=' . rawurlencode($genre));
            $expected = self::expectedIds($genre, '');
            self::assertNotSame([], $expected);
            self::assertSame($expected, self::resultIds($page), "Genre tab $genre");
            $count = count($expected);
            self::assertSame("Showing $count " . ($count === 1 ? 'book' : 'books') . " in $genre.", $page->document()->text('//*[@id="explore-status"]'));
            self::assertSame([$genre], $page->document()->texts('//nav[' . HtmlDocument::hasClass('genre-tabs') . ']//a[@aria-current="page"]'));
        }

        // Unknown genres fall back to "all" instead of an empty or broken page.
        $unknown = $customer->get('Explore_Page.php?genre=' . rawurlencode("Nope' OR 1=1 -- "));
        $this->assertHtmlPage($unknown);
        self::assertSame(self::expectedIds(null, ''), self::resultIds($unknown));

        // Favorites tab: empty, then the customer's favorites only.
        $favorites = $customer->get('Explore_Page.php?genre=Favorites');
        $this->assertPageText("You haven't added any favorites yet.", $favorites);
        foreach ([13, 47] as $bookId) {
            $this->assertRedirect(
                $customer->submitForm($this->bookPage($customer, $bookId), 'Selected.php?id=' . $bookId, [], ['action' => 'toggle_favorite']),
                'Selected.php?id=' . $bookId . '#book'
            );
        }
        self::assertSame([13, 47], self::sorted(self::resultIds($customer->get('Explore_Page.php?genre=Favorites'))));
        self::assertSame([47], self::resultIds($customer->get('Explore_Page.php?genre=Favorites&q=love')));
    }

    public function testExploreSearchTreatsWildcardsLiterally(): void
    {
        [$customer] = $this->signedInCustomer();

        foreach (['Olivia', 'olivia wilson', 'THE', 'Love', "Daughter of The Moon"] as $query) {
            $page = $customer->get('Explore_Page.php?q=' . rawurlencode($query));
            $this->assertHtmlPage($page);
            self::assertSame(self::expectedIds(null, $query), self::resultIds($page), "Search for \"$query\"");
            self::assertSame($query, $page->document()->first('//input[@id="explore-q"]')?->getAttribute('value'));
        }
        self::assertSame(self::expectedIds('Romantic', 'Olivia'), self::resultIds($customer->get('Explore_Page.php?genre=Romantic&q=Olivia')));

        // LIKE wildcards are searched for literally: nothing in the seed contains them.
        foreach (['%', '_', '%%', '\\', "'", '"'] as $query) {
            $page = $customer->get('Explore_Page.php?q=' . rawurlencode($query));
            $this->assertHtmlPage($page);
            self::assertSame([], self::resultIds($page), 'Search for ' . json_encode($query));
            $this->assertPageText('No books match your search', $page);
        }

        $special = $this->insertBook(['title' => '100% Pure_Fun', 'author' => 'Back\\Slash']);
        foreach (['%', '_', '0%', 'e_F', '\\'] as $query) {
            self::assertSame([$special], self::resultIds($customer->get('Explore_Page.php?q=' . rawurlencode($query))), 'Search for ' . json_encode($query));
        }

        // An over-long query is cut at 100 characters, not rejected.
        $long = $customer->get('Explore_Page.php?q=' . str_repeat('a', 500));
        $this->assertHtmlPage($long);
        self::assertSame(str_repeat('a', 100), $long->document()->first('//input[@id="explore-q"]')?->getAttribute('value'));
    }

    public function testFetchBooksJsonContract(): void
    {
        $guest = $this->client();

        $all = $guest->get('fetch_books.php');
        $this->assertStatus(200, $all);
        self::assertStringStartsWith('application/json', $all->contentType());
        $data = $all->json();
        self::assertSame(['books', 'count', 'genre', 'q', 'truncated'], array_keys($data));
        self::assertSame('all', $data['genre']);
        self::assertSame('', $data['q']);
        self::assertFalse($data['truncated']);
        self::assertSame(self::SEED_BOOK_COUNT, $data['count']);
        self::assertCount(self::SEED_BOOK_COUNT, $data['books']);
        self::assertSame(self::expectedIds(null, ''), array_column($data['books'], 'id'));

        $first = $data['books'][0];
        self::assertSame(['id', 'title', 'author', 'genre', 'price', 'price_display', 'cover_image', 'url'], array_keys($first));
        $book = find_book($first['id']);
        self::assertNotNull($book);
        self::assertSame($book['title'], $first['title']);
        self::assertSame($book['price'], $first['price']);
        self::assertSame('SAR ' . number_format((float) $book['price'], 2), $first['price_display']);
        self::assertSame('Selected.php?id=' . $first['id'], $first['url']);
        foreach ($data['books'] as $row) {
            self::assertIsInt($row['id']);
            self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $row['price']);
            self::assertMatchesRegularExpression('#^(imges|uploads)/#', $row['cover_image']);
        }

        $mystery = $guest->get('fetch_books.php?genre=Mystery')->json();
        self::assertSame('Mystery', $mystery['genre']);
        self::assertSame(self::expectedIds('Mystery', ''), array_column($mystery['books'], 'id'));
        self::assertSame(['Mystery'], array_values(array_unique(array_column($mystery['books'], 'genre'))));

        $search = $guest->get('fetch_books.php?genre=all&q=' . rawurlencode('olivia'))->json();
        self::assertSame('olivia', $search['q']);
        self::assertSame(self::expectedIds(null, 'olivia'), array_column($search['books'], 'id'));
        self::assertSame(0, $guest->get('fetch_books.php?q=%25')->json()['count']);
        self::assertSame('all', $guest->get('fetch_books.php?genre=NoSuchGenre')->json()['genre']);

        // Favorites need a signed-in customer.
        $unauthorized = $guest->get('fetch_books.php?genre=Favorites');
        $this->assertStatus(401, $unauthorized);
        self::assertSame(['error' => 'Please log in as a customer to see your favorites.'], $unauthorized->json());

        [$customer] = $this->signedInCustomer();
        self::assertSame([], $customer->get('fetch_books.php?genre=Favorites')->json()['books']);
        foreach ([15, 23] as $bookId) {
            $customer->submitForm($this->bookPage($customer, $bookId), 'Selected.php?id=' . $bookId, [], ['action' => 'toggle_favorite']);
        }
        $favorites = $customer->get('fetch_books.php?genre=Favorites')->json();
        self::assertSame('Favorites', $favorites['genre']);
        self::assertSame([15, 23], self::sorted(array_column($favorites['books'], 'id')));
        self::assertSame([23], array_column($customer->get('fetch_books.php?genre=Favorites&q=1984')->json()['books'], 'id'));

        $post = $guest->postWithToken('fetch_books.php?genre=all');
        $this->assertStatus(405, $post);
        self::assertSame('GET, HEAD', $post->header('Allow'));
        self::assertSame(['error' => 'Method not allowed. Use GET.'], $post->json());
    }

    public function testBookPageReturns404ForMissingOrInvalidIds(): void
    {
        [$customer] = $this->signedInCustomer();
        foreach (['', '?id=', '?id=0', '?id=-1', '?id=abc', '?id=7abc', '?id=1.5', '?id=999999', '?id=99999999999', '?id[]=7', '?id=%2B7', '?id=0x07'] as $query) {
            $page = $customer->get('Selected.php' . $query);
            $this->assertHtmlPage($page, 404);
            self::assertSame('Page not found', $page->document()->text('//h1'), "Selected.php$query");
        }
        $this->assertHtmlPage($customer->get('Selected.php?id=7'));
    }
}
