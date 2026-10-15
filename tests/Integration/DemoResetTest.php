<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Docker\RunsProjectScripts;
use BookWise\Tests\Support\DatabaseTestCase;
use BookWise\Tests\Support\DemoShowcaseChecks;
use DemoStoreException;

/**
 * The public demo's daily reset (reset_demo_store() and bin/demo-reset.php):
 * everything visitors and administrators changed goes (customers with their
 * data, orders, the demo inbox, demo-guest records, uploads, added books, the
 * payment simulator's sessions), the 55 sample books come back as they were,
 * administrators stay, and the showcase is there again. Only in demo mode,
 * and the command only with --yes.
 */
final class DemoResetTest extends DatabaseTestCase
{
    use DemoShowcaseChecks;
    use RunsProjectScripts;

    private string $directory = '';
    /** @var list<string> Uploaded files a test created, deleted afterwards if the reset left them. */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bookwise-demo-reset-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->setEnv('APP_DEMO', 'true');
        $this->setEnv('LOG_DIR', $this->directory);
        $this->setEnv('PAYMENT_SIMULATOR_STORE', $this->directory . DIRECTORY_SEPARATOR . 'payment-simulator.json');
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * The sample catalog as database/seed.sql has it, without the stock.
     *
     * @return list<array<string, mixed>>
     */
    private static function catalogText(): array
    {
        return db_all('SELECT id, title, author, description, genre, price, cover_image, created_at FROM books ORDER BY id');
    }

    /**
     * @return array<int, array{title: string, price: string}>
     */
    private static function catalogPrices(): array
    {
        $catalog = [];
        foreach (db_all('SELECT id, title, price FROM books ORDER BY id') as $row) {
            $catalog[(int) $row['id']] = ['title' => (string) $row['title'], 'price' => (string) $row['price']];
        }
        return $catalog;
    }

    /**
     * An uploaded file with a generated name under public/$dir; returns its path relative to public/.
     */
    private function upload(string $dir, string $extension = 'png'): string
    {
        $path = $dir . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $file = BASE_PATH . '/public/' . $path;
        file_put_contents($file, 'not really a picture');
        $this->files[] = $file;
        return $path;
    }

    /**
     * A paid order of one copy of book 51 (also for a deleted account: $userId null).
     */
    private function paidOrder(?int $userId): int
    {
        db_run(
            "INSERT INTO orders (user_id, status, currency, total_amount, amount_minor, paid_at, fulfilment_status) VALUES (?, 'paid', 'sar', '19.99', 1999, NOW(), 'processing')",
            [$userId]
        );
        $orderId = (int) db()->lastInsertId();
        db_run("INSERT INTO order_items (order_id, book_id, title, unit_price, quantity, stock_taken) VALUES (?, 51, 'Esabella', '19.99', 1, 1)", [$orderId]);
        if ($userId !== null) {
            db_run('INSERT INTO sales (book_id, user_id, order_id, quantity) VALUES (51, ?, ?, 1)', [$userId, $orderId]);
        }
        return $orderId;
    }

