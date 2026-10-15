<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\AccountHelpers;
use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;

/**
 * The demo's showcase (src/demo.php) on the real pages, in English and
 * Arabic: the home page's best sellers and ratings, the book pages with
 * their reviews and reactions, and the admin dashboard, order list and
 * order pages with the showcase's orders. The integration tests check the
 * rows; these check that every page renders them.
 */
final class DemoShowcasePagesTest extends HttpTestCase
{
    use AccountHelpers;

    private const LOCALES = ['en', 'ar'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('APP_DEMO', 'true');
        $this->configureServer(['APP_DEMO' => 'true']);
        self::assertSame('seeded', seed_demo_showcase()['status']);
    }

    private static function inLocale(HttpClient $client, string $locale): HttpClient
    {
        $client->setCookie(LOCALE_COOKIE, $locale);
        return $client;
    }

    /**
     * $callback's result with the interface language set to $locale, for the texts a page should show.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private static function translated(string $locale, callable $callback): mixed
    {
        use_locale($locale);
        try {
            return $callback();
        } finally {
            use_locale(null);
        }
    }

    /**
     * The page contract, in the language asked for.
     */
    private function assertPageIn(string $locale, HttpResponse $page): HtmlDocument
    {
        $document = $this->assertHtmlPage($page);
        $html = $document->first('/html');
        self::assertSame([$locale, text_direction($locale)], [$html?->getAttribute('lang'), $html?->getAttribute('dir')], $page->url);
        self::assertDoesNotMatchRegularExpression('/\b(?:shop|admin|common)\.[a-z_]+\.[a-z_]/', $page->body, "Untranslated key on {$page->url}");
        return $document;
    }

    public function testTheHomePageShowsTheBestSellersAndTheirRatings(): void
    {
        $bestSellers = best_selling_books(8);
        $recommended = recommended_books(8);
        self::assertSame('1984', $bestSellers[0]['title']);

        foreach (self::LOCALES as $locale) {
            $document = $this->assertPageIn($locale, self::inLocale($this->client(), $locale)->get('Home_Page.php'));
            $loved = '//section[@aria-labelledby="home-loved-title"]//li[' . HtmlDocument::hasClass('home-book') . ']';
            self::assertSame(array_column($bestSellers, 'title'), $document->texts($loved . '//h3'));
            self::assertSame(
                self::translated($locale, static fn (): array => array_map(static fn (array $book): string => tn('shop.home.sold', (int) $book['sold']), $bestSellers)),
                $document->texts($loved . '//span[' . HtmlDocument::hasClass('home-book-note') . ']'),
                'Every best seller shows its sales'
            );

            $rated = '//section[@aria-labelledby="home-recommended-title"]//li[' . HtmlDocument::hasClass('home-book') . ']';
            self::assertSame(array_column($recommended, 'title'), $document->texts($rated . '//h3'));
            self::assertSame(
                self::translated($locale, static fn (): array => array_map(
                    static fn (array $book): string => '★ ' . number_format(round((float) $book['avg_rating'], 1), 1) . ' ' . t('shop.home.rating_sr'),
                    $recommended
                )),
                $document->texts($rated . '//span[' . HtmlDocument::hasClass('home-book-note') . ']'),
                'Every recommended book shows its rating'
            );
        }
    }

    public function testEveryReviewedBookShowsItsReviewsInBothLanguages(): void
    {
        $books = array_map('intval', array_column(db_all('SELECT DISTINCT book_id FROM comments ORDER BY book_id'), 'book_id'));
        self::assertGreaterThan(10, count($books));
        $arabicReviews = 0;

        foreach (self::LOCALES as $locale) {
            $guest = self::inLocale($this->client(), $locale);
            foreach ($books as $bookId) {
                $reviews = book_comments($bookId, null);
                $document = $this->assertPageIn($locale, $this->bookPage($guest, $bookId));
                $list = '//section[@id="reviews"]//li[' . HtmlDocument::hasClass('review') . ']';
                self::assertSame(array_column($reviews, 'author_name'), $document->texts($list . '//p[' . HtmlDocument::hasClass('review-author') . ']'), "Book $bookId: the reviewers' names, newest first");
                self::assertSame(array_column($reviews, 'comment'), $document->texts($list . '//p[' . HtmlDocument::hasClass('review-text') . ']'), "Book $bookId: the reviews' texts");
                self::assertSame(['auto'], array_values(array_unique(array_map(
                    static fn (\DOMElement $text): string => $text->getAttribute('dir'),
                    $document->elements($list . '//p[' . HtmlDocument::hasClass('review-text') . ']')
                ))), 'English and Arabic reviews each keep their own direction');
                $tallies = [];
                foreach ($reviews as $review) {
                    array_push($tallies, (string) $review['likes'], (string) $review['dislikes']);
                }
                self::assertSame($tallies, $document->texts($list . '//span[' . HtmlDocument::hasClass('reaction-count') . ']'), "Book $bookId: likes and dislikes");
                $summary = book_rating_summary($bookId);
                self::assertStringContainsString(
                    self::translated($locale, static fn (): string => tn('shop.book.reviews_count', (int) $summary['count'])),
                    $document->text('//li[' . HtmlDocument::hasClass('book-rating') . ']')
                );
                $arabicReviews += count(array_filter(array_column($reviews, 'comment'), static fn (string $text): bool => preg_match('/\p{Arabic}/u', $text) === 1));
            }
        }
        self::assertGreaterThan(0, $arabicReviews, 'Arabic reviews among them');
    }

