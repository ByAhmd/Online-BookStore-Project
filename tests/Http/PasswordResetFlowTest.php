<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;

/**
 * "Forgot your password?" end to end: request, email (mail.log in the run's
 * LOG_DIR), reset form, new password, single use and expiry.
 */
final class PasswordResetFlowTest extends HttpTestCase
{
    private const NEW_PASSWORD = 'brand-new-Passphrase-9';

    private function requestReset(HttpClient $client, string $email): HttpResponse
    {
        $page = $client->get('index.php?form=reset');
        $this->assertHtmlPage($page);
        return $client->submitForm($page, 'reset_password.php', ['email' => $email]);
    }

    /**
     * The reset link from the newest reset email to $email.
     */
    private function resetLink(string $email): string
    {
        $mails = array_values(array_filter($this->mailsTo($email), static fn (array $mail): bool => $mail['subject'] === 'Reset your BookWise password'));
        self::assertNotSame([], $mails, "No reset email for $email in mail.log");
        $body = $mails[count($mails) - 1]['body'];
        self::assertSame(1, preg_match('#(\S+/reset_form\.php\?token=[a-f0-9]{64})#', $body, $match), "No reset link in:\n$body");
        return $match[1];
    }

    public function testKnownAndUnknownEmailsGetIdenticalResponses(): void
    {
        $user = $this->createUser(self::uniqueEmail('forgetful'));
        $unknown = self::uniqueEmail('stranger');

        $answers = [];
        foreach ([(string) $user['Eaddress'], $unknown] as $email) {
            $client = $this->client();
            $response = $this->requestReset($client, $email);
            $this->assertRedirect($response, 'index.php?form=login');
            $page = $client->follow($response);
            $this->assertHtmlPage($page);
            $this->assertFlash($page, 'success', "If an account exists for that email, we've sent a password reset link. It expires in 60 minutes.");
            $answers[] = [$response->status, $response->header('Location'), count($response->headerValues('Set-Cookie')), self::normalizeBody($page->body, [$email])];
        }
        self::assertSame($answers[0], $answers[1], 'Known and unknown emails must be indistinguishable');

        self::assertCount(1, $this->mailsTo((string) $user['Eaddress']));
        self::assertSame([], $this->mailsTo($unknown));
        self::assertSame(1, $this->countRows('reset_tokens'));
        $link = $this->resetLink((string) $user['Eaddress']);
        self::assertStringStartsWith(self::server()->baseUrl . '/reset_form.php?token=', $link, 'The link uses APP_URL');
        $token = substr($link, -64);
        self::assertNull(db_value('SELECT 1 FROM reset_tokens WHERE token = ?', [$token]), 'Only a hash of the token is stored');
        self::assertSame(hash('sha256', $token), db_value('SELECT token FROM reset_tokens'));

        // An invalid address is reported on the field, not silently accepted.
        $client = $this->client();
        $response = $this->requestReset($client, 'not-an-email');
        $this->assertRedirect($response, 'index.php?form=reset');
        self::assertSame('Please enter a valid email address.', $client->follow($response)->document()->fieldError('reset-email-error'));
    }

