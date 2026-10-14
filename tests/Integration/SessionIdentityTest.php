<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\DatabaseTestCase;

/**
 * current_user() / current_admin() read the session array directly, so they
 * are tested with a plain $_SESSION (no session is started). Both cache rows
 * per request in static variables, hence the unique emails and refreshes.
 */
final class SessionIdentityTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testCurrentUserLoadsTheSignedInCustomer(): void
    {
        $user = $this->createUser(name: 'Signed In');
        $_SESSION = ['user_id' => (int) $user['id'], 'role' => 'user', 'session_version' => 0];

        $current = current_user(true);

        self::assertSame($user, $current);
        self::assertArrayNotHasKey('pass', $current);
        self::assertArrayNotHasKey('session_version', $current);
        self::assertFalse(is_admin());
    }

    public function testSessionWithoutTheAccountsSessionVersionIsRejected(): void
    {
        $user = $this->createUser();

        $_SESSION = ['user_id' => (int) $user['id'], 'role' => 'user'];
        self::assertNull(current_user(true), 'Sessions must carry the version they signed in with');
    }

    public function testPasswordResetSignsOutExistingSessions(): void
    {
        $user = $this->createUser();
        $_SESSION = ['user_id' => (int) $user['id'], 'role' => 'user', 'session_version' => 0];
        self::assertNotNull(current_user(true));

        $raw = bin2hex(random_bytes(32));
        db_run(
            'INSERT INTO reset_tokens (email, token, expiry) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)',
            [$user['Eaddress'], hash('sha256', $raw)]
        );
        self::assertTrue(complete_password_reset($raw, 'a brand new password'));

        self::assertNull(current_user(true), 'The old session no longer authenticates');
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testCurrentUserRequiresAnIntegerId(): void
    {
        $user = $this->createUser();

        $_SESSION = ['user_id' => (string) $user['id']];
        self::assertNull(current_user(true));

        $_SESSION = [];
        self::assertNull(current_user(true));
    }

    public function testDeletedAccountSignsTheCustomerOut(): void
    {
        $user = $this->createUser();
        $_SESSION = ['user_id' => (int) $user['id'], 'role' => 'user', 'other' => 'kept'];
        db_run('DELETE FROM users WHERE id = ?', [$user['id']]);

        self::assertNull(current_user(true));
        self::assertSame(['other' => 'kept'], $_SESSION);
    }

    public function testCurrentAdminLoadsTheSignedInAdministrator(): void
    {
        $admin = $this->createAdmin(name: 'Boss');
        $_SESSION = ['admin_email' => $admin['Eaddress'], 'role' => 'admin', 'session_version' => 0];

        self::assertSame(['Eaddress' => $admin['Eaddress'], 'name' => 'Boss', 'role' => ADMIN_ROLE_OWNER], current_admin());
        self::assertTrue(is_admin());
        self::assertNull(current_user(true), 'An admin session is not a customer session');
    }

    public function testUnknownAdminSessionIsCleared(): void
    {
        $_SESSION = ['admin_email' => self::uniqueEmail('ghost-admin'), 'role' => 'admin'];

        self::assertNull(current_admin());
        self::assertFalse(is_admin());
        self::assertSame([], $_SESSION);
    }

    public function testNonStringAdminSessionIsIgnored(): void
    {
        $_SESSION = ['admin_email' => ['x']];

        self::assertNull(current_admin());
    }

    public function testCustomerEmailIsNotAnAdminSession(): void
    {
        $user = $this->createUser();
        $_SESSION = ['admin_email' => $user['Eaddress']];

        self::assertNull(current_admin());
    }
}
