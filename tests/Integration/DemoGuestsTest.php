<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Docker\RunsProjectScripts;
use BookWise\Tests\Support\CheckoutTestCase;

/**
 * Demo guests, the throwaway customers of the demo's "Try as a customer"
 * button: how they are created (confirmed, unknown password, favorites),
 * the rate limits (per IPv4 address or IPv6 /64, and for all visitors
 * together) and the cap on live guests, what deleting one
 * removes and keeps, the daily cleanup (in code and bin/demo-cleanup.php),
 * and that they can never get a password.
 */
final class DemoGuestsTest extends CheckoutTestCase
{
    use RunsProjectScripts;

    private const IP = '198.51.100.7';

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * @return array<string, mixed> The new guest (find_user() row)
     */
    private function createGuest(string $ip = self::IP, int $max = DEMO_GUESTS_MAX): array
    {
        $result = create_demo_guest($ip, $max);
        self::assertSame('created', $result['status']);
        self::assertNotNull($result['user']);
        return $result['user'];
    }

    private function ageGuest(int $userId, string $interval): void
    {
        db_run("UPDATE users SET demo_guest_at = NOW() - INTERVAL $interval WHERE id = ?", [$userId]);
    }

    private function userExists(int $userId): bool
    {
        return db_value('SELECT 1 FROM users WHERE id = ?', [$userId]) !== null;
    }

    public function testCreatesAConfirmedThrowawayCustomerWithAFewFavorites(): void
    {
        $this->setEnv('REQUIRE_EMAIL_VERIFICATION', 'true');

        $guest = $this->createGuest();
        $userId = (int) $guest['id'];
        $row = db_one('SELECT Eaddress, name, pass, locale, email_verified_at, demo_guest_at FROM users WHERE id = ?', [$userId]);
        self::assertNotNull($row);

        self::assertMatchesRegularExpression('/^reader-[a-f0-9]{24}@demo\.invalid$/D', (string) $row['Eaddress'], 'A random address at the reserved domain');
        self::assertMatchesRegularExpression('/^Demo reader \d{4}$/D', (string) $row['name']);
        self::assertSame('en', $row['locale']);
        self::assertNotNull($row['email_verified_at'], 'Already confirmed');
        self::assertFalse(customer_awaits_verification($userId));
        self::assertNotNull($row['demo_guest_at']);
        self::assertTrue(is_demo_guest($userId));
        self::assertSame('bcrypt', password_get_info((string) $row['pass'])['algoName'], 'A real hash of a random password that nobody knows');

        $favorites = array_map('intval', array_column(db_all('SELECT book_id FROM favorites WHERE user_id = ? ORDER BY book_id', [$userId]), 'book_id'));
        $expected = array_map(static fn (array $book): int => (int) $book['id'], recommended_books(DEMO_GUEST_FAVORITES));
        sort($expected);
        self::assertCount(DEMO_GUEST_FAVORITES, $favorites);
        self::assertSame($expected, $favorites);

        self::assertSame([['ip_address' => self::IP]], db_all('SELECT ip_address FROM demo_guest_creations'));

        // Every click gets its own account.
        $second = $this->createGuest();
        self::assertNotSame($guest['Eaddress'], $second['Eaddress']);
        self::assertFalse(is_demo_guest((int) $this->createUser()['id']), 'Ordinary customers are not demo guests');
    }

    public function testTheGuestIsNamedInTheVisitorsLanguage(): void
    {
        use_locale('ar');
        try {
            $guest = $this->createGuest();
        } finally {
            use_locale(null);
        }
        self::assertMatchesRegularExpression('/^قارئ تجريبي \d{4}$/uD', (string) $guest['name']);
        self::assertSame('ar', db_value('SELECT locale FROM users WHERE id = ?', [$guest['id']]));
    }

    public function testOneAddressMayCreateTenGuestsAnHour(): void
    {
        for ($i = 1; $i <= DEMO_GUEST_RATE_LIMIT; $i++) {
            $this->createGuest();
        }
        self::assertSame(10, DEMO_GUEST_RATE_LIMIT);
        self::assertSame(['status' => 'throttled', 'user' => null], create_demo_guest(self::IP));
        self::assertSame(DEMO_GUEST_RATE_LIMIT, $this->countRows('users'), 'Nothing is created when throttled');
        self::assertSame(DEMO_GUEST_RATE_LIMIT, $this->countRows('demo_guest_creations'), 'Refusals are not counted');

        // Other visitors are not affected, and the limit is per hour.
        $this->createGuest('203.0.113.9');
        db_run('UPDATE demo_guest_creations SET created_at = NOW() - INTERVAL ? MINUTE WHERE ip_address = ?', [DEMO_GUEST_RATE_WINDOW_MINUTES + 1, self::IP]);
        $this->createGuest();
    }

