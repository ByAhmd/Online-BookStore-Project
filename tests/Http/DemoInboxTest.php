<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\AccountHelpers;
use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;

/**
 * The demo inbox (inbox.php) with APP_DEMO=true and MAIL_DRIVER=database:
 * a browser reads only the mail of the account it is signed in to and of
 * the accounts it created by signing up (in a renewed session), never mail
 * it merely triggered for someone else's address; messages are escaped,
 * link only to the store, keep their own language and direction, and go
 * when they expire or their account is deleted.
 */
final class DemoInboxTest extends HttpTestCase
{
    use AccountHelpers;

    private const PASSWORD = 'a-Strong-passphrase-1';
    private const VERIFY_SUBJECT = 'Confirm your BookWise email address';
    private const NOTICE = 'This demo store sends no real email. Emails to the account you are logged in to, and to the accounts you created in this browser session, appear in the demo inbox.'
        . " Once the session ends, for example when you log out, an account's emails can only be read by logging in to it.";
    private const GUEST_TEXT = 'Sign up, or log in, to read the emails this store sends you.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureServer(['APP_DEMO' => 'true', 'MAIL_DRIVER' => 'database', 'REQUIRE_EMAIL_VERIFICATION' => 'true']);
    }

    private function inbox(HttpClient $client): HtmlDocument
    {
        $page = $client->get('inbox.php');
        $document = $this->assertHtmlPage($page);
        self::assertSame('no-store', $page->header('Cache-Control'), 'Messages carry single-use links');
        return $document;
    }

    /**
     * The messages listed on an inbox page, newest first.
     *
     * @return list<array{subject: string, recipient: string, href: string}>
     */
    private static function listed(HtmlDocument $inbox): array
    {
        $items = [];
        foreach ($inbox->elements('//ul[' . HtmlDocument::hasClass('inbox-list') . ']/li/a') as $link) {
            $items[] = [
                'subject' => HtmlDocument::normalize($inbox->first('.//*[' . HtmlDocument::hasClass('inbox-item-subject') . ']', $link)?->textContent ?? ''),
                'recipient' => HtmlDocument::normalize($inbox->first('.//*[' . HtmlDocument::hasClass('inbox-item-recipient') . ']', $link)?->textContent ?? ''),
                'href' => $link->getAttribute('href'),
            ];
        }
        return $items;
    }

    /**
     * @return list<string>
     */
    private static function subjects(HtmlDocument $inbox): array
    {
        return array_column(self::listed($inbox), 'subject');
    }

    /**
     * Links inside a message's text.
     *
     * @return list<string>
     */
    private static function bodyLinks(HtmlDocument $message): array
    {
        return array_map(
            static fn (\DOMElement $link): string => $link->getAttribute('href'),
            $message->elements('//div[' . HtmlDocument::hasClass('inbox-message-body') . ']//a')
        );
    }

    /**
     * A confirmed customer signed in through the login form.
     *
     * @return array{0: HttpClient, 1: array<string, mixed>}
     */
    private function verifiedCustomer(string $prefix = 'inbox-customer'): array
    {
        $user = $this->createUser(self::uniqueEmail($prefix), 'Inbox Customer');
        $this->markVerified((int) $user['id']);
        $client = $this->client();
        $this->assertRedirect($this->logIn($client, (string) $user['Eaddress'], self::DEFAULT_PASSWORD), 'Home_Page.php');
        return [$client, $user];
    }

    private function storeMessage(string $recipient, string $subject, string $text = 'text', string $html = '<p>html</p>', string $locale = 'en', string $age = '0 SECOND'): int
    {
        db_run(
            "INSERT INTO demo_mail (recipient, subject, html, text, locale, created_at) VALUES (?, ?, ?, ?, ?, NOW() - INTERVAL $age)",
            [$recipient, $subject, $html, $text, $locale]
        );
        return (int) db()->lastInsertId();
    }

    private function confirm(HttpClient $client, string $link): HttpResponse
    {
        $form = $client->get($link);
        $this->assertHtmlPage($form);
        return $client->submitForm($form, 'verify_email.php', ['password' => self::PASSWORD]);
    }

    public function testTheInboxExistsOnlyInDemoModeWithTheDatabaseDriver(): void
    {
        foreach ([
            ['APP_DEMO' => 'false', 'MAIL_DRIVER' => 'log'],
            ['APP_DEMO' => 'true', 'MAIL_DRIVER' => 'log'],
            ['APP_DEMO' => 'true', 'MAIL_DRIVER' => 'null'],
            ['APP_DEMO' => 'false', 'MAIL_DRIVER' => 'database'],
        ] as $settings) {
            $this->configureServer($settings);
            $guest = $this->client();
            $where = json_encode($settings);
            $this->assertHtmlPage($guest->get('inbox.php'), 404);
            $home = $this->assertHtmlPage($guest->get('Home_Page.php'));
            self::assertSame(0, $home->count('//a[@href="inbox.php"]'), "No inbox link with $where");
        }

        $this->configureServer(['APP_DEMO' => 'true', 'MAIL_DRIVER' => 'database']);
        $guest = $this->client();
        $home = $this->assertHtmlPage($guest->get('Home_Page.php'));
        $link = $home->first('//nav//ul[' . HtmlDocument::hasClass('nav-links') . ']//a[@href="inbox.php"]');
        self::assertNotNull($link, 'The navigation links to the inbox');
        self::assertSame(['Inbox', 'Demo inbox'], [HtmlDocument::normalize($link->textContent), $link->getAttribute('aria-label')]);
        self::assertStringContainsString('emails go to the demo inbox instead of being sent', $home->text('//p[' . HtmlDocument::hasClass('demo-banner') . ']'));

        $inbox = $this->inbox($guest);
        self::assertSame('Demo inbox', $inbox->text('//h1'));
        self::assertSame('page', $inbox->first('//nav//a[@href="inbox.php"]')?->getAttribute('aria-current'));
        self::assertStringContainsString('are deleted after 48 hours, or earlier when the demo is reset.', $inbox->text());
        self::assertStringContainsString("to the accounts you created in this browser session. Once the session ends, for example when you log out, an account's emails can only be read by logging in to it.", $inbox->text());
        self::assertSame(self::GUEST_TEXT, $inbox->text('//div[' . HtmlDocument::hasClass('inbox-guest') . ']/p[1]'));
        self::assertSame([], self::listed($inbox));
    }

    public function testSignUpShowsTheConfirmationEmailOnlyInTheBrowserThatCreatedTheAccount(): void
    {
        $email = self::uniqueEmail('inbox-new');
        $creator = $this->client();
        $this->assertRedirect($this->signUp($creator, $email, 'Inbox Reader', self::PASSWORD), 'index.php?form=verify');

        $inbox = $this->inbox($creator);
        self::assertSame([$email], $inbox->texts('//ul[' . HtmlDocument::hasClass('inbox-addresses') . ']/li'));
        $listed = self::listed($inbox);
        self::assertCount(1, $listed);
        self::assertSame([self::VERIFY_SUBJECT, "To $email"], [$listed[0]['subject'], $listed[0]['recipient']]);
        self::assertSame('1 email', $inbox->text('//p[' . HtmlDocument::hasClass('inbox-summary') . ']'));

        $message = $this->assertHtmlPage($creator->get($listed[0]['href']));
        self::assertSame(self::VERIFY_SUBJECT, $message->text('//h1'));
        self::assertStringContainsString($email, $message->text('//dl[' . HtmlDocument::hasClass('inbox-message-meta') . ']'));
        $links = self::bodyLinks($message);
        self::assertCount(1, $links, 'The confirmation link is clickable');
        self::assertMatchesRegularExpression('#^' . preg_quote(self::server()->baseUrl, '#') . '/verify_email\.php\?token=[a-f0-9]{64}$#', $links[0]);

        // A browser that did not create the account sees nothing, not even by number.
        $stranger = $this->client();
        $strangerInbox = $this->inbox($stranger);
        self::assertSame(self::GUEST_TEXT, $strangerInbox->text('//div[' . HtmlDocument::hasClass('inbox-guest') . ']/p[1]'));
        self::assertSame([], self::listed($strangerInbox));
        $this->assertHtmlPage($stranger->get($listed[0]['href']), 404);

        // The link from the inbox confirms the account.
        $this->assertRedirect($this->confirm($creator, $links[0]), 'Home_Page.php');
        self::assertSame([self::VERIFY_SUBJECT], self::subjects($this->inbox($creator)), 'Signed in, the customer still reads it');

        // Logging out ends the session, and with it access to the account it created.
        $this->assertRedirect($creator->submitForm($creator->get('Home_Page.php'), 'logout.php'), 'index.php?form=login');
        self::assertSame([], self::listed($this->inbox($creator)));
        $this->assertHtmlPage($creator->get($listed[0]['href']), 404);
    }

    public function testSignUpRenewsTheSessionSoAPlantedSessionIdReadsNothing(): void
    {
        // An attacker starts a valid session and plants its ID in the victim's browser.
        $attacker = $this->client();
        $this->assertHtmlPage($attacker->get('index.php?form=signup'));
        $planted = (string) $attacker->cookie(self::SESSION_COOKIE);
        self::assertNotSame('', $planted);
        $victim = $this->client();
        $victim->setCookie(self::SESSION_COOKIE, $planted);
        $this->assertHtmlPage($victim->get('index.php?form=signup'));
        $plantedToken = $victim->csrfToken();

        $this->assertRedirect($this->signUp($victim, self::uniqueEmail('inbox-fixation'), 'Planted Reader', self::PASSWORD), 'index.php?form=verify');
        self::assertNotSame($planted, $victim->cookie(self::SESSION_COOKIE), 'A new session ID, as at login');
        $this->assertHtmlPage($victim->get('index.php?form=verify'));
        self::assertNotSame($plantedToken, $victim->csrfToken(), 'And a new CSRF token');

        $victimList = self::listed($this->inbox($victim));
        self::assertSame([self::VERIFY_SUBJECT], array_column($victimList, 'subject'));
        self::assertSame([], self::listed($this->inbox($attacker)), 'The planted session ID grants nothing');
        $this->assertHtmlPage($attacker->get($victimList[0]['href']), 404);
    }

    public function testTypingSomeoneElsesAddressNeverRevealsTheirMail(): void
    {
        $owner = $this->createUser(self::uniqueEmail('inbox-owner'), 'Owner');
        $this->markVerified((int) $owner['id']);
        $address = (string) $owner['Eaddress'];

        // Sign-up with a registered address, a reset request and a resend: the
        // owner is emailed, and the visitor gets the usual answers.
        $visitor = $this->client();
        $this->assertRedirect($this->signUp($visitor, $address, 'Not The Owner', self::PASSWORD), 'index.php?form=verify');
        $reset = $visitor->get('index.php?form=reset');
        $this->assertRedirect($visitor->submitForm($reset, 'reset_password.php', ['email' => $address]), 'index.php?form=login');
        $this->assertRedirect($visitor->postWithToken('resend_verification.php', ['email' => $address]), 'index.php?form=verify');
        $ids = array_map('intval', array_column(db_all('SELECT id FROM demo_mail WHERE recipient = ?', [$address]), 'id'));
        self::assertCount(2, $ids, 'The sign-up notice and the reset link');

        $visitorInbox = $this->inbox($visitor);
        self::assertSame([], self::listed($visitorInbox));
        self::assertSame(self::GUEST_TEXT, $visitorInbox->text('//div[' . HtmlDocument::hasClass('inbox-guest') . ']/p[1]'));
        foreach ($ids as $id) {
            $this->assertHtmlPage($visitor->get('inbox.php?id=' . $id), 404);
        }

        // The owner, signed in, reads both, newest first.
        $ownerClient = $this->client();
        $this->assertRedirect($this->logIn($ownerClient, $address, self::DEFAULT_PASSWORD), 'Home_Page.php');
        self::assertSame(['Reset your BookWise password', 'Someone tried to sign up with your email address'], self::subjects($this->inbox($ownerClient)));
        $message = $this->assertHtmlPage($ownerClient->get('inbox.php?id=' . max($ids)));
        self::assertMatchesRegularExpression('#/reset_form\.php\?token=[a-f0-9]{64}$#', self::bodyLinks($message)[0] ?? '');

        // Signing out ends access.
        $logout = $ownerClient->submitForm($ownerClient->get('Home_Page.php'), 'logout.php');
        $this->assertRedirect($logout, 'index.php?form=login');
        self::assertSame([], self::listed($this->inbox($ownerClient)));
    }

    public function testAnAdministratorReadsOnlyTheirOwnMail(): void
    {
        // Accounts created in this browser before an administrator signs in stay hidden.
        $client = $this->client();
        $created = self::uniqueEmail('inbox-created');
        $this->assertRedirect($this->signUp($client, $created, 'Created Reader', self::PASSWORD), 'index.php?form=verify');
        $createdMail = (int) db_value('SELECT id FROM demo_mail WHERE recipient = ?', [$created]);

        $admin = $this->createAdmin(self::uniqueEmail('inbox-admin'), 'Inbox Admin');
        $this->assertRedirect($this->logIn($client, $admin['Eaddress'], self::DEFAULT_PASSWORD), 'admin_dashboard.php');
        $adminMail = $this->storeMessage($admin['Eaddress'], 'Administrator notice');

        $dashboard = $this->assertHtmlPage($client->get('admin_dashboard.php'));
        self::assertNotNull($dashboard->first('//nav//a[@href="inbox.php"]'), 'Administrators get the link too');
        $inbox = $this->inbox($client);
        self::assertSame([$admin['Eaddress']], $inbox->texts('//ul[' . HtmlDocument::hasClass('inbox-addresses') . ']/li'));
        self::assertSame(['Administrator notice'], self::subjects($inbox));
        self::assertStringContainsString('As an administrator, you can read the emails sent to your own address.', $inbox->text());
        $this->assertHtmlPage($client->get('inbox.php?id=' . $adminMail));
        $this->assertHtmlPage($client->get('inbox.php?id=' . $createdMail), 404);
    }

    public function testAnAddressRegisteredAgainStartsWithAnEmptyInbox(): void
    {
        $email = self::uniqueEmail('inbox-reused');
        $first = $this->client();
        $this->assertRedirect($this->signUp($first, $email, 'First Owner', self::PASSWORD), 'index.php?form=verify');
        $oldMail = (int) db_value('SELECT id FROM demo_mail WHERE recipient = ?', [$email]);
        self::assertSame([self::VERIFY_SUBJECT], self::subjects($this->inbox($first)));

        // The account is deleted (here directly) and its mail stays behind; someone else registers the address.
        db_run('DELETE FROM users WHERE Eaddress = ?', [$email]);
        $second = $this->client();
        $this->assertRedirect($this->signUp($second, $email, 'Second Owner', self::PASSWORD), 'index.php?form=verify');

        self::assertSame(0, $this->countRows('demo_mail', 'id = ?', [$oldMail]), "The former owner's mail is deleted");
        $secondList = self::listed($this->inbox($second));
        self::assertSame([self::VERIFY_SUBJECT], array_column($secondList, 'subject'));

        // The first browser created the deleted account, not the new one.
        self::assertSame([], self::listed($this->inbox($first)));
        $this->assertHtmlPage($first->get($secondList[0]['href']), 404);
    }

    public function testWithoutVerificationTheWelcomeEmailIsShown(): void
    {
        $this->configureServer(['REQUIRE_EMAIL_VERIFICATION' => 'false']);
        $client = $this->client();
        $email = self::uniqueEmail('inbox-welcome');
        $this->assertRedirect($this->signUp($client, $email, 'Welcome Reader', self::PASSWORD), 'Home_Page.php');

        $inbox = $this->inbox($client);
        self::assertSame(['Welcome to BookWise!'], self::subjects($inbox));
        $message = $this->assertHtmlPage($client->get(self::listed($inbox)[0]['href']));
        self::assertSame([self::server()->baseUrl . '/Explore_Page.php'], self::bodyLinks($message));
    }

    public function testMessagesAreEscapedAndOnlyLinksToTheStoreAreClickable(): void
    {
        [$client, $user] = $this->verifiedCustomer();
        $base = self::server()->baseUrl;
        $id = $this->storeMessage(
            (string) $user['Eaddress'],
            '<img src=x onerror=alert(1)> Hello',
            "Hi <script>alert(1)</script>\n"
            . "Open $base/reset_form.php?token=abc&next=1.\n"
            . 'Not these: https://evil.example/phish javascript:alert(1) "' . $base . '/x"onmouseover="alert(1)',
            '<script>alert(1)</script><p>HTML-PART-MARKER</p>'
        );

        $inbox = $this->inbox($client);
        self::assertSame(['<img src=x onerror=alert(1)> Hello'], self::subjects($inbox));

        $response = $client->get('inbox.php?id=' . $id);
        $message = $this->assertHtmlPage($response);
        self::assertSame('<img src=x onerror=alert(1)> Hello', $message->text('//h1'));
        self::assertSame([$base . '/reset_form.php?token=abc&next=1', $base . '/x'], self::bodyLinks($message));
        $text = $message->text('//div[' . HtmlDocument::hasClass('inbox-message-body') . ']');
        self::assertStringContainsString('Hi <script>alert(1)</script>', $text, 'Shown as text');
        self::assertStringContainsString('https://evil.example/phish javascript:alert(1)', $text);
        self::assertStringNotContainsString('HTML-PART-MARKER', $response->body, 'The HTML part is never rendered');
        self::assertStringNotContainsString('<img src=x', $response->body);
        self::assertStringContainsString('Only links to this store can be opened from here.', $message->text());
    }

    public function testMessagesKeepTheirOwnLanguageAndDirection(): void
    {
        $email = self::uniqueEmail('inbox-arabic');
        $client = $this->client();
        $client->setCookie(LOCALE_COOKIE, 'ar');
        $this->assertRedirect($this->signUp($client, $email, 'قارئ عربي', self::PASSWORD), 'index.php?form=verify');
        $this->storeMessage($email, 'English notice', 'Hello', '<p>Hello</p>', 'en', '1 MINUTE');

        // English interface: the Arabic message stays right-to-left.
        $client->setCookie(LOCALE_COOKIE, 'en');
        $inbox = $this->inbox($client);
        self::assertSame(['en', 'ltr'], [$inbox->first('/html')?->getAttribute('lang'), $inbox->first('/html')?->getAttribute('dir')]);
        $subjects = $inbox->elements('//*[' . HtmlDocument::hasClass('inbox-item-subject') . ']');
        self::assertSame(
            [['أكّد عنوان بريدك الإلكتروني في BookWise', 'ar', 'rtl'], ['English notice', 'en', 'ltr']],
            array_map(static fn (\DOMElement $subject): array => [HtmlDocument::normalize($subject->textContent), $subject->getAttribute('lang'), $subject->getAttribute('dir')], $subjects)
        );
        $message = $this->assertHtmlPage($client->get(self::listed($inbox)[0]['href']));
        foreach (['//h1', '//div[' . HtmlDocument::hasClass('inbox-message-body') . ']'] as $expression) {
            self::assertSame(['ar', 'rtl'], [$message->first($expression)?->getAttribute('lang'), $message->first($expression)?->getAttribute('dir')], $expression);
        }
        self::assertSame(['To', 'Received'], $message->texts('//dl[' . HtmlDocument::hasClass('inbox-message-meta') . ']//dt'));
        self::assertMatchesRegularExpression('/^[A-Z][a-z]{2} \d{1,2}, \d{4}, \d{2}:\d{2}$/', $message->text('//dl//time'));

        // Arabic interface: Arabic text, Arabic dates, and the English message stays left-to-right.
        $client->setCookie(LOCALE_COOKIE, 'ar');
        $inbox = $this->inbox($client);
        self::assertSame(['ar', 'rtl'], [$inbox->first('/html')?->getAttribute('lang'), $inbox->first('/html')?->getAttribute('dir')]);
        self::assertSame('صندوق البريد التجريبي', $inbox->text('//h1'));
        self::assertSame('رسالتان', $inbox->text('//p[' . HtmlDocument::hasClass('inbox-summary') . ']'));
        self::assertStringContainsString('وتُحذف بعد 48 ساعة، أو قبل ذلك إذا أُعيد المتجر التجريبي إلى حالته الأولى.', $inbox->text());
        $link = $inbox->first('//nav//a[@href="inbox.php"]');
        self::assertSame(['صندوق البريد', 'صندوق البريد التجريبي'], [HtmlDocument::normalize((string) $link?->textContent), $link?->getAttribute('aria-label')]);
        self::assertSame('ltr', $inbox->first('//*[' . HtmlDocument::hasClass('inbox-item-subject') . '][@lang="en"]')?->getAttribute('dir'));
        foreach ($inbox->texts('//ul[' . HtmlDocument::hasClass('inbox-list') . ']//time') as $time) {
            self::assertStringContainsString('، الساعة ', $time);
        }
        self::assertSame(['إلى ' . $email], array_unique(array_column(self::listed($inbox), 'recipient')));
        self::assertDoesNotMatchRegularExpression('/\b(?:mail|common|auth)\.[a-z_]+\.[a-z_]/', $inbox->html, 'No untranslated keys');
    }

    public function testExpiredMessagesAreHiddenAndDeletedWhenTheInboxIsOpened(): void
    {
        [$client, $user] = $this->verifiedCustomer();
        $old = $this->storeMessage((string) $user['Eaddress'], 'Two days old', age: '49 HOUR');
        $someoneElses = $this->storeMessage(self::uniqueEmail('inbox-quiet'), 'Also two days old', age: '49 HOUR');

        $inbox = $this->inbox($client);
        self::assertSame([], self::listed($inbox));
        self::assertSame('No emails yet.', $inbox->text('//p[' . HtmlDocument::hasClass('inbox-empty') . ']'));
        self::assertSame(0, $this->countRows('demo_mail', 'id IN (?, ?)', [$old, $someoneElses]), 'Every expired message is deleted');
        $this->assertHtmlPage($client->get('inbox.php?id=' . $old), 404);
    }

    public function testDeletingTheAccountDeletesItsMail(): void
    {
        [$client, $user] = $this->verifiedCustomer('inbox-leaving');
        $email = (string) $user['Eaddress'];
        $this->storeMessage($email, 'Reset your BookWise password', 'Hello Inbox Customer');
        self::assertSame(['Reset your BookWise password'], self::subjects($this->inbox($client)));

        $page = $client->get('profilePage.php?section=delete');
        $this->assertStatus(200, $page);
        $deleted = $client->submitForm($page, 'profilePage.php', ['delete_password' => self::DEFAULT_PASSWORD, 'confirm_delete' => 'yes'], ['action' => 'delete_account']);
        $this->assertRedirect($deleted, 'Home_Page.php');

        self::assertNull(find_user((int) $user['id']));
        self::assertSame(0, $this->countRows('demo_mail', 'recipient = ?', [$email]), 'Its mail, and no "account deleted" notice that nobody could read');
    }

    public function testTheInboxOnlyAnswersGetRequests(): void
    {
        [$client, $user] = $this->verifiedCustomer();
        $id = $this->storeMessage((string) $user['Eaddress'], 'Read only');

        $post = $client->postWithToken('inbox.php?id=' . $id);
        $this->assertHtmlPage($post, 405);
        self::assertSame('GET, HEAD', $post->header('Allow'));
        $this->assertStatus(200, $client->request('HEAD', 'inbox.php?id=' . $id));
        foreach (['inbox.php?id=abc', 'inbox.php?id=0', 'inbox.php?id[]=' . $id, 'inbox.php?id=' . ($id + 1000)] as $path) {
            $error = $this->assertHtmlPage($client->get($path), 404);
            self::assertSame('This email does not exist, has expired, or was not sent to you.', $error->text('//section//p[not(@class)]'), $path);
        }
    }

    public function testTheSignInPagesPointToTheInbox(): void
    {
        $guest = $this->client();
        foreach (['en' => [self::NOTICE, 'demo inbox'], 'ar' => [null, 'صندوق البريد التجريبي']] as $locale => [$notice, $linkText]) {
            $guest->setCookie(LOCALE_COOKIE, $locale);
            foreach (['index.php?form=reset' => 200, 'index.php?form=verify' => 200, 'verify_email.php?token=' . str_repeat('0', 64) => 404] as $path => $status) {
                $page = $this->assertHtmlPage($guest->get($path), $status);
                $note = $page->first('//p[' . HtmlDocument::hasClass('auth-notice') . '][not(ancestor::*[@hidden])]');
                self::assertNotNull($note, "$locale $path");
                if ($notice !== null) {
                    self::assertSame($notice, HtmlDocument::normalize($note->textContent), $path);
                }
                $links = $page->elements('.//a', $note);
                self::assertCount(1, $links, "$locale $path");
                self::assertSame(['inbox.php', $linkText], [$links[0]->getAttribute('href'), HtmlDocument::normalize($links[0]->textContent)]);
                self::assertStringNotContainsString('does not send email', $page->text());
            }
        }
    }
}
