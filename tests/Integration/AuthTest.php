<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\DatabaseTestCase;

final class AuthTest extends DatabaseTestCase
{
    public function testCustomerLogsInWithoutExposingTheHash(): void
    {
        $user = $this->createUser('login@example.com', 'Login Reader');

        $result = attempt_login('login@example.com', self::DEFAULT_PASSWORD);

        self::assertSame(['type' => 'user', 'record' => ['id' => $user['id'], 'Eaddress' => 'login@example.com', 'name' => 'Login Reader']], $result);
    }

    public function testWrongPasswordAndUnknownEmailFail(): void
    {
        $this->createUser('login@example.com');

        self::assertNull(attempt_login('login@example.com', 'wrong-password'));
        self::assertNull(attempt_login('login@example.com', strtoupper(self::DEFAULT_PASSWORD)));
        self::assertNull(attempt_login('login@example.com', ''));
        self::assertNull(attempt_login('nobody@example.com', self::DEFAULT_PASSWORD));
    }

    public function testCredentialCheckIgnoresTheVerificationState(): void
    {
        // attempt_login() only checks credentials; Register.php applies the verification gate
        // afterwards, so a wrong password never reveals whether an address is confirmed.
        $this->setEnv('REQUIRE_EMAIL_VERIFICATION', 'true');
        $user = $this->createUser('unconfirmed@example.com', 'Unconfirmed');

        self::assertSame('user', attempt_login('unconfirmed@example.com', self::DEFAULT_PASSWORD)['type'] ?? null);
        self::assertNull(attempt_login('unconfirmed@example.com', 'wrong-password'));
        self::assertTrue(customer_awaits_verification((int) $user['id']));
    }

    public function testAdministratorLogsIn(): void
    {
        $this->createAdmin('admin@example.com', 'Store Admin');

        self::assertSame(
            ['type' => 'admin', 'record' => ['Eaddress' => 'admin@example.com', 'name' => 'Store Admin']],
            attempt_login('admin@example.com', self::DEFAULT_PASSWORD)
        );
        self::assertNull(attempt_login('admin@example.com', 'wrong-password'));
    }

    public function testSameEmailInBothTablesResolvesByPassword(): void
    {
        // Possible in databases migrated from the original project.
        $this->createAdmin('both@example.com', 'Admin Side', 'admin-password');
        db_run('INSERT INTO users (Eaddress, name, pass) VALUES (?, ?, ?)', ['both@example.com', 'Customer Side', hash_password('customer-password')]);

        self::assertSame('admin', attempt_login('both@example.com', 'admin-password')['type'] ?? null);
        self::assertSame('user', attempt_login('both@example.com', 'customer-password')['type'] ?? null);
        self::assertNull(attempt_login('both@example.com', 'neither-password'));
    }

    public function testLegacyMd5CustomerHashIsUpgradedOnLogin(): void
    {
        db_run('INSERT INTO users (Eaddress, name, pass) VALUES (?, ?, ?)', ['legacy@example.com', 'Legacy', md5('legacy-pass')]);

        self::assertNull(attempt_login('legacy@example.com', 'wrong-pass'));
        self::assertSame(md5('legacy-pass'), db_value('SELECT pass FROM users WHERE Eaddress = ?', ['legacy@example.com']), 'A failed login does not touch the hash');

        self::assertSame('user', attempt_login('legacy@example.com', 'legacy-pass')['type'] ?? null);
        $upgraded = (string) db_value('SELECT pass FROM users WHERE Eaddress = ?', ['legacy@example.com']);
        self::assertStringStartsWith('$2y$', $upgraded);
        self::assertTrue(password_verify('legacy-pass', $upgraded));

        self::assertSame('user', attempt_login('legacy@example.com', 'legacy-pass')['type'] ?? null, 'Still works after the upgrade');
        self::assertSame($upgraded, db_value('SELECT pass FROM users WHERE Eaddress = ?', ['legacy@example.com']), 'No needless rehash');
    }

    public function testLegacyMd5AdminHashIsUpgradedOnLogin(): void
    {
        db_run('INSERT INTO admins (Eaddress, name, password) VALUES (?, ?, ?)', ['old-admin@example.com', 'Old Admin', strtoupper(md5('admin-pass'))]);

        self::assertSame('admin', attempt_login('old-admin@example.com', 'admin-pass')['type'] ?? null);
        $upgraded = (string) db_value('SELECT password FROM admins WHERE Eaddress = ?', ['old-admin@example.com']);
        self::assertStringStartsWith('$2y$', $upgraded);
        self::assertTrue(password_verify('admin-pass', $upgraded));
    }

