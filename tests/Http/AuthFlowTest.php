<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpTestCase;

/**
 * Registration, login, logout and login throttling through the real forms.
 */
final class AuthFlowTest extends HttpTestCase
{
    private const PASSWORD = 'a-Strong-passphrase-1';

    public function testRegistrationCreatesAnAccountAndSignsIn(): void
    {
        $client = $this->client();
        $email = self::uniqueEmail('signup');

        $client->get('index.php?form=signup');
        $guestSession = $client->cookie(self::SESSION_COOKIE);
        self::assertNotNull($guestSession);

        $response = $this->signUp($client, '  ' . strtoupper($email) . ' ', 'New Reader', self::PASSWORD);
        $this->assertRedirect($response, 'Home_Page.php');
        self::assertNotSame($guestSession, $client->cookie(self::SESSION_COOKIE), 'Signing up must issue a new session id');

        $user = db_one('SELECT id, name, pass FROM users WHERE Eaddress = ?', [$email]);
        self::assertNotNull($user, 'The email is stored normalized (trimmed, lower-case)');
        self::assertSame('New Reader', $user['name']);
        self::assertNotSame(self::PASSWORD, $user['pass']);
        self::assertTrue(password_verify(self::PASSWORD, (string) $user['pass']), 'Password stored with password_hash()');

        $home = $client->follow($response);
        $this->assertHtmlPage($home);
        $this->assertFlash($home, 'success', 'Welcome to BookWise, New Reader! Your account is ready.');
        $this->assertPageText('Hello, New Reader!', $home);
        self::assertTrue($this->isSignedInCustomer($client));

        $welcome = $this->mailsTo($email);
        self::assertCount(1, $welcome, 'A welcome email is written to mail.log in the run\'s LOG_DIR');
        self::assertSame('Welcome to BookWise!', $welcome[0]['subject']);
        self::assertStringContainsString(self::server()->baseUrl . '/Explore_Page.php', $welcome[0]['body'], 'Links use APP_URL');
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $existing = $this->createUser(self::uniqueEmail('taken'), 'First Owner');
        $client = $this->client();

        $response = $this->signUp($client, (string) $existing['Eaddress'], 'Second Owner', self::PASSWORD);
        $this->assertRedirect($response, 'index.php?form=signup');

        $page = $client->follow($response);
        $document = $this->assertHtmlPage($page);
        self::assertSame('An account with this email already exists.', $document->fieldError('signup-email-error'));
        self::assertFalse($document->first('//section[@id="panel-signup"]')?->hasAttribute('hidden'), 'The sign-up panel is shown again');
        self::assertSame((string) $existing['Eaddress'], $document->first('//input[@id="signup-email"]')?->getAttribute('value'));
        self::assertSame(1, $this->countRows('users', 'Eaddress = ?', [$existing['Eaddress']]));
        self::assertSame('First Owner', db_value('SELECT name FROM users WHERE id = ?', [$existing['id']]));
        self::assertFalse($this->isSignedInCustomer($client));
    }

    public function testMismatchedAndWeakPasswordsAreRejected(): void
    {
        $client = $this->client();
        $email = self::uniqueEmail('mismatch');

        $response = $this->signUp($client, $email, 'Mismatch', self::PASSWORD, self::PASSWORD . 'x');
        $this->assertRedirect($response, 'index.php?form=signup');
        $document = $client->follow($response)->document();
        self::assertSame('Passwords do not match.', $document->fieldError('signup-password-confirmation-error'));
        self::assertSame('', $document->fieldError('signup-password-error'));

        $response = $this->signUp($client, $email, 'Weak', 'short');
        $this->assertRedirect($response, 'index.php?form=signup');
        self::assertSame('Password must be at least 8 characters long.', $client->follow($response)->document()->fieldError('signup-password-error'));

        $response = $this->signUp($client, 'not-an-email', '<b>Bad</b>', self::PASSWORD);
        $document = $client->follow($response)->document();
        self::assertSame('Please enter a valid email address.', $document->fieldError('signup-email-error'));
        self::assertSame('Name contains characters that are not allowed.', $document->fieldError('signup-name-error'));

        self::assertSame(0, $this->countRows('users'));
        self::assertSame([], $this->mailsTo($email));
    }

