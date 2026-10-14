<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;

/**
 * Defensive response headers and session cookie flags on real responses.
 */
final class SecurityHeadersTest extends HttpTestCase
{
    /**
     * @return array<string, string> CSP directive => value
     */
    private static function cspDirectives(HttpResponse $response): array
    {
        $header = $response->header('Content-Security-Policy');
        self::assertNotNull($header, "No Content-Security-Policy on {$response->url}");
        $directives = [];
        foreach (explode(';', $header) as $directive) {
            $parts = preg_split('/\s+/', trim($directive), 2);
            if ($parts !== false && $parts[0] !== '') {
                $directives[strtolower($parts[0])] = $parts[1] ?? '';
            }
        }
        return $directives;
    }

    private function assertSecurityHeaders(HttpResponse $response): void
    {
        $where = "{$response->method} {$response->url} ({$response->status})";
        $csp = self::cspDirectives($response);
        self::assertSame("'self'", $csp['script-src'] ?? null, "$where: script-src must be exactly 'self'");
        self::assertSame("'self'", $csp['default-src'] ?? null, $where);
        self::assertSame("'none'", $csp['frame-ancestors'] ?? null, $where);
        self::assertSame("'none'", $csp['object-src'] ?? null, $where);
        self::assertSame("'self'", $csp['base-uri'] ?? null, $where);
        $formAction = preg_split('/\s+/', $csp['form-action'] ?? '') ?: [];
        self::assertContains("'self'", $formAction, $where);
        self::assertContains('https://checkout.stripe.com', $formAction, $where);
        self::assertContains($this->stripe->baseUrl, $formAction, "$where: the test Stripe mock must be an allowed form target");

        self::assertSame('DENY', $response->header('X-Frame-Options'), $where);
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'), $where);
        // reset_form.php carries a token in its URL and deliberately sends the stricter no-referrer.
        $expectedReferrerPolicy = str_contains($response->url, '/reset_form.php') ? 'no-referrer' : 'strict-origin-when-cross-origin';
        self::assertSame($expectedReferrerPolicy, $response->header('Referrer-Policy'), $where);
        self::assertNotNull($response->header('Permissions-Policy'), $where);
        self::assertNull($response->header('X-Powered-By'), "$where leaks X-Powered-By");
        self::assertNull($response->header('Strict-Transport-Security'), "$where: no HSTS over plain http");
    }

    private function assertSessionCookieFlags(?string $setCookie, bool $deletion = false): void
    {
        self::assertNotNull($setCookie, 'Expected a session cookie');
        $attributes = array_map('trim', explode(';', $setCookie));
        [, $value] = explode('=', (string) array_shift($attributes), 2);
        $flags = [];
        foreach ($attributes as $attribute) {
            $pair = explode('=', $attribute, 2);
            $flags[strtolower($pair[0])] = $pair[1] ?? true;
        }

        self::assertTrue($flags['httponly'] ?? false, "Session cookie must be HttpOnly: $setCookie");
        self::assertSame('Lax', $flags['samesite'] ?? null, "Session cookie must be SameSite=Lax: $setCookie");
        self::assertSame('/', $flags['path'] ?? null, $setCookie);
        self::assertArrayNotHasKey('secure', $flags, 'Plain-http APP_URL: the cookie cannot be Secure');
        self::assertArrayNotHasKey('domain', $flags, $setCookie);
        if ($deletion) {
            return;
        }
        self::assertArrayNotHasKey('expires', $flags, 'The session cookie must end with the browser session');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9,-]{22,256}$/', $value, $setCookie);
    }

    public function testPagesRedirectsErrorsAndJsonCarryTheSecurityHeaders(): void
    {
        $guest = $this->client();
        $responses = [
            $guest->get('Home_Page.php'),
            $guest->get('index.php?form=login'),
            $guest->get('cart.php'),                                // redirect
            $guest->get('Register.php'),                            // 405 error page
            $guest->post('reset_password.php', ['email' => 'a@example.com']), // 419 error page
            $guest->get('fetch_books.php?genre=Mystery'),           // JSON
            $guest->get('reset_form.php'),                          // 404 page
        ];
        [$customer] = $this->signedInCustomer();
        $responses[] = $customer->get('cart.php');
        $responses[] = $customer->get('Selected.php?id=999999');      // 404
        $responses[] = $customer->get('admin_dashboard.php');         // 403

        $expected = [200, 200, 303, 405, 419, 200, 404, 200, 404, 403];
        self::assertSame($expected, array_map(static fn (HttpResponse $r): int => $r->status, $responses));
        foreach ($responses as $response) {
            $this->assertSecurityHeaders($response);
        }
    }

    public function testSessionCookieIsHttpOnlyAndSameSiteLaxThroughoutItsLife(): void
    {
        $user = $this->createUser(self::uniqueEmail('cookie'));
        $client = $this->client();

        $guestPage = $client->get('index.php?form=login');
        $this->assertSessionCookieFlags($guestPage->setCookieHeader(self::SESSION_COOKIE));

        $login = $this->logIn($client, (string) $user['Eaddress'], self::DEFAULT_PASSWORD);
        $this->assertRedirect($login, 'Home_Page.php');
        $this->assertSessionCookieFlags($login->setCookieHeader(self::SESSION_COOKIE));

        $home = $client->follow($login);
        $logout = $client->submitForm($home, 'logout.php');
        $this->assertRedirect($logout, 'index.php?form=login');
        $cookies = array_values(array_filter(
            $logout->headerValues('Set-Cookie'),
            static fn (string $line): bool => str_starts_with($line, self::SESSION_COOKIE . '=')
        ));
        self::assertNotSame([], $cookies);
        foreach ($cookies as $line) {
            $this->assertSessionCookieFlags($line, str_contains($line, 'deleted') || stripos($line, 'Max-Age=0') !== false);
        }
    }

    public function testStatelessEndpointsDoNotStartASession(): void
    {
        $client = $this->client();
        $webhook = $client->get('stripe_webhook.php');
        $this->assertStatus(405, $webhook);
        self::assertSame([], $webhook->headerValues('Set-Cookie'), 'The webhook endpoint must not start a session');
        self::assertSame('nosniff', $webhook->header('X-Content-Type-Options'));

        $json = $client->get('fetch_books.php');
        self::assertStringStartsWith('application/json', $json->contentType());
        self::assertSame('no-store', $json->header('Cache-Control'));
    }
}
