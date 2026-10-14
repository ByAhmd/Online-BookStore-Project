<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\AccountHelpers;
use BookWise\Tests\Support\DatabaseTestCase;

final class PasswordResetTest extends DatabaseTestCase
{
    use AccountHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        // PHPUnit turns value="false" in phpunit.xml.dist into an empty string,
        // which leaves the application default (verification required) in effect.
        $this->setEnv('REQUIRE_EMAIL_VERIFICATION', 'false');
    }

    /**
     * Inserts a token directly and returns the raw value the email would carry.
     */
    private function issueToken(string $email, string $expirySql = 'NOW() + INTERVAL 60 MINUTE'): string
    {
        $raw = bin2hex(random_bytes(32));
        db_run("INSERT INTO reset_tokens (email, token, expiry) VALUES (?, ?, $expirySql)", [$email, hash('sha256', $raw)]);
        return $raw;
    }

    /**
     * Runs request_password_reset() with the "log" mail driver writing to a
     * temporary LOG_DIR and returns the raw token from the emailed link (null
     * if no email). It runs in this process (not IsolatedPhp, whose throwaway
     * BASE_PATH has no lang/ directory) because the email is translated.
     */
    private function requestResetAndReadEmail(string $email, string $expectedName = 'Test Reader', string $expectedSubject = 'Reset your BookWise password'): ?string
    {
        $this->setEnv('APP_URL', 'https://books.example.com');
        $mails = $this->captureMail(static fn () => request_password_reset($email));
        if ($mails === []) {
            return null;
        }
        self::assertCount(1, $mails);
        self::assertSame([$email, $expectedName, $expectedSubject], [$mails[0]['to'], $mails[0]['name'], $mails[0]['subject']]);
        self::assertSame(1, preg_match('#https://books\.example\.com/reset_form\.php\?token=([a-f0-9]{64})\b#', $mails[0]['body'], $m), $mails[0]['body']);
        return $m[1];
    }

    public function testResetEmailUsesTheAccountsLanguage(): void
    {
        $user = $this->createUser('arabic-reader@example.com', 'قارئ عربي');
        update_user_locale((int) $user['id'], 'ar');

        $raw = $this->requestResetAndReadEmail('arabic-reader@example.com', 'قارئ عربي', 'إعادة تعيين كلمة المرور في BookWise');

        self::assertNotNull($raw);
        self::assertSame('en', current_locale(), 'The visitor language is restored after composing the email');
    }

    public function testUnconfirmedAccountNamesAreLeftOutWhenVerificationIsRequired(): void
    {
        // A pre-registered (unconfirmed) account's name may have been typed by someone else.
        $this->setEnv('REQUIRE_EMAIL_VERIFICATION', 'true');
        $user = $this->createUser('pending@example.com', 'Call +1 555 0100 now');

        self::assertNotNull($this->requestResetAndReadEmail('pending@example.com', ''));

        $this->markVerified((int) $user['id']);
        $this->ageResetTokens('pending@example.com');
        self::assertNotNull($this->requestResetAndReadEmail('pending@example.com', 'Call +1 555 0100 now'));
    }

    public function testCompletingAResetConfirmsTheEmailAddress(): void
    {
        $user = $this->createUser('unconfirmed@example.com');
        $userId = (int) $user['id'];
        $verification = $this->issueVerificationToken($userId);
        self::assertFalse($this->isVerified($userId));

        self::assertTrue(complete_password_reset($this->issueToken('unconfirmed@example.com'), 'brand-new-password'));

        self::assertTrue($this->isVerified($userId), 'The reset link proved ownership of the mailbox');
        self::assertSame(0, $this->countRows('email_verifications', 'user_id = ?', [$userId]), 'Pending confirmation links are dropped');
        self::assertNull(complete_email_verification($verification));

        $this->setEnv('REQUIRE_EMAIL_VERIFICATION', 'true');
        self::assertFalse(customer_awaits_verification($userId));
    }

    public function testResetKeepsAnEarlierConfirmationDate(): void
    {
        $user = $this->createUser('confirmed@example.com');
        db_run("UPDATE users SET email_verified_at = '2024-01-02 03:04:05' WHERE id = ?", [$user['id']]);

        self::assertTrue(complete_password_reset($this->issueToken('confirmed@example.com'), 'brand-new-password'));

        self::assertSame('2024-01-02 03:04:05', db_value('SELECT email_verified_at FROM users WHERE id = ?', [$user['id']]));
    }

    private function ageResetTokens(string $email): void
    {
        db_run('UPDATE reset_tokens SET expiry = NOW() + INTERVAL ? MINUTE WHERE email = ?', [RESET_TOKEN_TTL_MINUTES - RESET_REQUEST_COOLDOWN_MINUTES - 1, $email]);
    }

    public function testRequestEmailsALinkAndStoresOnlyTheTokenHash(): void
    {
        $this->createUser('forgetful@example.com');

        $raw = $this->requestResetAndReadEmail('forgetful@example.com');

        self::assertNotNull($raw);
        $row = db_one('SELECT email, token, expiry > NOW() + INTERVAL 59 MINUTE AS fresh, expiry <= NOW() + INTERVAL 60 MINUTE AS bounded FROM reset_tokens');
        self::assertSame('forgetful@example.com', $row['email']);
        self::assertSame(hash('sha256', $raw), $row['token']);
        self::assertNotSame($raw, $row['token']);
        self::assertSame([1, 1], [(int) $row['fresh'], (int) $row['bounded']], 'Valid for RESET_TOKEN_TTL_MINUTES');
        self::assertSame('forgetful@example.com', find_valid_reset_email($raw));
    }

    public function testUnknownEmailIsSilent(): void
    {
        self::assertNull($this->requestResetAndReadEmail('nobody@example.com'));
        self::assertSame(0, $this->countRows('reset_tokens'));

        request_password_reset('nobody@example.com');
        self::assertSame(0, $this->countRows('reset_tokens'));
    }

    public function testAdministratorsCannotUseTheCustomerReset(): void
    {
        $this->createAdmin('admin@example.com');

        request_password_reset('admin@example.com');

        self::assertSame(0, $this->countRows('reset_tokens'));
    }

    public function testRepeatedRequestsWithinTheCooldownAreIgnored(): void
    {
        $this->createUser('forgetful@example.com');
        request_password_reset('forgetful@example.com');
        $first = db_value('SELECT token FROM reset_tokens');

        request_password_reset('forgetful@example.com');

        self::assertSame(1, $this->countRows('reset_tokens'));
        self::assertSame($first, db_value('SELECT token FROM reset_tokens'));
    }

    public function testRequestAfterTheCooldownReplacesTheOldToken(): void
    {
        $this->createUser('forgetful@example.com');
        $old = $this->issueToken('forgetful@example.com', 'NOW() + INTERVAL ' . (RESET_TOKEN_TTL_MINUTES - RESET_REQUEST_COOLDOWN_MINUTES - 1) . ' MINUTE');

        request_password_reset('forgetful@example.com');

        self::assertSame(1, $this->countRows('reset_tokens'));
        self::assertNotSame(hash('sha256', $old), db_value('SELECT token FROM reset_tokens'));
        self::assertNull(find_valid_reset_email($old), 'A new request invalidates the previous link');
    }

    public function testCompleteResetChangesThePasswordOnce(): void
    {
        $this->createUser('forgetful@example.com');
        record_failed_login('forgetful@example.com', '198.51.100.1');
        $raw = $this->issueToken('forgetful@example.com');

        self::assertTrue(complete_password_reset($raw, 'brand-new-password'));

        self::assertSame('user', attempt_login('forgetful@example.com', 'brand-new-password')['type'] ?? null);
        self::assertNull(attempt_login('forgetful@example.com', self::DEFAULT_PASSWORD));
        self::assertSame(0, $this->countRows('login_attempts'), 'Lockout counters are cleared');

        self::assertFalse(complete_password_reset($raw, 'second-attempt-pass'), 'Single use');
        self::assertNull(find_valid_reset_email($raw));
        self::assertSame('user', attempt_login('forgetful@example.com', 'brand-new-password')['type'] ?? null);
    }

    public function testCompletingOneTokenInvalidatesAllTokensForTheAccount(): void
    {
        $this->createUser('forgetful@example.com');
        $this->createUser('bystander@example.com');
        $first = $this->issueToken('forgetful@example.com');
        $second = $this->issueToken('forgetful@example.com');
        $other = $this->issueToken('bystander@example.com');

        self::assertTrue(complete_password_reset($first, 'brand-new-password'));

        self::assertNull(find_valid_reset_email($second));
        self::assertFalse(complete_password_reset($second, 'another-new-password'));
        self::assertSame('bystander@example.com', find_valid_reset_email($other), 'Other accounts keep their tokens');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->createUser('forgetful@example.com');
        $raw = $this->issueToken('forgetful@example.com');
        db_run('UPDATE reset_tokens SET expiry = NOW() - INTERVAL 1 MINUTE');

        self::assertNull(find_valid_reset_email($raw));
        self::assertFalse(complete_password_reset($raw, 'brand-new-password'));
        self::assertSame('user', attempt_login('forgetful@example.com', self::DEFAULT_PASSWORD)['type'] ?? null, 'Password unchanged');
    }

    public function testMalformedTokensAreRejectedWithoutTouchingTheDatabase(): void
    {
        $this->createUser('forgetful@example.com');
        $raw = $this->issueToken('forgetful@example.com');

        foreach (['', 'abc', strtoupper($raw), substr($raw, 1), $raw . 'a', hash('sha256', $raw) . ' '] as $candidate) {
            self::assertNull(find_valid_reset_email($candidate));
            self::assertFalse(complete_password_reset($candidate, 'brand-new-password'));
        }
        self::assertNull(find_valid_reset_email(hash('sha256', $raw)), 'The stored hash itself is not a valid token');
        self::assertSame(1, $this->countRows('reset_tokens'));
    }

    public function testTokenForADeletedAccountChangesNothing(): void
    {
        $user = $this->createUser('gone@example.com');
        $raw = $this->issueToken('gone@example.com');
        db_run('DELETE FROM users WHERE id = ?', [$user['id']]);

        self::assertFalse(complete_password_reset($raw, 'brand-new-password'));
        self::assertSame(0, $this->countRows('reset_tokens'));
    }
}
