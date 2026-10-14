<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\AccountHelpers;
use BookWise\Tests\Support\DatabaseTestCase;
use BookWise\Tests\Support\StripeMock;
use UploadException;

/**
 * Account self-service domain code: changing the password, deleting the
 * account, the language preference and the localized form messages.
 */
final class AccountManagementTest extends DatabaseTestCase
{
    use AccountHelpers;

    private const IP = '198.51.100.20';
    private const NEW_PASSWORD = 'brand-new-password-7';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('REQUIRE_EMAIL_VERIFICATION', 'false');
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /* -----------------------------------------------------------------
     * Changing the password
     * --------------------------------------------------------------- */

    public function testChangePasswordChecksTheCurrentPassword(): void
    {
        $user = $this->createUser('changer@example.com');
        $userId = (int) $user['id'];
        db_run('INSERT INTO reset_tokens (email, token, expiry) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)', ['changer@example.com', str_repeat('a', 64)]);

        self::assertSame(['status' => 'wrong_password', 'session_version' => null], change_user_password($userId, 'not-my-password', self::NEW_PASSWORD, self::IP));
        self::assertSame(1, $this->countRows('login_attempts', 'email = ? AND ip_address = ?', ['changer@example.com', self::IP]), 'Counts as a failed sign-in');
        self::assertSame('user', attempt_login('changer@example.com', self::DEFAULT_PASSWORD)['type'] ?? null, 'Password unchanged');

        self::assertSame(['status' => 'changed', 'session_version' => 1], change_user_password($userId, self::DEFAULT_PASSWORD, self::NEW_PASSWORD, self::IP));
        self::assertSame(1, (int) db_value('SELECT session_version FROM users WHERE id = ?', [$userId]));
        self::assertNull(attempt_login('changer@example.com', self::DEFAULT_PASSWORD));
        self::assertSame('user', attempt_login('changer@example.com', self::NEW_PASSWORD)['type'] ?? null);
        self::assertSame(0, $this->countRows('login_attempts', 'email = ?', ['changer@example.com']), 'Success clears the failures');
        self::assertSame(0, $this->countRows('reset_tokens'), 'Outstanding reset links stop working');
    }

    public function testWrongCurrentPasswordsAreThrottled(): void
    {
        $user = $this->createUser('guessed@example.com');
        $userId = (int) $user['id'];

        for ($i = 1; $i <= LOGIN_MAX_ATTEMPTS_PER_EMAIL; $i++) {
            self::assertSame('wrong_password', change_user_password($userId, "guess-number-$i", self::NEW_PASSWORD, self::IP)['status']);
        }
        self::assertSame('throttled', change_user_password($userId, self::DEFAULT_PASSWORD, self::NEW_PASSWORD, self::IP)['status']);
        self::assertSame(LOGIN_MAX_ATTEMPTS_PER_EMAIL, $this->countRows('login_attempts'), 'Throttled attempts are not counted again');
        self::assertSame('user', attempt_login('guessed@example.com', self::DEFAULT_PASSWORD)['type'] ?? null, 'Password unchanged');

        // The sign-in form is throttled by the same counters.
        self::assertTrue(login_is_throttled('guessed@example.com', self::IP));
    }

    public function testChangingThePasswordSignsOutOtherSessionsButKeepsThisOne(): void
    {
        $user = $this->createUser('sessions@example.com');
        $userId = (int) $user['id'];
        $_SESSION = ['user_id' => $userId, 'role' => 'user', 'session_version' => 0, '_csrf_token' => str_repeat('c', 64)];
        self::assertNotNull(current_user(true));

        $result = change_user_password($userId, self::DEFAULT_PASSWORD, self::NEW_PASSWORD, self::IP);
        self::assertSame('changed', $result['status']);

        $otherSession = ['user_id' => $userId, 'role' => 'user', 'session_version' => 0];
        $mine = $_SESSION;
        $_SESSION = $otherSession;
        self::assertNull(current_user(true), 'Another session that signed in before the change is signed out');

        $_SESSION = $mine;
        keep_session_after_password_change((int) $result['session_version']);
        self::assertSame(1, $_SESSION['session_version']);
        self::assertNotSame(str_repeat('c', 64), $_SESSION['_csrf_token'], 'A new CSRF token is issued');
        self::assertSame($userId, current_user(true)['id'] ?? null, 'This session stays signed in');
    }

    /* -----------------------------------------------------------------
     * Deleting the account
     * --------------------------------------------------------------- */

