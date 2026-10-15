<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;
use BookWise\Tests\Support\StockFixtures;

/**
 * The checkout page (checkout.php) between the cart and the payment: the
 * delivery address (a saved one or a new one), the VAT-inclusive totals,
 * and the address book on the profile page.
 */
final class CheckoutDeliveryTest extends HttpTestCase
{
    use StockFixtures;

    private const BIOLOGY = 7;      // 5.50
    private const ESABELLA = 51;    // 19.99

    /**
     * Fills the cart and opens the checkout page.
     *
     * @param array<int, int> $quantities
     */
    private function checkoutPage(HttpClient $client, array $quantities = [self::ESABELLA => 1]): HttpResponse
    {
        foreach ($quantities as $bookId => $quantity) {
            $this->assertRedirect($this->addToCart($client, $bookId, $quantity), 'cart.php');
        }
        $page = $client->get('checkout.php');
        $this->assertHtmlPage($page);
        return $page;
    }

    private static function sessionFrom(HttpResponse $response): string
    {
        $location = (string) $response->header('Location');
        self::assertMatchesRegularExpression('#/pay/cs_test_[A-Za-z0-9]+$#', $location, 'Checkout redirects to the (mock) Stripe payment page');
        return substr($location, strrpos($location, '/') + 1);
    }

    /**
     * @return list<string>
     */
    private static function radioValues(HtmlDocument $document): array
    {
        return array_map(
            static fn (\DOMElement $input): string => $input->getAttribute('value'),
            $document->elements('//form[@action="checkout.php"]//input[@type="radio"][@name="address"]')
        );
    }

    public function testANewCustomerEntersTheDeliveryAddressOnTheCheckoutPage(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];
        $this->assertRedirect($this->addToCart($customer, self::BIOLOGY, 1), 'cart.php');
        $this->assertPageText('Prices include 15% VAT. Delivery within Saudi Arabia is free.', $customer->get('cart.php'));

        $page = $this->checkoutPage($customer, [self::ESABELLA => 2]);
        $document = $page->document();
        self::assertSame('no-store', $page->header('Cache-Control'));
        self::assertSame('Checkout', $document->text('//h1'));
        self::assertSame(['Cart', 'Delivery and review', 'Payment'], $document->texts('//ol[' . HtmlDocument::hasClass('checkout-steps') . ']/li'));
        self::assertSame('Delivery and review', $document->text('//ol/li[@aria-current="step"]'));
        self::assertSame('cart.php', $document->first('//ol[' . HtmlDocument::hasClass('checkout-steps') . ']//a')?->getAttribute('href'));

        // Without saved addresses the page asks for one.
        self::assertSame('new', $document->form('checkout.php')->field('address'));
        self::assertSame(
            ['Recipient full name', 'Mobile number', 'City', 'District', 'Street', 'Building number', 'Postal code', 'Additional number (optional)', 'Short address (optional)'],
            $document->texts('//div[' . HtmlDocument::hasClass('address-fields') . ']//label')
        );
        foreach (['recipient_name', 'phone', 'city', 'district', 'street', 'building_number', 'postal_code'] as $field) {
            self::assertTrue($document->first('//input[@id="new-address-' . $field . '"]')?->hasAttribute('required'), "$field is required");
        }
        self::assertFalse($document->first('//input[@id="new-address-short_address"]')?->hasAttribute('required'));
        self::assertSame('new-address-phone-hint', $document->first('//input[@id="new-address-phone"]')?->getAttribute('aria-describedby'));
        self::assertContains('Riyadh', array_map(static fn (\DOMElement $option): string => $option->getAttribute('value'), $document->elements('//datalist[@id="new-address-cities"]/option')));

        // The books and the totals: prices include VAT, delivery is free.
        self::assertSame(['Biology Quantity: 1 · SAR 5.50 each SAR 5.50', 'Esabella Quantity: 2 · SAR 19.99 each SAR 39.98'], $document->texts('//ul[' . HtmlDocument::hasClass('checkout-items') . ']/li'));
        self::assertSame(
            ['Items 3', 'Delivery Free', 'Total (VAT included) SAR 45.48', 'Includes VAT (15%) SAR 5.93'],
            $document->texts('//aside//dl[' . HtmlDocument::hasClass('summary-list') . ']/div')
        );
        self::assertSame('Continue to payment', $document->text('//button[@type="submit"]//span[' . HtmlDocument::hasClass('label-idle') . ']'));

