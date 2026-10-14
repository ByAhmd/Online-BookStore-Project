<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Docker\RunsProjectScripts;
use BookWise\Tests\Support\DatabaseTestCase;

/**
 * Administrator roles: owners may change everything, viewers nothing.
 * bin/create-admin.php makes either.
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

    public function testCreateAdminMakesOwnersOrWithViewerReadOnlyAdministrators(): void
    {
        $env = ['BOOKWISE_ADMIN_PASSWORD' => self::PASSWORD];

        $viewer = self::runProjectScript('bin/create-admin.php', $env, ['--viewer', 'Reviewer@Example.com', 'Book Reviewer']);
        self::assertSame(0, $viewer['status'], $viewer['stdout'] . $viewer['stderr']);
        self::assertSame("Created read-only administrator reviewer@example.com.\n", str_replace("\r\n", "\n", $viewer['stdout']));
        self::assertSame(ADMIN_ROLE_VIEWER, self::role('reviewer@example.com'));
        self::assertSame('Book Reviewer', db_value('SELECT name FROM admins WHERE Eaddress = ?', ['reviewer@example.com']));
        self::assertSame('admin', attempt_login('reviewer@example.com', self::PASSWORD)['type'] ?? null);
        self::assertStringNotContainsString(self::PASSWORD, $viewer['stdout'] . $viewer['stderr']);

        // Running it again sets the role given: an owner unless --viewer.
        $owner = self::runProjectScript('bin/create-admin.php', $env, ['reviewer@example.com', 'Book Reviewer']);
        self::assertSame(0, $owner['status'], $owner['stdout'] . $owner['stderr']);
        self::assertStringStartsWith('Updated administrator reviewer@example.com (', $owner['stdout']);
        self::assertSame(ADMIN_ROLE_OWNER, self::role('reviewer@example.com'));

        $again = self::runProjectScript('bin/create-admin.php', $env, ['reviewer@example.com', 'Book Reviewer', '--viewer']);
        self::assertStringStartsWith('Updated read-only administrator reviewer@example.com (', $again['stdout']);
        self::assertSame(ADMIN_ROLE_VIEWER, self::role('reviewer@example.com'));

        $unknown = self::runProjectScript('bin/create-admin.php', $env, ['other@example.com', '--read-only']);
        self::assertSame(1, $unknown['status']);
        self::assertStringContainsString('Unknown option: --read-only', $unknown['stderr']);
        self::assertStringContainsString('[--viewer]', $unknown['stderr']);
        self::assertNull(self::role('other@example.com'));
    }
}