    public function testDeleteAccountRemovesPersonalDataAndKeepsOrdersAnonymised(): void
    {
        $user = $this->createUser('leaving@example.com', 'Leaving Reader');
        $userId = (int) $user['id'];
        $other = $this->createUser('staying@example.com', 'Staying Reader');
        $otherId = (int) $other['id'];
        db_run("UPDATE users SET profile_image = 'uploads/avatars/0123456789abcdef0123456789abcdef.png', locale = 'ar' WHERE id = ?", [$userId]);

        db_run('INSERT INTO cart (user_id, book_id, quantity) VALUES (?, 7, 2), (?, 7, 1)', [$userId, $otherId]);
        db_run('INSERT INTO favorites (user_id, book_id) VALUES (?, 7), (?, 7)', [$userId, $otherId]);
        db_run("INSERT INTO orders (user_id, status, currency, total_amount, amount_minor, paid_at) VALUES (?, 'paid', 'sar', 25.00, 2500, NOW())", [$userId]);
        $orderId = (int) db()->lastInsertId();
        db_run("INSERT INTO order_items (order_id, book_id, title, unit_price, quantity) VALUES (?, 7, 'Kept Title', 25.00, 1)", [$orderId]);
        db_run('INSERT INTO sales (book_id, user_id, order_id, quantity) VALUES (7, ?, ?, 1)', [$userId, $orderId]);
        db_run("INSERT INTO comments (book_id, username, comment, rating) VALUES (7, 'leaving@example.com', 'My review', 4)");
        $leavingReview = (int) db()->lastInsertId();
        db_run("INSERT INTO comments (book_id, username, comment, rating) VALUES (7, 'staying@example.com', 'Their review', 5)");
        $stayingReview = (int) db()->lastInsertId();
        db_run("INSERT INTO comment_interactions (comment_id, user_id, reaction_type) VALUES (?, ?, 'like'), (?, ?, 'dislike')", [$leavingReview, $otherId, $stayingReview, $userId]);
        db_run('INSERT INTO reset_tokens (email, token, expiry) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)', ['leaving@example.com', str_repeat('b', 64)]);
        record_failed_login('leaving@example.com', '203.0.113.9');
        $this->issueVerificationToken($userId);

        // A wrong password deletes nothing and counts as a failed sign-in.
        self::assertSame(['status' => 'wrong_password', 'account' => null], delete_customer_account($userId, 'not-my-password', self::IP));
        self::assertNotNull(find_user($userId));
        self::assertSame(1, $this->countRows('login_attempts', 'ip_address = ?', [self::IP]));

        $result = delete_customer_account($userId, self::DEFAULT_PASSWORD, self::IP);

        self::assertSame('deleted', $result['status']);
        self::assertSame([
            'id' => $userId,
            'Eaddress' => 'leaving@example.com',
            'name' => 'Leaving Reader',
            'locale' => 'ar',
            'profile_image' => 'uploads/avatars/0123456789abcdef0123456789abcdef.png',
        ], $result['account'], 'The caller gets what it needs for the avatar file and the goodbye email, never the hash');

        self::assertNull(find_user($userId));
        foreach (['cart', 'favorites', 'sales', 'comment_interactions', 'email_verifications'] as $table) {
            self::assertSame(0, $this->countRows($table, 'user_id = ?', [$userId]), "$table rows of the deleted account");
        }
        self::assertSame(0, $this->countRows('comments', 'username = ?', ['leaving@example.com']));
        self::assertSame(0, $this->countRows('comment_interactions', 'comment_id = ?', [$leavingReview]), 'Reactions to the deleted review go too');
        self::assertSame(0, $this->countRows('reset_tokens', 'email = ?', ['leaving@example.com']));
        self::assertSame(0, $this->countRows('login_attempts', 'email = ?', ['leaving@example.com']));

        // Orders are financial records: kept, without the link to the customer.
        $order = db_one('SELECT user_id, status, total_amount FROM orders WHERE id = ?', [$orderId]);
        self::assertSame(['user_id' => null, 'status' => 'paid', 'total_amount' => '25.00'], $order);
        self::assertSame(1, $this->countRows('order_items', 'order_id = ? AND title = ?', [$orderId, 'Kept Title']));

        // Other customers keep everything.
        self::assertNotNull(find_user($otherId));
        self::assertSame(1, $this->countRows('cart', 'user_id = ?', [$otherId]));
        self::assertSame(1, $this->countRows('favorites', 'user_id = ?', [$otherId]));
        self::assertSame(1, $this->countRows('comments', 'id = ?', [$stayingReview]));

        self::assertNull(attempt_login('leaving@example.com', self::DEFAULT_PASSWORD), 'The account cannot sign in again');
        self::assertSame(['status' => 'wrong_password', 'account' => null], delete_customer_account($userId, self::DEFAULT_PASSWORD, self::IP));
    }

