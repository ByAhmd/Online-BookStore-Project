<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use BookWise\Tests\Support\EnvironmentOverrides;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    use EnvironmentOverrides;

    /** @var array<string, mixed> */
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_SESSION = [];
    }

    /* -----------------------------------------------------------------
     * Escaping
     * --------------------------------------------------------------- */

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function escapeCases(): iterable
    {
        yield 'markup and both quotes' => ['<a href="x" title=\'y\'>&</a>', '&lt;a href=&quot;x&quot; title=&#039;y&#039;&gt;&amp;&lt;/a&gt;'];
        yield 'attribute breakout' => ['" onmouseover="alert(1)', '&quot; onmouseover=&quot;alert(1)'];
        yield 'null' => [null, ''];
        yield 'integer' => [42, '42'];
        yield 'float' => [1.5, '1.5'];
        yield 'already escaped is escaped again' => ['&amp;', '&amp;amp;'];
        yield 'multibyte untouched' => ['كتاب — café', 'كتاب — café'];
        yield 'invalid UTF-8 is substituted, not dropped' => ["ab\xC3\x28", "ab\u{FFFD}("];
    }

    #[DataProvider('escapeCases')]
    public function testEscape(mixed $input, string $expected): void
    {
        self::assertSame($expected, e($input));
    }

    /* -----------------------------------------------------------------
     * Paths and URLs
     * --------------------------------------------------------------- */

    public function testBasePath(): void
    {
        self::assertSame(BASE_PATH, base_path());
        self::assertSame(BASE_PATH . '/public/imges', base_path('/public/imges'));
        self::assertSame(BASE_PATH . '/storage/logs', base_path('storage/logs'));
    }

    public function testIsHttps(): void
    {
        $this->setEnv('APP_URL', 'http://127.0.0.1:8100');
        unset($_SERVER['HTTPS']);
        self::assertFalse(is_https());

        $_SERVER['HTTPS'] = 'off';
        self::assertFalse(is_https(), 'IIS reports "off" for plain HTTP');

        $_SERVER['HTTPS'] = 'on';
        self::assertTrue(is_https());

        unset($_SERVER['HTTPS']);
        $this->setEnv('APP_URL', 'https://books.example.com');
        self::assertTrue(is_https(), 'Behind a TLS-terminating proxy the configured URL decides');
    }

    public function testAbsoluteUrl(): void
    {
        $this->setEnv('APP_URL', 'https://books.example.com/');

        self::assertSame('https://books.example.com/reset_form.php?token=abc', absolute_url('reset_form.php?token=abc'));
        self::assertSame('https://books.example.com/cart.php', absolute_url('/cart.php'));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function acceptedRedirects(): iterable
    {
        yield 'page' => ['cart.php'];
        yield 'page with id' => ['Selected.php?id=4'];
        yield 'page with encoded query' => ['Explore_Page.php?genre=Science%20Fiction&q=a.b'];
        yield 'login form' => ['index.php?form=login'];
    }

    #[DataProvider('acceptedRedirects')]
    public function testSafeRedirectPathAcceptsLocalPages(string $path): void
    {
        self::assertSame($path, safe_redirect_path($path, 'fallback.php'));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function rejectedRedirects(): iterable
    {
        yield 'absolute URL' => ['https://evil.com'];
        yield 'absolute URL to a page' => ['https://evil.com/cart.php'];
        yield 'protocol-relative' => ['//evil.com'];
        yield 'protocol-relative page' => ['//evil.com/cart.php'];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'rooted page' => ['/cart.php'];
        yield 'CRLF header injection' => ["cart.php\r\nX: y"];
        yield 'javascript URL' => ['javascript:alert(1)'];
        yield 'parent directory' => ['../cart.php'];
        yield 'backslash trick' => ['\\\\evil.com\\cart.php'];
        yield 'not a php page' => ['.env'];
        yield 'fragment' => ['cart.php#top'];
        yield 'query with quote' => ['cart.php?x="'];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'array' => [['cart.php']];
        yield 'integer' => [42];
    }

    #[DataProvider('rejectedRedirects')]
    public function testSafeRedirectPathRejectsEverythingElse(mixed $path): void
    {
        self::assertSame('fallback.php', safe_redirect_path($path, 'fallback.php'));
        self::assertSame('Home_Page.php', safe_redirect_path($path));
    }

    /**
     * Regression test for a fixed bug (src/helpers.php safe_redirect_path): the pattern ends in
     * `$`, which also matches before a trailing "\n", so "cart.php\n" (e.g.
     * from ?next=cart.php%0A) is returned unchanged. redirect() then calls
     * header() with a newline, which PHP rejects with a warning (a 500 under
     * configure_runtime()). Use `\z` or the D modifier. The same `$` pattern
     * is used in validate_genre_name(), validate_cover_image(), parse_int()
     * (after trim, harmless) and the reset-token checks.
     */
    public function testSafeRedirectPathRejectsTrailingNewline(): void
    {
        self::assertSame('fallback.php', safe_redirect_path("cart.php\n", 'fallback.php'));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string}>
     */
    public static function imageUrlCases(): iterable
    {
        yield 'existing seed cover' => ['imges/All_section/biology.png', 'imges/All_section/biology.png'];
        yield 'surrounding whitespace is trimmed' => ['  imges/All_section/biology.png ', 'imges/All_section/biology.png'];
        yield 'https URL' => ['https://covers.example.com/book.png', 'https://covers.example.com/book.png'];
        yield 'https URL, any case' => ['HTTPS://covers.example.com/b.png', 'HTTPS://covers.example.com/b.png'];
        yield 'plain http URL (blocked by CSP)' => ['http://covers.example.com/b.png', 'fallback.png'];
        yield 'dotenv traversal' => ['../.env', 'fallback.png'];
        yield 'traversal inside allowed dir' => ['imges/../../.env', 'fallback.png'];
        yield 'missing file' => ['imges/missing.png', 'fallback.png'];
        yield 'directory, not a file' => ['imges/All_section', 'fallback.png'];
        yield 'outside allowed dirs' => ['css/common.css', 'fallback.png'];
        yield 'absolute path' => ['/etc/passwd', 'fallback.png'];
        yield 'windows drive' => ['C:/Windows/win.ini', 'fallback.png'];
        yield 'backslashes' => ['imges\\All_section\\biology.png', 'fallback.png'];
        yield 'javascript URL' => ['javascript:alert(1)', 'fallback.png'];
        yield 'data URL' => ['data:image/png;base64,AAAA', 'fallback.png'];
        yield 'protocol-relative' => ['//evil.example/x.png', 'fallback.png'];
        yield 'quote in path' => ['imges/"onerror=alert(1).png', 'fallback.png'];
        yield 'empty' => ['', 'fallback.png'];
        yield 'whitespace only' => ['   ', 'fallback.png'];
        yield 'null' => [null, 'fallback.png'];
    }

    #[DataProvider('imageUrlCases')]
    public function testImageUrl(?string $path, string $expected): void
    {
        self::assertSame($expected, image_url($path, 'fallback.png'));
    }

    public function testImageUrlDefaultFallbackIsTheDefaultAvatar(): void
    {
        self::assertSame('imges/profileImage2.png', image_url('imges/missing.png'));
        self::assertFileExists(BASE_PATH . '/public/imges/profileImage2.png');
    }

    /* -----------------------------------------------------------------
     * Request input
     * --------------------------------------------------------------- */

    /**
     * @return iterable<string, array{0: mixed, 1: int, 2: int, 3: int|null}>
     */
    public static function parseIntCases(): iterable
    {
        $max = PHP_INT_MAX;
        yield "'1'" => ['1', 1, $max, 1];
        yield "'01' (leading zero)" => ['01', 1, $max, 1];
        yield "' 2 ' (surrounding spaces trimmed)" => [' 2 ', 1, $max, 2];
        yield "'-1'" => ['-1', 1, $max, null];
        yield "'+1'" => ['+1', 1, $max, null];
        yield "'1.5'" => ['1.5', 1, $max, null];
        yield "'1.0'" => ['1.0', 1, $max, null];
        yield "'1e3'" => ['1e3', 1, $max, null];
        yield "'0x1A'" => ['0x1A', 1, $max, null];
        yield "'12abc'" => ['12abc', 1, $max, null];
        yield "'1 2'" => ['1 2', 1, $max, null];
        yield "'' (empty)" => ['', 1, $max, null];
        yield 'Arabic-Indic digits' => ['١٢', 1, $max, null];
        yield '11 digits' => ['12345678901', 1, $max, null];
        yield '10 digits' => ['1234567890', 1, $max, 1234567890];
        yield 'array' => [['1'], 1, $max, null];
        yield 'null' => [null, 1, $max, null];
        yield 'bool' => [true, 1, $max, null];
        yield 'float' => [1.0, 1, $max, null];
        yield 'int' => [7, 1, $max, 7];
        yield 'negative int' => [-7, 1, $max, null];
        yield "'0' below default min" => ['0', 1, $max, null];
        yield "'0' with min 0" => ['0', 0, $max, 0];
        yield 'at max' => ['5', 1, 5, 5];
        yield 'above max' => ['6', 1, 5, null];
        yield 'at min' => ['3', 3, 5, 3];
        yield 'below min' => ['2', 3, 5, null];
    }

    #[DataProvider('parseIntCases')]
    public function testParseInt(mixed $value, int $min, int $max, ?int $expected): void
    {
        self::assertSame($expected, parse_int($value, $min, $max));
    }

    public function testInputString(): void
    {
        $source = ['name' => "  Reader \n", 'list' => ['x'], 'number' => 5, 'arabic' => 'كتابة'];

        self::assertSame('Reader', input_string($source, 'name'));
        self::assertSame('', input_string($source, 'list'), 'Arrays are ignored');
        self::assertSame('', input_string($source, 'number'), 'Non-strings are ignored');
        self::assertSame('', input_string($source, 'missing'));
        self::assertSame('كت', input_string($source, 'arabic', 2), 'Truncation counts characters, not bytes');
    }

    public function testRequestMethod(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        self::assertSame('GET', request_method());
        self::assertFalse(is_post());

        $_SERVER['REQUEST_METHOD'] = 'post';
        self::assertSame('POST', request_method());
        self::assertTrue(is_post());
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function clientIpCases(): iterable
    {
        yield 'IPv4' => ['203.0.113.7', '203.0.113.7'];
        yield 'IPv6' => ['::1', '::1'];
        yield 'garbage' => ['not-an-ip', '0.0.0.0'];
        yield 'header-style list' => ['1.2.3.4, 5.6.7.8', '0.0.0.0'];
        yield 'missing' => [null, '0.0.0.0'];
    }

    #[DataProvider('clientIpCases')]
    public function testClientIp(?string $remoteAddr, string $expected): void
    {
        if ($remoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $remoteAddr;
        }
        self::assertSame($expected, client_ip());
    }

    /* -----------------------------------------------------------------
     * Flash messages
     * --------------------------------------------------------------- */

    public function testFlashIsIgnoredWithoutAnActiveSession(): void
    {
        $_SESSION = [];
        flash('success', 'Saved');

        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame([], $_SESSION);
    }

    public function testTakeFlashesReturnsAndClearsQueuedMessages(): void
    {
        $_SESSION = ['_flash' => [['type' => 'error', 'message' => 'Nope'], ['type' => 'info', 'message' => 'FYI']]];

        self::assertSame([['type' => 'error', 'message' => 'Nope'], ['type' => 'info', 'message' => 'FYI']], take_flashes());
        self::assertSame([], take_flashes());
        self::assertArrayNotHasKey('_flash', $_SESSION);
    }

    public function testTakeFlashesIgnoresCorruptedSessionData(): void
    {
        $_SESSION = ['_flash' => 'not a list'];

        self::assertSame([], take_flashes());
    }
}
