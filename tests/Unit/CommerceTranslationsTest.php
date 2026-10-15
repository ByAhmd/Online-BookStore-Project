<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every translation file (lang/en/*.php and lang/ar/*.php): English and
 * Arabic define the same keys, keep the same :placeholders, Arabic plurals
 * have every CLDR form, and every literal shop and admin key the code uses
 * exists in both languages.
 */
final class CommerceTranslationsTest extends TestCase
{
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
     * The translation namespaces, one file each.
     *
     * @return list<string>
     */
    private static function files(string $locale): array
    {
        return array_map(static fn (string $file): string => basename($file, '.php'), glob(BASE_PATH . "/lang/$locale/*.php") ?: []);
    }

    /**
     * Every namespace, so that a new translation file is checked too.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function namespaces(): iterable
    {
        foreach (self::files('en') as $namespace) {
            yield $namespace => [$namespace];
        }
    }

    public function testEnglishAndArabicHaveTheSameTranslationFiles(): void
    {
        self::assertContains('mail', self::files('en'), 'The scan finds the files');
        self::assertSame(self::files('en'), self::files('ar'));
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

    /**
     * In right-to-left text, the punctuation at the end of a Latin path or
     * URL is drawn on its other side ("https://" shows as "//:https",
     * "public/" as "/public"). Arabic translations therefore wrap such
     * tokens in a left-to-right isolate (U+2066 … U+2069).
     */
    #[DataProvider('namespaces')]
    public function testArabicTextKeepsLatinPathsAndUrlsLeftToRight(string $namespace): void
    {
        foreach (self::leaves(self::load('ar', $namespace)) as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $text) {
                if (!is_string($text)) {
                    continue;
                }
                $outside = (string) preg_replace('/\x{2066}[^\x{2066}\x{2069}]*\x{2069}/u', '', $text);
                // A word of two or more Latin letters before a slash ("public/"), or a URL scheme.
                // Single letters are left alone: "d/m/Y" is a date format, not text.
                self::assertDoesNotMatchRegularExpression('~[A-Za-z_][A-Za-z0-9_.-]+/|://~', $outside, "$namespace.$key: wrap the path or URL in \\u{2066}…\\u{2069}");
                self::assertSame(substr_count($text, "\u{2066}"), substr_count($text, "\u{2069}"), "$namespace.$key: every isolate is closed");
            }
        }
    }

    public function testTheCoverHintReadsCorrectlyInArabic(): void
    {
        use_locale('ar');
        try {
            self::assertSame(
                "مسار داخل \u{2066}public/\u{2069}، مثل \u{2066}imges/All_section/biology.png\u{2069}، أو رابط يبدأ بـ \u{2066}https://\u{2069}",
                t('admin.form.cover_hint')
            );
            self::assertStringContainsString("\u{2066}https://\u{2069}", t('admin.validation.cover_url'));
            self::assertStringContainsString("«\u{2066}imges/All_section/book.png\u{2069}»", t('admin.validation.cover_path'));
        } finally {
            use_locale(null);
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

    /**
     * The header's cart link shows the number of items on its icon, so its
     * accessible name contains that number (WCAG 2.5.3, Label in Name) in
     * every plural form of both languages.
     */
    public function testTheCartLinkNameContainsTheCountShownOnTheIcon(): void
    {
        foreach (['en', 'ar'] as $locale) {
            use_locale($locale);
            try {
                foreach ([0, 1, 2, 3, 10, 11, 99, 100, 101, 102] as $count) {
                    self::assertMatchesRegularExpression('/(?<![0-9])' . $count . '(?![0-9])/', tn('common.nav.cart', $count), "$locale, $count items");
                }
            } finally {
                use_locale(null);
            }
        }
        use_locale('ar');
        try {
            self::assertSame('السلة (0)، لا توجد عناصر', tn('common.nav.cart', 0));
            self::assertSame('السلة (2)، عنصران', tn('common.nav.cart', 2));
        } finally {
            use_locale(null);
        }
    }

    /**
     * Flash messages quote titles and names isolated (bidi_isolate()), and the
     * page shows each isolate as a <bdi> element, after escaping.
     */
    public function testFlashMessagesShowIsolatedValuesAsBdiElements(): void
    {
        $message = t('admin.dashboard.added', ['title' => bidi_isolate('<ألف ليلة وليلة>')]);
        self::assertSame("“\u{2068}<ألف ليلة وليلة>\u{2069}” was added.", $message);
        self::assertSame('“<bdi>&lt;ألف ليلة وليلة&gt;</bdi>” was added.', bidi_isolates_html($message));

        $titles = implode(', ', [bidi_isolate('Esabella'), bidi_isolate('Biology')]);
        self::assertSame('<bdi>Esabella</bdi>, <bdi>Biology</bdi>', bidi_isolates_html($titles));
        // Other text is only escaped; an isolate character without its partner stays as it is.
        self::assertSame('Fish &amp; Chips', bidi_isolates_html('Fish & Chips'));
        self::assertSame("\u{2068}Fish &amp; Chips", bidi_isolates_html("\u{2068}Fish & Chips"));
    }

    public function testCheckoutMessagesResolveToEnglishText(): void
    {
        // Translations are loaded from the source tree, independent of BASE_PATH.
        self::assertSame('Your cart is empty.', t('shop.checkout.empty_cart'));
        self::assertSame('The minimum order total is SAR 2.00. Please add another book.', t('shop.checkout.minimum_total', ['amount' => 'SAR 2.00']));
        self::assertSame('shop.no.such.key', t('shop.no.such.key'));
    }
}