    public function testPendingOrdersAreSettledBeforeDeletion(): void
    {
        $user = $this->createUser('pending@example.com');
        $userId = (int) $user['id'];
        // Session creation never completed: settle_pending_orders() marks it failed without calling Stripe.
        db_run("INSERT INTO orders (user_id, status, currency, total_amount, amount_minor) VALUES (?, 'pending', 'sar', 12.00, 1200)", [$userId]);
        $orderId = (int) db()->lastInsertId();

        self::assertSame('deleted', delete_customer_account($userId, self::DEFAULT_PASSWORD, self::IP)['status']);

        self::assertSame(['user_id' => null, 'status' => 'failed'], db_one('SELECT user_id, status FROM orders WHERE id = ?', [$orderId]));
    }

    public function testOpenCheckoutIsExpiredAndAPaymentStillProcessingBlocksDeletion(): void
    {
        // Start the local Stripe mock before anything in this process creates the Stripe client.
        $stripe = StripeMock::start();
        $stripe->reset();

        $user = $this->createUser('paying@example.com');
        $userId = (int) $user['id'];
        self::assertTrue(cart_add($userId, 7, 1));
        $url = start_checkout($user);
        $sessionId = substr($url, (int) strrpos($url, '/') + 1);

        // An asynchronous payment that is still processing: its outcome is unknown, so nothing is deleted.
        $stripe->setState($sessionId, 'complete', 'unpaid');
        self::assertSame(['status' => 'unresolved', 'account' => null], delete_customer_account($userId, self::DEFAULT_PASSWORD, self::IP));
        self::assertNotNull(find_user($userId));
        self::assertSame('pending', db_value('SELECT status FROM orders WHERE stripe_session_id = ?', [$sessionId]));
        self::assertSame(0, (int) db_value('SELECT IS_USED_LOCK(?) IS NOT NULL', ['bookwise-checkout-' . $userId]), 'The checkout lock is released');

        // A checkout that is merely open is expired on Stripe first, then the account goes.
        $stripe->setState($sessionId, 'open', 'unpaid');
        self::assertSame('deleted', delete_customer_account($userId, self::DEFAULT_PASSWORD, self::IP)['status']);
        self::assertSame('expired', $stripe->session($sessionId)['status'] ?? null, 'The session can no longer be paid');
        self::assertSame(['user_id' => null, 'status' => 'canceled'], db_one('SELECT user_id, status FROM orders WHERE stripe_session_id = ?', [$sessionId]));
    }

    public function testDeletionIsThrottledLikeSignIn(): void
    {
        $user = $this->createUser('target@example.com');
        $userId = (int) $user['id'];
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS_PER_EMAIL; $i++) {
            record_failed_login('target@example.com', self::IP);
        }