    public function testWeakBcryptHashIsRehashed(): void
    {
        db_run('INSERT INTO users (Eaddress, name, pass) VALUES (?, ?, ?)', ['cheap@example.com', 'Cheap', password_hash('cheap-pass', PASSWORD_BCRYPT, ['cost' => 4])]);

        self::assertSame('user', attempt_login('cheap@example.com', 'cheap-pass')['type'] ?? null);
        $hash = (string) db_value('SELECT pass FROM users WHERE Eaddress = ?', ['cheap@example.com']);
        self::assertFalse(password_needs_rehash($hash, PASSWORD_DEFAULT));
    }

    public function testEmptyStoredHashNeverLogsIn(): void
    {
        db_run('INSERT INTO users (Eaddress, name, pass) VALUES (?, ?, ?)', ['empty@example.com', 'Empty', '']);

        self::assertNull(attempt_login('empty@example.com', ''));
        self::assertNull(attempt_login('empty@example.com', 'anything'));
    }

    /* -----------------------------------------------------------------
     * Throttling
     * --------------------------------------------------------------- */

    public function testEmailIsThrottledAfterFiveFailuresFromTheSameIp(): void
    {
        for ($i = 1; $i < LOGIN_MAX_ATTEMPTS_PER_EMAIL; $i++) {
            record_failed_login('target@example.com', '198.51.100.1');
        }
        self::assertFalse(login_is_throttled('target@example.com', '198.51.100.1'), 'Four failures are allowed');

        record_failed_login('target@example.com', '198.51.100.1');
        self::assertTrue(login_is_throttled('target@example.com', '198.51.100.1'));
        self::assertFalse(login_is_throttled('other@example.com', '198.51.100.2'));
    }

    public function testFailuresFromOtherIpsCannotLockTheOwnerOut(): void
    {
        // An attacker hammering someone else's (e.g. an admin's) email must not lock out the real owner.
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS_PER_EMAIL * 3; $i++) {
            record_failed_login('admin@example.com', '198.51.100.' . ($i % 3 + 1));
        }

