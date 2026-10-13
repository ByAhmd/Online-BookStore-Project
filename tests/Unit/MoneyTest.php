<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use BookWise\Tests\Support\EnvironmentOverrides;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    use EnvironmentOverrides;

    /**
     * @return iterable<string, array{0: string|int|float, 1: int}>
     */
    public static function toMinorCases(): iterable
    {
        yield "'19.99'" => ['19.99', 1999];
        yield "'0.1'" => ['0.1', 10];
        yield "'0.10'" => ['0.10', 10];
        yield "'0.01'" => ['0.01', 1];
        yield "'5'" => ['5', 500];
        yield "'19.9'" => ['19.9', 1990];
        yield "'1.15'" => ['1.15', 115];
        yield "'4.35'" => ['4.35', 435];
        yield "'10000.00'" => ['10000.00', 1000000];
        yield "' 7.25 ' (trimmed)" => [' 7.25 ', 725];
        yield "'0'" => ['0', 0];
        yield 'float 19.99' => [19.99, 1999];
        yield 'float 0.1' => [0.1, 10];
        yield 'float 1.15 (114.999... * 100)' => [1.15, 115];
        yield 'float 4.35 (434.999... * 100)' => [4.35, 435];
        yield 'float 0.1 + 0.2' => [0.1 + 0.2, 30];
        yield 'int 5' => [5, 500];
        yield 'int 0' => [0, 0];
        yield "'19.999' rounds" => ['19.999', 2000];
    }

    #[DataProvider('toMinorCases')]
    public function testMoneyToMinor(string|int|float $amount, int $expected): void
    {
        self::assertSame($expected, money_to_minor($amount));
    }

    public function testMoneyToMinorSumsWithoutFloatDrift(): void
    {
        $total = 0;
        foreach (array_fill(0, 3, '0.1') as $price) {
            $total += money_to_minor($price);
        }
        self::assertSame(30, $total);
        self::assertSame(3 * 1999, 3 * money_to_minor('19.99'));
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function toDecimalCases(): iterable
    {
        yield '1999' => [1999, '19.99'];
        yield '10' => [10, '0.10'];
        yield '1' => [1, '0.01'];
        yield '0' => [0, '0.00'];
        yield '500' => [500, '5.00'];
        yield 'no thousands separator' => [123456789, '1234567.89'];
    }

    #[DataProvider('toDecimalCases')]
    public function testMinorToDecimal(int $minor, string $expected): void
    {
        self::assertSame($expected, minor_to_decimal($minor));
    }

    public function testRoundTrip(): void
    {
        foreach (['0.01', '0.10', '1.15', '4.35', '19.99', '49.99', '9999.99'] as $amount) {
            self::assertSame($amount, minor_to_decimal(money_to_minor($amount)));
        }
    }

    public function testFormatMoneyUsesTheConfiguredCurrency(): void
    {
        $this->setEnv('STRIPE_CURRENCY', 'sar');

        self::assertSame('SAR 19.99', format_money('19.99'));
        self::assertSame('SAR 0.10', format_money(0.1));
        self::assertSame('SAR 5.00', format_money(5));
        self::assertSame('SAR 1,234.50', format_money('1234.5'));
        self::assertSame('SAR 19.99', format_minor(1999));
        self::assertSame('SAR 0.30', format_minor(money_to_minor(0.1 + 0.2)));

        $this->setEnv('STRIPE_CURRENCY', 'usd');
        self::assertSame('USD 1.00', format_minor(100));
    }
}