        self::assertSame('throttled', delete_customer_account($userId, self::DEFAULT_PASSWORD, self::IP)['status']);
        self::assertNotNull(find_user($userId));
        self::assertSame('deleted', delete_customer_account($userId, self::DEFAULT_PASSWORD, '203.0.113.50')['status'], 'The owner elsewhere is not locked out');
    }

    /* -----------------------------------------------------------------
     * Language preference and account emails
     * --------------------------------------------------------------- */

    public function testLanguagePreferenceIsValidatedAndStored(): void
    {
        $user = $this->createUser('lang@example.com');
        $userId = (int) $user['id'];
        self::assertSame('en', find_user_account($userId)['locale'] ?? null);

        foreach (['fr', 'AR', '', 'ar ', 'english'] as $invalid) {
            self::assertFalse(update_user_locale($userId, $invalid), $invalid);
        }
        self::assertTrue(update_user_locale($userId, 'ar'));
        self::assertSame('ar', find_user_account($userId)['locale'] ?? null);

        // A value outside the supported list (e.g. edited by hand) falls back to the default.
        db_run("UPDATE users SET locale = 'xx' WHERE id = ?", [$userId]);
        self::assertSame('en', find_user_account($userId)['locale'] ?? null);
    }

    public function testAccountEmailsUseTheAccountsLanguage(): void
    {
        $user = $this->createUser('mails@example.com', 'Mail Reader');
        $userId = (int) $user['id'];
        update_user_locale($userId, 'ar');

        $mails = $this->captureMail(static fn () => send_password_changed_email($userId));
        self::assertCount(1, $mails);
        self::assertSame(['mails@example.com', 'Mail Reader', 'تم تغيير كلمة المرور لحسابك في BookWise'], [$mails[0]['to'], $mails[0]['name'], $mails[0]['subject']]);
        self::assertStringStartsWith('مرحبًا Mail Reader،', $mails[0]['body']);

        $mails = $this->captureMail(static fn () => send_account_deleted_email(['Eaddress' => 'mails@example.com', 'locale' => 'en']));
        self::assertSame(['mails@example.com', '', 'Your BookWise account was deleted'], [$mails[0]['to'], $mails[0]['name'], $mails[0]['subject']]);
        self::assertSame('en', current_locale());

        // With verification required, an unconfirmed account's name is left out.
        $this->setEnv('REQUIRE_EMAIL_VERIFICATION', 'true');
        $mails = $this->captureMail(static fn () => send_password_changed_email($userId));
        self::assertSame('', $mails[0]['name']);
        self::assertStringStartsWith('مرحبًا،', $mails[0]['body']);
    }

    /* -----------------------------------------------------------------
     * Localized form messages
     * --------------------------------------------------------------- */

    public function testEnglishMessagesMatchTheValidationRules(): void
    {
        $long = str_repeat('n', NAME_MAX_LENGTH + 1);
        foreach (['', 'not-an-email', str_repeat('a', 250) . '@example.com', 'reader@example.com'] as $email) {
            self::assertSame(validate_email($email), email_error($email), $email);
        }
        foreach (['', $long, '<b>', "Tab\tName", 'Valid Name'] as $name) {
            self::assertSame(validate_person_name($name), person_name_error($name), $name);
        }
        foreach ([['short', 'short'], [str_repeat('x', 73), str_repeat('x', 73)], ['long-enough-1', 'different-1'], ['long-enough-1', 'long-enough-1']] as [$password, $confirmation]) {
            $expected = validate_new_password($password, $confirmation);
            $errors = new_password_errors($password, $confirmation, 'password', 'password_confirmation');
            self::assertSame($expected, $errors === [] ? null : reset($errors));
        }
        foreach (['', str_repeat('g', 101), '<i>', 'Sci-Fi/Fantasy'] as $genre) {
            self::assertSame(validate_favorite_genre($genre), favorite_genre_error($genre), $genre);
        }
        foreach (['', '2023-02-30', '17/05/1990', '1899-12-31', (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'), '1990-05-17'] as $date) {
            self::assertSame(validate_date_of_birth($date), date_of_birth_error($date), $date);
        }
    }

    public function testMessagesAreTranslatedAndPointAtTheRightField(): void
    {
        use_locale('ar');
        try {
            self::assertSame('يُرجى إدخال بريدك الإلكتروني.', email_error(''));
            self::assertSame('يجب ألا يزيد الاسم على 100 حرف.', person_name_error(str_repeat('ن', 101)));
            self::assertSame(['new' => 'يجب ألا تقل كلمة المرور عن 8 أحرف.'], new_password_errors('short', 'short', 'new', 'confirm'));
            self::assertSame(['confirm' => 'كلمتا المرور غير متطابقتين.'], new_password_errors('long-enough-1', 'different-1', 'new', 'confirm'));
            self::assertSame(['new' => 'يجب ألا يزيد طول كلمة المرور على 72 بايت.'], new_password_errors(str_repeat('x', 73), str_repeat('x', 73), 'new', 'confirm'));
            self::assertSame('يُرجى إدخال تاريخ ميلاد صالح.', date_of_birth_error('1990-02-30'));
        } finally {
            use_locale(null);
        }
    }

    public function testUploadErrorsAreTranslated(): void
    {
        $messages = [
            'Profile pictures must be 2 MB or smaller.',
            'The picture could not be uploaded. Please try again.',
            'The selected file is empty. Please choose an image.',
            'Please upload a JPEG, PNG, GIF or WebP image.',
            'Please upload a valid JPEG, PNG, GIF or WebP image.',
            'Images must be at most 4000×4000 pixels.',
        ];
        foreach ($messages as $message) {
            self::assertSame($message, upload_error_message(new UploadException($message)), 'English stays identical');
            use_locale('ar');
            try {
                // Only the format names stay in Latin script.
                $arabic = str_replace(['JPEG', 'WebP', 'PNG', 'GIF'], '', upload_error_message(new UploadException($message)));
                self::assertDoesNotMatchRegularExpression('/[A-Za-z]{2,}/', $arabic, $message);
            } finally {
                use_locale(null);
            }
        }
        self::assertSame('Something new', upload_error_message(new UploadException('Something new')), 'Unknown messages pass through');
    }

    public function testTranslatedHtmlKeepsMarkupOnlyWhereGiven(): void
    {
        $email = '<b>x</b>@example.com';
        self::assertSame(
            'Set a new password for <strong>&lt;b&gt;x&lt;/b&gt;@example.com</strong>.',
            t_html('auth.reset.form_intro', ['email' => '<strong>' . e($email) . '</strong>'])
        );
        self::assertSame('Placed &lt;now&gt;', t_html('account.orders.placed', [], ['date' => '<now>']), 'Plain replacements are escaped');
    }
}
