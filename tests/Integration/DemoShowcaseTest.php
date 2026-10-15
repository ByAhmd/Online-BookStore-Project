<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Docker\RunsProjectScripts;
use BookWise\Tests\Support\DatabaseTestCase;
use BookWise\Tests\Support\DemoShowcaseChecks;
use DemoStoreException;
use PDO;
use PDOException;

/**
 * The public demo's showcase (src/demo.php, bin/demo-seed.php and the hook
 * in bin/install.php): fictional customers, their orders, reviews, likes and
 * favorites that keep every rule of the store (sales of paid orders only,
 * sold counts, stock, delivery address copies, reviews by buyers), that make
 * the home page and the dashboard look alive, that are added once and in one
 * piece, and only in demo mode.
 */
final class DemoShowcaseTest extends DatabaseTestCase
{
    use DemoShowcaseChecks;
    use RunsProjectScripts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('APP_DEMO', 'true');
    }

    /**
     * @return array<int, array{title: string, price: string}>
     */
    private static function catalog(): array
    {
        $catalog = [];
        foreach (db_all('SELECT id, title, price FROM books ORDER BY id') as $row) {
            $catalog[(int) $row['id']] = ['title' => (string) $row['title'], 'price' => (string) $row['price']];
        }
        return $catalog;
    }

    /**
     * Row counts of every table the showcase writes to, the stock and the
     * newest ids, to tell whether anything changed.
     *
     * @return array<string, mixed>
     */
    private static function snapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'addresses', 'favorites', 'orders', 'order_items', 'order_addresses', 'sales', 'comments', 'comment_interactions'] as $table) {
            $snapshot[$table] = (int) db_value("SELECT COUNT(*) FROM $table");
        }
        $snapshot['last_order'] = db_value('SELECT MAX(id) FROM orders');
        $snapshot['stock'] = self::stockByBook();
        return $snapshot;
    }

    public function testItIsOnlyForAPublicDemo(): void
    {
        $this->setEnv('APP_DEMO', 'false');
        $before = self::snapshot();
        try {
            seed_demo_showcase();
            self::fail('The showcase must be refused outside demo mode');
        } catch (DemoStoreException $e) {
            self::assertStringContainsString('APP_DEMO=true', $e->getMessage());
        }
        self::assertSame($before, self::snapshot(), 'Nothing was added');

        $run = self::runProjectScript('bin/demo-seed.php', ['APP_DEMO' => 'false']);
        self::assertSame(1, $run['status']);
        self::assertSame('', $run['stdout']);
        self::assertStringContainsString('Refusing to add the showcase', $run['stderr']);
        self::assertSame($before, self::snapshot());
    }

    public function testAddsFictionalCustomersOrdersReviewsAndFavorites(): void
    {
        $catalog = self::catalog();

        $result = seed_demo_showcase();

        self::assertSame('seeded', $result['status']);
        self::assertSame(12, $result['customers']);
        self::assertSame(31, $result['orders']);
        self::assertSame(32, $result['reviews']);
        self::assertSame(36, $result['favorites']);
        self::assertGreaterThan(0, $result['reactions']);
        foreach (['users' => 'customers', 'orders' => 'orders', 'comments' => 'reviews', 'comment_interactions' => 'reactions', 'favorites' => 'favorites'] as $table => $key) {
            self::assertSame($result[$key], $this->countRows($table), "The result counts the $table rows");
        }
        self::assertTrue(demo_showcase_present());

        self::assertShowcaseCustomers();
        self::assertShowcaseOrders($catalog);
        self::assertShowcaseReviews();
    }

    public function testSalesSoldCountsAndStockFollowThePaidOrders(): void
    {
        $stock = self::stockByBook();

        seed_demo_showcase();

        self::assertShowcaseBookkeeping($stock);
        self::assertSame(0, $this->countRows('sales', "order_id IN (SELECT id FROM orders WHERE status <> 'paid')"), 'No sales for the refunded order or the abandoned checkout');
        // The refunded order (not shipped yet) put its copy of Cold Lake back.
        $refunded = db_one("SELECT oi.book_id, oi.stock_taken FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE o.status = 'refunded'");
        self::assertSame(['book_id' => 34, 'stock_taken' => 1], $refunded);
        self::assertSame($stock[34] - 5, self::stockByBook()[34], 'Five paid copies of Cold Lake; the refunded one is back in stock');
    }

    public function testTheHomePageAndTheDashboardLookAlive(): void
    {
        self::assertSame([], array_filter(array_map(static fn (array $book): int => (int) $book['sold'], best_selling_books(8))), 'Nothing sold before');

        seed_demo_showcase();

        self::assertTheStoreLooksAlive();
        self::assertSame('1984', best_selling_books(1)[0]['title'], 'The best seller');
        self::assertSame(['average' => 4.5, 'count' => 4], book_rating_summary(35));
        $reviews = book_comments(35, null);
        self::assertCount(4, $reviews);
        self::assertSame(['Daniel Hart', 'سلمان الغامدي', 'ليلى الحربي', 'نورة القحطاني'], array_map(
            static fn (array $review): string => (string) $review['author_name'],
            array_reverse($reviews)
        ), 'Reviews show their authors\' names; the page lists the newest first');
    }

    public function testRunningItAgainAddsNothing(): void
    {
        self::assertSame('seeded', seed_demo_showcase()['status']);
        $seeded = self::snapshot();

        self::assertSame(
            ['status' => 'present', 'customers' => 0, 'orders' => 0, 'reviews' => 0, 'reactions' => 0, 'favorites' => 0],
            seed_demo_showcase()
        );
        self::assertSame($seeded, self::snapshot());
    }

    public function testAFailureAddsNothing(): void
    {
        // A review left over under a showcase address (reviews name their author
        // by email, not by account) makes one of the showcase's reviews a duplicate.
        db_run("INSERT INTO comments (book_id, username, comment, rating) VALUES (19, 'showcase-salman@demo.invalid', 'Left over.', 4)");
        $before = self::snapshot();

        try {
            seed_demo_showcase();
            self::fail('The duplicate review must stop the showcase');
        } catch (PDOException $e) {
            self::assertTrue(is_duplicate_key_error($e));
        }
        self::assertSame($before, self::snapshot(), 'Everything written before the failure was rolled back');
        self::assertFalse(demo_showcase_present());
    }

    public function testOnlyThisStoresRunsShareItsLock(): void
    {
        self::assertSame('bookwise-demo-showcase:' . config('db.name'), demo_showcase_lock_name());

        // Another session on the same server, without this database (another BookWise demo, or
        // another account of a shared host), holds the lock of another store and the bare name.
        $other = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', config('db.host'), config('db.port')),
            (string) config('db.user'),
            (string) config('db.pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $value = static function (string $sql, string $lock) use ($other): int {
            $statement = $other->prepare($sql);
            $statement->execute([$lock]);
            return (int) $statement->fetchColumn();
        };
        foreach ([DEMO_SHOWCASE_LOCK, DEMO_SHOWCASE_LOCK . ':another_store_test'] as $lock) {
            self::assertSame(1, $value('SELECT GET_LOCK(?, 0)', $lock));
        }

        $started = microtime(true);
        self::assertSame('seeded', seed_demo_showcase()['status']);
        self::assertLessThan(10.0, microtime(true) - $started, 'Not held up by the other session (it would wait 30 seconds)');

        // This store's own seeds and resets still wait for each other.
        self::assertSame(0, with_demo_showcase_lock(static fn (): int => $value('SELECT IS_FREE_LOCK(?)', demo_showcase_lock_name())));
        self::assertSame(1, $value('SELECT IS_FREE_LOCK(?)', demo_showcase_lock_name()), 'Released afterwards');
        foreach ([DEMO_SHOWCASE_LOCK, DEMO_SHOWCASE_LOCK . ':another_store_test'] as $lock) {
            self::assertSame(1, $value('SELECT RELEASE_LOCK(?)', $lock));
        }

        // MySQL accepts lock names of at most 64 characters.
        $this->setEnv('DB_NAME', str_repeat('bookwise_', 6) . 'demo_test');
        self::assertSame(DEMO_SHOWCASE_LOCK . ':' . md5(str_repeat('bookwise_', 6) . 'demo_test'), demo_showcase_lock_name());
        self::assertLessThanOrEqual(64, strlen(demo_showcase_lock_name()));
    }

    public function testBooksNoLongerInTheCatalogAreLeftOut(): void
    {
        // An administrator deleted 1984, which the showcase sells most.
        db_run('DELETE FROM books WHERE id = 23');
        $catalog = self::catalog();
        $stock = self::stockByBook();

        $result = seed_demo_showcase();

        self::assertSame('seeded', $result['status']);
        self::assertSame(31, $result['orders'], 'Every order with 1984 had other books too');
        self::assertSame(29, $result['reviews'], 'Its three reviews are left out');
        self::assertSame(0, $this->countRows('order_items', "book_id IS NULL OR title = '1984'"));
        self::assertSame(0, $this->countRows('favorites', 'book_id = 23'));
        self::assertShowcaseOrders($catalog);
        self::assertShowcaseBookkeeping($stock);
        self::assertShowcaseReviews();
    }

    public function testNothingIsAddedWithoutTheSampleCatalog(): void
    {
        db_run('DELETE FROM books');

        self::assertSame('no_catalog', seed_demo_showcase()['status']);
        self::assertSame(0, $this->countRows('users'));
        self::assertFalse(demo_showcase_present());
    }

    public function testTheCommandAddsTheShowcaseOnce(): void
    {
        $run = self::runProjectScript('bin/demo-seed.php', ['APP_DEMO' => 'true']);

        self::assertSame(0, $run['status'], $run['stdout'] . $run['stderr']);
        self::assertSame('', $run['stderr']);
        self::assertMatchesRegularExpression(
            '/^\[[0-9: -]+\] Demo showcase: added 12 customer\(s\), 31 order\(s\), 32 review\(s\), \d+ like\(s\) and dislike\(s\) and 36 favorite\(s\)\.\R$/D',
            $run['stdout']
        );
        self::assertTrue(demo_showcase_present());

        $again = self::runProjectScript('bin/demo-seed.php', ['APP_DEMO' => 'true']);
        self::assertSame(0, $again['status'], $again['stdout'] . $again['stderr']);
        self::assertStringContainsString('Demo showcase: already there, nothing added.', $again['stdout']);
        self::assertSame(12, $this->countRows('users'));
    }

    public function testTheInstallerAddsTheShowcaseToADemoThatHasNone(): void
    {
        $plain = self::runProjectScript('bin/install.php', ['APP_DEMO' => 'false']);
        self::assertSame(0, $plain['status'], $plain['stdout'] . $plain['stderr']);
        self::assertStringNotContainsString('Demo', $plain['stdout'], 'A real store gets no showcase');
        self::assertSame(0, $this->countRows('users'));

        $skipped = self::runProjectScript('bin/install.php', ['APP_DEMO' => 'true'], ['--no-showcase']);
        self::assertSame(0, $skipped['status'], $skipped['stdout'] . $skipped['stderr']);
        self::assertStringContainsString('Demo: restocked', $skipped['stdout']);
        self::assertStringNotContainsString('Demo showcase', $skipped['stdout']);
        self::assertSame(0, $this->countRows('users'));

        $first = self::runProjectScript('bin/install.php', ['APP_DEMO' => 'true']);
        self::assertSame(0, $first['status'], $first['stdout'] . $first['stderr']);
        self::assertStringContainsString('Database schema is up to date.', $first['stdout']);
        self::assertStringContainsString('Demo showcase: added 12 customer(s), 31 order(s)', $first['stdout']);
        self::assertTrue(demo_showcase_present());

        $again = self::runProjectScript('bin/install.php', ['APP_DEMO' => 'true']);
        self::assertSame(0, $again['status'], $again['stdout'] . $again['stderr']);
        self::assertStringNotContainsString('Demo showcase', $again['stdout'], 'Added once; quiet afterwards');
        self::assertSame(12, $this->countRows('users'));
        self::assertSame(31, $this->countRows('orders'));
    }
}
