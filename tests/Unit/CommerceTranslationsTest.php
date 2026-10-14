<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shop and admin translation files: English and Arabic define the same
 * keys, keep the same :placeholders, Arabic plurals have every CLDR form,
 * and every literal key the code uses exists in both languages.
 */
final class CommerceTranslationsTest extends TestCase
{
    private const NAMESPACES = ['shop', 'admin'];
    private const PLURAL_FORMS = ['zero', 'one', 'two', 'few', 'many', 'other'];

    /**
     * @return array<string, mixed>
     */
    private static function load(string $locale, string $namespace): array
    {
        $data = require BASE_PATH . "/lang/$locale/$namespace.php";
        self::assertIsArray($data, "lang/$locale/$namespace.php must return an array");
        return $data;
    }

    private static function isPluralEntry(mixed $value): bool
    {
        return is_array($value) && $value !== [] && array_diff(array_keys($value), self::PLURAL_FORMS) === [];
    }

    /**
     * Leaves keyed by dotted path: strings, plural entries and lists.
     *
     * @param array<string|int, mixed> $data
     * @return array<string, mixed>
     */
    private static function leaves(array $data, string $prefix = ''): array
    {
        $leaves = [];
        foreach ($data as $key => $value) {
            $path = $prefix . $key;
            if (is_array($value) && !self::isPluralEntry($value) && !array_is_list($value)) {
                $leaves += self::leaves($value, $path . '.');
            } else {
                $leaves[$path] = $value;
            }
        }
        return $leaves;
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $text, bool $withoutCount = false): array
    {
        preg_match_all('/:[a-z_]+/', $text, $matches);
        $names = array_values(array_unique($matches[0]));
        if ($withoutCount) {
            $names = array_values(array_diff($names, [':count']));
        }
        sort($names);
        return $names;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function namespaces(): iterable
    {
        foreach (self::NAMESPACES as $namespace) {
            yield $namespace => [$namespace];
        }
    }

    #[DataProvider('namespaces')]
    public function testEnglishAndArabicDefineTheSameKeys(string $namespace): void
    {
        $en = self::leaves(self::load('en', $namespace));
        $ar = self::leaves(self::load('ar', $namespace));

        self::assertSame([], array_keys(array_diff_key($en, $ar)), "Missing in lang/ar/$namespace.php");
        self::assertSame([], array_keys(array_diff_key($ar, $en)), "Only in lang/ar/$namespace.php");
    }

    #[DataProvider('namespaces')]
    public function testTranslationsKeepThePlaceholdersAndPluralForms(string $namespace): void
    {
        $en = self::leaves(self::load('en', $namespace));
        $ar = self::leaves(self::load('ar', $namespace));

        foreach ($en as $key => $english) {
            $arabic = $ar[$key] ?? null;
            if (is_string($english)) {
                self::assertIsString($arabic, "$namespace.$key");
                self::assertNotSame('', trim($arabic), "$namespace.$key is empty in Arabic");
                self::assertSame(self::placeholders($english), self::placeholders($arabic), "Placeholders of $namespace.$key");
                continue;
            }
            if (self::isPluralEntry($english)) {
                self::assertIsArray($arabic, "$namespace.$key");
                self::assertArrayHasKey('one', $english, "$namespace.$key (en)");
                self::assertArrayHasKey('other', $english, "$namespace.$key (en)");
                self::assertSame(self::PLURAL_FORMS, array_keys($arabic), "$namespace.$key must have every Arabic plural form, in CLDR order");
                $expected = self::placeholders((string) $english['other'], true);
                foreach ($english as $form => $text) {
                    self::assertSame($expected, self::placeholders((string) $text, true), "Placeholders of $namespace.$key.$form (en)");
                }
                foreach ($arabic as $form => $text) {
                    self::assertIsString($text);
                    self::assertNotSame('', trim($text), "$namespace.$key.$form is empty");
                    if ($form !== 'zero') {
                        // "zero" may be worded without the details ("No books.").
                        self::assertSame($expected, self::placeholders($text, true), "Placeholders of $namespace.$key.$form (ar)");
                    }
                }
                continue;
            }
            // Lists (e.g. team member names): same length in both languages.
            self::assertIsArray($arabic, "$namespace.$key");
            self::assertCount(count($english), $arabic, "$namespace.$key");
        }
    }

    public function testEveryKeyUsedInTheCodeExistsInBothLanguages(): void
    {
        $files = array_merge(
            glob(BASE_PATH . '/src/*.php') ?: [],
            glob(BASE_PATH . '/public/*.php') ?: [],
        );
        $used = [];
        foreach ($files as $file) {
            preg_match_all("/'((?:shop|admin)\\.[a-z_.]+)'/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $key) {
                if (!str_ends_with($key, '.')) { // prefixes of keys built at runtime
                    $used[$key] = basename($file);
                }
            }
        }
        self::assertGreaterThan(200, count($used), 'The scan finds the keys');

        foreach (['en', 'ar'] as $locale) {
            foreach ($used as $key => $file) {
                self::assertNotNull(translation_entry($key, $locale), "$key (used in $file) is missing in lang/$locale");
            }
        }
    }

    public function testKeysBuiltAtRuntimeExist(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (['paid', 'processing', 'unpaid', 'invalid', 'error', 'review'] as $state) {
                self::assertNotNull(translation_entry("shop.result.titles.$state", $locale), "shop.result.titles.$state ($locale)");
            }
            foreach (ORDER_STATUS_FILTERS as $status) {
                self::assertNotNull(translation_entry("common.status.$status", $locale), "common.status.$status ($locale)");
            }
            foreach (['title', 'author', 'genre', 'description'] as $field) {
                self::assertNotNull(translation_entry("admin.validation.labels.$field", $locale));
            }
            for ($month = 1; $month <= 12; $month++) {
                self::assertNotNull(translation_entry("shop.date.months.$month", $locale), "Month $month ($locale)");
            }
        }
    }

    public function testDatesAreWrittenInTheVisitorsLanguage(): void
    {
        $date = new \DateTimeImmutable('2026-10-04 09:05:00');

        self::assertSame('Oct 4, 2026', display_date($date));
        self::assertSame('Oct 4, 2026, 09:05', display_date('2026-10-04 09:05:00', true));
        self::assertSame('', display_date(null));
        self::assertSame('not a date', display_date('not a date'));
        use_locale('ar');
        try {
            self::assertSame('4 أكتوبر 2026', display_date($date));
            self::assertSame('4 أكتوبر 2026، الساعة 09:05', display_date($date, true));
        } finally {
            use_locale(null);
        }
    }

    public function testMarkupPlaceholdersAreInsertedAfterEscaping(): void
    {
        self::assertSame(
            'by <span dir="auto">&lt;b&gt;Ada&lt;/b&gt;</span>',
            t_markup('shop.book.by', ['author' => '<span dir="auto">' . e('<b>Ada</b>') . '</span>'])
        );
        // Plain values are escaped, and text that looks like a placeholder stays text.
        self::assertSame(
            'Order <strong>#7</strong> is confirmed, paid on :order &amp;.',
            t_markup('shop.result.paid_lead_on', ['order' => '<strong>#7</strong>'], ['date' => ':order &'])
        );
    }

    public function testSafeTranslationsFallBackToEnglishText(): void
    {
        self::assertSame('Your cart is empty.', t_safe('shop.checkout.empty_cart'));
        self::assertSame('The minimum order total is SAR 2.00. Please add another book.', t_safe('shop.checkout.minimum_total', ['amount' => 'SAR 2.00']));
        self::assertSame('shop.no.such.key', t_safe('shop.no.such.key'));
    }
}
