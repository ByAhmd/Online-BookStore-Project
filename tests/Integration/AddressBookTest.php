<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\DatabaseTestCase;

/**
 * The customers' saved delivery addresses (src/addresses.php) against a real
 * database: one default per customer, at most five addresses, and every
 * change scoped to the customer's own addresses.
 */
final class AddressBookTest extends DatabaseTestCase
{
    /**
     * @return array<int, int> address id => is_default, in display order
     */
    private static function defaults(int $userId): array
    {
        $defaults = [];
        foreach (customer_addresses($userId) as $address) {
            $defaults[(int) $address['id']] = (int) $address['is_default'];
        }
        return $defaults;
    }

    public function testTheFirstAddressIsTheDefaultAndTheDefaultIsListedFirst(): void
    {
        $userId = (int) $this->createUser()['id'];
        self::assertSame([], customer_addresses($userId));
        self::assertNull(default_address_id($userId));

        $home = $this->createAddress($userId);
        $work = $this->createAddress($userId, ['recipient_name' => 'Sara at work', 'district' => 'Al Malqa']);
        self::assertSame([$home => 1, $work => 0], self::defaults($userId));
        self::assertSame($home, default_address_id($userId));

        $stored = find_user_address($userId, $home);
        self::assertNotNull($stored);
        self::assertSame(['+966512345678', '6789', 'RRRD2929'], [$stored['phone'], $stored['additional_number'], $stored['short_address']]);

        // A new default goes first; there is only ever one.
        $parents = $this->createAddress($userId, ['recipient_name' => 'Parents', 'additional_number' => '', 'short_address' => ''], true);
        self::assertSame([$parents => 1, $home => 0, $work => 0], self::defaults($userId));
        self::assertNull(find_user_address($userId, $parents)['additional_number'], 'Empty optional fields are stored as NULL');

        self::assertTrue(set_default_address($userId, $work));
        self::assertSame([$work => 1, $home => 0, $parents => 0], self::defaults($userId));
    }

    public function testAtMostFiveAddressesPerCustomer(): void
    {
        $userId = (int) $this->createUser()['id'];
        for ($i = 1; $i <= ADDRESS_MAX_PER_CUSTOMER; $i++) {
            $this->createAddress($userId, ['recipient_name' => 'Recipient ' . $i]);
        }
        [$address] = validate_address_input(self::SAMPLE_ADDRESS);

        self::assertSame(['status' => 'limit', 'id' => null], save_customer_address($userId, $address));
        self::assertSame(ADDRESS_MAX_PER_CUSTOMER, $this->countRows('addresses', 'user_id = ?', [$userId]));

        // Changing an existing address is still possible, and other customers are not affected.
        $first = (int) customer_addresses($userId)[0]['id'];
        self::assertSame('updated', save_customer_address($userId, ['city' => 'Jeddah'] + $address, $first)['status']);
        self::assertSame('Jeddah', find_user_address($userId, $first)['city']);
        self::assertSame('created', save_customer_address((int) $this->createUser()['id'], $address)['status']);
    }

    public function testEditingKeepsTheDefaultAndMayMakeTheAddressTheDefault(): void
    {
        $userId = (int) $this->createUser()['id'];
        $home = $this->createAddress($userId);
        $work = $this->createAddress($userId, ['recipient_name' => 'Work']);
        [$changed] = validate_address_input(['street' => 'Olaya Street', 'postal_code' => '٥٤٣٢١'] + self::SAMPLE_ADDRESS);

        self::assertSame(['status' => 'updated', 'id' => $home], save_customer_address($userId, $changed, $home));
        self::assertSame(['Olaya Street', '54321'], [find_user_address($userId, $home)['street'], find_user_address($userId, $home)['postal_code']]);
        self::assertSame([$home => 1, $work => 0], self::defaults($userId));

        self::assertSame(['status' => 'updated', 'id' => $work], save_customer_address($userId, $changed, $work, true));
        self::assertSame([$work => 1, $home => 0], self::defaults($userId));
    }

    public function testCustomersCanOnlyChangeTheirOwnAddresses(): void
    {
        $alice = (int) $this->createUser()['id'];
        $bob = (int) $this->createUser()['id'];
        $aliceAddress = $this->createAddress($alice);
        $bobAddress = $this->createAddress($bob);
        [$forged] = validate_address_input(['recipient_name' => 'Mallory'] + self::SAMPLE_ADDRESS);

        self::assertNull(find_user_address($bob, $aliceAddress));
        self::assertSame(['status' => 'missing', 'id' => null], save_customer_address($bob, $forged, $aliceAddress));
        self::assertFalse(set_default_address($bob, $aliceAddress));
        self::assertFalse(delete_customer_address($bob, $aliceAddress));
        self::assertSame('Sara Ahmed', find_user_address($alice, $aliceAddress)['recipient_name']);
        self::assertSame([$bobAddress => 1], self::defaults($bob));
        self::assertSame(['status' => 'missing', 'id' => null], save_customer_address(999999, $forged), 'No such customer');
    }

    public function testDeletingTheDefaultMakesTheOldestRemainingAddressTheDefault(): void
    {
        $userId = (int) $this->createUser()['id'];
        $first = $this->createAddress($userId);
        $second = $this->createAddress($userId, ['recipient_name' => 'Second']);
        $third = $this->createAddress($userId, ['recipient_name' => 'Third'], true);

        self::assertTrue(delete_customer_address($userId, $third));
        self::assertSame([$first => 1, $second => 0], self::defaults($userId));
        self::assertTrue(delete_customer_address($userId, $second));
        self::assertSame([$first => 1], self::defaults($userId));
        self::assertTrue(delete_customer_address($userId, $first));
        self::assertSame([], customer_addresses($userId));
        self::assertFalse(delete_customer_address($userId, $first), 'Already deleted');
    }

    public function testAddressesAreDeletedWithTheAccount(): void
    {
        $user = $this->createUser();
        $this->createAddress((int) $user['id']);
        $this->createAddress((int) $user['id'], ['recipient_name' => 'Second']);

        self::assertSame('deleted', delete_customer_account((int) $user['id'], self::DEFAULT_PASSWORD, '203.0.113.9')['status']);
        self::assertSame(0, $this->countRows('addresses'));
    }
}
