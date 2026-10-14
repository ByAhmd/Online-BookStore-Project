<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\AccountHelpers;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;

/**
 * Sign-up with email verification (REQUIRE_EMAIL_VERIFICATION=true) through
 * the real pages: identical answers for new and registered addresses,
 * confirmation links, unconfirmed sign-in, resending and throttling.
 */
final class EmailVerificationTest extends HttpTestCase
{
    use AccountHelpers;

    private const PASSWORD = 'a-Strong-passphrase-1';
    private const CHECK_INBOX = 'Check your inbox to confirm your email. We have sent a message to the address you entered.';
    private const RESENT = "If an account with that email is waiting for confirmation, we've sent a new link. It expires in 24 hours.";
    private const VERIFY_SUBJECT = 'Confirm your BookWise email address';
    private const NOTICE_SUBJECT = 'Someone tried to sign up with your email address';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureServer(['REQUIRE_EMAIL_VERIFICATION' => 'true']);
        $this->setEnv('REQUIRE_EMAIL_VERIFICATION', 'true');
    }

    /**
     * Mails with the given subject sent to $email since the test started.
     *
     * @return list<array{subject: string, body: string}>
     */
    private function mailsWithSubject(string $email, string $subject): array
    {
        return array_values(array_filter($this->mailsTo($email), static fn (array $mail): bool => $mail['subject'] === $subject));
    }

    private function verificationLink(string $email): string
    {
        $mails = $this->mailsWithSubject($email, self::VERIFY_SUBJECT);
        self::assertNotSame([], $mails, "No verification email for $email");
        return self::tokenLink('verify_email.php', $mails[count($mails) - 1]['body']);
    }

    private function resend(HttpClient $client, string $email): HttpResponse
    {
        $page = $client->get('index.php?form=verify');
        $this->assertHtmlPage($page);
        return $client->submitForm($page, 'resend_verification.php', ['email' => $email]);
    }

    /**
     * What a visitor can observe about one sign-up attempt.
     *
     * @return array<string, mixed>
     */
    private function observeSignUp(string $email, string $name): array
    {
        $client = $this->client();
        $client->get('index.php?form=signup');
        $session = $client->cookie(self::SESSION_COOKIE);
        $response = $this->signUp($client, $email, $name, self::PASSWORD);
        $this->assertRedirect($response, 'index.php?form=verify');
        self::assertSame($session, $client->cookie(self::SESSION_COOKIE), 'Signing up does not sign in');
        $page = $client->follow($response);
        $this->assertHtmlPage($page);
        $this->assertFlash($page, 'success', self::CHECK_INBOX);
        self::assertFalse($this->isSignedInCustomer($client));
        return [
            'status' => $response->status,
            'location' => $response->header('Location'),
            'cookies' => count($response->headerValues('Set-Cookie')),
            'page' => self::normalizeBody($page->body, [$email]),
        ];
    }

    public function testSignUpEmailsAConfirmationLinkThatSignsTheCustomerIn(): void
    {
        $client = $this->client();
        $email = self::uniqueEmail('confirm');
        $client->get('index.php?form=signup');
        $guestSession = $client->cookie(self::SESSION_COOKIE);

        $response = $this->signUp($client, '  ' . strtoupper($email) . ' ', 'Confirm Reader', self::PASSWORD);
        $this->assertRedirect($response, 'index.php?form=verify');
        self::assertSame($guestSession, $client->cookie(self::SESSION_COOKIE));
        $page = $client->follow($response);
        $document = $this->assertHtmlPage($page);
        $this->assertFlash($page, 'success', self::CHECK_INBOX);
        self::assertFalse($document->first('//section[@id="panel-verify"]')?->hasAttribute('hidden'), 'The confirmation panel is shown');
        self::assertSame($email, $document->form('resend_verification.php')->field('email'), 'The resend form is prefilled');
        self::assertFalse($this->isSignedInCustomer($client));

        $account = find_user_account_by_email($email);
        self::assertNotNull($account, 'The email is stored normalized');
        self::assertNull($account['email_verified_at']);
        self::assertSame('en', $account['locale']);

        $mails = $this->mailsTo($email);
        self::assertCount(1, $mails);
        self::assertSame(self::VERIFY_SUBJECT, $mails[0]['subject']);
        self::assertStringNotContainsString('Confirm Reader', $mails[0]['body'], 'No user-supplied text in mail to an unconfirmed address');
        $link = $this->verificationLink($email);
        self::assertStringStartsWith(self::server()->baseUrl . '/verify_email.php?token=', $link, 'The link uses APP_URL');
        self::assertSame(hash('sha256', self::tokenOf($link)), db_value('SELECT token FROM email_verifications'), 'Only a hash is stored');

        // Opening the link confirms the address and signs in, with a fresh session id.
        $confirmed = $client->get($link);
        $this->assertRedirect($confirmed, 'Home_Page.php');
        self::assertSame('no-referrer', $confirmed->header('Referrer-Policy'), 'The token must not leak through Referer');
        self::assertStringContainsString('no-store', (string) $confirmed->header('Cache-Control'));
        self::assertNotSame($guestSession, $client->cookie(self::SESSION_COOKIE));
        $home = $client->follow($confirmed);
        $this->assertHtmlPage($home);
        $this->assertFlash($home, 'success', 'Thank you, your email address is confirmed. Welcome to BookWise, Confirm Reader!');
        self::assertTrue($this->isSignedInCustomer($client));
        self::assertTrue($this->isVerified($account['id']));
        self::assertSame(0, $this->countRows('email_verifications'));

        // The link is single use.
        $replay = $this->client()->get($link);
        $invalid = $this->assertHtmlPage($replay, 404);
        self::assertSame('This confirmation link is invalid or has expired', $invalid->text('//h1'));
        self::assertSame('no-referrer', $replay->header('Referrer-Policy'));
        self::assertStringContainsString('no-store', (string) $replay->header('Cache-Control'));
        self::assertTrue($invalid->hasForm('resend_verification.php'), 'The page offers a new link');

        // From now on the password signs in normally.
        $this->assertRedirect($this->logIn($this->client(), $email, self::PASSWORD), 'Home_Page.php');
    }

    public function testNewRegisteredAndAdministratorAddressesGetTheSameAnswer(): void
    {
        $owner = $this->createUser(self::uniqueEmail('owner'), 'Real Owner');
        $this->markVerified((int) $owner['id']);
        $ownerHash = db_value('SELECT pass FROM users WHERE id = ?', [$owner['id']]);
        $pending = $this->createUser(self::uniqueEmail('pending'), 'Pending Owner');
        $admin = $this->createAdmin(self::uniqueEmail('admin'), 'Store Admin');
        $new = self::uniqueEmail('fresh');

        $answers = [];
        foreach ([$new, (string) $owner['Eaddress'], (string) $pending['Eaddress'], $admin['Eaddress']] as $email) {
            $answers[] = $this->observeSignUp($email, 'Someone Else');
        }
        self::assertSame($answers[0], $answers[1], 'A registered customer address must look like a new one');
        self::assertSame($answers[0], $answers[2], 'An unconfirmed customer address must look like a new one');
        self::assertSame($answers[0], $answers[3], 'An administrator address must look like a new one');

        // Behind the scenes: a confirmation link for the new address only.
        self::assertCount(1, $this->mailsWithSubject($new, self::VERIFY_SUBJECT));
        foreach ([(string) $owner['Eaddress'], (string) $pending['Eaddress']] as $email) {
            $mails = $this->mailsTo($email);
            self::assertCount(1, $mails, "Exactly one notice to $email");
            self::assertSame(self::NOTICE_SUBJECT, $mails[0]['subject']);
            self::assertStringContainsString(self::server()->baseUrl . '/index.php?form=reset', $mails[0]['body']);
            self::assertStringNotContainsString('verify_email.php', $mails[0]['body'], 'Never a confirmation link for an existing account');
        }
        self::assertSame([], $this->mailsTo($admin['Eaddress']), 'Administrators get nothing');

        // Nothing about the existing accounts changed.
        self::assertSame(['Real Owner', $ownerHash], array_values(db_one('SELECT name, pass FROM users WHERE id = ?', [$owner['id']]) ?? []));
        self::assertSame('Pending Owner', db_value('SELECT name FROM users WHERE id = ?', [$pending['id']]));
        self::assertSame(0, $this->countRows('users', 'Eaddress = ?', [$admin['Eaddress']]));
        self::assertSame(1, $this->countRows('users', 'Eaddress = ?', [$owner['Eaddress']]));
    }

    public function testEmailsAreThrottledWithoutChangingTheAnswer(): void
    {
        $email = self::uniqueEmail('impatient');
        $this->observeSignUp($email, 'Impatient Reader');
        $firstLink = $this->verificationLink($email);

        // Resending and signing up again within the cooldown: same answers, no new email.
        $client = $this->client();
        $response = $this->resend($client, $email);
        $this->assertRedirect($response, 'index.php?form=verify');
        $this->assertFlash($client->follow($response), 'success', self::RESENT);
        $this->observeSignUp($email, 'Impatient Again');
        self::assertCount(1, $this->mailsTo($email));

        // After the cooldown a new link replaces the old one.
        $this->ageAccountEmails();
        $response = $this->resend($client, $email);
        $this->assertFlash($client->follow($response), 'success', self::RESENT);
        self::assertCount(2, $this->mailsTo($email));
        $secondLink = $this->verificationLink($email);
        self::assertNotSame($firstLink, $secondLink);
        $this->assertHtmlPage($this->client()->get($firstLink), 404);
        $this->assertRedirect($this->client()->get($secondLink), 'Home_Page.php');

        // Confirmed, unknown and administrator addresses get the same answer and no email.
        $admin = $this->createAdmin(self::uniqueEmail('admin'));
        $this->ageAccountEmails();
        foreach ([$email, self::uniqueEmail('nobody'), $admin['Eaddress']] as $address) {
            $visitor = $this->client();
            $this->assertFlash($visitor->follow($this->resend($visitor, $address)), 'success', self::RESENT);
        }
        self::assertCount(2, $this->mailsTo($email));
        self::assertSame([], $this->mailsTo($admin['Eaddress']));
    }

    public function testUnconfirmedLoginRevealsNothingWithoutThePassword(): void
    {
        $user = $this->createUser(self::uniqueEmail('unconfirmed'), 'Unconfirmed Reader');
        $email = (string) $user['Eaddress'];

        // Wrong password: the usual generic answer.
        $client = $this->client();
        $response = $this->logIn($client, $email, 'wrong-password-1');
        $this->assertRedirect($response, 'index.php?form=login');
        $page = $client->follow($response);
        self::assertSame([['type' => 'error', 'message' => 'Incorrect email or password.']], $page->document()->flashes());
        self::assertSame(1, $this->countRows('login_attempts', 'email = ?', [$email]));

        // Right password: not signed in, told to confirm first, with the resend form.
        $client->get('index.php?form=login');
        $session = $client->cookie(self::SESSION_COOKIE);
        $response = $this->logIn($client, $email, self::DEFAULT_PASSWORD);
        $this->assertRedirect($response, 'index.php?form=verify');
        self::assertSame($session, $client->cookie(self::SESSION_COOKIE), 'No new (signed-in) session');
        $page = $client->follow($response);
        $document = $this->assertHtmlPage($page);
        $this->assertFlash($page, 'info', 'Please confirm your email first. Open the link we emailed you, or request a new one below.');
        self::assertSame($email, $document->form('resend_verification.php')->field('email'));
        self::assertFalse($this->isSignedInCustomer($client));
        self::assertSame(0, $this->countRows('login_attempts', 'email = ?', [$email]), 'The right password is not a failure');

        // Resending from there delivers a working link.
        $this->assertRedirect($client->submitForm($page, 'resend_verification.php'), 'index.php?form=verify');
        $this->assertRedirect($client->get($this->verificationLink($email)), 'Home_Page.php');
        self::assertTrue($this->isSignedInCustomer($client));
    }

    public function testThrottlingStillComesFirstForUnconfirmedAccounts(): void
    {
        $user = $this->createUser(self::uniqueEmail('hammered'));
        $email = (string) $user['Eaddress'];
        $client = $this->client();
        for ($attempt = 1; $attempt <= LOGIN_MAX_ATTEMPTS_PER_EMAIL; $attempt++) {
            $this->logIn($client, $email, "wrong-password-$attempt");
        }

        $response = $this->logIn($client, $email, self::DEFAULT_PASSWORD);
        $this->assertRedirect($response, 'index.php?form=login');
        self::assertSame(
            [['type' => 'error', 'message' => 'Too many failed sign-in attempts. Please wait 15 minutes and try again.']],
            $client->follow($response)->document()->flashes()
        );
    }

    public function testInvalidExpiredAndForgedLinks(): void
    {
        $user = $this->createUser(self::uniqueEmail('late'));
        $userId = (int) $user['id'];
        $expired = $this->issueVerificationToken($userId, 'NOW() - INTERVAL 1 MINUTE');
        $visitor = $this->client();

        foreach (['', '?token=', '?token=abc', '?token=' . str_repeat('g', 64), '?token=' . strtoupper($expired), '?token=' . $expired, '?token[]=' . $expired] as $query) {
            $response = $visitor->get('verify_email.php' . $query);
            $document = $this->assertHtmlPage($response, 404);
            self::assertSame('This confirmation link is invalid or has expired', $document->text('//h1'), $query);
            self::assertSame('no-referrer', $response->header('Referrer-Policy'), $query);
        }
        self::assertFalse($this->isVerified($userId));
        self::assertFalse($this->isSignedInCustomer($visitor));

        // Only GET uses a token: POST is refused and HEAD (mail link checkers) leaves it unused.
        $valid = $this->issueVerificationToken($userId);
        $this->assertHtmlPage($visitor->postWithToken('verify_email.php?token=' . $valid), 405);
        $head = $visitor->request('HEAD', 'verify_email.php?token=' . $valid);
        $this->assertStatus(200, $head);
        self::assertSame($userId, find_valid_email_verification($valid));
        self::assertFalse($this->isVerified($userId));
        $this->assertRedirect($visitor->get('verify_email.php?token=' . $valid), 'Home_Page.php');
    }

    public function testResendRequiresPostCsrfAndAValidAddress(): void
    {
        $user = $this->createUser(self::uniqueEmail('csrf'));
        $email = (string) $user['Eaddress'];
        $client = $this->client();

        $this->assertHtmlPage($client->get('resend_verification.php'), 405);
        $this->assertHtmlPage($client->post('resend_verification.php', ['email' => $email]), 419);
        $this->assertHtmlPage($client->post('resend_verification.php', ['email' => $email, '_token' => str_repeat('0', 64)]), 419);
        self::assertSame([], $this->mailsTo($email));

        $response = $this->resend($client, 'not-an-email');
        $this->assertRedirect($response, 'index.php?form=verify');
        $document = $this->assertHtmlPage($client->follow($response));
        self::assertSame('Please enter a valid email address.', $document->fieldError('verify-email-error'));
        self::assertSame('true', $document->first('//input[@id="verify-email"]')?->getAttribute('aria-invalid'));
    }

    public function testPasswordResetConfirmsTheAddress(): void
    {
        $user = $this->createUser(self::uniqueEmail('reset-confirms'));
        $email = (string) $user['Eaddress'];
        $userId = (int) $user['id'];

        $client = $this->client();
        $page = $client->get('index.php?form=reset');
        $this->assertRedirect($client->submitForm($page, 'reset_password.php', ['email' => $email]), 'index.php?form=login');
        $resetMails = array_values(array_filter($this->mailsTo($email), static fn (array $mail): bool => $mail['subject'] === 'Reset your BookWise password'));
        self::assertCount(1, $resetMails);
        self::assertStringStartsWith('Hello,', $resetMails[0]['body'], 'An unconfirmed account name is left out');
        $link = self::tokenLink('reset_form.php', $resetMails[0]['body']);

        $form = $client->get($link);
        $this->assertStatus(200, $form);
        $done = $client->submitForm($form, 'update_password.php', ['password' => 'Reset-new-password-5', 'password_confirmation' => 'Reset-new-password-5']);
        $this->assertRedirect($done, 'index.php?form=login');

        self::assertTrue($this->isVerified($userId), 'The reset link proved ownership of the mailbox');
        $this->assertRedirect($this->logIn($this->client(), $email, 'Reset-new-password-5'), 'Home_Page.php');
    }

    public function testAdministratorsAndConfirmedAccountsSignInAsBefore(): void
    {
        $admin = $this->createAdmin(self::uniqueEmail('admin'), 'Verified Boss');
        $this->assertRedirect($this->logIn($this->client(), $admin['Eaddress'], self::DEFAULT_PASSWORD), 'admin_dashboard.php');

        // Accounts the migration marked as verified (email_verified_at set) are unaffected.
        $user = $this->createUser(self::uniqueEmail('migrated'), 'Migrated Reader');
        db_run("UPDATE users SET email_verified_at = '2024-05-01 10:00:00' WHERE id = ?", [$user['id']]);
        $client = $this->client();
        $response = $this->logIn($client, (string) $user['Eaddress'], self::DEFAULT_PASSWORD);
        $this->assertRedirect($response, 'Home_Page.php');
        $this->assertFlash($client->follow($response), 'success', 'Welcome back, Migrated Reader!');
    }

    public function testDemoModeExplainsThatNoEmailIsSent(): void
    {
        $notice = 'This demo store does not send email, so password reset and email confirmation links cannot be delivered.';
        $guest = $this->client();
        $this->assertNotPageText($notice, $guest->get('index.php?form=reset'));

        $this->configureServer(['APP_DEMO' => 'true']);
        foreach (['index.php?form=reset', 'index.php?form=verify'] as $path) {
            $page = $guest->get($path);
            $document = $this->assertHtmlPage($page);
            self::assertStringContainsString($notice, $document->text('//p[contains(@class, "auth-notice")]'), $path);
        }
    }

    public function testWithoutVerificationTheConfirmationPagesStepAside(): void
    {
        $this->configureServer(['REQUIRE_EMAIL_VERIFICATION' => 'false']);
        $guest = $this->client();

        $page = $guest->get('index.php?form=verify');
        $document = $this->assertHtmlPage($page);
        self::assertNull($document->first('//section[@id="panel-verify"]'));
        self::assertFalse($document->first('//section[@id="panel-login"]')?->hasAttribute('hidden'), 'Unknown panels fall back to login');
        $this->assertRedirect($guest->postWithToken('resend_verification.php', ['email' => self::uniqueEmail('x')]), 'index.php?form=login');

        // Sign-up signs in immediately, as before.
        $this->assertRedirect($this->signUp($guest, self::uniqueEmail('instant'), 'Instant Reader', self::PASSWORD), 'Home_Page.php');
    }
}