    /**
     * A day of a public demo: yesterday's showcase, a customer who signed up,
     * bought, reviewed and uploaded a picture, a one-click demo customer, an
     * order of a deleted account, failed sign-ins, a reset link, demo inbox
     * emails, a payment page left open, and administrators who added a book
     * with an uploaded cover, changed one sample book and deleted another.
     *
     * @return array{avatar: string, cover: string, owner: string, last_user: int}
     */
    private function aDayOfVisitors(): array
    {
        self::assertSame('seeded', seed_demo_showcase()['status']);

        $customer = $this->createUser();
        $customerId = (int) $customer['id'];
        $this->createAddress($customerId);
        self::assertTrue(cart_add($customerId, 7, 2));
        toggle_favorite($customerId, 9);
        $this->paidOrder($customerId);
        self::assertTrue(add_review(51, (string) $customer['Eaddress'], 'Bought it yesterday.', 5));
        $avatar = $this->upload(AVATAR_DIR);
        db_run('UPDATE users SET profile_image = ? WHERE id = ?', [$avatar, $customerId]);
        record_failed_login((string) $customer['Eaddress'], '203.0.113.5');
        db_run("INSERT INTO reset_tokens (email, token, expiry) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)", [$customer['Eaddress'], str_repeat('a', 64)]);
        db_run("INSERT INTO demo_mail (recipient, subject, html, text) VALUES (?, 'Your order', '<p>Hi</p>', 'Hi')", [$customer['Eaddress']]);

        $guest = create_demo_guest('198.51.100.7');
        self::assertSame('created', $guest['status']);
        $this->paidOrder(null);

        $owner = $this->createAdmin();
        db_run("INSERT INTO demo_mail (recipient, subject, html, text) VALUES (?, 'For the owner', '<p>Hi</p>', 'Hi')", [$owner['Eaddress']]);
        demo_viewer_admin();

        $cover = $this->upload(COVER_DIR);
        $copies = webp_variant_paths(BASE_PATH . '/public/' . $cover);
        foreach ([$copies['full'], $copies['thumb']] as $copy) {
            file_put_contents($copy, 'not really a picture');
            $this->files[] = $copy;
        }
        $this->insertBook(['title' => 'Added by an administrator', 'cover_image' => $cover]);
        db_run("UPDATE books SET title = 'Renamed', price = '99.00', stock = 0 WHERE id = 7");
        db_run('DELETE FROM books WHERE id = 61');

        payment_simulator_transaction(static function (array &$state): void {
            $state['sessions']['cs_test_left_open'] = ['id' => 'cs_test_left_open', 'status' => 'open', 'payment_status' => 'unpaid', 'expires_at' => time() + 3600];
        });

        return ['avatar' => $avatar, 'cover' => $cover, 'owner' => $owner['Eaddress'], 'last_user' => (int) db_value('SELECT MAX(id) FROM users')];
    }

    public function testTheResetReturnsTheDemoToItsStartingState(): void
    {
        $catalog = self::catalogText();
        $prices = self::catalogPrices();
        $stock = self::stockByBook();
        $day = $this->aDayOfVisitors();
        self::assertSame(14, $this->countRows('users'), 'The showcase, a customer and a demo guest');

        $summary = reset_demo_store();

        $showcase = $summary['showcase'];
        unset($summary['showcase']);
        self::assertSame([
            'customers' => 14,
            'orders' => 33,
            'reviews' => 33,
            'mail' => 2,
            'pictures' => 2,
            'books_removed' => 1,
            'books_restored' => 1,
            'books' => self::SEED_BOOK_COUNT,
            'payment_simulator' => 'cleared',
        ], $summary);
        self::assertSame(['customers' => 12, 'orders' => 31, 'reviews' => 32, 'reactions' => $showcase['reactions'], 'favorites' => 36], $showcase);

        // Visitors' data is gone; the showcase is back, with new ids.
        foreach (['cart', 'reset_tokens', 'login_attempts', 'demo_mail', 'demo_guest_creations'] as $table) {
            self::assertSame(0, $this->countRows($table), "$table is empty");
        }
        self::assertSame(0, $this->countRows('users', 'demo_guest_at IS NOT NULL'));
        self::assertSame(0, $this->countRows('orders', 'user_id IS NULL'), 'Orders of deleted accounts go too');
        self::assertSame(0, $this->countRows('users', 'id <= ?', [$day['last_user']]), 'Ids keep counting up: a session that remembers an old account never finds a new one');
        self::assertShowcaseCustomers();
        self::assertShowcaseOrders($prices);
        self::assertShowcaseReviews();
        self::assertTheStoreLooksAlive();

        // The administrators stay, with their roles.
        self::assertSame([
            ['Eaddress' => $day['owner'], 'role' => ADMIN_ROLE_OWNER],
            ['Eaddress' => DEMO_VIEWER_ADMIN_EMAIL, 'role' => ADMIN_ROLE_VIEWER],
        ], db_all('SELECT Eaddress, role FROM admins ORDER BY Eaddress = ?, Eaddress', [DEMO_VIEWER_ADMIN_EMAIL]));

        // The sample catalog is back as it was, less the copies the showcase took.
        self::assertSame($catalog, self::catalogText());
        self::assertShowcaseBookkeeping($stock);

        // The uploads of the deleted rows are deleted; the folders keep their own files.
        foreach ($this->files as $file) {
            self::assertFileDoesNotExist($file);
        }
        self::assertFileExists(BASE_PATH . '/public/uploads/avatars/.gitkeep');
        self::assertFileExists(BASE_PATH . '/public/uploads/covers/.gitkeep');

        // The payment page left open can no longer be paid.
        $state = json_decode((string) file_get_contents($this->directory . DIRECTORY_SEPARATOR . 'payment-simulator.json'), true);
        self::assertSame(['sessions' => [], 'refunds' => [], 'idempotency' => []], $state);
        self::assertNull(payment_simulator_session('cs_test_left_open'));

    }