    public function testCustomerLoginRegeneratesTheSessionId(): void
    {
        $user = $this->createUser(self::uniqueEmail('login'), 'Login Reader');
        $client = $this->client();

        $client->get('index.php?form=login');
        $guestSession = (string) $client->cookie(self::SESSION_COOKIE);
        self::assertNotSame('', $guestSession);

        $response = $this->logIn($client, strtoupper((string) $user['Eaddress']), self::DEFAULT_PASSWORD);
        $this->assertRedirect($response, 'Home_Page.php');
        $userSession = (string) $client->cookie(self::SESSION_COOKIE);
        self::assertNotSame($guestSession, $userSession, 'Login must issue a new session id (session fixation)');

        $home = $client->follow($response);
        $this->assertFlash($home, 'success', 'Welcome back, Login Reader!');
        self::assertTrue($this->isSignedInCustomer($client));

        // The pre-login (guest) session id is dead: planting it gives a guest.
        $attacker = $this->client();
        $attacker->setCookie(self::SESSION_COOKIE, $guestSession);
        $this->assertRedirect($attacker->get('cart.php'), 'index.php?form=login');
        self::assertNotSame($guestSession, $attacker->cookie(self::SESSION_COOKIE), 'Strict mode replaces the unknown id');
    }

    public function testAdministratorLoginGoesToTheDashboard(): void
    {
        $admin = $this->createAdmin(self::uniqueEmail('boss'), 'Store Boss');
        $client = $this->client();
        $client->get('index.php');
        $guestSession = $client->cookie(self::SESSION_COOKIE);

        $response = $this->logIn($client, $admin['Eaddress'], self::DEFAULT_PASSWORD);
        $this->assertRedirect($response, 'admin_dashboard.php');
        self::assertNotSame($guestSession, $client->cookie(self::SESSION_COOKIE));

        $dashboard = $client->follow($response);
        $this->assertHtmlPage($dashboard);
        $this->assertFlash($dashboard, 'success', 'Welcome back, Store Boss!');
        $this->assertPageText('Signed in as Store Boss', $dashboard);
    }

    public function testWrongPasswordAndUnknownEmailGetTheSameGenericAnswer(): void
    {
        $user = $this->createUser(self::uniqueEmail('known'));
        $unknownEmail = self::uniqueEmail('unknown');

        $answers = [];
        foreach ([[(string) $user['Eaddress'], 'wrong-password-1'], [$unknownEmail, 'wrong-password-1']] as [$email, $password]) {
            $client = $this->client();
            $response = $this->logIn($client, $email, $password);
            $this->assertRedirect($response, 'index.php?form=login');
            $page = $client->follow($response);
            $this->assertHtmlPage($page);
            $this->assertFlash($page, 'error', 'Incorrect email or password.');
            self::assertFalse($this->isSignedInCustomer($client));
            $answers[] = [
                'status' => $response->status,
                'location' => $response->header('Location'),
                'cookies' => count($response->headerValues('Set-Cookie')),
                'page' => self::normalizeBody($page->body, [$email]),
            ];
        }
        self::assertSame($answers[0], $answers[1], 'A wrong password and an unknown email must be indistinguishable');
        self::assertSame(2, $this->countRows('login_attempts'));
    }

    public function testLogoutRequiresPostWithCsrfTokenAndEndsTheSession(): void
    {
        [$client] = $this->signedInCustomer();
        $session = (string) $client->cookie(self::SESSION_COOKIE);

        // GET is a harmless redirect that keeps the session.
        $this->assertRedirect($client->get('logout.php'), 'Home_Page.php');
        self::assertTrue($this->isSignedInCustomer($client));

        // POST without (or with a wrong) token is refused.
        $this->assertStatus(419, $client->post('logout.php'));
        $this->assertStatus(419, $client->post('logout.php', ['_token' => str_repeat('0', 64)]));
        self::assertTrue($this->isSignedInCustomer($client));
        self::assertSame($session, $client->cookie(self::SESSION_COOKIE));

        $response = $client->submitForm($client->get('Home_Page.php'), 'logout.php');
        $this->assertRedirect($response, 'index.php?form=login');
        self::assertNotSame($session, $client->cookie(self::SESSION_COOKIE));
        $page = $client->follow($response);
        $this->assertFlash($page, 'info', 'You have been logged out.');
        self::assertFalse($this->isSignedInCustomer($client));

        // The old session id no longer signs anybody in.
        $replay = $this->client();
        $replay->setCookie(self::SESSION_COOKIE, $session);
        self::assertFalse($this->isSignedInCustomer($replay));
    }

