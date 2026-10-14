<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Delivery addresses without a database: Saudi mobile numbers, National
 * Address validation in both languages, and how addresses are written in
 * text and HTML (src/addresses.php).
 */
final class AddressesTest extends TestCase
{
    private const VALID = [
        'recipient_name' => 'Sara Ahmed',
        'phone' => '0512345678',
        'city' => 'Riyadh',
        'district' => 'Al Olaya',
        'street' => 'King Fahd Road',
        'building_number' => '1234',
        'postal_code' => '12345',
        'additional_number' => '6789',
        'short_address' => 'RRRD2929',
    ];

    protected function tearDown(): void
    {
        use_locale(null);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function mobileNumbers(): iterable
    {
        yield 'local' => ['0512345678', '+966512345678'];
        yield 'without the leading zero' => ['512345678', '+966512345678'];
        yield 'international' => ['+966512345678', '+966512345678'];
        yield 'international with spaces' => ['+966 51 234 5678', '+966512345678'];
        yield 'with 00' => ['00966512345678', '+966512345678'];
        yield 'with 966 only' => ['966512345678', '+966512345678'];
        yield 'dashes, dots and brackets' => ['(051) 234-56.78', '+966512345678'];
        yield 'Arabic-Indic digits' => ['٠٥١٢٣٤٥٦٧٨', '+966512345678'];
        yield 'Eastern Arabic-Indic digits' => ['۰۵۵۱۲۳۴۵۶۷', '+966551234567'];
        yield 'copied from right-to-left text' => ["\u{200F}+966 50 000 0000\u{200E}", '+966500000000'];
        yield 'landline' => ['0112345678', null];
        yield 'not a mobile prefix' => ['0612345678', null];
        yield 'too short' => ['051234567', null];
        yield 'too long' => ['05123456789', null];
        yield 'another country' => ['+971512345678', null];
        yield 'letters' => ['05123abc78', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('mobileNumbers')]
    public function testSaudiMobileNumbersAreNormalized(string $input, ?string $expected): void
    {
        self::assertSame($expected, normalize_saudi_mobile($input));
    }

    public function testMobileNumbersAreGroupedForReading(): void
    {
        self::assertSame('+966 51 234 5678', format_saudi_mobile('+966512345678'));
        self::assertSame('not a number', format_saudi_mobile('not a number'));
        self::assertSame('0123456789', ascii_digits('٠١٢٣٤٥٦٧٨٩'));
        self::assertSame('0123456789', ascii_digits('۰۱۲۳۴۵۶۷۸۹'));
        self::assertSame('Building 12', ascii_digits('Building ١٢'));
    }

    public function testAValidAddressIsNormalized(): void
    {
        [$values, $errors] = validate_address_input([
            'recipient_name' => '  سارة أحمد ',
            'phone' => '٠٥١٢٣٤٥٦٧٨',
            'city' => 'الرياض',
            'district' => 'حي العليا',
            'street' => 'طريق الملك فهد',
            'building_number' => '١٢٣٤',
            'postal_code' => ' 12345 ',
            'additional_number' => '',
            'short_address' => 'rrrd 2929',
            'unrelated' => 'ignored',
        ]);

        self::assertSame([], $errors);
        self::assertSame([
            'recipient_name' => 'سارة أحمد',
            'phone' => '+966512345678',
            'city' => 'الرياض',
            'district' => 'حي العليا',
            'street' => 'طريق الملك فهد',
            'building_number' => '1234',
            'postal_code' => '12345',
            'additional_number' => '',
            'short_address' => 'RRRD2929',
        ], $values);
        self::assertSame(['سارة أحمد', '+966512345678', 'الرياض', 'حي العليا', 'طريق الملك فهد', '1234', '12345', null, 'RRRD2929'], address_column_values($values));
    }

    public function testEveryRuleHasItsMessage(): void
    {
        [, $errors] = validate_address_input([]);
        self::assertSame([
            'recipient_name' => "Please enter the recipient's full name.",
            'city' => 'Please enter the city.',
            'district' => 'Please enter the district.',
            'street' => 'Please enter the street.',
            'phone' => 'Please enter a mobile number.',
            'building_number' => 'The building number has 4 digits.',
            'postal_code' => 'The postal code has 5 digits.',
        ], $errors, 'The additional number and the short address are optional');

        [, $errors] = validate_address_input([
            'recipient_name' => str_repeat('a', 101),
            'phone' => '0112345678',
            'city' => 'Riyadh<script>',
            'district' => "Al\nOlaya",
            'street' => '---',
            'building_number' => '123',
            'postal_code' => '1234a',
            'additional_number' => '12345',
            'short_address' => 'RRR2929',
        ]);
        self::assertSame([
            'recipient_name' => 'Please use at most 100 characters.',
            'city' => 'The city contains characters that are not allowed.',
            'district' => 'The district contains characters that are not allowed.',
            'street' => 'The street contains characters that are not allowed.',
            'phone' => 'Please enter a Saudi mobile number, such as 05XXXXXXXX.',
            'building_number' => 'The building number has 4 digits.',
            'postal_code' => 'The postal code has 5 digits.',
            'additional_number' => 'The additional number has 4 digits.',
            'short_address' => 'The short address has 4 letters and 4 digits, such as RRRD2929.',
        ], $errors);

        [, $errors] = validate_address_input(['city' => str_repeat('م', 61)] + self::VALID);
        self::assertSame(['city' => 'Please use at most 60 characters.'], $errors);
    }

    public function testMessagesAreInArabicForArabicVisitors(): void
    {
        use_locale('ar');
        [, $errors] = validate_address_input(['phone' => '123', 'city' => str_repeat('م', 61), 'street' => str_repeat('ش', 101)] + self::VALID);

        self::assertSame([
            'city' => 'يُرجى ألا يتجاوز النص 60 حرفًا.',
            'street' => 'يُرجى ألا يتجاوز النص 100 حرف.',
            'phone' => 'يُرجى إدخال رقم جوال سعودي، مثل 0512345678.',
        ], $errors);
    }

    public function testAddressesAreWrittenLineByLineInTheVisitorsLanguage(): void
    {
        $address = ['phone' => '+966512345678'] + self::VALID;

        self::assertSame([
            'Sara Ahmed',
            '1234 King Fahd Road',
            'Al Olaya',
            'Riyadh 12345-6789',
            'Short address: RRRD2929',
            "\u{2066}+966 51 234 5678\u{2069}",
        ], address_lines($address));

        $plain = ['additional_number' => null, 'short_address' => null] + $address;
        self::assertSame(['Sara Ahmed', '1234 King Fahd Road', 'Al Olaya', 'Riyadh 12345', "\u{2066}+966 51 234 5678\u{2069}"], address_lines($plain));

        use_locale('ar');
        self::assertSame('العنوان المختصر: RRRD2929', address_lines($address)[4]);
    }

    public function testAddressHtmlEscapesAndIsolatesWhatTheCustomerTyped(): void
    {
        $address = ['recipient_name' => '<b>Sara</b>', 'street' => 'Road & "Co"', 'phone' => '+966512345678'] + self::VALID;

        $html = address_html($address);

        self::assertStringContainsString('<span class="address-line address-recipient"><bdi dir="auto">&lt;b&gt;Sara&lt;/b&gt;</bdi></span>', $html);
        self::assertStringContainsString('<span class="address-line"><bdi dir="auto">1234</bdi> <bdi dir="auto">Road &amp; &quot;Co&quot;</bdi></span>', $html);
        self::assertStringContainsString('<span class="address-line address-phone"><span dir="ltr">+966 51 234 5678</span></span>', $html);
        self::assertStringNotContainsString('<b>', $html);
    }
}
