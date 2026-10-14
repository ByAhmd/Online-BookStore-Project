<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\AccountHelpers;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;

/**
 * Profile self-service through the real pages: the Security section (change
 * password), Delete account and the account's language preference.
 */
final class AccountSecurityTest extends HttpTestCase
{
    use AccountHelpers;

    private const NEW_PASSWORD = 'Changed-passphrase-42';

    /** @var list<string> Avatar files created by this test */
    private array $avatarFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->avatarFiles as $file) {
            @unlink($file);
        }
        $this->avatarFiles = [];
        parent::tearDown();
    }

    /**
     * @param array<string, string> $values
     */
    private function changePassword(HttpClient $client, array $values): HttpResponse
    {
        $page = $client->get('profilePage.php?section=security');
        $this->assertStatus(200, $page);
        return $client->submitForm($page, 'profilePage.php', $values, ['action' => 'change_password']);
    }

    /**
     * @param array<string, string> $values
     */
    private function deleteAccount(HttpClient $client, array $values): HttpResponse
    {
        $page = $client->get('profilePage.php?section=delete');
        $this->assertStatus(200, $page);
        return $client->submitForm($page, 'profilePage.php', $values, ['action' => 'delete_account']);
    }

    /* -----------------------------------------------------------------
     * Change password
     * --------------------------------------------------------------- */

    public function testSecuritySectionRendersAnAccessibleForm(): void
    {
        [$customer] = $this->signedInCustomer();
        $document = $this->assertHtmlPage($customer->get('profilePage.php?section=security'));

        self::assertFalse($document->first('//section[@id="section-security"]')?->hasAttribute('hidden'));
        self::assertSame('page', $document->first('//a[@data-section="security"]')?->getAttribute('aria-current'));
        foreach (['current_password' => 'current-password', 'new_password' => 'new-password', 'new_password_confirmation' => 'new-password'] as $id => $autocomplete) {
            $input = $document->first('//input[@id="' . $id . '"]');
            self::assertNotNull($input, $id);
            self::assertSame('password', $input->getAttribute('type'));
            self::assertSame($autocomplete, $input->getAttribute('autocomplete'));
            self::assertNotNull($document->first('//label[@for="' . $id . '"]'), "$id has a label");
        }
        $form = $document->form('profilePage.php', ['action' => 'change_password']);
        self::assertSame('POST', $form->method);
        self::assertNotNull($form->field('_token'));
        self::assertSame('Passwords do not match.', $document->first('//form[contains(@class, "password-form")]')?->getAttribute('data-i18n-mismatch'));
    }

    public function testChangingThePasswordKeepsThisSessionAndSignsOutTheOthers(): void
    {
        [$customer, $user] = $this->signedInCustomer('Careful Reader');
        $email = (string) $user['Eaddress'];
        $userId = (int) $user['id'];
        $otherDevice = $this->client();
        $this->assertRedirect($this->logIn($otherDevice, $email, self::DEFAULT_PASSWORD), 'Home_Page.php');
        db_run('INSERT INTO reset_tokens (email, token, expiry) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)', [$email, str_repeat('d', 64)]);

        $page = $customer->get('profilePage.php?section=security');
        $session = $customer->cookie(self::SESSION_COOKIE);
        $oldToken = (string) $page->document()->csrfToken();
        $response = $customer->submitForm($page, 'profilePage.php', [
            'current_password' => self::DEFAULT_PASSWORD,
            'new_password' => self::NEW_PASSWORD,
            'new_password_confirmation' => self::NEW_PASSWORD,
        ], ['action' => 'change_password']);

        $this->assertRedirect($response, 'profilePage.php?section=security');
        self::assertNotSame($session, $customer->cookie(self::SESSION_COOKIE), 'A new session id is issued');
        $after = $customer->follow($response);
        $this->assertHtmlPage($after);
        $this->assertFlash($after, 'success', 'Your password has been changed. Your other devices have been signed out.');
        self::assertNotSame($oldToken, $after->document()->csrfToken(), 'A new CSRF token is issued');
        self::assertTrue($this->isSignedInCustomer($customer), 'This session stays signed in');
        self::assertFalse($this->isSignedInCustomer($otherDevice), 'Every other session is signed out');

        // The old CSRF token no longer works for this session.
        $this->assertStatus(419, $customer->post('profilePage.php', ['_token' => $oldToken, 'action' => 'update_payment', 'payment_method' => 'Mada']));
        // Nor does the old session id.
        $replay = $this->client();
        $replay->setCookie(self::SESSION_COOKIE, (string) $session);
        self::assertFalse($this->isSignedInCustomer($replay));

        self::assertSame(1, (int) db_value('SELECT session_version FROM users WHERE id = ?', [$userId]));
        self::assertSame(0, $this->countRows('reset_tokens', 'email = ?', [$email]), 'Outstanding reset links stop working');
        $oldPassword = $this->client();
        $this->assertFlash($oldPassword->follow($this->logIn($oldPassword, $email, self::DEFAULT_PASSWORD)), 'error', 'Incorrect email or password.');
        $this->assertRedirect($this->logIn($this->client(), $email, self::NEW_PASSWORD), 'Home_Page.php');

        $mails = $this->mailsTo($email);
        self::assertCount(1, $mails);
        self::assertSame('Your BookWise password was changed', $mails[0]['subject']);
        self::assertStringContainsString(self::server()->baseUrl . '/index.php?form=reset', $mails[0]['body']);
    }

    public function testInvalidInputAndWrongCurrentPasswordChangeNothing(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $email = (string) $user['Eaddress'];
        $hash = db_value('SELECT pass FROM users WHERE id = ?', [$user['id']]);

        $cases = [
            [['current_password' => '', 'new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => self::NEW_PASSWORD], 'current_password-error', 'Please enter your current password.'],
            [['current_password' => self::DEFAULT_PASSWORD, 'new_password' => 'short', 'new_password_confirmation' => 'short'], 'new_password-error', 'Password must be at least 8 characters long.'],
            [['current_password' => self::DEFAULT_PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => 'something-else-9'], 'new_password_confirmation-error', 'Passwords do not match.'],
            [['current_password' => self::DEFAULT_PASSWORD, 'new_password' => str_repeat('é', 40), 'new_password_confirmation' => str_repeat('é', 40)], 'new_password-error', 'Password must be at most 72 bytes long.'],
        ];
        foreach ($cases as [$values, $errorId, $message]) {
            $response = $this->changePassword($customer, $values);
            $this->assertRedirect($response, 'profilePage.php?section=security');
            $page = $customer->follow($response);
            $document = $this->assertHtmlPage($page);
            self::assertSame($message, $document->fieldError($errorId), $errorId);
            $this->assertFlash($page, 'error', 'Your password was not changed.');
            self::assertFalse($document->first('//section[@id="section-security"]')?->hasAttribute('hidden'));
        }
        self::assertSame(0, $this->countRows('login_attempts'), 'Validation errors are not failed sign-ins');

        $response = $this->changePassword($customer, ['current_password' => 'not-my-password', 'new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => self::NEW_PASSWORD]);
        $page = $customer->follow($response);
        self::assertSame('Your current password is not correct.', $page->document()->fieldError('current_password-error'));
        self::assertSame('true', $page->document()->first('//input[@id="current_password"]')?->getAttribute('aria-invalid'));
        self::assertSame(1, $this->countRows('login_attempts', 'email = ?', [$email]), 'A wrong current password counts as a failed sign-in');

        self::assertSame($hash, db_value('SELECT pass FROM users WHERE id = ?', [$user['id']]));
        self::assertTrue($this->isSignedInCustomer($customer));
        self::assertSame([], $this->mailsTo($email));
    }

    public function testWrongCurrentPasswordsAreThrottled(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $email = (string) $user['Eaddress'];
        for ($attempt = 1; $attempt <= LOGIN_MAX_ATTEMPTS_PER_EMAIL; $attempt++) {
            $this->changePassword($customer, ['current_password' => "guess-$attempt-xyz", 'new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => self::NEW_PASSWORD]);
        }

        $response = $this->changePassword($customer, ['current_password' => self::DEFAULT_PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => self::NEW_PASSWORD]);
        $this->assertRedirect($response, 'profilePage.php?section=security');
        $this->assertFlash($customer->follow($response), 'error', 'Too many incorrect password attempts. Please wait 15 minutes and try again.');
        self::assertNull(attempt_login($email, self::NEW_PASSWORD), 'Even the right current password is refused while throttled');

        // The same counters lock the sign-in form for this address and IP.
        $visitor = $this->client();
        $page = $visitor->follow($this->logIn($visitor, $email, self::DEFAULT_PASSWORD));
        $this->assertFlash($page, 'error', 'Too many failed sign-in attempts. Please wait 15 minutes and try again.');
    }

    public function testChangePasswordRequiresTheCsrfTokenAndASignedInCustomer(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $hash = db_value('SELECT pass FROM users WHERE id = ?', [$user['id']]);
        $fields = ['action' => 'change_password', 'current_password' => self::DEFAULT_PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => self::NEW_PASSWORD];

        $this->assertStatus(419, $customer->post('profilePage.php', $fields));
        $this->assertStatus(419, $customer->post('profilePage.php', ['_token' => str_repeat('0', 64)] + $fields));
        $this->assertRedirect($this->client()->postWithToken('profilePage.php', $fields), 'index.php?form=login');
        [$admin] = $this->signedInAdmin();
        $this->assertRedirect($admin->postWithToken('profilePage.php', $fields), 'admin_dashboard.php');

        self::assertSame($hash, db_value('SELECT pass FROM users WHERE id = ?', [$user['id']]));
    }

    /* -----------------------------------------------------------------
     * Delete account
     * --------------------------------------------------------------- */

    public function testDeleteSectionExplainsWhatIsDeletedAndKept(): void
    {
        [$customer] = $this->signedInCustomer();
        $page = $customer->get('profilePage.php?section=delete');
        $document = $this->assertHtmlPage($page);

        self::assertFalse($document->first('//section[@id="section-delete"]')?->hasAttribute('hidden'));
        self::assertCount(5, $document->elements('//ul[@class="delete-list"]/li'));
        $this->assertPageText('Your order records (books, amounts, dates and payment references) are kept for accounting, anonymised', $page);
        $this->assertPageText('Deleting your account is permanent and cannot be undone.', $page);
        $form = $document->first('//form[contains(@class, "delete-form")]');
        self::assertSame('Delete your account permanently? This cannot be undone.', $form?->getAttribute('data-confirm'));
        self::assertNotNull($document->first('//input[@type="checkbox" and @id="confirm_delete" and @required]'));
        self::assertNotNull($document->first('//label[@for="confirm_delete"]'));
        self::assertNotNull($document->first('//label[@for="delete_password"]'));
    }

    public function testDeletionNeedsThePasswordTheConfirmationAndTheCsrfToken(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];

        $response = $this->deleteAccount($customer, ['delete_password' => self::DEFAULT_PASSWORD]);
        $this->assertRedirect($response, 'profilePage.php?section=delete');
        $page = $customer->follow($response);
        $this->assertFlash($page, 'error', 'Your account was not deleted.');
        self::assertSame('Please confirm that you want to delete your account.', $page->document()->fieldError('confirm_delete-error'));

        $page = $customer->follow($this->deleteAccount($customer, ['delete_password' => '', 'confirm_delete' => 'yes']));
        self::assertSame('Please enter your current password.', $page->document()->fieldError('delete_password-error'));

        $page = $customer->follow($this->deleteAccount($customer, ['delete_password' => 'not-my-password', 'confirm_delete' => 'yes']));
        self::assertSame('Your current password is not correct.', $page->document()->fieldError('delete_password-error'));
        self::assertSame(1, $this->countRows('login_attempts', 'email = ?', [$user['Eaddress']]));

        $fields = ['action' => 'delete_account', 'delete_password' => self::DEFAULT_PASSWORD, 'confirm_delete' => 'yes'];
        $this->assertStatus(419, $customer->post('profilePage.php', $fields));
        $this->assertStatus(419, $customer->post('profilePage.php', ['_token' => str_repeat('0', 64)] + $fields));
        $this->assertRedirect($this->client()->postWithToken('profilePage.php', $fields), 'index.php?form=login');

        self::assertNotNull(find_user($userId));
        self::assertTrue($this->isSignedInCustomer($customer));
    }

    public function testDeletingTheAccountRemovesPersonalDataAndKeepsOrdersAnonymised(): void
    {
        [$customer, $user] = $this->signedInCustomer('Departing Reader');
        $userId = (int) $user['id'];
        $email = (string) $user['Eaddress'];
        [$neighbour, $other] = $this->signedInCustomer('Staying Reader');
        $otherId = (int) $other['id'];
        $otherDevice = $this->client();
        $this->assertRedirect($this->logIn($otherDevice, $email, self::DEFAULT_PASSWORD), 'Home_Page.php');

        // A paid order, then cart, favorites, reviews and reactions.
        $purchase = $this->purchase($customer, [51 => 1]);
        $orderId = (int) $purchase['order']['id'];
        $this->assertRedirect($this->addToCart($customer, 7, 2), 'cart.php');
        db_run('INSERT INTO favorites (user_id, book_id) VALUES (?, 7), (?, 7)', [$userId, $otherId]);
        db_run("INSERT INTO comments (book_id, username, comment, rating) VALUES (51, ?, 'Loved it', 5), (51, ?, 'Also good', 4)", [$email, $other['Eaddress']]);
        $ownReview = (int) db_value('SELECT id FROM comments WHERE username = ?', [$email]);
        $otherReview = (int) db_value('SELECT id FROM comments WHERE username = ?', [$other['Eaddress']]);
        db_run("INSERT INTO comment_interactions (comment_id, user_id, reaction_type) VALUES (?, ?, 'like'), (?, ?, 'like')", [$otherReview, $userId, $ownReview, $otherId]);
        db_run('INSERT INTO reset_tokens (email, token, expiry) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)', [$email, str_repeat('e', 64)]);
        db_run("INSERT INTO login_attempts (email, ip_address) VALUES (?, '203.0.113.7')", [$email]);
        $this->issueVerificationToken($userId);

        // An uploaded avatar.
        $avatar = 'uploads/avatars/' . bin2hex(random_bytes(16)) . '.png';
        $avatarFile = BASE_PATH . '/public/' . $avatar;
        @mkdir(dirname($avatarFile), 0775, true);
        file_put_contents($avatarFile, 'avatar bytes');
        $this->avatarFiles[] = $avatarFile;
        db_run('UPDATE users SET profile_image = ? WHERE id = ?', [$avatar, $userId]);

        $session = $customer->cookie(self::SESSION_COOKIE);
        $response = $this->deleteAccount($customer, ['delete_password' => self::DEFAULT_PASSWORD, 'confirm_delete' => 'yes']);
        $this->assertRedirect($response, 'Home_Page.php');
        self::assertNotSame($session, $customer->cookie(self::SESSION_COOKIE));
        $home = $customer->follow($response);
        $this->assertHtmlPage($home);
        $this->assertFlash($home, 'success', 'Your account has been deleted. Thank you for reading with us.');
        self::assertFalse($this->isSignedInCustomer($customer));
        self::assertFalse($this->isSignedInCustomer($otherDevice), 'Other sessions of the account end too');

        self::assertNull(find_user($userId));
        foreach (['cart', 'favorites', 'sales', 'comment_interactions', 'email_verifications'] as $table) {
            self::assertSame(0, $this->countRows($table, 'user_id = ?', [$userId]), $table);
        }
        self::assertSame(0, $this->countRows('comments', 'username = ?', [$email]));
        self::assertSame(0, $this->countRows('comment_interactions', 'comment_id = ?', [$ownReview]));
        self::assertSame(0, $this->countRows('reset_tokens', 'email = ?', [$email]));
        self::assertSame(0, $this->countRows('login_attempts', 'email = ?', [$email]));
        self::assertFileDoesNotExist($avatarFile, 'The uploaded avatar is deleted');

        // The order stays as an anonymous financial record.
        $order = db_one('SELECT user_id, status, amount_minor, stripe_session_id FROM orders WHERE id = ?', [$orderId]);
        self::assertSame(['user_id' => null, 'status' => 'paid', 'amount_minor' => (int) $purchase['order']['amount_minor'], 'stripe_session_id' => $purchase['session']], $order);
        self::assertSame(1, $this->countRows('order_items', 'order_id = ?', [$orderId]));

        // Other customers keep their data and still see their own review.
        self::assertNotNull(find_user($otherId));
        self::assertSame(1, $this->countRows('favorites', 'user_id = ?', [$otherId]));
        self::assertSame(1, $this->countRows('comments', 'id = ?', [$otherReview]));
        self::assertTrue($this->isSignedInCustomer($neighbour));

        // The address cannot sign in again, and the owner was told.
        $returning = $this->client();
        $this->assertFlash($returning->follow($this->logIn($returning, $email, self::DEFAULT_PASSWORD)), 'error', 'Incorrect email or password.');
        $goodbye = array_values(array_filter($this->mailsTo($email), static fn (array $mail): bool => $mail['subject'] === 'Your BookWise account was deleted'));
        self::assertCount(1, $goodbye);
    }

    public function testOpenCheckoutsAreSettledAndUnresolvedPaymentsBlockDeletion(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];
        $this->assertRedirect($this->addToCart($customer, 7, 1), 'cart.php');
        $sessionId = $this->startCheckout($customer);

        // A payment that is still processing: refuse, change nothing.
        $this->stripe->setState($sessionId, 'complete', 'unpaid');
        $response = $this->deleteAccount($customer, ['delete_password' => self::DEFAULT_PASSWORD, 'confirm_delete' => 'yes']);
        $this->assertRedirect($response, 'profilePage.php?section=delete');
        $this->assertFlash($customer->follow($response), 'error', 'We could not confirm the status of a checkout you started, so your account was not deleted. You have not been charged twice. Please try again in a few minutes.');
        self::assertNotNull(find_user($userId));
        self::assertSame('pending', $this->orderBySession($sessionId)['status']);
        self::assertTrue($this->isSignedInCustomer($customer));

        // A checkout that is only open is expired on Stripe, then the account is deleted.
        $this->stripe->setState($sessionId, 'open', 'unpaid');
        $this->assertRedirect($this->deleteAccount($customer, ['delete_password' => self::DEFAULT_PASSWORD, 'confirm_delete' => 'yes']), 'Home_Page.php');
        self::assertNull(find_user($userId));
        self::assertSame('expired', $this->stripe->session($sessionId)['status'] ?? null, 'The abandoned session can no longer be paid');
        $order = $this->orderBySession($sessionId);
        self::assertSame([null, 'canceled'], [$order['user_id'], $order['status']]);
    }

    /* -----------------------------------------------------------------
     * Language preference
     * --------------------------------------------------------------- */

    public function testLanguagePreferenceIsSavedToTheAccountAndTheCookie(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];

        $page = $customer->get('profilePage.php?section=account');
        $document = $this->assertHtmlPage($page);
        self::assertSame(['English', 'العربية'], $document->texts('//select[@id="locale"]/option'));
        self::assertSame('en', $document->form('profilePage.php', ['action' => 'update_language'])->field('locale'));

        $response = $customer->submitForm($page, 'profilePage.php', ['locale' => 'ar'], ['action' => 'update_language']);
        $this->assertRedirect($response, 'profilePage.php?section=account');
        self::assertStringStartsWith(LOCALE_COOKIE . '=ar;', (string) $response->setCookieHeader(LOCALE_COOKIE));
        self::assertSame('ar', $customer->cookie(LOCALE_COOKIE));
        self::assertSame('ar', find_user_account($userId)['locale'] ?? null);

        $arabic = $customer->follow($response);
        $document = $this->assertHtmlPage($arabic);
        self::assertSame('ar', $document->first('//html')?->getAttribute('lang'));
        self::assertSame('rtl', $document->first('//html')?->getAttribute('dir'));
        $this->assertFlash($arabic, 'success', 'تم حفظ اللغة المفضّلة.');
        self::assertSame('ar', $document->form('profilePage.php', ['action' => 'update_language'])->field('locale'));

        // Unsupported values are refused.
        $response = $customer->submitForm($arabic, 'profilePage.php', ['locale' => 'fr'], ['action' => 'update_language']);
        $this->assertRedirect($response, 'profilePage.php?section=account');
        $page = $customer->follow($response);
        self::assertSame('يُرجى اختيار إحدى اللغات المدرجة.', $page->document()->fieldError('locale-error'));
        self::assertSame('ar', find_user_account($userId)['locale'] ?? null);
        $this->assertStatus(419, $customer->post('profilePage.php', ['action' => 'update_language', 'locale' => 'en']));
    }

    public function testASignedOutSessionCannotChangeTheAccountLanguage(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];
        // A password change elsewhere signs this session out.
        db_run('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);

        $this->assertRedirect($customer->get('Home_Page.php?lang=ar'), 'Home_Page.php');
        self::assertSame('ar', $customer->cookie(LOCALE_COOKIE), 'The visitor still gets the language');
        self::assertSame('en', find_user_account($userId)['locale'] ?? null, 'The account is not changed');

        // A valid session does store it.
        [$current, $other] = $this->signedInCustomer();
        $this->assertRedirect($current->get('Home_Page.php?lang=ar'), 'Home_Page.php');
        self::assertSame('ar', find_user_account((int) $other['id'])['locale'] ?? null);
    }

    public function testEmailsFollowTheAccountLanguageNotTheVisitors(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $email = (string) $user['Eaddress'];
        $page = $customer->get('profilePage.php?section=account');
        $this->assertRedirect($customer->submitForm($page, 'profilePage.php', ['locale' => 'ar'], ['action' => 'update_language']), 'profilePage.php?section=account');

        // An English-speaking visitor asks for a reset link for this account.
        $visitor = $this->client();
        $visitor->setCookie(LOCALE_COOKIE, 'en');
        $form = $visitor->get('index.php?form=reset');
        $response = $visitor->submitForm($form, 'reset_password.php', ['email' => $email]);
        $this->assertRedirect($response, 'index.php?form=login');
        $this->assertFlash($visitor->follow($response), 'success', "If an account exists for that email, we've sent a password reset link. It expires in 60 minutes.");

        $mails = $this->mailsTo($email);
        self::assertCount(1, $mails);
        self::assertSame('إعادة تعيين كلمة المرور في BookWise', $mails[0]['subject']);
        self::assertStringContainsString('60 دقيقة', $mails[0]['body']);
    }
}
