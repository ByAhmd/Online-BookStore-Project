<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure helpers behind read-only administrators and the demo's one-click
 * accounts: shortened customer details, the reserved demo address domain
 * and the demo administrator's name.
 */
final class DemoAccountsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function maskedEmails(): iterable
    {
        yield 'plain' => ['reader@example.com', 'r…@….com'];
        yield 'subdomain' => ['sara.ali@mail.example.co.uk', 's…@….uk'];
        yield 'demo guest' => ['reader-0a1b2c@demo.invalid', 'r…@….invalid'];
        yield 'no top-level domain' => ['root@localhost', 'r…@…'];
        yield 'not an address' => ['nobody', '…'];
        yield 'nothing before the @' => ['@example.com', '…'];
    }

    #[DataProvider('maskedEmails')]
    public function testEmailAddressesAreShortenedForReadOnlyAdministrators(string $email, string $expected): void
    {
        self::assertSame($expected, masked_email($email));
    }

    public function testNamesAreShortenedToTheirFirstLetter(): void
    {
        self::assertSame('S…', masked_name('Sara Ali'));
        self::assertSame('س…', masked_name('سارة علي'), 'Multibyte letters stay whole');
        self::assertSame('…', masked_name('  '));
    }

    public function testNobodyMaySignUpWithTheDemoDomain(): void
    {
        self::assertSame('demo.invalid', DEMO_EMAIL_DOMAIN);
        self::assertSame('viewer@demo.invalid', DEMO_VIEWER_ADMIN_EMAIL);
        self::assertSame('Please enter a valid email address.', email_error('reader-0a1b2c@demo.invalid'));
        self::assertSame('Please enter a valid email address.', email_error(DEMO_VIEWER_ADMIN_EMAIL));
        self::assertNull(email_error('reader@demo.invalid.example.com'), 'Only the reserved domain itself');
        self::assertNull(email_error('reader@example.com'));
    }

    public function testTheDemoAdministratorIsGreetedInTheVisitorsLanguage(): void
    {
        self::assertSame('Demo administrator', admin_display_name(['Eaddress' => DEMO_VIEWER_ADMIN_EMAIL, 'name' => 'Stored name']));
        use_locale('ar');
        try {
            self::assertSame('المشرف التجريبي', admin_display_name(['Eaddress' => DEMO_VIEWER_ADMIN_EMAIL, 'name' => 'Stored name']));
        } finally {
            use_locale(null);
        }
        self::assertSame('Store Admin', admin_display_name(['Eaddress' => 'admin@example.com', 'name' => 'Store Admin']));
    }
}
