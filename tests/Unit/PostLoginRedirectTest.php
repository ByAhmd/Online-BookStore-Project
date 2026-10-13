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
}
