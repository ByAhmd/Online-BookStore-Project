<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

/**
 * Base class for the HTTP end-to-end suite (tests/Http): drives the real
 * pages in public/ through PHP's built-in web server.
 *
 *  - Database: inherits DatabaseTestCase (the "_test" name guard, schema
 *    reset on first use, mutable tables emptied before every test).
 *  - Stripe: the local mock (StripeMock), reset before every test.
 *  - Server: one AppServer per test class, started on first use and stopped
 *    in tearDownAfterClass().
 *  - After every passing test: no new php-error.log entries, no ERROR lines
 *    in app.log except the ones the test announced with expectAppLogError(),
 *    and no PHP errors printed by the server process.
 */
abstract class HttpTestCase extends DatabaseTestCase
{
    protected const SESSION_COOKIE = 'bookwise_session';

    protected StripeMock $stripe;

    private static ?AppServer $server = null;

    /** @var array<string, int> */
    private array $logOffsets = [];
    private int $outputOffset = 0;

    /** @var list<string> */
    private array $expectedAppLogErrors = [];

    /** @var array<string, true> Local assets already fetched successfully in this run */
    private static array $checkedAssets = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = StripeMock::start();
        $this->stripe->reset();

        if (self::$server === null || !self::$server->isRunning()) {
            self::$server?->stop();
            self::$server = AppServer::start([
                'host' => (string) config('db.host'),
                'port' => (int) config('db.port'),
                'name' => (string) config('db.name'),
                'user' => (string) config('db.user'),
                'pass' => (string) config('db.pass'),
            ], $this->stripe->baseUrl);
        }