        self::assertTrue(login_is_throttled('admin@example.com', '198.51.100.1'));
        self::assertFalse(login_is_throttled('admin@example.com', '203.0.113.50'), 'The owner signs in from elsewhere');
    }

    public function testVeryManyFailuresForOneEmailThrottleItEverywhere(): void
    {
        $rows = [];
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS_PER_EMAIL_GLOBAL; $i++) {
            $rows[] = "('target@example.com', '10.0." . intdiv($i, 250) . '.' . ($i % 250) . "')";
        }
        db_run('INSERT INTO login_attempts (email, ip_address) VALUES ' . implode(',', $rows));

        self::assertTrue(login_is_throttled('target@example.com', '203.0.113.77'), 'Distributed guessing hits the global ceiling');
    }

    public function testTheGlobalCeilingNeedsManyNetworks(): void
    {
        // The guesses of one IPv6 host spread over its /64 never reach the global
        // ceiling: they stop after LOGIN_MAX_ATTEMPTS_PER_EMAIL.
        $failed = 0;
        for ($i = 1; $i <= LOGIN_MAX_ATTEMPTS_PER_EMAIL_GLOBAL; $i++) {
            $ip = '2001:db8:1:2::' . dechex($i);
            if (!login_is_throttled('admin@example.com', $ip)) {
                record_failed_login('admin@example.com', $ip);
                $failed++;
            }
        }

        self::assertSame(LOGIN_MAX_ATTEMPTS_PER_EMAIL, $failed);
        self::assertFalse(login_is_throttled('admin@example.com', '203.0.113.9'), 'The owner still signs in from elsewhere');
    }

    public function testIpIsThrottledAfterTwentyFailuresAcrossEmails(): void
    {
        for ($i = 1; $i < LOGIN_MAX_ATTEMPTS_PER_IP; $i++) {
            record_failed_login("victim-$i@example.com", '192.0.2.10');
        }
        self::assertFalse(login_is_throttled('fresh@example.com', '192.0.2.10'));

        record_failed_login('victim-20@example.com', '192.0.2.10');
        self::assertTrue(login_is_throttled('fresh@example.com', '192.0.2.10'));
        self::assertFalse(login_is_throttled('fresh@example.com', '192.0.2.11'), 'Other IPs are unaffected');
    }

    public function testAnIpv6HostCannotEscapeTheLimitsWithinItsNetwork(): void
    {
        // One host picks a new address of its /64 for every guess.
        for ($i = 1; $i <= LOGIN_MAX_ATTEMPTS_PER_EMAIL; $i++) {
            record_failed_login('target@example.com', '2001:db8:1:2::' . dechex($i));
        }
        self::assertTrue(login_is_throttled('target@example.com', '2001:db8:1:2:ffff::99'), 'The whole /64 counts as one visitor');
        self::assertSame(['2001:db8:1:2::/64'], array_values(array_unique(array_map('strval', db_run('SELECT ip_address FROM login_attempts')->fetchAll(\PDO::FETCH_COLUMN)))));
        self::assertFalse(login_is_throttled('target@example.com', '2001:db8:1:3::1'), 'The next /64 is another visitor');

        for ($i = LOGIN_MAX_ATTEMPTS_PER_EMAIL + 1; $i <= LOGIN_MAX_ATTEMPTS_PER_IP; $i++) {
            record_failed_login("victim-$i@example.com", '2001:db8:1:2:' . dechex($i) . '::1');
        }
        self::assertTrue(login_is_throttled('fresh@example.com', '2001:db8:1:2:abcd::1'), 'Spraying many accounts from one /64 is limited too');
        self::assertFalse(login_is_throttled('fresh@example.com', '198.51.100.20'));
    }

    public function testAnIpv4MappedAddressCountsAsItsIpv4Address(): void
    {
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS_PER_EMAIL; $i++) {
            record_failed_login('target@example.com', $i % 2 === 0 ? '198.51.100.30' : '::ffff:198.51.100.30');
        }

        self::assertTrue(login_is_throttled('target@example.com', '198.51.100.30'));
        self::assertTrue(login_is_throttled('target@example.com', '::ffff:198.51.100.30'));
    }

    public function testClearingFailuresUnlocksTheEmail(): void
    {
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS_PER_EMAIL; $i++) {
            record_failed_login('target@example.com', '198.51.100.1');
        }
        record_failed_login('someone-else@example.com', '198.51.100.1');
        self::assertTrue(login_is_throttled('target@example.com', '198.51.100.1'));

        clear_failed_logins('target@example.com');

        self::assertFalse(login_is_throttled('target@example.com', '198.51.100.1'));
        self::assertSame(1, $this->countRows('login_attempts'), 'Only that email is cleared');
    }

    public function testAttemptsOutsideTheLockoutWindowDoNotCount(): void
    {
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS_PER_EMAIL; $i++) {
            db_run(
                'INSERT INTO login_attempts (email, ip_address, attempted_at) VALUES (?, ?, NOW() - INTERVAL ? MINUTE)',
                ['target@example.com', '198.51.100.1', LOGIN_LOCKOUT_MINUTES + 1]
            );
        }

        self::assertFalse(login_is_throttled('target@example.com', '198.51.100.1'));
    }

    public function testRecordingPrunesAttemptsOlderThanADay(): void
    {
        db_run("INSERT INTO login_attempts (email, ip_address, attempted_at) VALUES ('old@example.com', '198.51.100.1', NOW() - INTERVAL 2 DAY)");

        record_failed_login('new@example.com', '198.51.100.1');

        self::assertSame(0, $this->countRows('login_attempts', 'email = ?', ['old@example.com']));
        self::assertSame(1, $this->countRows('login_attempts', 'email = ?', ['new@example.com']));
    }

    public function testThrottlingIsCaseInsensitiveForEmails(): void
    {
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS_PER_EMAIL; $i++) {
            record_failed_login('Target@Example.com', '198.51.100.9');
        }

        self::assertTrue(login_is_throttled('target@example.com', '198.51.100.9'));
    }

    public function testOverlongEmailIsTruncatedWhenRecorded(): void
    {
        record_failed_login(str_repeat('a', 300) . '@example.com', '198.51.100.1');

        self::assertSame(255, (int) db_value('SELECT CHAR_LENGTH(email) FROM login_attempts'));
    }
}
