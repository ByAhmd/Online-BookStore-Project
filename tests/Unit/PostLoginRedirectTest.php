<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostLoginRedirectTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAdministratorsAlwaysGoToTheDashboard(): void
    {
        $_SESSION['intended'] = 'cart.php';

        self::assertSame('admin_dashboard.php', post_login_redirect('admin'));
        self::assertArrayNotHasKey('intended', $_SESSION);
    }

    public function testCustomersReturnToTheIntendedPageOnce(): void
    {
        $_SESSION['intended'] = 'Selected.php?id=4';

        self::assertSame('Selected.php?id=4', post_login_redirect('user'));
        self::assertArrayNotHasKey('intended', $_SESSION);
        self::assertSame('Home_Page.php', post_login_redirect('user'));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function unsafeTargets(): iterable
    {
        yield 'external URL' => ['https://evil.com/'];
        yield 'protocol-relative' => ['//evil.com'];
        yield 'absolute path' => ['/admin_dashboard.php'];
        yield 'array' => [['cart.php']];
        yield 'missing' => [null];
    }

    #[DataProvider('unsafeTargets')]
    public function testUnsafeIntendedTargetsFallBackToHome(mixed $target): void
    {
        if ($target !== null) {
            $_SESSION['intended'] = $target;
        }

        self::assertSame('Home_Page.php', post_login_redirect('user'));
        self::assertArrayNotHasKey('intended', $_SESSION);
    }

    /* -----------------------------------------------------------------
     * "Log in to buy" links (login_url() and the sign-in page's ?return=)
     * --------------------------------------------------------------- */

    public function testLoginLinksCarryTheReturnPathEncoded(): void
    {
        self::assertSame('index.php?form=login&return=Selected.php%3Fid%3D7', login_url('Selected.php?id=7'));
        self::assertSame(
            'index.php?form=login&return=Explore_Page.php%3Fgenre%3DFavorites%26q%3Dlove',
            login_url('Explore_Page.php?genre=Favorites&q=love')
        );
    }

    public function testTheReturnPathIsUsedOnceAfterSigningIn(): void
    {
        remember_return_path('Selected.php?id=7');

        self::assertSame('Selected.php?id=7', post_login_redirect('user'));
        self::assertSame('Home_Page.php', post_login_redirect('user'), 'Only once');
    }

    public function testAdministratorsIgnoreAShopReturnPath(): void
    {
        remember_return_path('Selected.php?id=7');

        self::assertSame('admin_dashboard.php', post_login_redirect('admin'));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function unsafeReturnPaths(): iterable
    {
        yield 'another site' => ['https://evil.example/'];
        yield 'protocol-relative' => ['//evil.example/Selected.php'];
        yield 'absolute path' => ['/Selected.php?id=7'];
        yield 'header injection' => ["Selected.php?id=7\r\nSet-Cookie: evil=1"];
        yield 'script URL' => ['javascript:alert(1)'];
        yield 'fragment' => ['Selected.php?id=7#purchase'];
        yield 'array' => [['Selected.php?id=7']];
        yield 'empty' => [''];
    }

    #[DataProvider('unsafeReturnPaths')]
    public function testUnsafeReturnPathsAreIgnored(mixed $path): void
    {
        $_SESSION['intended'] = 'cart.php';

        remember_return_path($path);

        self::assertSame('cart.php', $_SESSION['intended'], 'The page remembered before stays');
    }
}
