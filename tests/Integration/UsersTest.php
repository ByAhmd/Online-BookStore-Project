<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\DatabaseTestCase;

final class UsersTest extends DatabaseTestCase
{
    public function testRegisterUserStoresAHashedPasswordAndDefaults(): void
    {
        $id = register_user('new-reader@example.com', 'New Reader', 'a-secret-pass');

        self::assertIsInt($id);
        self::assertSame([
            'id' => $id,
            'Eaddress' => 'new-reader@example.com',
            'name' => 'New Reader',
            'favorite_genre' => 'Unknown',
            'date_of_birth' => null,
            'payment_method' => null,
            'profile_image' => 'imges/profileImage2.png',
        ], find_user($id));

        $hash = (string) db_value('SELECT pass FROM users WHERE id = ?', [$id]);
        self::assertNotSame('a-secret-pass', $hash);
        self::assertStringStartsWith('$2y$', $hash);
        self::assertTrue(password_verify('a-secret-pass', $hash));
    }

    public function testDuplicateEmailIsRejected(): void
    {
        self::assertIsInt(register_user('dup@example.com', 'First', 'a-secret-pass'));

        self::assertNull(register_user('dup@example.com', 'Second', 'another-pass'));
        self::assertSame(1, $this->countRows('users'));
    }

    public function testDuplicateEmailCheckIsCaseInsensitive(): void
    {
        register_user('Mixed.Case@Example.com', 'First', 'a-secret-pass');

        self::assertTrue(user_email_exists('mixed.case@example.com'));
        self::assertNull(register_user('mixed.case@example.com', 'Second', 'a-secret-pass'));
        self::assertNull(register_user('MIXED.CASE@EXAMPLE.COM', 'Third', 'a-secret-pass'));
        self::assertSame(1, $this->countRows('users'));
    }

    public function testAdminEmailCannotBeRegisteredAsCustomer(): void
    {
        $admin = $this->createAdmin('boss@example.com');

        self::assertTrue(user_email_exists($admin['Eaddress']));
        self::assertNull(register_user('boss@example.com', 'Impostor', 'a-secret-pass'));
        self::assertSame(0, $this->countRows('users'));
    }

    public function testUnknownEmailDoesNotExist(): void
    {
        self::assertFalse(user_email_exists('nobody@example.com'));
        self::assertNull(find_user(999999));
    }

    public function testUpdateProfileKeepsOrReplacesThePicture(): void
    {
        $user = $this->createUser();
        $id = (int) $user['id'];

        update_user_profile($id, 'Renamed', 'Mystery', '1990-05-17');
        $row = find_user($id);
        self::assertSame(['Renamed', 'Mystery', '1990-05-17', 'imges/profileImage2.png'], [$row['name'], $row['favorite_genre'], $row['date_of_birth'], $row['profile_image']]);

        update_user_profile($id, 'Renamed', 'Science', null, 'uploads/avatars/' . str_repeat('a', 32) . '.png');
        $row = find_user($id);
        self::assertSame(['Science', null, 'uploads/avatars/' . str_repeat('a', 32) . '.png'], [$row['favorite_genre'], $row['date_of_birth'], $row['profile_image']]);
    }

    public function testUpdateProfileOnlyTouchesTheGivenUser(): void
    {
        $alice = $this->createUser(name: 'Alice');
        $bob = $this->createUser(name: 'Bob');

        update_user_profile((int) $alice['id'], 'Alice Updated', 'Drama', null);

        self::assertSame('Bob', find_user((int) $bob['id'])['name']);
    }

    public function testPaymentPreferenceMustBeAKnownLabel(): void
    {
        $id = (int) $this->createUser()['id'];

        self::assertTrue(update_payment_preference($id, 'Mada'));
        self::assertSame('Mada', find_user($id)['payment_method']);

        self::assertFalse(update_payment_preference($id, '4111 1111 1111 1111'));
        self::assertFalse(update_payment_preference($id, 'mada'), 'Labels are matched exactly');
        self::assertSame('Mada', find_user($id)['payment_method'], 'Rejected values change nothing');

        self::assertTrue(update_payment_preference($id, null));
        self::assertNull(find_user($id)['payment_method']);
    }
}