        // Mistakes are shown next to their fields, and no payment starts.
        $refused = $customer->submitForm($page, 'checkout.php', ['phone' => '0112345678', 'building_number' => '12', 'recipient_name' => ''] + self::SAMPLE_ADDRESS);
        $this->assertRedirect($refused, 'checkout.php');
        $again = $customer->follow($refused);
        $document = $this->assertHtmlPage($again);
        $this->assertFlash($again, 'error', 'Please correct the delivery address. Your payment was not started.');
        self::assertSame("Please enter the recipient's full name.", $document->fieldError('new-address-recipient_name-error'));
        self::assertSame('Please enter a Saudi mobile number, such as 05XXXXXXXX.', $document->fieldError('new-address-phone-error'));
        self::assertSame('The building number has 4 digits.', $document->fieldError('new-address-building_number-error'));
        $phone = $document->first('//input[@id="new-address-phone"]');
        self::assertSame(['true', 'new-address-phone-hint new-address-phone-error'], [$phone?->getAttribute('aria-invalid'), $phone?->getAttribute('aria-describedby')]);
        self::assertSame('Al Olaya', $document->first('//input[@id="new-address-district"]')?->getAttribute('value'), 'What was typed is kept');
        self::assertSame(0, $this->countRows('orders'));
        self::assertSame(0, $this->countRows('addresses'));
        self::assertSame([], $this->stripe->sessions());

