<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\AccountHelpers;
use BookWise\Tests\Support\DatabaseTestCase;

/**
 * Email verification domain code (src/users.php): sign-up without account
 * enumeration, single-use 24-hour tokens, throttled emails.
 */
final class EmailVerificationTest extends DatabaseTestCase
{
    use AccountHelpers;

    private const PASSWORD = 'a-secret-pass-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('REQUIRE_EMAIL_VERIFICATION', 'true');
        $this->setEnv('APP_URL', 'https://books.example.com');
    }

    public function testSignUpCreatesAnUnverifiedAccountAndEmailsALink(): void
    {
        $mails = $this->captureMail(static fn () => self::assertSame('created', sign_up_with_verification('new@example.com', 'New Reader', self::PASSWORD)));

        $account = find_user_account_by_email('new@example.com');
        self::assertNotNull($account);
        self::assertNull($account['email_verified_at']);
        self::assertSame('en', $account['locale']);
        self::assertTrue(password_verify(self::PASSWORD, (string) db_value('SELECT pass FROM users WHERE id = ?', [$account['id']])));
        self::assertTrue(customer_awaits_verification($account['id']));

        self::assertCount(1, $mails);
        self::assertSame(['new@example.com', '', 'Confirm your BookWise email address'], [$mails[0]['to'], $mails[0]['name'], $mails[0]['subject']]);
        self::assertStringNotContainsString('New Reader', $mails[0]['body'], 'An unconfirmed address gets no user-supplied text');
        $link = self::tokenLink('verify_email.php', $mails[0]['body']);
        self::assertStringStartsWith('https://books.example.com/verify_email.php?token=', $link);
        self::assertStringContainsString('expires in 24 hours', $mails[0]['body']);

        $raw = self::tokenOf($link);
        $row = db_one(
            'SELECT user_id, token, expiry > NOW() + INTERVAL 1439 MINUTE AS fresh, expiry <= NOW() + INTERVAL 24 HOUR AS bounded FROM email_verifications'
        );
        self::assertSame($account['id'], (int) $row['user_id']);
        self::assertSame(hash('sha256', $raw), $row['token'], 'Only the SHA-256 hash is stored');
        self::assertSame([1, 1], [(int) $row['fresh'], (int) $row['bounded']], 'Valid for 24 hours');
        self::assertSame($account['id'], find_valid_email_verification($raw));
    }

    public function testSignUpRemembersTheVisitorsLanguageForEmails(): void
    {
        use_locale('ar');
        try {
            $mails = $this->captureMail(static fn () => sign_up_with_verification('arabic@example.com', 'قارئ', self::PASSWORD));
        } finally {
            use_locale(null);
        }

        self::assertSame('ar', find_user_account_by_email('arabic@example.com')['locale'] ?? null);
        self::assertCount(1, $mails);
        self::assertSame('أكّد عنوان بريدك الإلكتروني في BookWise', $mails[0]['subject']);
        self::assertStringStartsWith('مرحبًا،', $mails[0]['body']);
        self::assertStringContainsString('24 ساعة', $mails[0]['body']);

        // Later emails follow the account's language, not the current visitor's.
        $this->ageAccountEmails();
        $mails = $this->captureMail(static fn () => resend_email_verification('arabic@example.com'));
        self::assertSame('أكّد عنوان بريدك الإلكتروني في BookWise', $mails[0]['subject'] ?? null);
        self::assertSame('en', current_locale());
    }

    public function testExistingCustomerGetsANoticeAndTheAccountIsUnchanged(): void
    {
        $user = $this->createUser('taken@example.com', 'Real Owner');
        $this->markVerified((int) $user['id']);
        $hash = db_value('SELECT pass FROM users WHERE id = ?', [$user['id']]);

        $mails = $this->captureMail(static fn () => self::assertSame('existing', sign_up_with_verification('taken@example.com', 'Impostor', 'impostor-password')));

        self::assertSame(1, $this->countRows('users'));
        self::assertSame(['Real Owner', $hash], array_values(db_one('SELECT name, pass FROM users WHERE id = ?', [$user['id']]) ?? []));
        self::assertCount(1, $mails);
        self::assertSame(['taken@example.com', 'Someone tried to sign up with your email address'], [$mails[0]['to'], $mails[0]['subject']]);
        self::assertStringContainsString('https://books.example.com/index.php?form=login', $mails[0]['body']);
        self::assertStringContainsString('https://books.example.com/index.php?form=reset', $mails[0]['body']);
        self::assertStringNotContainsString('verify_email.php', $mails[0]['body']);
        self::assertStringNotContainsString('Impostor', $mails[0]['body']);
        self::assertSame(0, $this->countRows('email_verifications', 'expiry > NOW()'), 'The throttle record can never confirm anything');
    }

    public function testUnconfirmedExistingAccountAlsoOnlyGetsANotice(): void
    {
        // Never email a confirmation link for an account someone else may have created with this address.
        $this->captureMail(static fn () => sign_up_with_verification('pending@example.com', 'First', self::PASSWORD));
        $this->ageAccountEmails();
        $firstToken = db_value('SELECT token FROM email_verifications');

        $mails = $this->captureMail(static fn () => sign_up_with_verification('pending@example.com', 'Second', 'other-password-2'));

        self::assertCount(1, $mails);
        self::assertSame('Someone tried to sign up with your email address', $mails[0]['subject']);
        self::assertSame($firstToken, db_value('SELECT token FROM email_verifications WHERE expiry > NOW()'), 'The pending confirmation link still works');
        self::assertSame('First', db_value('SELECT name FROM users WHERE Eaddress = ?', ['pending@example.com']));
    }

    public function testAdministratorAddressGetsNothing(): void
    {
        $this->createAdmin('boss@example.com');

        $mails = $this->captureMail(static fn () => self::assertSame('existing', sign_up_with_verification('boss@example.com', 'Impostor', self::PASSWORD)));

        self::assertSame([], $mails);
        self::assertSame(0, $this->countRows('users'));
        self::assertSame(0, $this->countRows('email_verifications'));
    }

    public function testAccountEmailsAreThrottledPerAddress(): void
    {
        $mails = $this->captureMail(static function (): void {
            sign_up_with_verification('slow@example.com', 'Slow Reader', self::PASSWORD);
            resend_email_verification('slow@example.com');
            sign_up_with_verification('slow@example.com', 'Slow Again', self::PASSWORD);
        });
        self::assertCount(1, $mails, 'One email per address within the cooldown');
        $first = self::tokenOf(self::tokenLink('verify_email.php', $mails[0]['body']));

        $this->ageAccountEmails();
        $mails = $this->captureMail(static fn () => resend_email_verification('slow@example.com'));
        self::assertCount(1, $mails);
        $second = self::tokenOf(self::tokenLink('verify_email.php', $mails[0]['body']));
        self::assertNotSame($first, $second);
        self::assertNull(find_valid_email_verification($first), 'A new link replaces the old one');
        self::assertNotNull(find_valid_email_verification($second));

        // Notices to a confirmed customer are throttled the same way.
        $owner = $this->createUser('owner@example.com');
        $this->markVerified((int) $owner['id']);
        $notices = $this->captureMail(static function (): void {
            sign_up_with_verification('owner@example.com', 'Someone', self::PASSWORD);
            sign_up_with_verification('owner@example.com', 'Someone', self::PASSWORD);
        });
        self::assertCount(1, $notices);
        $this->ageAccountEmails();
        self::assertCount(1, $this->captureMail(static fn () => sign_up_with_verification('owner@example.com', 'Someone', self::PASSWORD)));

        // Resending is silent for confirmed, unknown and administrator addresses.
        $this->createAdmin('boss@example.com');
        $this->ageAccountEmails();
        self::assertSame([], $this->captureMail(static function (): void {
            resend_email_verification('owner@example.com');
            resend_email_verification('nobody@example.com');
            resend_email_verification('boss@example.com');
        }));
    }

    public function testOldThrottleRecordsArePruned(): void
    {
        $user = $this->createUser('owner@example.com');
        $userId = (int) $user['id'];
        $this->markVerified($userId);
        for ($i = 0; $i < 3; $i++) {
            db_run(
                'INSERT INTO email_verifications (user_id, token, expiry, created_at) VALUES (?, ?, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY)',
                [$userId, hash('sha256', bin2hex(random_bytes(32)))]
            );
        }

        $this->captureMail(static fn () => sign_up_with_verification('owner@example.com', 'Someone', self::PASSWORD));

        self::assertSame(1, $this->countRows('email_verifications', 'user_id = ?', [$userId]));
    }

    public function testCompletingVerificationIsSingleUseAndDropsEveryToken(): void
    {
        $user = $this->createUser('confirm@example.com', 'Confirm Reader');
        $userId = (int) $user['id'];
        $raw = $this->issueVerificationToken($userId);
        $other = $this->issueVerificationToken($userId);
        $bystander = $this->createUser('bystander@example.com');
        $bystanderToken = $this->issueVerificationToken((int) $bystander['id']);

        $account = complete_email_verification($raw);

        self::assertNotNull($account);
        self::assertSame([$userId, 'confirm@example.com', 'Confirm Reader'], [$account['id'], $account['Eaddress'], $account['name']]);
        self::assertNotNull($account['email_verified_at']);
        self::assertTrue($this->isVerified($userId));
        self::assertFalse(customer_awaits_verification($userId));
        self::assertSame(0, $this->countRows('email_verifications', 'user_id = ?', [$userId]));
        self::assertNull(complete_email_verification($raw), 'Single use');
        self::assertNull(complete_email_verification($other), 'Every token of the account is dropped');
        self::assertSame((int) $bystander['id'], find_valid_email_verification($bystanderToken), 'Other accounts keep theirs');
    }

    public function testExpiredAndMalformedTokensAreRejected(): void
    {
        $user = $this->createUser('late@example.com');
        $userId = (int) $user['id'];
        $expired = $this->issueVerificationToken($userId, 'NOW() - INTERVAL 1 MINUTE');

        self::assertNull(find_valid_email_verification($expired));
        self::assertNull(complete_email_verification($expired));
        self::assertFalse($this->isVerified($userId));

        $raw = $this->issueVerificationToken($userId);
        foreach (['', 'abc', strtoupper($raw), substr($raw, 1), $raw . 'a', ' ' . $raw, hash('sha256', $raw)] as $candidate) {
            self::assertNull(find_valid_email_verification($candidate), var_export($candidate, true));
            self::assertNull(complete_email_verification($candidate), var_export($candidate, true));
        }
        self::assertFalse($this->isVerified($userId));
        self::assertNotNull(complete_email_verification($raw));
    }

    public function testVerificationGateFollowsTheSetting(): void
    {
        $user = $this->createUser('gate@example.com');
        $userId = (int) $user['id'];

        self::assertTrue(customer_awaits_verification($userId));
        $this->setEnv('REQUIRE_EMAIL_VERIFICATION', 'false');
        self::assertFalse(customer_awaits_verification($userId), 'Without the setting nobody waits');
        self::assertFalse(email_verification_required());

        $this->setEnv('REQUIRE_EMAIL_VERIFICATION', 'true');
        $this->markVerified($userId);
        self::assertFalse(customer_awaits_verification($userId), 'Confirmed (or migrated) accounts sign in normally');
        self::assertFalse(customer_awaits_verification(999999));
    }

    public function testVerificationEmailIsNotSentTwiceToAConfirmedAccount(): void
    {
        $user = $this->createUser('done@example.com');
        $this->markVerified((int) $user['id']);
        $account = find_user_account((int) $user['id']);
        self::assertNotNull($account);

        self::assertSame([], $this->captureMail(static fn () => self::assertFalse(send_email_verification($account))));
        self::assertSame(0, $this->countRows('email_verifications'));
    }
}