    public function testAResetDemoCanBeResetAgain(): void
    {
        $stock = self::stockByBook();
        reset_demo_store();
        $first = db_all('SELECT name, Eaddress FROM users ORDER BY id');

        $summary = reset_demo_store();

        self::assertSame(12, $summary['customers'], 'Only the showcase was there');
        self::assertSame(31, $summary['orders']);
        self::assertSame('none', $summary['payment_simulator'], 'No state file yet');
        self::assertSame($first, db_all('SELECT name, Eaddress FROM users ORDER BY id'), 'The same showcase again');
        self::assertShowcaseBookkeeping($stock);
    }

    public function testItIsOnlyForAPublicDemo(): void
    {
        $customer = $this->createUser();
        $this->setEnv('APP_DEMO', 'false');

        try {
            reset_demo_store();
            self::fail('The reset must be refused outside demo mode');
        } catch (DemoStoreException $e) {
            self::assertStringContainsString('APP_DEMO=true', $e->getMessage());
        }
        self::assertSame(1, $this->countRows('users', 'id = ?', [$customer['id']]), 'Nothing was deleted');
    }

    public function testTheCommandNeedsDemoModeAndYes(): void
    {
        $customer = $this->createUser();

        $refused = self::runProjectScript('bin/demo-reset.php', ['APP_DEMO' => 'false'], ['--yes']);
        self::assertSame(1, $refused['status']);
        self::assertSame('', $refused['stdout']);
        self::assertStringContainsString('Refusing to reset: only a public demo (APP_DEMO=true) is reset', $refused['stderr']);

        $unasked = self::runProjectScript('bin/demo-reset.php');
        self::assertSame(1, $unasked['status']);
        self::assertSame('', $unasked['stdout']);
        self::assertStringContainsString('Usage: php bin/demo-reset.php --yes', $unasked['stderr']);
        self::assertStringContainsString('Run it with --yes to reset the demo.', $unasked['stderr']);

        $unknown = self::runProjectScript('bin/demo-reset.php', [], ['--yes', '--force']);
        self::assertSame(1, $unknown['status']);
        self::assertStringContainsString('Unknown argument: --force', $unknown['stderr']);

        self::assertSame(1, $this->countRows('users', 'id = ?', [$customer['id']]), 'Nothing was deleted');
        self::assertFalse(demo_showcase_present());
    }

    public function testTheCommandResetsTheDemoFromAScheduledTask(): void
    {
        $customer = $this->createUser();
        $this->paidOrder((int) $customer['id']);

        $run = self::runProjectScript('bin/demo-reset.php', [], ['--yes']);

        self::assertSame(0, $run['status'], $run['stdout'] . $run['stderr']);
        self::assertSame('', $run['stderr']);
        self::assertMatchesRegularExpression(
            '/^\[[0-9: -]+\] Demo reset: deleted 1 customer\(s\), 1 order\(s\), 0 review\(s\), 0 demo inbox email\(s\) and 0 uploaded picture\(s\); '
            . 'removed 0 book\(s\) added by administrators and put back the 55 sample books\.\R'
            . '\[[0-9: -]+\] Demo showcase: added 12 customer\(s\), 31 order\(s\), 32 review\(s\), \d+ like\(s\) and dislike\(s\) and 36 favorite\(s\)\.\R$/D',
            $run['stdout']
        );
        self::assertSame(0, $this->countRows('users', 'id = ?', [$customer['id']]));
        self::assertTrue(demo_showcase_present());
        // Logged with its counts.
        self::assertMatchesRegularExpression(
            '/INFO: Demo reset \{"customers":1,"orders":1,"reviews":0,"mail":0,"pictures":0,"books_removed":0,"books_restored":0,"books":55,"payment_simulator":"none","showcase":\{"customers":12,/',
            (string) file_get_contents($this->directory . DIRECTORY_SEPARATOR . 'app.log')
        );
    }
}
