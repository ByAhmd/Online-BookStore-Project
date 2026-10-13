<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;
use RuntimeException;

/**
 * profilePage.php: account details, avatar upload, payment preference and orders.
 */
final class ProfileTest extends HttpTestCase
{
    /** @var list<string> Temporary upload files created by this test */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        $this->temporaryFiles = [];
        parent::tearDown();
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bookwise-upload-');
        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary file');
        }
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;
        return $path;
    }

    private static function pngBytes(int $width = 24, int $height = 16): string
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('The GD extension is needed to generate a test image.');
        }
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 200, 30, 60));
        ob_start();
        imagepng($image);
        return (string) ob_get_clean();
    }

    /**
     * @param array<string, string> $values
     * @param array<string, array{path: string, filename: string, type: string}> $files
     */
    private function updateProfile(HttpClient $client, array $values, array $files = []): HttpResponse
    {
        $page = $client->get('profilePage.php?section=account');
        $this->assertStatus(200, $page);
        return $client->submitForm($page, 'profilePage.php', $values, ['action' => 'update_profile'], $files);
    }

    public function testAccountDetailsAreValidated(): void
    {
        [$customer, $user] = $this->signedInCustomer('Profile Reader');
        $userId = (int) $user['id'];

        $page = $customer->get('profilePage.php?section=account');
        $form = $page->document()->form('profilePage.php', ['action' => 'update_profile']);
        self::assertSame('multipart/form-data', $form->enctype);
        self::assertSame('Profile Reader', $form->field('name'));
        self::assertSame('Unknown', $form->field('favorite_genre'));

        $response = $this->updateProfile($customer, ['name' => 'Renamed Reader', 'favorite_genre' => 'Science Fiction', 'date_of_birth' => '1990-05-17']);
        $this->assertRedirect($response, 'profilePage.php?section=account');
        $page = $customer->follow($response);
        $this->assertHtmlPage($page);
        $this->assertFlash($page, 'success', 'Your profile has been updated.');
        $stored = find_user($userId);
        self::assertSame(['Renamed Reader', 'Science Fiction', '1990-05-17'], [$stored['name'], $stored['favorite_genre'], $stored['date_of_birth']]);
        self::assertSame('1990-05-17', $page->document()->first('//input[@id="date_of_birth"]')?->getAttribute('value'));

        $invalid = [
            [['name' => ''], 'name-error', 'Please enter your name.'],
            [['name' => '<script>x</script>'], 'name-error', 'Name contains characters that are not allowed.'],
            [['name' => str_repeat('n', 101)], 'name-error', 'Name must be 100 characters or fewer.'],
            [['favorite_genre' => ''], 'favorite_genre-error', 'Favorite genre is required.'],
            [['favorite_genre' => '<b>'], 'favorite_genre-error', 'Favorite genre contains characters that are not allowed.'],
            [['favorite_genre' => "Drama\tNoir"], 'favorite_genre-error', 'Favorite genre contains characters that are not allowed.'],
            [['favorite_genre' => str_repeat('g', 101)], 'favorite_genre-error', 'Favorite genre must be 100 characters or fewer.'],
            [['date_of_birth' => '2023-02-30'], 'date_of_birth-error', 'Please enter a valid date of birth.'],
            [['date_of_birth' => '17/05/1990'], 'date_of_birth-error', 'Please enter a valid date of birth.'],
            [['date_of_birth' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d')], 'date_of_birth-error', 'Date of birth must be a past date after 1900.'],
            [['date_of_birth' => '1899-12-31'], 'date_of_birth-error', 'Date of birth must be a past date after 1900.'],
        ];
        foreach ($invalid as [$values, $errorId, $message]) {
            $response = $this->updateProfile($customer, $values);
            $this->assertRedirect($response, 'profilePage.php?section=account');
            $page = $customer->follow($response);
            $this->assertHtmlPage($page);
            self::assertSame($message, $page->document()->fieldError($errorId), json_encode($values));
            $this->assertFlash($page, 'error', 'Please correct the highlighted fields. Your profile was not updated.');
            self::assertSame($stored, find_user($userId), 'Invalid input changes nothing: ' . json_encode($values));
        }

        // An empty date of birth clears it (the field is optional).
        $this->assertRedirect($this->updateProfile($customer, ['date_of_birth' => '']), 'profilePage.php?section=account');
        self::assertNull(find_user($userId)['date_of_birth']);
    }

    public function testFreeTextFavoriteGenresNeverBlockProfileUpdates(): void
    {
        [$customer, $user] = $this->signedInCustomer('Legacy Reader');
        $userId = (int) $user['id'];

        // A legacy value stays valid when the customer only changes another field.
        db_run('UPDATE users SET favorite_genre = ? WHERE id = ?', ['Sci-Fi/Fantasy', $userId]);
        $response = $this->updateProfile($customer, ['name' => 'Renamed Legacy Reader']);
        $this->assertRedirect($response, 'profilePage.php?section=account');
        $this->assertFlash($customer->follow($response), 'success', 'Your profile has been updated.');
        $stored = find_user($userId);
        self::assertSame(['Renamed Legacy Reader', 'Sci-Fi/Fantasy'], [$stored['name'], $stored['favorite_genre']]);

        // Free text is not a catalog genre name: punctuation and tab names are fine here.
        foreach (['Children’s', 'Favorites', 'رواية'] as $genre) {
            $response = $this->updateProfile($customer, ['favorite_genre' => $genre]);
            $this->assertRedirect($response, 'profilePage.php?section=account');
            $this->assertFlash($customer->follow($response), 'success', 'Your profile has been updated.');
            self::assertSame($genre, find_user($userId)['favorite_genre']);
        }
    }

    public function testAvatarUploadAcceptsRealImagesOnly(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];
        $png = self::pngBytes();

        $response = $this->updateProfile($customer, [], ['profile_image' => ['path' => $this->temporaryFile($png), 'filename' => 'me.png', 'type' => 'image/png']]);
        $this->assertRedirect($response, 'profilePage.php?section=account');
        $page = $customer->follow($response);
        $this->assertFlash($page, 'success', 'Your profile has been updated.');

        $avatar = (string) find_user($userId)['profile_image'];
        self::assertMatchesRegularExpression('#^uploads/avatars/[a-f0-9]{32}\.png$#', $avatar, 'Stored under a random name, never the client name');
        self::assertFileExists(BASE_PATH . '/public/' . $avatar);
        self::assertSame($avatar, $page->document()->first('//img[@id="avatar-preview"]')?->getAttribute('src'));
        self::assertSame($avatar, $page->document()->first('//img[contains(@class, "nav-avatar")]')?->getAttribute('src'));

        $served = $customer->get($avatar);
        $this->assertStatus(200, $served);
        self::assertSame('image/png', strtok($served->contentType(), ';'));
        $info = getimagesizefromstring($served->body);
        self::assertIsArray($info);
        self::assertSame([24, 16, 'image/png'], [$info[0], $info[1], $info['mime']]);

        // A second upload replaces (and deletes) the first file.
        $response = $this->updateProfile($customer, [], ['profile_image' => ['path' => $this->temporaryFile(self::pngBytes(8, 8)), 'filename' => 'again.png', 'type' => 'image/png']]);
        $this->assertRedirect($response, 'profilePage.php?section=account');
        $second = (string) find_user($userId)['profile_image'];
        self::assertNotSame($avatar, $second);
        self::assertFileDoesNotExist(BASE_PATH . '/public/' . $avatar);
        self::assertFileExists(BASE_PATH . '/public/' . $second);

        // PHP code named .png is refused and stored nowhere.
        $marker = 'bookwise-http-payload-' . bin2hex(random_bytes(8));
        $payload = "<?php echo '$marker'; system(\$_GET['c'] ?? 'id'); ?>\n";
        foreach ([['shell.png', 'image/png'], ['shell.php', 'image/png'], ['shell.png.php', 'application/x-php']] as [$filename, $type]) {
            $response = $this->updateProfile($customer, ['name' => 'Should Not Save'], ['profile_image' => ['path' => $this->temporaryFile($payload), 'filename' => $filename, 'type' => $type]]);
            $this->assertRedirect($response, 'profilePage.php?section=account');
            $page = $customer->follow($response);
            self::assertSame('Please upload a JPEG, PNG, GIF or WebP image.', $page->document()->fieldError('profile_image-error'), $filename);
            self::assertSame($second, find_user($userId)['profile_image']);
            self::assertNotSame('Should Not Save', find_user($userId)['name']);
        }

        // A real image with PHP appended is re-encoded: the stored copy carries no code.
        $polyglot = self::pngBytes() . $payload;
        $response = $this->updateProfile($customer, [], ['profile_image' => ['path' => $this->temporaryFile($polyglot), 'filename' => 'polyglot.php', 'type' => 'image/png']]);
        $this->assertRedirect($response, 'profilePage.php?section=account');
        $third = (string) find_user($userId)['profile_image'];
        self::assertMatchesRegularExpression('#^uploads/avatars/[a-f0-9]{32}\.png$#', $third);
        self::assertStringNotContainsString('<?php', (string) file_get_contents(BASE_PATH . '/public/' . $third));

        foreach (glob(BASE_PATH . '/public/uploads/avatars/*') ?: [] as $file) {
            self::assertStringNotContainsString($marker, (string) file_get_contents($file), "Payload stored as $file");
        }

        // An empty file never replaces the picture (PHP may report it as "no file chosen").
        $response = $this->updateProfile($customer, [], ['profile_image' => ['path' => $this->temporaryFile(''), 'filename' => 'empty.png', 'type' => 'image/png']]);
        $message = $customer->follow($response)->document()->fieldError('profile_image-error');
        self::assertContains($message, ['', 'The selected file is empty. Please choose an image.', 'The picture could not be uploaded. Please try again.']);
        self::assertSame($third, find_user($userId)['profile_image']);
    }

    public function testPaymentPreference(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];

        $page = $customer->get('profilePage.php?section=payment');
        $document = $this->assertHtmlPage($page);
        self::assertSame(['No preference', ...PAYMENT_PREFERENCES], $document->texts('//select[@id="payment_method"]/option'));

        $response = $customer->submitForm($page, 'profilePage.php', ['payment_method' => 'Apple Pay'], ['action' => 'update_payment']);
        $this->assertRedirect($response, 'profilePage.php?section=payment');
        $page = $customer->follow($response);
        $this->assertFlash($page, 'success', 'Your payment preference has been saved.');
        self::assertSame('Apple Pay', find_user($userId)['payment_method']);
        self::assertSame('Apple Pay', $page->document()->form('profilePage.php', ['action' => 'update_payment'])->field('payment_method'));

        foreach (['4111 1111 1111 1111', 'Bitcoin', 'apple pay'] as $invalid) {
            $response = $customer->submitForm($customer->get('profilePage.php?section=payment'), 'profilePage.php', ['payment_method' => $invalid], ['action' => 'update_payment']);
            $this->assertRedirect($response, 'profilePage.php?section=payment');
            $page = $customer->follow($response);
            $this->assertFlash($page, 'error', 'Your payment preference was not saved.');
            self::assertSame('Please choose one of the listed payment preferences.', $page->document()->fieldError('payment_method-error'));
            self::assertSame('Apple Pay', find_user($userId)['payment_method']);
            self::assertStringNotContainsString('4111', $page->body);
        }

        $this->assertRedirect($customer->postWithToken('profilePage.php', ['action' => 'update_payment', 'payment_method' => '']), 'profilePage.php?section=payment');
        self::assertNull(find_user($userId)['payment_method'], '"No preference" clears it');

        // A legacy free-text value (possibly a card number) is never echoed back.
        db_run('UPDATE users SET payment_method = ? WHERE id = ?', ['4242 4242 4242 4242', $userId]);
        $page = $customer->get('profilePage.php?section=payment');
        self::assertStringNotContainsString('4242', $page->body);
        $this->assertPageText('Your previous entry was cleared for your security', $page);
    }

    public function testOrdersSectionListsPaidOrders(): void
    {
        [$customer] = $this->signedInCustomer();
        $empty = $customer->get('profilePage.php?section=orders');
        $this->assertPageText("You haven't placed any orders yet.", $empty);

        $purchase = $this->purchase($customer, [51 => 2, 8 => 1]);
        $orderId = (int) $purchase['order']['id'];

        $page = $customer->get('profilePage.php?section=orders');
        $document = $this->assertHtmlPage($page);
        self::assertFalse($document->first('//section[@id="section-orders"]')?->hasAttribute('hidden'));
        self::assertSame(['Order #' . $orderId], $document->texts('//h3[contains(@class, "order-number")]'));
        self::assertSame(['paid'], $document->texts('//span[contains(@class, "status-badge")]'));
        self::assertSame(['Esabella × 2', 'The Danger Bridge × 1'], $document->texts('//ul[@class="order-items"]/li'));
        $this->assertPageText('Total ' . format_money((string) $purchase['order']['total_amount']), $page);

        // Stored orders keep their own currency, whatever STRIPE_CURRENCY says today.
        db_run('UPDATE orders SET currency = ? WHERE id = ?', ['usd', $orderId]);
        $page = $customer->get('profilePage.php?section=orders');
        self::assertSame(['Total USD ' . number_format((float) $purchase['order']['total_amount'], 2)], $page->document()->texts('//p[contains(@class, "order-total")]'));
    }

    public function testUnknownProfileActionIsABadRequest(): void
    {
        [$customer] = $this->signedInCustomer();
        $this->assertHtmlPage($customer->postWithToken('profilePage.php', ['action' => 'delete_account']), 400);
    }
}