    public function testTheAdminPagesShowTheShowcaseOrders(): void
    {
        [$admin] = $this->signedInAdmin();
        $recent = recent_orders(20);
        $paid = (int) db_value("SELECT COUNT(*) FROM orders WHERE status = 'paid'");
        $revenue = (string) db_value("SELECT SUM(total_amount) FROM orders WHERE status = 'paid'");
        $orders = array_map('intval', array_column(db_all('SELECT id FROM orders ORDER BY id DESC'), 'id'));
        self::assertCount(31, $orders);
        $shipped = db_one(
            "SELECT o.id, o.carrier, o.tracking_number, u.name FROM orders o JOIN users u ON u.id = o.user_id
             WHERE u.Eaddress = 'showcase-layla@demo.invalid' AND o.fulfilment_status = 'shipped'"
        );
        self::assertNotNull($shipped);
        $shippedId = (int) $shipped['id'];
        $address = order_address($shippedId);
        self::assertNotNull($address);

        foreach (self::LOCALES as $locale) {
            self::inLocale($admin, $locale);
            $dashboard = $this->assertPageIn($locale, $admin->get('admin_dashboard.php'));
            self::assertSame(
                self::translated($locale, static fn (): array => [number_format($paid), format_money($revenue, 'sar')]),
                array_slice($dashboard->texts('//section[' . HtmlDocument::hasClass('admin-stats') . ']//p[' . HtmlDocument::hasClass('stat-value') . ']'), 2),
                'The paid orders and the revenue'
            );
            self::assertSame(array_column($recent, 'customer_name'), $dashboard->texts('//section[@id="recent-orders"]//tbody//span[' . HtmlDocument::hasClass('customer-name') . ']'));

            $list = $this->assertPageIn($locale, $admin->get('admin_orders.php'));
            self::assertSame(
                array_map(static fn (int $id): string => '#' . $id, array_slice($orders, 0, 20)),
                $list->texts('//table[' . HtmlDocument::hasClass('order-list-table') . ']//tbody/tr/th'),
                'The newest orders first'
            );

            // Every order page renders, whatever stage its order reached.
            foreach ($orders as $id) {
                $this->assertPageIn($locale, $admin->get('admin_order.php?id=' . $id));
            }

            // A shipped order of an Arabic-speaking customer: her name, address, carrier and tracking number.
            $page = $this->assertPageIn($locale, $admin->get('admin_order.php?id=' . $shippedId));
            self::assertSame($shipped['name'], $page->text('//p[' . HtmlDocument::hasClass('customer-name') . ']'));
            foreach (['street', 'district', 'city'] as $field) {
                self::assertStringContainsString((string) $address[$field], $page->text('//p[' . HtmlDocument::hasClass('address-text') . ']'));
            }
            self::assertSame((string) $shipped['carrier'], $page->text('//dl[' . HtmlDocument::hasClass('fulfilment-facts') . ']/dd[3]'));
            self::assertSame((string) $shipped['tracking_number'], $page->text('//span[' . HtmlDocument::hasClass('tracking-number') . ']'));
            self::assertTrue($page->hasForm('admin_order.php?id=' . $shippedId, ['action' => 'deliver']), 'The owner can mark it delivered');
            self::assertTrue($page->hasForm('admin_order.php?id=' . $shippedId, ['action' => 'refund']), 'and refund it');
        }
    }
}