        // A valid address is saved in the address book and copied into the order.
        $response = $customer->submitForm($again, 'checkout.php', ['phone' => '05 1234 5678'] + self::SAMPLE_ADDRESS);
        $this->assertStatus(303, $response);
        $order = $this->orderBySession(self::sessionFrom($response));
        self::assertSame('pending', $order['status']);
        self::assertSame(2 * 1999 + 550, (int) $order['amount_minor']);
        $saved = customer_addresses($userId);
        self::assertCount(1, $saved);
        self::assertSame(['Sara Ahmed', '+966512345678', 1], [$saved[0]['recipient_name'], $saved[0]['phone'], (int) $saved[0]['is_default']]);
        $copy = order_address((int) $order['id']);
        self::assertSame(['Sara Ahmed', '+966512345678', 'Riyadh', 'RRRD2929'], [$copy['recipient_name'] ?? null, $copy['phone'] ?? null, $copy['city'] ?? null, $copy['short_address'] ?? null]);
    }

    public function testSavedAddressesAreOfferedWithTheDefaultChosen(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];
        $home = $this->createAddress($userId);
        $office = $this->createAddress($userId, ['recipient_name' => 'Office Desk', 'city' => 'Jeddah', 'district' => 'Al Rawdah']);

        $document = $this->checkoutPage($customer)->document();
        self::assertSame([(string) $home, (string) $office, 'new'], self::radioValues($document));
        self::assertSame((string) $home, $document->form('checkout.php')->field('address'), 'The default address is chosen');
        self::assertStringContainsString('Sara Ahmed 1234 King Fahd Road Al Olaya Riyadh 12345-6789 Short address: RRRD2929 +966 51 234 5678 Default', $document->text('//label[@for="address-' . $home . '"]'));
        self::assertStringContainsString('Office Desk', $document->text('//label[@for="address-' . $office . '"]'));
        self::assertSame('Deliver to a new address', $document->text('//label[@for="address-new"]'));
        self::assertFalse($document->first('//input[@id="new-address-recipient_name"]')?->hasAttribute('required'), 'The new address is only needed when chosen');
        self::assertSame('profilePage.php?section=addresses', $document->first('//a[normalize-space()="Manage your saved addresses"]')?->getAttribute('href'));

        // The customer picks the other address.
        $response = $this->submitCheckout($customer, ['address' => (string) $office]);
        $this->assertStatus(303, $response);
        $first = $this->orderBySession(self::sessionFrom($response));
        self::assertSame(['Office Desk', 'Jeddah'], [order_address((int) $first['id'])['recipient_name'] ?? null, order_address((int) $first['id'])['city'] ?? null]);

        // Another customer's address is refused, and no payment starts.
        $foreign = $this->createAddress((int) $this->createUser()['id']);
        $sessions = count($this->stripe->sessions());
        $refused = $customer->postWithToken('checkout.php', ['address' => (string) $foreign]);
        $this->assertRedirect($refused, 'checkout.php');
        $this->assertFlash($customer->follow($refused), 'error', 'Please choose where we should deliver your books.');
        self::assertCount($sessions, $this->stripe->sessions());
        self::assertSame(1, $this->countRows('orders', 'user_id = ?', [$userId]), 'Only the first checkout created an order');

        // A new address from the list of choices, made the default.
        $response = $this->submitCheckout($customer, ['address' => 'new', 'make_default' => '1', 'recipient_name' => 'New Home'] + self::SAMPLE_ADDRESS);
        $this->assertStatus(303, $response);
        self::assertSame(['New Home', 'Sara Ahmed', 'Office Desk'], array_column(customer_addresses($userId), 'recipient_name'));
        self::assertSame(1, (int) customer_addresses($userId)[0]['is_default']);
    }

    public function testTheChosenAddressIsOfferedAgainWhenThePaymentDoesNotGoAhead(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];
        $home = $this->createAddress($userId);
        $page = $this->checkoutPage($customer);

        // A new address is typed in, but the book sold out meanwhile: the payment does not start.
        $office = ['recipient_name' => 'Office Person', 'city' => 'Jeddah'] + self::SAMPLE_ADDRESS;
        $this->setStock(self::ESABELLA, 0);
        $refused = $customer->submitForm($page, 'checkout.php', ['address' => 'new'] + $office);
        $this->assertRedirect($refused, 'cart.php');
        self::assertSame(0, $this->countRows('orders'));
        $saved = customer_addresses($userId);
        self::assertSame(['Sara Ahmed', 'Office Person'], array_column($saved, 'recipient_name'), 'The new address is in the address book');
        $officeId = (int) $saved[1]['id'];

        // Back at checkout, the address just typed is chosen, not the default one.
        $this->setStock(self::ESABELLA, 5);
        $page = $customer->get('checkout.php');
        self::assertSame((string) $officeId, $this->assertHtmlPage($page)->form('checkout.php')->field('address'));

        // Typing it again (or sending the form twice) uses the saved address instead of a copy.
        $response = $customer->submitForm($page, 'checkout.php', ['address' => 'new'] + $office);
        $this->assertStatus(303, $response);
        $order = $this->orderBySession(self::sessionFrom($response));
        self::assertSame('Office Person', order_address((int) $order['id'])['recipient_name'] ?? null);
        $again = $customer->postWithToken('checkout.php', ['address' => 'new'] + $office);
        $this->assertStatus(303, $again);
        self::assertSame([$home, $officeId], array_map('intval', array_column(customer_addresses($userId), 'id')));

        // A customer who leaves the payment page and comes back finds the same address chosen.
        self::assertSame((string) $officeId, $customer->get('checkout.php')->document()->form('checkout.php')->field('address'));
        // Once it is deleted (or for any id that is not one of the customer's), the default is chosen again.
        self::assertTrue(delete_customer_address($userId, $officeId));
        self::assertSame((string) $home, $customer->get('checkout.php')->document()->form('checkout.php')->field('address'));
        $foreign = $this->createAddress((int) $this->createUser()['id']);
        $this->assertRedirect($customer->postWithToken('checkout.php', ['address' => (string) $foreign]), 'checkout.php');
        self::assertSame((string) $home, $customer->get('checkout.php')->document()->form('checkout.php')->field('address'));
    }

    public function testAFullAddressBookOffersOnlyTheSavedAddresses(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];
        for ($i = 1; $i <= ADDRESS_MAX_PER_CUSTOMER; $i++) {
            $this->createAddress($userId, ['recipient_name' => 'Recipient ' . $i]);
        }

        $page = $this->checkoutPage($customer);
        self::assertNotContains('new', self::radioValues($page->document()));
        $this->assertPageText('You have saved 5 addresses, the most allowed. Delete one in your account to add another.', $page);

        $forged = $customer->postWithToken('checkout.php', ['address' => 'new'] + self::SAMPLE_ADDRESS);
        $this->assertRedirect($forged, 'checkout.php');
        $this->assertFlash($customer->follow($forged), 'error', 'You have saved 5 addresses, the most allowed. Delete one in your account to add another.');
        self::assertSame(ADDRESS_MAX_PER_CUSTOMER, $this->countRows('addresses', 'user_id = ?', [$userId]));
        self::assertSame(0, $this->countRows('orders'));
    }

    public function testTheCheckoutPageNeedsACustomerAndAFilledCart(): void
    {
        $guest = $this->client();
        $this->assertRedirect($guest->get('checkout.php'), 'index.php?form=login');
        $this->assertRedirect($guest->postWithToken('checkout.php', ['address' => 'new'] + self::SAMPLE_ADDRESS), 'index.php?form=login');
        self::assertSame(0, $this->countRows('addresses'));

        // A guest who logs in comes back to the checkout page.
        $user = $this->createUser();
        $this->assertRedirect($guest->get('checkout.php'), 'index.php?form=login');
        $this->assertRedirect($this->logIn($guest, (string) $user['Eaddress'], self::DEFAULT_PASSWORD), 'checkout.php');

        [$customer] = $this->signedInCustomer();
        $this->assertRedirect($customer->get('checkout.php'), 'cart.php', 303, 'Empty cart');
        $this->assertStatus(419, $customer->post('checkout.php', ['address' => 'new'] + self::SAMPLE_ADDRESS));
        self::assertSame(0, $this->countRows('addresses'));
    }

    /* -----------------------------------------------------------------
     * The address book on the profile page
     * --------------------------------------------------------------- */

    public function testTheAddressBookOnTheProfilePage(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];

        $page = $customer->get('profilePage.php?section=addresses');
        $document = $this->assertHtmlPage($page);
        self::assertFalse($document->first('//section[@id="section-addresses"]')?->hasAttribute('hidden'));
        self::assertSame('Delivery Addresses', $document->text('//section[@id="section-addresses"]/h2'));
        self::assertSame('page', $document->first('//a[@data-section="addresses"]')?->getAttribute('aria-current'));
        $this->assertPageText('We deliver within Saudi Arabia, free of charge. You can save up to 5 addresses', $page);
        $this->assertPageText('You have not saved a delivery address yet.', $page);
        self::assertSame('Add an address', $document->text('//h3[@id="address-form-title"]'));
        self::assertSame(0, $document->count('//input[@name="make_default"]'), 'The first address is always the default');

        // Mistakes are explained next to the fields. The page opens without a
        // #fragment, which would take the focus off the first rejected field.
        $refused = $customer->submitForm($page, 'profilePage.php', ['postal_code' => '1234', 'short_address' => 'RR29'] + self::SAMPLE_ADDRESS, ['action' => 'save_address']);
        $this->assertRedirect($refused, 'profilePage.php?section=addresses');
        $page = $customer->follow($refused);
        $document = $this->assertHtmlPage($page);
        $this->assertFlash($page, 'error', 'Please correct the highlighted fields. The address was not saved.');
        self::assertSame('The postal code has 5 digits.', $document->fieldError('address-postal_code-error'));
        self::assertSame('The short address has 4 letters and 4 digits, such as RRRD2929.', $document->fieldError('address-short_address-error'));
        self::assertSame('true', $document->first('//input[@id="address-postal_code"]')?->getAttribute('aria-invalid'));
        self::assertSame('Sara Ahmed', $document->first('//input[@id="address-recipient_name"]')?->getAttribute('value'));
        self::assertSame(0, $this->countRows('addresses'));

        // Saved: the first address is the default.
        $saved = $customer->submitForm($page, 'profilePage.php', self::SAMPLE_ADDRESS, ['action' => 'save_address']);
        $this->assertRedirect($saved, 'profilePage.php?section=addresses');
        $page = $customer->follow($saved);
        $this->assertFlash($page, 'success', 'Your address has been saved.');
        $cards = $page->document()->texts('//ul[' . HtmlDocument::hasClass('address-list') . ']/li');
        self::assertCount(1, $cards);
        self::assertStringStartsWith('Sara Ahmed 1234 King Fahd Road Al Olaya Riyadh 12345-6789 Short address: RRRD2929 +966 51 234 5678 Default Edit', $cards[0]);
        $home = (int) customer_addresses($userId)[0]['id'];

        // The same address is not added a second time.
        $duplicate = $customer->submitForm($page, 'profilePage.php', ['phone' => '+966 51 234 5678'] + self::SAMPLE_ADDRESS, ['action' => 'save_address']);
        $this->assertRedirect($duplicate, 'profilePage.php?section=addresses');
        $this->assertFlash($customer->follow($duplicate), 'info', 'This address is already in your address book, so it was not added again.');
        self::assertSame([$home], array_map('intval', array_column(customer_addresses($userId), 'id')));

        // A second address, then made the default from the list.
        $second = $customer->submitForm($page, 'profilePage.php', ['recipient_name' => 'Noura Ali', 'city' => 'Dammam'] + self::SAMPLE_ADDRESS, ['action' => 'save_address']);
        $page = $customer->follow($second);
        $office = (int) customer_addresses($userId)[1]['id'];
        self::assertSame('Edit address of Noura Ali', $page->document()->text('//a[contains(@href, "edit=' . $office . '")]'));
        $made = $customer->submitForm($page, 'profilePage.php', [], ['action' => 'default_address', 'address_id' => (string) $office]);
        $this->assertRedirect($made, 'profilePage.php?section=addresses');
        $this->assertFlash($customer->follow($made), 'success', 'Your default address has been changed.');
        self::assertSame([$office, $home], array_map('intval', array_column(customer_addresses($userId), 'id')));

        // Editing fills the form with the address.
        $edit = $customer->get('profilePage.php?section=addresses&edit=' . $home);
        $document = $this->assertHtmlPage($edit);
        self::assertSame('Edit address', $document->text('//h3[@id="address-form-title"]'));
        self::assertSame('+966512345678', $document->first('//input[@id="address-phone"]')?->getAttribute('value'));
        $updated = $customer->submitForm($edit, 'profilePage.php', ['city' => 'Khobar'], ['action' => 'save_address']);
        $this->assertRedirect($updated, 'profilePage.php?section=addresses');
        $this->assertFlash($customer->follow($updated), 'success', 'Your address has been updated.');
        self::assertSame('Khobar', find_user_address($userId, $home)['city'] ?? null);

        // Deleting the default makes the remaining address the default.
        $deleted = $customer->submitForm($customer->get('profilePage.php?section=addresses'), 'profilePage.php', [], ['action' => 'delete_address', 'address_id' => (string) $office]);
        $this->assertFlash($customer->follow($deleted), 'success', 'The address has been deleted.');
        self::assertSame([$home => 1], array_map('intval', array_column(customer_addresses($userId), 'is_default', 'id')));
        self::assertSame(
            'Delete this address? Orders you have already placed keep their delivery address.',
            $customer->get('profilePage.php?section=addresses')->document()->first('//form[.//input[@value="delete_address"]]')?->getAttribute('data-confirm')
        );
    }

    public function testCustomersOnlySeeAndChangeTheirOwnAddresses(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $mine = $this->createAddress((int) $user['id']);
        $theirs = $this->createAddress((int) $this->createUser()['id'], ['recipient_name' => 'Hidden Neighbour']);

        $page = $customer->get('profilePage.php?section=addresses&edit=' . $theirs);
        $document = $this->assertHtmlPage($page);
        $this->assertNotPageText('Hidden Neighbour', $page);
        self::assertSame('Add an address', $document->text('//h3[@id="address-form-title"]'), "Someone else's address is never shown");

        foreach ([
            ['action' => 'save_address', 'address_id' => (string) $theirs] + self::SAMPLE_ADDRESS,
            ['action' => 'default_address', 'address_id' => (string) $theirs],
            ['action' => 'delete_address', 'address_id' => (string) $theirs],
            ['action' => 'delete_address', 'address_id' => 'x'],
        ] as $forged) {
            $response = $customer->postWithToken('profilePage.php', $forged);
            $this->assertRedirect($response, 'profilePage.php?section=addresses');
            $this->assertFlash($customer->follow($response), 'error', 'That address could not be found. It may already have been deleted.');
        }
        self::assertSame('Hidden Neighbour', db_value('SELECT recipient_name FROM addresses WHERE id = ?', [$theirs]));
        self::assertSame(2, $this->countRows('addresses'));

        // Address changes need the CSRF token.
        $this->assertStatus(419, $customer->post('profilePage.php', ['action' => 'delete_address', 'address_id' => (string) $mine]));
        self::assertNotNull(find_user_address((int) $user['id'], $mine));

        // The sixth address is refused.
        for ($i = 2; $i <= ADDRESS_MAX_PER_CUSTOMER; $i++) {
            $this->createAddress((int) $user['id'], ['recipient_name' => 'Recipient ' . $i]);
        }
        $full = $customer->get('profilePage.php?section=addresses');
        $this->assertPageText('You can save up to 5 addresses. Delete one to add another.', $full);
        self::assertFalse($full->document()->hasForm('profilePage.php', ['action' => 'save_address']), 'No form for a sixth address');
        $sixth = $customer->postWithToken('profilePage.php', ['action' => 'save_address', 'recipient_name' => 'Recipient 6'] + self::SAMPLE_ADDRESS);
        $this->assertFlash($customer->follow($sixth), 'error', 'You can save up to 5 addresses. Delete one to add another.');
        self::assertSame(ADDRESS_MAX_PER_CUSTOMER, $this->countRows('addresses', 'user_id = ?', [$user['id']]));
    }
}