        foreach (['php-error.log', 'app.log', 'mail.log'] as $file) {
            $this->logOffsets[$file] = self::$server->logSize($file);
        }
        $this->outputOffset = self::$server->outputSize();
        $this->expectedAppLogErrors = [];
    }

    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();
        $server = self::server();

        $phpErrors = trim($server->readLog('php-error.log', $this->logOffsets['php-error.log']));
        self::assertSame('', $phpErrors, "The application wrote to php-error.log during this test:\n$phpErrors");

        $unexpected = [];
        $hits = array_fill(0, count($this->expectedAppLogErrors), 0);
        foreach ($this->appLogLines() as $line) {
            if (preg_match('/^\[[^\]]*\] (ERROR|CRITICAL|ALERT|EMERGENCY): /', $line) !== 1) {
                continue;
            }
            $expected = false;
            foreach ($this->expectedAppLogErrors as $index => $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $hits[$index]++;
                    $expected = true;
                }
            }
            if (!$expected) {
                $unexpected[] = $line;
            }
        }
        self::assertSame([], $unexpected, 'Unexpected ERROR lines in app.log during this test.');
        foreach ($this->expectedAppLogErrors as $index => $pattern) {
            self::assertGreaterThan(0, $hits[$index], "Expected an ERROR line in app.log matching $pattern");
        }

        $output = $server->output($this->outputOffset);
        self::assertDoesNotMatchRegularExpression(
            '/PHP (Fatal error|Parse error|Warning|Notice|Deprecated|Recoverable fatal error)/i',
            $output,
            "The web server printed PHP errors:\n$output"
        );
    }

    protected function tearDown(): void
    {
        // Avatars are stored in public/uploads/avatars of the checkout; remove
        // every one referenced by this test database (only this suite's uploads).
        try {
            foreach (db_all("SELECT profile_image FROM users WHERE profile_image LIKE 'uploads/avatars/%'") as $row) {
                delete_uploaded_avatar((string) $row['profile_image']);
            }
        } catch (\Throwable) {
            // The database may be unavailable when the test was skipped.
        }
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
        parent::tearDownAfterClass();
    }

    protected static function server(): AppServer
    {
        if (self::$server === null) {
            self::fail('The HTTP test server is not running.');
        }
        return self::$server;
    }

    /* -----------------------------------------------------------------
     * Logs
     * --------------------------------------------------------------- */

    /**
     * Declares an ERROR line this test causes on purpose (regex on the full line).
     */
    protected function expectAppLogError(string $pattern): void
    {
        $this->expectedAppLogErrors[] = $pattern;
    }

    /**
     * app.log lines written since the test started.
     *
     * @return list<string>
     */
    protected function appLogLines(): array
    {
        $log = self::server()->readLog('app.log', $this->logOffsets['app.log'] ?? 0);
        return array_values(array_filter(preg_split('/\R/', $log) ?: [], static fn (string $line): bool => $line !== ''));
    }

    /**
     * mail.log content written since the test started.
     */
    protected function newMail(): string
    {
        return self::server()->readLog('mail.log', $this->logOffsets['mail.log'] ?? 0);
    }

    /**
     * Messages (header + text) logged to mail.log for one recipient since the test started.
     *
     * @return list<array{subject: string, body: string}>
     */
    protected function mailsTo(string $email): array
    {
        $mails = [];
        $blocks = preg_split('/^==== [^\n]* ====\n/m', $this->newMail()) ?: [];
        foreach ($blocks as $block) {
            if (!preg_match('/^To: .*<([^>\n]+)>\nSubject: ([^\n]*)\n\n(.*)$/s', $block, $match)) {
                continue;
            }
            if (strcasecmp($match[1], $email) === 0) {
                $mails[] = ['subject' => $match[2], 'body' => $match[3]];
            }
        }
        return $mails;
    }

    /* -----------------------------------------------------------------
     * Visitors
     * --------------------------------------------------------------- */

    protected function client(): HttpClient
    {
        return new HttpClient(self::server()->baseUrl);
    }

    /**
     * Submits the login form; returns the response to the POST.
     */
    protected function logIn(HttpClient $client, string $email, string $password): HttpResponse
    {
        $page = $client->get('index.php?form=login');
        self::assertSame(200, $page->status, $page->describe());
        return $client->submitForm($page, 'Register.php', ['email' => $email, 'password' => $password], ['action' => 'login']);
    }

    /**
     * Submits the sign-up form; returns the response to the POST.
     */
    protected function signUp(HttpClient $client, string $email, string $name, string $password, ?string $confirmation = null): HttpResponse
    {
        $page = $client->get('index.php?form=signup');
        self::assertSame(200, $page->status, $page->describe());
        return $client->submitForm($page, 'Register.php', [
            'email' => $email,
            'name' => $name,
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ], ['action' => 'register']);
    }

    /**
     * A new customer (created in the database) signed in through the login form.
     *
     * @return array{0: HttpClient, 1: array<string, mixed>}
     */
    protected function signedInCustomer(string $name = 'HTTP Reader'): array
    {
        $user = $this->createUser(self::uniqueEmail('http-reader'), $name);
        $client = $this->client();
        $this->assertRedirect($this->logIn($client, (string) $user['Eaddress'], self::DEFAULT_PASSWORD), 'Home_Page.php');
        return [$client, $user];
    }

    /**
     * A new administrator (admins row with password_hash) signed in through the login form.
     *
     * @return array{0: HttpClient, 1: array{Eaddress: string, name: string}}
     */
    protected function signedInAdmin(string $name = 'HTTP Admin'): array
    {
        $admin = $this->createAdmin(self::uniqueEmail('http-admin'), $name);
        $client = $this->client();
        $this->assertRedirect($this->logIn($client, $admin['Eaddress'], self::DEFAULT_PASSWORD), 'admin_dashboard.php');
        return [$client, $admin];
    }

    /**
     * True when the client's session belongs to a signed-in customer.
     */
    protected function isSignedInCustomer(HttpClient $client): bool
    {
        $response = $client->get('cart.php');
        return $response->status === 200;
    }

    /* -----------------------------------------------------------------
     * Assertions
     * --------------------------------------------------------------- */

    protected function assertStatus(int $expected, HttpResponse $response, string $message = ''): void
    {
        self::assertSame($expected, $response->status, ($message !== '' ? "$message\n" : '') . $response->describe() . $this->recentErrors());
    }

    protected function assertRedirect(HttpResponse $response, string $location, int $status = 303, string $message = ''): void
    {
        $context = ($message !== '' ? "$message\n" : '') . $response->describe() . $this->recentErrors();
        self::assertSame($status, $response->status, "Expected a $status redirect to $location.\n" . $context);
        self::assertSame($location, $response->header('Location'), "Unexpected redirect target.\n" . $context);
    }

    /**
     * The generic page contract: expected status, HTML, exactly one <h1>, no
     * inline scripts, event-handler attributes or javascript: URLs (the CSP
     * forbids them), no PHP error output, and every local asset loads.
     */
    protected function assertHtmlPage(HttpResponse $response, int $status = 200): HtmlDocument
    {
        $this->assertStatus($status, $response);
        $where = "{$response->method} {$response->url}";
        self::assertTrue($response->isHtml(), "$where is not text/html: " . $response->contentType());

        $document = $response->document();
        self::assertSame(1, $document->count('//h1'), "$where must have exactly one <h1>; found: " . json_encode($document->texts('//h1')));
        self::assertSame([], $document->inlineScripts(), "$where has inline <script> blocks");
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc\s*=)[^>]*>/i', $response->body, "$where has an inline <script>");
        // Checked on parsed attributes: escaped text inside an attribute value
        // (e.g. data-confirm="...onerror=...") is data, not a handler.
        self::assertSame([], $document->eventHandlerAttributes(), "$where has on* event-handler attributes");
        self::assertSame([], $document->javascriptUrls(), "$where uses javascript: URLs");
        self::assertDoesNotMatchRegularExpression(
            '/(?:<b>)?(?:Warning|Notice|Deprecated|Fatal error|Parse error|Strict Standards)(?:<\/b>)?:\s|ErrorException|Stack trace:/',
            $response->body,
            "$where contains PHP error output"
        );
        self::assertStringContainsString('<title>', $response->body, "$where has no <title>");

        $client = new HttpClient(self::server()->baseUrl);
        foreach ($document->localAssets() as $asset) {
            if (isset(self::$checkedAssets[$asset])) {
                continue;
            }
            $assetResponse = $client->get($asset);
            self::assertSame(200, $assetResponse->status, "$where references $asset, which does not load");
            self::$checkedAssets[$asset] = true;
        }

        return $document;
    }

    /**
     * Asserts that the page shows a flash message of the given type.
     */
    protected function assertFlash(HttpResponse $page, string $type, string $message): void
    {
        $flashes = $page->document()->flashes();
        self::assertContains(['type' => $type, 'message' => $message], $flashes, "Flash messages on {$page->url}: " . json_encode($flashes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function assertPageText(string $expected, HttpResponse $page): void
    {
        self::assertStringContainsString($expected, $page->document()->text(), "Text not found on {$page->url}");
    }

    protected function assertNotPageText(string $unexpected, HttpResponse $page): void
    {
        self::assertStringNotContainsString($unexpected, $page->document()->text(), "Unexpected text on {$page->url}");
    }

    private function recentErrors(): string
    {
        if (self::$server === null) {
            return '';
        }
        $errors = array_filter($this->appLogLines(), static fn (string $line): bool => str_contains($line, '] ERROR: '));
        $php = trim(self::$server->readLog('php-error.log', $this->logOffsets['php-error.log'] ?? 0));
        $context = '';
        if ($errors !== []) {
            $context .= "\napp.log errors:\n" . implode("\n", $errors);
        }
        if ($php !== '') {
            $context .= "\nphp-error.log:\n" . $php;
        }
        return $context;
    }

    /* -----------------------------------------------------------------
     * Shopping
     * --------------------------------------------------------------- */

    protected function bookPage(HttpClient $client, int $bookId): HttpResponse
    {
        $page = $client->get('Selected.php?id=' . $bookId);
        $this->assertStatus(200, $page);
        return $page;
    }

    /**
     * Uses the "Add to cart" form of the book page; returns the POST response.
     */
    protected function addToCart(HttpClient $client, int $bookId, int|string $quantity): HttpResponse
    {
        return $client->submitForm($this->bookPage($client, $bookId), 'Selected.php?id=' . $bookId, ['quantity' => (string) $quantity], ['action' => 'add_to_cart']);
    }

    /**
     * @return array<int, int> book id => quantity in the user's cart (database)
     */
    protected function cartQuantities(int $userId): array
    {
        $quantities = [];
        foreach (db_all('SELECT book_id, quantity FROM cart WHERE user_id = ? ORDER BY book_id', [$userId]) as $row) {
            $quantities[(int) $row['book_id']] = (int) $row['quantity'];
        }
        return $quantities;
    }

    protected function cartLineId(int $userId, int $bookId): int
    {
        $id = db_value('SELECT id FROM cart WHERE user_id = ? AND book_id = ?', [$userId, $bookId]);
        self::assertNotNull($id, "Book $bookId is not in the cart of user $userId");
        return (int) $id;
    }

    /**
     * Goes from the cart to the checkout page (checkout.php) and submits its
     * form: with the address the page has chosen (the default one), or, for
     * a customer without saved addresses, with a new address
     * (SAMPLE_ADDRESS). Returns the response to the POST.
     *
     * @param array<string, scalar> $values Fields to change, e.g. ['address' => '7']
     */
    protected function submitCheckout(HttpClient $client, array $values = []): HttpResponse
    {
        $cart = $client->get('cart.php');
        $this->assertStatus(200, $cart);
        self::assertSame('checkout.php', $cart->document()->first('//a[' . HtmlDocument::hasClass('checkout-link') . ']')?->getAttribute('href'), 'The cart leads to the checkout page');
        $page = $client->get('checkout.php');
        $this->assertStatus(200, $page);
        $form = $page->document()->form('checkout.php');
        if (($values['address'] ?? $form->field('address')) === 'new') {
            $values += self::SAMPLE_ADDRESS;
        }
        return $client->submitForm($page, 'checkout.php', $values);
    }

    /**
     * Checks out through the checkout page (see submitCheckout()) and returns
     * the Stripe (mock) Checkout Session id from the 303 redirect.
     */
    protected function startCheckout(HttpClient $client): string
    {
        $response = $this->submitCheckout($client);
        $this->assertStatus(303, $response);
        $location = (string) $response->header('Location');
        self::assertMatchesRegularExpression(
            '#^' . preg_quote($this->stripe->baseUrl, '#') . '/pay/(cs_test_[A-Za-z0-9]+)$#',
            $location,
            'Checkout must redirect to the (mock) Stripe hosted payment page'
        );
        return substr($location, strrpos($location, '/') + 1);
    }

    /**
     * Presses "Pay" on the mock hosted checkout page (no BookWise cookies);
     * returns its 303 response back to the shop.
     */
    protected function payOnHostedPage(string $sessionId): HttpResponse
    {
        $stripeVisitor = new HttpClient($this->stripe->baseUrl);
        $page = $stripeVisitor->get('/pay/' . $sessionId);
        $this->assertStatus(200, $page);
        $response = $stripeVisitor->submitForm($page, '', ['action' => 'pay']);
        $this->assertStatus(303, $response);
        return $response;
    }

    /**
     * Adds the books through the book pages, checks out, pays on the mock
     * hosted page and returns from Stripe to success_pay.php.
     *
     * @param array<int, int> $quantities book id => quantity
     * @return array{session: string, order: array<string, mixed>, page: HttpResponse}
     */
    protected function purchase(HttpClient $client, array $quantities): array
    {
        foreach ($quantities as $bookId => $quantity) {
            $this->assertRedirect($this->addToCart($client, $bookId, $quantity), 'cart.php');
        }
        $sessionId = $this->startCheckout($client);
        $return = $this->payOnHostedPage($sessionId);
        self::assertSame(self::server()->baseUrl . '/success_pay.php?session_id=' . $sessionId, $return->header('Location'));
        $page = $client->follow($return);
        $this->assertStatus(200, $page);
        self::assertSame('Thank you! Your payment was received.', $page->document()->text('//h1'));

        $order = db_one('SELECT * FROM orders WHERE stripe_session_id = ?', [$sessionId]);
        self::assertNotNull($order);
        self::assertSame('paid', $order['status']);
        return ['session' => $sessionId, 'order' => $order, 'page' => $page];
    }

    /**
     * @return array<string, mixed>
     */
    protected function orderBySession(string $sessionId): array
    {
        $order = db_one('SELECT * FROM orders WHERE stripe_session_id = ?', [$sessionId]);
        self::assertNotNull($order, "No order stores session $sessionId");
        return $order;
    }

    /**
     * @return array<int, int> book id => quantity in sales for the order
     */
    protected function salesForOrder(int $orderId): array
    {
        $sales = [];
        foreach (db_all('SELECT book_id, quantity FROM sales WHERE order_id = ? ORDER BY book_id', [$orderId]) as $row) {
            $sales[(int) $row['book_id']] = (int) $row['quantity'];
        }
        return $sales;
    }

    protected static function stripeSignature(string $payload, ?int $timestamp = null, string $secret = AppServer::STRIPE_WEBHOOK_SECRET): string
    {
        $timestamp ??= time();
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    /**
     * Replaces values that legitimately differ between two visitors (CSRF
     * tokens, the given strings) so their pages can be compared.
     *
     * @param list<string> $replace
     */
    protected static function normalizeBody(string $body, array $replace = []): string
    {
        $body = (string) preg_replace('/\b[a-f0-9]{64}\b/', 'TOKEN', $body);
        foreach ($replace as $value) {
            $body = str_replace([$value, e($value)], 'VALUE', $body);
        }
        return $body;
    }
}