    public function testDeletingAGuestDoesNotGiveTheAddressNewCreations(): void
    {
        for ($i = 1; $i <= DEMO_GUEST_RATE_LIMIT; $i++) {
            $guest = $this->createGuest();
            self::assertSame('deleted', delete_demo_guest((int) $guest['id']));
        }
        self::assertSame('throttled', create_demo_guest(self::IP)['status'], 'The limit counts creations, not live guests');
    }

    public function testAnIpv6VisitorIsLimitedByItsWholeNetwork(): void
    {
        // Every address of one /64 counts together: picking a new address does not help.
        for ($i = 1; $i <= DEMO_GUEST_RATE_LIMIT; $i++) {
            $this->createGuest(sprintf('2001:db8:1:2:%x::%x', $i, random_int(1, 0xffff)));
        }
        self::assertSame([['ip_address' => '2001:db8:1:2::/64', 'created' => DEMO_GUEST_RATE_LIMIT]], db_all('SELECT ip_address, COUNT(*) AS created FROM demo_guest_creations GROUP BY ip_address'));
        self::assertSame(['status' => 'throttled', 'user' => null], create_demo_guest('2001:db8:1:2:ffff:ffff:ffff:fffe'));

        // The next network is another visitor.
        $this->createGuest('2001:db8:1:3::1');
        self::assertSame(DEMO_GUEST_RATE_LIMIT + 1, $this->countRows('users', 'demo_guest_at IS NOT NULL'));
    }

    public function testAllVisitorsTogetherMayCreateSixtyGuestsAnHour(): void
    {
        self::assertSame(60, DEMO_GUEST_RATE_LIMIT_TOTAL);
        $live = $this->createGuest('203.0.113.1', 3);
        // Creations from many different networks within the hour.
        for ($i = 2; $i <= DEMO_GUEST_RATE_LIMIT_TOTAL; $i++) {
            db_run('INSERT INTO demo_guest_creations (ip_address, created_at) VALUES (?, NOW() - INTERVAL 30 MINUTE)', ['2001:db8:' . dechex($i) . '::/64']);
        }

        // A new visitor is refused, and nobody else's guest is pushed out to make room.
        self::assertSame(['status' => 'crowded', 'user' => null], create_demo_guest(self::IP, 1));
        self::assertTrue($this->userExists((int) $live['id']));
        self::assertSame(1, $this->countRows('users'));
        self::assertSame(DEMO_GUEST_RATE_LIMIT_TOTAL, $this->countRows('demo_guest_creations'), 'Refusals are not counted');

        // An hour later there is room again.
        db_run('UPDATE demo_guest_creations SET created_at = NOW() - INTERVAL ? MINUTE WHERE ip_address LIKE ?', [DEMO_GUEST_RATE_WINDOW_MINUTES + 1, '2001:db8:%']);
        $this->createGuest();
    }

    public function testTheOldestGuestsMakeRoomWhenTheLimitIsReached(): void
    {
        $customer = $this->createUser();
        $guests = [];
        foreach (['3 HOUR', '2 HOUR', '1 HOUR'] as $index => $age) {
            $guests[$index] = $this->createGuest('203.0.113.' . ($index + 1), 3);
            $this->ageGuest((int) $guests[$index]['id'], $age);
        }
        $oldest = (int) $guests[0]['id'];
        self::assertTrue(cart_add($oldest, 7, 2));

        $newest = $this->createGuest(self::IP, 3);

        self::assertFalse($this->userExists($oldest), 'The oldest guest made room');
        self::assertSame(0, $this->countRows('cart', 'user_id = ?', [$oldest]), 'With its data');
        self::assertSame(0, $this->countRows('favorites', 'user_id = ?', [$oldest]));
        foreach ([$guests[1], $guests[2], $newest, $customer] as $kept) {
            self::assertTrue($this->userExists((int) $kept['id']));
        }
        self::assertSame(3, $this->countRows('users', 'demo_guest_at IS NOT NULL'));
    }