    public function testFiveFailuresLockTheAccountEvenForTheRightPassword(): void
    {
        $user = $this->createUser(self::uniqueEmail('locked'));
        $email = (string) $user['Eaddress'];
        $client = $this->client();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $page = $client->follow($this->logIn($client, $email, "wrong-password-$attempt"));
            $this->assertFlash($page, 'error', 'Incorrect email or password.');
        }
        self::assertSame(5, $this->countRows('login_attempts', 'email = ?', [$email]));

        $response = $this->logIn($client, $email, self::DEFAULT_PASSWORD);
        $this->assertRedirect($response, 'index.php?form=login');
        $page = $client->follow($response);
        $this->assertFlash($page, 'error', 'Too many failed sign-in attempts. Please wait 15 minutes and try again.');
        self::assertFalse($this->isSignedInCustomer($client), 'A throttled login must not sign in');
        self::assertSame(5, $this->countRows('login_attempts', 'email = ?', [$email]), 'Blocked attempts are not counted again');

        // Other accounts from the same address are unaffected (per-IP limit is higher).
        $other = $this->createUser(self::uniqueEmail('other'));
        $this->assertRedirect($this->logIn($this->client(), (string) $other['Eaddress'], self::DEFAULT_PASSWORD), 'Home_Page.php');

        // The lock expires with the window.
        db_run('UPDATE login_attempts SET attempted_at = NOW() - INTERVAL 16 MINUTE WHERE email = ?', [$email]);
        $fresh = $this->client();
        $this->assertRedirect($this->logIn($fresh, $email, self::DEFAULT_PASSWORD), 'Home_Page.php');
        self::assertSame(0, $this->countRows('login_attempts', 'email = ?', [$email]), 'A successful login clears the failures');
    }

    public function testEmptyLoginFieldsShowFieldErrors(): void
    {
        $client = $this->client();
        $response = $this->logIn($client, '', '');
        $this->assertRedirect($response, 'index.php?form=login');
        $document = $client->follow($response)->document();
        self::assertSame('Please enter your email address.', $document->fieldError('login-email-error'));
        self::assertSame('Please enter your password.', $document->fieldError('login-password-error'));
        self::assertSame(0, $this->countRows('login_attempts'));
    }

    public function testIdleSessionsExpireAfterTwoHours(): void
    {
        [$client] = $this->signedInCustomer();
        $sessionFile = self::server()->runDirectory . DIRECTORY_SEPARATOR . 'sessions' . DIRECTORY_SEPARATOR . 'sess_' . $client->cookie(self::SESSION_COOKIE);
        self::assertFileExists($sessionFile);
        $idleFor = static function (int $seconds) use ($sessionFile): void {
            $data = (string) file_get_contents($sessionFile);
            // session.serialize_handler "php" (_last_activity|i:123;) or "php_serialize" (s:14:"_last_activity";i:123;)
            $aged = preg_replace('/(_last_activity(?:\|i:|";i:))\d+/', '${1}' . (time() - $seconds), $data, 1, $count);
            self::assertSame(1, $count, "No _last_activity in the session file:\n$data");
            file_put_contents($sessionFile, (string) $aged);
        };

        $idleFor(3600);
        self::assertTrue($this->isSignedInCustomer($client), 'One idle hour keeps the session');

        $idleFor(2 * 3600 + 60);
        $response = $client->get('cart.php');
        $this->assertRedirect($response, 'index.php?form=login');
        $page = $client->follow($response);
        $this->assertFlash($page, 'info', 'Your session expired. Please log in again.');
        self::assertFalse($this->isSignedInCustomer($client));
    }

    public function testNamesWithQuotesAndAmpersandsAreShownExactly(): void
    {
        $client = $this->client();
        $name = 'O\'Brien "Bo" & Co';
        $response = $this->signUp($client, self::uniqueEmail('quotes'), $name, self::PASSWORD);
        $this->assertRedirect($response, 'Home_Page.php');
        $home = $client->follow($response);
        $this->assertHtmlPage($home);
        $this->assertPageText('Hello, ' . $name . '!', $home);
        $this->assertFlash($home, 'success', 'Welcome to BookWise, ' . $name . '! Your account is ready.');
        self::assertStringContainsString(e($name), $home->body);
        $profile = $client->get('profilePage.php');
        self::assertSame($name, $profile->document()->form('profilePage.php', ['action' => 'update_profile'])->field('name'));
    }

    public function testUnknownFormActionIsABadRequest(): void
    {
        $client = new HttpClient(self::server()->baseUrl);
        $this->assertHtmlPage($client->postWithToken('Register.php', ['action' => 'delete_everything']), 400);
    }
}
