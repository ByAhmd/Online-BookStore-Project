<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure helpers behind read-only administrators, such as the demo's:
 * shortened customer details.
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
}
