<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Docker\RunsProjectScripts;
use BookWise\Tests\Support\DatabaseTestCase;

/**
 * Administrator roles: owners may change everything, viewers nothing.
 * bin/create-admin.php makes either, and the demo's shared administrator is
 * always a viewer whose password nobody knows.
 */
final class AdminRolesTest extends DatabaseTestCase
{
    use RunsProjectScripts;

    private const PASSWORD = 'admin-password-123';

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

    private static function role(string $email): mixed
    {
        return db_value('SELECT role FROM admins WHERE Eaddress = ?', [$email]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function signInAs(array $row): void
    {
        $_SESSION = ['admin_email' => $row['Eaddress'], 'role' => 'admin', 'session_version' => 0];
    }

    public function testOnlyOwnersMayChangeThingsOrSeeCustomersInFull(): void
    {
        $owner = $this->createAdmin();
        self::assertSame(ADMIN_ROLE_OWNER, self::role($owner['Eaddress']), 'Administrators are owners unless made viewers');
        self::signInAs($owner);
        self::assertTrue(admin_can_write());
        self::assertTrue(admin_sees_customer_details());

        $viewer = $this->createAdmin();
        db_run('UPDATE admins SET role = ? WHERE Eaddress = ?', [ADMIN_ROLE_VIEWER, $viewer['Eaddress']]);
        self::signInAs($viewer);
        self::assertSame(ADMIN_ROLE_VIEWER, current_admin()['role'] ?? null);
        self::assertTrue(is_admin(), 'A viewer may open the admin pages');
        self::assertFalse(admin_can_write());
        self::assertFalse(admin_sees_customer_details());

        $_SESSION = ['user_id' => (int) $this->createUser()['id'], 'role' => 'user', 'session_version' => 0];
        self::assertFalse(admin_can_write(), 'Customers never');
        $_SESSION = [];
        self::assertFalse(admin_can_write(), 'Guests never');
    }

    public function testTheDemoAdministratorIsAViewerThatNobodyCanSignInTo(): void
    {
        $admin = demo_viewer_admin();
        self::assertSame(['Eaddress' => DEMO_VIEWER_ADMIN_EMAIL, 'name' => 'Demo administrator', 'role' => ADMIN_ROLE_VIEWER], $admin);
        $hash = (string) db_value('SELECT password FROM admins WHERE Eaddress = ?', [DEMO_VIEWER_ADMIN_EMAIL]);
        self::assertSame('bcrypt', password_get_info($hash)['algoName'], 'A random password that nobody knows');
        self::assertNull(attempt_login(DEMO_VIEWER_ADMIN_EMAIL, ''));

        self::assertSame($admin, demo_viewer_admin(), 'Created once and shared');
        self::assertSame(1, $this->countRows('admins'));
        self::assertSame($hash, db_value('SELECT password FROM admins WHERE Eaddress = ?', [DEMO_VIEWER_ADMIN_EMAIL]));

        // Even if someone made it an owner, the demo button signs in to a viewer.
        db_run('UPDATE admins SET role = ? WHERE Eaddress = ?', [ADMIN_ROLE_OWNER, DEMO_VIEWER_ADMIN_EMAIL]);
        self::assertSame(ADMIN_ROLE_VIEWER, demo_viewer_admin()['role']);
        self::assertSame(ADMIN_ROLE_VIEWER, self::role(DEMO_VIEWER_ADMIN_EMAIL));
    }

    public function testCreateAdminMakesOwnersOrWithViewerReadOnlyAdministratorsAndKeepsTheRoleOnAReset(): void
    {
        $env = ['BOOKWISE_ADMIN_PASSWORD' => self::PASSWORD];

        $viewer = self::runProjectScript('bin/create-admin.php', $env, ['--viewer', 'Reviewer@Example.com', 'Book Reviewer']);
        self::assertSame(0, $viewer['status'], $viewer['stdout'] . $viewer['stderr']);
        self::assertSame("Created read-only administrator reviewer@example.com.\n", str_replace("\r\n", "\n", $viewer['stdout']));
        self::assertSame(ADMIN_ROLE_VIEWER, self::role('reviewer@example.com'));
        self::assertSame('Book Reviewer', db_value('SELECT name FROM admins WHERE Eaddress = ?', ['reviewer@example.com']));
        self::assertSame('admin', attempt_login('reviewer@example.com', self::PASSWORD)['type'] ?? null);
        self::assertStringNotContainsString(self::PASSWORD, $viewer['stdout'] . $viewer['stderr']);

        // Resetting the password the documented way keeps the role: a viewer never becomes an owner by accident.
        $reset = self::runProjectScript('bin/create-admin.php', ['BOOKWISE_ADMIN_PASSWORD' => 'new-admin-password-456'], ['reviewer@example.com', 'Book Reviewer']);
        self::assertSame(0, $reset['status'], $reset['stdout'] . $reset['stderr']);
        self::assertSame("Updated read-only administrator reviewer@example.com (other sessions signed out, sign-in lockout cleared).\n", str_replace("\r\n", "\n", $reset['stdout']));
        self::assertSame(ADMIN_ROLE_VIEWER, self::role('reviewer@example.com'));
        self::assertSame('admin', attempt_login('reviewer@example.com', 'new-admin-password-456')['type'] ?? null);

        // Only an explicit option changes it, and the change is reported.
        $owner = self::runProjectScript('bin/create-admin.php', $env, ['reviewer@example.com', 'Book Reviewer', '--owner']);
        self::assertSame(0, $owner['status'], $owner['stdout'] . $owner['stderr']);
        self::assertSame("Updated administrator reviewer@example.com (role changed from viewer to owner; other sessions signed out, sign-in lockout cleared).\n", str_replace("\r\n", "\n", $owner['stdout']));
        self::assertSame(ADMIN_ROLE_OWNER, self::role('reviewer@example.com'));

        $kept = self::runProjectScript('bin/create-admin.php', $env, ['reviewer@example.com', 'Book Reviewer']);
        self::assertStringStartsWith('Updated administrator reviewer@example.com (other sessions', $kept['stdout']);
        self::assertSame(ADMIN_ROLE_OWNER, self::role('reviewer@example.com'), 'An owner stays an owner too');

        $again = self::runProjectScript('bin/create-admin.php', $env, ['reviewer@example.com', 'Book Reviewer', '--viewer']);
        self::assertStringStartsWith('Updated read-only administrator reviewer@example.com (role changed from owner to viewer; ', $again['stdout']);
        self::assertSame(ADMIN_ROLE_VIEWER, self::role('reviewer@example.com'));

        $created = self::runProjectScript('bin/create-admin.php', $env, ['--owner', 'owner@example.com']);
        self::assertSame("Created administrator owner@example.com.\n", str_replace("\r\n", "\n", $created['stdout']));
        self::assertSame(ADMIN_ROLE_OWNER, self::role('owner@example.com'));

        $both = self::runProjectScript('bin/create-admin.php', $env, ['other@example.com', '--viewer', '--owner']);
        self::assertSame(1, $both['status']);
        self::assertStringContainsString('Give either --viewer or --owner, not both.', $both['stderr']);

        $unknown = self::runProjectScript('bin/create-admin.php', $env, ['other@example.com', '--read-only']);
        self::assertSame(1, $unknown['status']);
        self::assertStringContainsString('Unknown option: --read-only', $unknown['stderr']);
        self::assertStringContainsString('[--viewer | --owner]', $unknown['stderr']);
        self::assertNull(self::role('other@example.com'));
    }
}