    public function testDeletingAGuestRemovesItsDataAndKeepsPaidOrdersAnonymised(): void
    {
        $guest = $this->createGuest();
        $userId = (int) $guest['id'];
        $email = (string) $guest['Eaddress'];
        $reader = $this->createUser();

        self::assertTrue(cart_add($userId, 7, 1));
        $orders = [];
        foreach (['paid', 'refunded', 'failed', 'canceled', 'expired'] as $status) {
            db_run(
                'INSERT INTO orders (user_id, status, currency, total_amount, amount_minor) VALUES (?, ?, ?, ?, ?)',
                [$userId, $status, 'sar', '19.99', 1999]
            );
            $orders[$status] = (int) db()->lastInsertId();
            db_run("INSERT INTO order_items (order_id, book_id, title, unit_price, quantity) VALUES (?, 51, 'Esabella', '19.99', 1)", [$orders[$status]]);
        }
        db_run('INSERT INTO sales (book_id, user_id, order_id, quantity) VALUES (51, ?, ?, 1)', [$userId, $orders['paid']]);
        db_run("INSERT INTO comments (book_id, username, comment, rating) VALUES (51, ?, 'A demo review.', 5)", [$email]);
        db_run("INSERT INTO comments (book_id, username, comment, rating) VALUES (51, ?, 'Another review.', 4)", [$reader['Eaddress']]);
        $readerReview = (int) db()->lastInsertId();
        db_run("INSERT INTO comment_interactions (comment_id, user_id, reaction_type) VALUES (?, ?, 'like')", [$readerReview, $userId]);
        db_run("INSERT INTO demo_mail (recipient, subject, html, text) VALUES (?, 'Hello', '<p>Hi</p>', 'Hi')", [$email]);

        $avatar = 'uploads/avatars/' . bin2hex(random_bytes(16)) . '.png';
        $avatarFile = BASE_PATH . '/public/' . $avatar;
        file_put_contents($avatarFile, 'not really a picture');
        db_run('UPDATE users SET profile_image = ? WHERE id = ?', [$avatar, $userId]);

        try {
            self::assertSame('deleted', delete_demo_guest($userId));
            self::assertFileDoesNotExist($avatarFile, 'The uploaded picture goes too');
        } finally {
            @unlink($avatarFile);
        }

        self::assertFalse($this->userExists($userId));
        foreach (['cart', 'favorites', 'sales', 'comment_interactions'] as $table) {
            self::assertSame(0, $this->countRows($table, 'user_id = ?', [$userId]), $table);
        }
        self::assertSame(0, $this->countRows('comments', 'username = ?', [$email]));
        self::assertSame(1, $this->countRows('comments', 'id = ?', [$readerReview]), "Other readers' reviews stay");
        self::assertSame(0, $this->countRows('demo_mail', 'recipient = ?', [$email]));

        // Orders that took money stay as anonymised records; abandoned checkouts go.
        foreach (['paid', 'refunded', 'failed'] as $status) {
            self::assertSame(['user_id' => null, 'status' => $status], db_one('SELECT user_id, status FROM orders WHERE id = ?', [$orders[$status]]), $status);
            self::assertSame(1, $this->countRows('order_items', 'order_id = ?', [$orders[$status]]));
        }
        foreach (['canceled', 'expired'] as $status) {
            self::assertSame(0, $this->countRows('orders', 'id = ?', [$orders[$status]]), $status);
            self::assertSame(0, $this->countRows('order_items', 'order_id = ?', [$orders[$status]]));
        }

        self::assertSame('missing', delete_demo_guest($userId), 'Already gone');
        self::assertSame('missing', delete_demo_guest((int) $reader['id']), 'Never an ordinary customer');
        self::assertTrue($this->userExists((int) $reader['id']));
    }

