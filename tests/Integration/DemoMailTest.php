<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\DatabaseTestCase;

/**
 * The demo inbox's domain code (src/mailer.php) against the database: the
 * "database" mail driver, the 48-hour retention, what happens to the mail
 * of deleted accounts, and which addresses a browser session may read.
 */
final class DemoMailTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('APP_DEMO', 'true');
        $this->setEnv('MAIL_DRIVER', 'database');
        $this->setEnv('APP_URL', 'https://demo.example.com');
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * Inserts a message directly, $ageSql before now (e.g. "47 HOUR").
     */
    private function storeMessage(string $recipient, string $subject, string $ageSql = '0 SECOND', string $locale = 'en'): int
    {
        db_run(
            "INSERT INTO demo_mail (recipient, subject, html, text, locale, created_at) VALUES (?, ?, '<p>html</p>', 'text', ?, NOW() - INTERVAL $ageSql)",
            [$recipient, $subject, $locale]
        );
        return (int) db()->lastInsertId();
    }

    /**
     * @param list<array{id: int, recipient: string, subject: string, locale: string, created_at: string}> $messages
     * @return list<string>
     */
    private static function subjects(array $messages): array
    {
        return array_map(static fn (array $message): string => $message['subject'], $messages);
    }

    public function testTheDatabaseDriverStoresTheMessageWithItsLanguage(): void
    {
        $this->createUser('reader@example.com');
        $this->createUser('other@example.com');
        self::assertTrue(send_mail(' Reader@Example.com ', 'Reader', 'تأكيد البريد', '<p dir="rtl">html</p>', "نص\nhttps://demo.example.com/x", 'ar'));
        self::assertTrue(send_mail('other@example.com', '', 'Plain', '', 'text', 'xx'));

        $rows = db_all('SELECT recipient, subject, html, text, locale, created_at > NOW() - INTERVAL 1 MINUTE AS fresh FROM demo_mail ORDER BY id');
        self::assertSame([
            ['recipient' => 'reader@example.com', 'subject' => 'تأكيد البريد', 'html' => '<p dir="rtl">html</p>', 'text' => "نص\nhttps://demo.example.com/x", 'locale' => 'ar', 'fresh' => 1],
            ['recipient' => 'other@example.com', 'subject' => 'Plain', 'html' => '', 'text' => 'text', 'locale' => 'en', 'fresh' => 1],
        ], $rows, 'Addresses are normalised and unknown languages fall back to the default');
    }

    public function testLocalizedMailIsStoredInTheRecipientsLanguage(): void
    {
        $user = $this->createUser('arabic-reader@example.com', 'قارئ');
        db_run("UPDATE users SET locale = 'ar' WHERE id = ?", [$user['id']]);

        request_password_reset('arabic-reader@example.com');

        $row = db_one('SELECT recipient, subject, locale, text FROM demo_mail');
        self::assertNotNull($row);
        self::assertSame(['arabic-reader@example.com', 'إعادة تعيين كلمة المرور في BookWise', 'ar'], [$row['recipient'], $row['subject'], $row['locale']]);
        self::assertStringContainsString('https://demo.example.com/reset_form.php?token=', (string) $row['text']);
        self::assertSame('en', current_locale(), "The visitor's language is restored");
    }

    public function testStoringAMessageDeletesMessagesOlderThan48Hours(): void
    {
        $this->createUser('new@example.com');
        $this->storeMessage('reader@example.com', 'Expired', '49 HOUR');
        $this->storeMessage('someone@example.com', 'Also expired', '2881 MINUTE');
        $this->storeMessage('reader@example.com', 'Recent', '47 HOUR');

        self::assertTrue(send_mail('new@example.com', '', 'New', '<p>x</p>', 'x'));

        self::assertSame(['Recent', 'New'], array_column(db_all('SELECT subject FROM demo_mail ORDER BY id'), 'subject'));

        $this->storeMessage('reader@example.com', 'Expired since', '49 HOUR');
        purge_expired_demo_mail();
        self::assertSame(['Recent', 'New'], array_column(db_all('SELECT subject FROM demo_mail ORDER BY id'), 'subject'), 'The inbox page purges too');
    }

    public function testMailToAnAddressWithoutAccountIsNotStored(): void
    {
        $this->createUser('customer@example.com');
        $this->createAdmin('boss@example.com');
        $this->storeMessage('someone@example.com', 'Expired', '49 HOUR');

        // Nobody could ever read it, so it is dropped (and the email counts as handled).
        self::assertTrue(send_mail('nobody@example.com', 'Nobody', 'Lost', '<p>x</p>', 'x'));
        self::assertTrue(send_mail('Customer@Example.com', '', 'For the customer', '<p>x</p>', 'x'));
        self::assertTrue(send_mail('boss@example.com', '', 'For the administrator', '<p>x</p>', 'x'));

        self::assertSame(
            [['customer@example.com', 'For the customer'], ['boss@example.com', 'For the administrator']],
            array_map(static fn (array $row): array => [$row['recipient'], $row['subject']], db_all('SELECT recipient, subject FROM demo_mail ORDER BY id'))
        );
    }

    public function testDeletingAnAccountDeletesItsMailAndStoresNoGoodbye(): void
    {
        $user = $this->createUser('leaving@example.com', 'Leaving Reader');
        $this->createUser('staying@example.com');
        request_password_reset('leaving@example.com');
        $this->storeMessage('leaving@example.com', 'Older mail', '1 HOUR');
        $this->storeMessage('staying@example.com', 'Their mail');
        self::assertSame(2, $this->countRows('demo_mail', 'recipient = ?', ['leaving@example.com']));

        $result = delete_customer_account((int) $user['id'], self::DEFAULT_PASSWORD, '203.0.113.20');
        self::assertSame('deleted', $result['status']);
        self::assertNotNull($result['account']);
        send_account_deleted_email($result['account']);

        self::assertSame(0, $this->countRows('demo_mail', 'recipient = ?', ['leaving@example.com']), 'Its mail, and no "account deleted" notice nobody could read');
        self::assertSame(['Their mail'], array_column(db_all('SELECT subject FROM demo_mail'), 'subject'));
    }

    public function testNothingIsStoredOutsideDemoMode(): void
    {
        $this->setEnv('APP_DEMO', 'false');
        $this->setEnv('LOG_DIR', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bookwise-demo-mail-' . bin2hex(random_bytes(4)));

        self::assertFalse(send_mail('reader@example.com', '', 'Subject', '<p>x</p>', 'x'));
        self::assertSame(0, $this->countRows('demo_mail'));
        $log = log_path('app.log');
        self::assertStringContainsString('ERROR: MAIL_DRIVER=database is only available in demo mode', (string) file_get_contents($log));
        @unlink($log);
        @rmdir(dirname($log));
    }

    public function testAGuestReadsOnlyTheAccountsItCreated(): void
    {
        self::assertSame([], demo_inbox_addresses(), 'A new browser can read nothing');

        $mine = $this->createUser('mine@example.com');
        $this->createUser('someone-else@example.com');
        demo_inbox_remember_account('mine@example.com');
        demo_inbox_remember_account('mine@example.com');
        demo_inbox_remember_account('nobody@example.com'); // no such account: nothing to remember

        self::assertSame([(int) $mine['id'] => 'mine@example.com'], $_SESSION[DEMO_INBOX_SESSION_KEY]);
        self::assertSame(['mine@example.com'], demo_inbox_addresses());
    }

    public function testAccessEndsWhenTheAccountIsDeletedAndTheAddressRegisteredAgain(): void
    {
        $this->createUser('reused@example.com');
        demo_inbox_remember_account('reused@example.com');
        self::assertSame(['reused@example.com'], demo_inbox_addresses());

        db_run('DELETE FROM users WHERE Eaddress = ?', ['reused@example.com']);
        self::assertSame([], demo_inbox_addresses(), 'The deleted account');
        $this->createUser('reused@example.com', 'Someone Else');
        self::assertSame([], demo_inbox_addresses(), 'The new account belongs to whoever created it');
    }

    public function testASignedInCustomerReadsTheirOwnAddressAndTheAccountsTheyCreated(): void
    {
        $customer = $this->createUser('customer@example.com');
        $this->createUser('created@example.com');
        demo_inbox_remember_account('created@example.com');
        $_SESSION += ['user_id' => (int) $customer['id'], 'role' => 'user', 'session_version' => 0];

        self::assertSame(['customer@example.com', 'created@example.com'], demo_inbox_addresses());
    }

    public function testAnAdministratorReadsOnlyTheirOwnAddress(): void
    {
        $this->createUser('created-before@example.com');
        demo_inbox_remember_account('created-before@example.com');
        $admin = $this->createAdmin('Boss@Example.com');
        $_SESSION += ['admin_email' => $admin['Eaddress'], 'role' => 'admin', 'session_version' => 0];

        self::assertSame(['boss@example.com'], demo_inbox_addresses());
    }

    public function testNothingIsRememberedWhenTheInboxIsOff(): void
    {
        $this->createUser('created@example.com');
        foreach ([['APP_DEMO', 'false'], ['MAIL_DRIVER', 'null']] as [$key, $value]) {
            $this->setEnv('APP_DEMO', 'true');
            $this->setEnv('MAIL_DRIVER', 'database');
            $this->setEnv($key, $value);
            demo_inbox_remember_account('created@example.com');
            self::assertSame([], $_SESSION, "$key=$value");
        }
    }

    public function testABrowserRemembersAtMostTwentyAccounts(): void
    {
        $ids = [];
        for ($i = 1; $i <= DEMO_INBOX_MAX_ACCOUNTS + 2; $i++) {
            $ids[] = (int) $this->createUser("created-$i@example.com")['id'];
            demo_inbox_remember_account("created-$i@example.com");
        }

        self::assertSame(array_slice($ids, 2), array_keys($_SESSION[DEMO_INBOX_SESSION_KEY]), 'The oldest are forgotten first');
        self::assertCount(DEMO_INBOX_MAX_ACCOUNTS, demo_inbox_addresses());
    }

    public function testMessagesAreListedNewestFirstForTheGivenAddressesOnly(): void
    {
        $this->storeMessage('a@example.com', 'A old', '3 HOUR');
        $newest = $this->storeMessage('a@example.com', 'A new', '1 MINUTE', 'ar');
        $this->storeMessage('b@example.com', 'B', '2 HOUR');
        $other = $this->storeMessage('c@example.com', 'C', '1 SECOND');
        $expired = $this->storeMessage('a@example.com', 'A expired', '49 HOUR');

        $messages = demo_inbox_messages(['a@example.com', 'b@example.com']);
        self::assertSame(['A new', 'B', 'A old'], self::subjects($messages));
        self::assertSame(['id' => $newest, 'recipient' => 'a@example.com', 'subject' => 'A new', 'locale' => 'ar'], array_diff_key($messages[0], ['created_at' => true]));
        self::assertSame([], demo_inbox_messages([]));

        self::assertSame('text', demo_inbox_message($newest, ['a@example.com'])['text'] ?? null);
        self::assertNull(demo_inbox_message($other, ['a@example.com', 'b@example.com']), "Someone else's message");
        self::assertNull(demo_inbox_message($expired, ['a@example.com']), 'An expired message');
        self::assertNull(demo_inbox_message($newest, []));
        self::assertNull(demo_inbox_message(999999, ['a@example.com']));
    }

    public function testSignUpForgetsMailKeptForAnAddressWithoutAccount(): void
    {
        $this->createUser('member@example.com');
        $this->storeMessage('member@example.com', 'Member mail');
        $this->storeMessage('gone@example.com', 'Your account was deleted');

        demo_inbox_forget_unowned_mail('member@example.com');
        demo_inbox_forget_unowned_mail('gone@example.com');
        self::assertSame(['Member mail'], array_column(db_all('SELECT subject FROM demo_mail'), 'subject'));

        $this->storeMessage('gone@example.com', 'Kept while the inbox is off');
        $this->setEnv('APP_DEMO', 'false');
        demo_inbox_forget_unowned_mail('gone@example.com');
        self::assertSame(2, $this->countRows('demo_mail'));
    }

    public function testMigration004CreatesTheSameTableAsTheSchema(): void
    {
        $definition = static fn (): string => (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) db_one('SHOW CREATE TABLE demo_mail')['Create Table']);
        $fresh = $definition();

        try {
            // A database from before 004.
            db()->exec('DROP TABLE demo_mail');
            db_run('DELETE FROM schema_migrations WHERE version = ?', ['004_demo_mail']);

            self::assertSame(['004_demo_mail'], run_migrations());
            self::assertSame($fresh, $definition());
            self::assertSame([], run_migrations(), 'Applied once');
        } finally {
            self::schemaChanged();
        }
    }
}