    public function testResetChangesThePasswordOnceAndOnlyOnce(): void
    {
        $user = $this->createUser(self::uniqueEmail('resetter'));
        $email = (string) $user['Eaddress'];
        $this->assertRedirect($this->requestReset($this->client(), $email), 'index.php?form=login');
        $link = $this->resetLink($email);

        $visitor = $this->client();
        $form = $visitor->get($link);
        $document = $this->assertHtmlPage($form);
        self::assertSame('no-referrer', $form->header('Referrer-Policy'), 'The token must not leak through Referer');
        self::assertStringContainsString('no-store', (string) $form->header('Cache-Control'));
        self::assertSame('Choose a new password', $document->text('//h1'));
        $this->assertPageText('Set a new password for ' . $email . '.', $form);
        self::assertSame(substr($link, -64), $document->form('update_password.php')->field('token'));

        // Validation errors keep the link usable.
        $mismatch = $visitor->submitForm($form, 'update_password.php', ['password' => self::NEW_PASSWORD, 'password_confirmation' => 'something-else-1']);
        $this->assertRedirect($mismatch, 'reset_form.php?token=' . substr($link, -64));
        self::assertSame('Passwords do not match.', $visitor->follow($mismatch)->document()->fieldError('new-password-confirmation-error'));
        $short = $visitor->submitForm($visitor->get($link), 'update_password.php', ['password' => 'short', 'password_confirmation' => 'short']);
        self::assertSame('Password must be at least 8 characters long.', $visitor->follow($short)->document()->fieldError('new-password-error'));
        self::assertSame(1, $this->countRows('reset_tokens'));

        $form = $visitor->get($link);
        $done = $visitor->submitForm($form, 'update_password.php', ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
        $this->assertRedirect($done, 'index.php?form=login');
        $this->assertFlash($visitor->follow($done), 'success', 'Your password has been updated. You can now log in.');
        self::assertSame(0, $this->countRows('reset_tokens'));

        // Old password fails, new one works.
        $oldLogin = $this->client();
        $this->assertFlash($oldLogin->follow($this->logIn($oldLogin, $email, self::DEFAULT_PASSWORD)), 'error', 'Incorrect email or password.');
        $this->assertRedirect($this->logIn($this->client(), $email, self::NEW_PASSWORD), 'Home_Page.php');

        // The link is dead now.
        $expired = $visitor->get($link);
        $this->assertHtmlPage($expired, 404);
        self::assertSame('This reset link is invalid or has expired', $expired->document()->text('//h1'));
        self::assertSame('no-referrer', $expired->header('Referrer-Policy'));
        $replay = $visitor->submitForm($form, 'update_password.php', ['password' => 'third-password-123', 'password_confirmation' => 'third-password-123']);
        $this->assertRedirect($replay, 'index.php?form=reset');
        $this->assertFlash($visitor->follow($replay), 'error', 'This reset link is invalid or has expired. Please request a new one.');
        $this->assertRedirect($this->logIn($this->client(), $email, self::NEW_PASSWORD), 'Home_Page.php');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $user = $this->createUser(self::uniqueEmail('slow'));
        $email = (string) $user['Eaddress'];
        $this->assertRedirect($this->requestReset($this->client(), $email), 'index.php?form=login');
        $link = $this->resetLink($email);
        $visitor = $this->client();
        $form = $visitor->get($link);
        $this->assertStatus(200, $form);

        db_run('UPDATE reset_tokens SET expiry = NOW() - INTERVAL 1 MINUTE WHERE email = ?', [$email]);

        $this->assertHtmlPage($visitor->get($link), 404);
        $response = $visitor->submitForm($form, 'update_password.php', ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
        $this->assertRedirect($response, 'index.php?form=reset');
        $this->assertFlash($visitor->follow($response), 'error', 'This reset link is invalid or has expired. Please request a new one.');
        $this->assertRedirect($this->logIn($this->client(), $email, self::DEFAULT_PASSWORD), 'Home_Page.php', 303, 'The password is unchanged');

        // Malformed tokens never reach the database lookup.
        foreach (['', 'abc', str_repeat('g', 64), str_repeat('A', 64)] as $token) {
            $this->assertHtmlPage($visitor->get('reset_form.php?token=' . $token), 404);
        }
        $this->assertRedirect($visitor->postWithToken('update_password.php', ['token' => 'abc', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]), 'index.php?form=reset');
    }

    public function testRepeatedRequestsAreThrottledAndANewLinkReplacesTheOld(): void
    {
        $user = $this->createUser(self::uniqueEmail('impatient'));
        $email = (string) $user['Eaddress'];
        $this->assertRedirect($this->requestReset($this->client(), $email), 'index.php?form=login');
        $this->assertRedirect($this->requestReset($this->client(), $email), 'index.php?form=login');
        self::assertCount(1, $this->mailsTo($email), 'A second request within the cooldown sends nothing');
        $firstLink = $this->resetLink($email);

        // After the cooldown a new link is sent and the old one stops working.
        db_run('UPDATE reset_tokens SET expiry = NOW() + INTERVAL 50 MINUTE WHERE email = ?', [$email]);
        $this->assertRedirect($this->requestReset($this->client(), $email), 'index.php?form=login');
        self::assertCount(2, $this->mailsTo($email));
        $secondLink = $this->resetLink($email);
        self::assertNotSame($firstLink, $secondLink);
        $visitor = $this->client();
        $this->assertStatus(404, $visitor->get($firstLink));
        $this->assertStatus(200, $visitor->get($secondLink));
    }
}