    public function testAnOpenCheckoutIsSettledBeforeTheGuestIsDeleted(): void
    {
        // An abandoned checkout: the session is expired and the order goes.
        $abandoned = $this->createGuest();
        self::assertTrue(cart_add((int) $abandoned['id'], 51, 1));
        [$abandonedSession, $abandonedOrder] = $this->checkout($abandoned);
        self::assertSame('deleted', delete_demo_guest((int) $abandoned['id']));
        self::assertSame('expired', $this->stripe->session($abandonedSession)['status'] ?? null);
        self::assertSame(0, $this->countRows('orders', 'id = ?', [$abandonedOrder['id']]));

        // Paid but not confirmed yet: the payment is recorded and the order kept.
        $buyer = $this->createGuest();
        self::assertTrue(cart_add((int) $buyer['id'], 51, 1));
        [$paidSession, $paidOrder] = $this->checkout($buyer);
        $this->stripe->pay($paidSession);
        self::assertSame('deleted', delete_demo_guest((int) $buyer['id']));
        self::assertSame(['user_id' => null, 'status' => 'paid'], db_one('SELECT user_id, status FROM orders WHERE id = ?', [$paidOrder['id']]));

        // A payment that is still being confirmed: the guest waits for the next attempt.
        $waiting = $this->createGuest();
        self::assertTrue(cart_add((int) $waiting['id'], 51, 1));
        [$waitingSession] = $this->checkout($waiting);
        $this->stripe->setState($waitingSession, 'complete', 'unpaid');
        self::assertSame('unresolved', delete_demo_guest((int) $waiting['id']));
        self::assertTrue($this->userExists((int) $waiting['id']));
    }

    public function testTheCleanupDeletesOnlyGuestsOlderThanADay(): void
    {
        $old = $this->createGuest();
        $this->ageGuest((int) $old['id'], '25 HOUR');
        $young = $this->createGuest();
        $this->ageGuest((int) $young['id'], '23 HOUR');
        $customer = $this->createUser();
        db_run('UPDATE demo_guest_creations SET created_at = NOW() - INTERVAL 2 DAY WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM demo_guest_creations) AS first)');
        db_run("INSERT INTO demo_mail (recipient, subject, html, text, created_at) VALUES (?, 'Old', '', 'Old', NOW() - INTERVAL 3 DAY)", [$young['Eaddress']]);

        self::assertSame(['deleted' => 1, 'kept' => 0], delete_expired_demo_guests());

        self::assertFalse($this->userExists((int) $old['id']));
        self::assertTrue($this->userExists((int) $young['id']));
        self::assertTrue($this->userExists((int) $customer['id']));
        self::assertSame(1, $this->countRows('demo_guest_creations'), 'Creations older than a day are forgotten');
        self::assertSame(0, $this->countRows('demo_mail'), 'Expired demo inbox messages go too');

        self::assertSame(['deleted' => 0, 'kept' => 0], delete_expired_demo_guests(), 'Safe to run again');
    }

    public function testTheCleanupCommandCanRunFromAScheduledTask(): void
    {
        $old = $this->createGuest();
        $this->ageGuest((int) $old['id'], '2 DAY');
        $young = $this->createGuest();

        $run = self::runProjectScript('bin/demo-cleanup.php');

        self::assertSame(0, $run['status'], $run['stdout'] . $run['stderr']);
        self::assertSame('', $run['stderr']);
        self::assertMatchesRegularExpression('/^\[[0-9: -]+\] Demo cleanup: deleted 1 demo guest\(s\) older than 24 hours\.\R$/D', $run['stdout']);
        self::assertFalse($this->userExists((int) $old['id']));
        self::assertTrue($this->userExists((int) $young['id']));

        $again = self::runProjectScript('bin/demo-cleanup.php');
        self::assertSame(0, $again['status'], $again['stdout'] . $again['stderr']);
        self::assertStringContainsString('deleted 0 demo guest(s)', $again['stdout']);
    }

    public function testGuestsNeverGetAPasswordResetLink(): void
    {
        $guest = $this->createGuest();
        $customer = $this->createUser();

        $mails = $this->captureResetMails(static function () use ($guest, $customer): void {
            request_password_reset((string) $guest['Eaddress']);
            request_password_reset((string) $customer['Eaddress']);
        });

        self::assertSame(0, $this->countRows('reset_tokens', 'email = ?', [$guest['Eaddress']]));
        self::assertSame(1, $this->countRows('reset_tokens', 'email = ?', [$customer['Eaddress']]));
        self::assertSame([(string) $customer['Eaddress']], $mails);
    }

    /**
     * Recipients of the emails sent by $action (MAIL_DRIVER=log in a private LOG_DIR).
     *
     * @param callable(): void $action
     * @return list<string>
     */
    private function captureResetMails(callable $action): array
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bookwise-demo-mail-' . bin2hex(random_bytes(6));
        $this->setEnv('MAIL_DRIVER', 'log');
        $this->setEnv('LOG_DIR', $directory);
        try {
            $action();
            $log = (string) @file_get_contents($directory . DIRECTORY_SEPARATOR . 'mail.log');
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
        preg_match_all('/^To: .*<([^>\n]+)>$/m', $log, $matches);
        return $matches[1];
    }
}
