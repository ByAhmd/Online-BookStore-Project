<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    private const SEED_COVER = 'imges/All_section/biology.png';

    /* -----------------------------------------------------------------
     * Email
     * --------------------------------------------------------------- */

    public function testNormalizeEmail(): void
    {
        self::assertSame('john@example.com', normalize_email("  John@Example.COM \n"));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function emails(): iterable
    {
        yield 'valid' => ['reader@example.com', true];
        yield 'plus addressing' => ['reader+books@example.co.uk', true];
        yield 'empty' => ['', false];
        yield 'no at sign' => ['reader.example.com', false];
        yield 'no domain' => ['reader@', false];
        yield 'spaces' => ['re ader@example.com', false];
        yield 'markup' => ['<script>@example.com', false];
        yield 'header injection' => ["reader@example.com\r\nBcc: x@example.com", false];
        // RFC 5321 caps addresses at 254 characters; the column is VARCHAR(255).
        yield '254 characters' => [str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 57) . '.com', true];
        yield '256 characters' => [str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 59) . '.com', false];
        yield 'local part over 64 characters' => [str_repeat('a', 65) . '@example.com', false];
    }

    #[DataProvider('emails')]
    public function testValidateEmail(string $email, bool $valid): void
    {
        $error = validate_email($email);

        $valid ? self::assertNull($error) : self::assertIsString($error);
    }

    public function testEmailBoundaryFixturesHaveTheIntendedLength(): void
    {
        $cases = iterator_to_array(self::emails());
        self::assertSame(254, strlen($cases['254 characters'][0]));
        self::assertSame(256, strlen($cases['256 characters'][0]));
        self::assertSame('Please enter your email address.', validate_email(''));
    }

    /* -----------------------------------------------------------------
     * Names
     * --------------------------------------------------------------- */

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function names(): iterable
    {
        yield 'simple' => ['Ahmed', true];
        yield 'Arabic with space' => ['أحمد علي', true];
        yield 'apostrophe and hyphen' => ["Seán O'Brien-Smith", true];
        yield '100 multibyte characters (200 bytes)' => [str_repeat('ع', 100), true];
        yield '101 multibyte characters' => [str_repeat('ع', 101), false];
        yield '100 ASCII characters' => [str_repeat('a', 100), true];
        yield '101 ASCII characters' => [str_repeat('a', 101), false];
        yield 'empty' => ['', false];
        yield 'markup' => ['<script>alert(1)</script>', false];
        yield 'angle bracket' => ['Ahmed >', false];
        yield 'tab' => ["Ahmed\tAli", false];
        yield 'newline' => ["Ahmed\nAli", false];
        yield 'NUL byte' => ["Ahmed\0", false];
        yield 'DEL' => ["Ahmed\x7F", false];
    }

    #[DataProvider('names')]
    public function testValidatePersonName(string $name, bool $valid): void
    {
        $error = validate_person_name($name);

        $valid ? self::assertNull($error) : self::assertIsString($error);
    }

    /* -----------------------------------------------------------------
     * Passwords
     * --------------------------------------------------------------- */

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function passwords(): iterable
    {
        yield '7 characters' => [str_repeat('a', 7), 'Password must be at least 8 characters long.'];
        yield '8 characters' => [str_repeat('a', 8), null];
        yield '72 bytes' => [str_repeat('a', 72), null];
        yield '73 bytes' => [str_repeat('a', 73), 'Password must be at most 72 bytes long.'];
        yield '8 multibyte characters (16 bytes)' => [str_repeat('é', 8), null];
        yield '7 multibyte characters (14 bytes) is too short' => [str_repeat('é', 7), 'Password must be at least 8 characters long.'];
        yield '36 two-byte characters (72 bytes)' => [str_repeat('é', 36), null];
        yield '37 two-byte characters (74 bytes)' => [str_repeat('é', 37), 'Password must be at most 72 bytes long.'];
        yield '25 emoji (100 bytes)' => [str_repeat('😀', 25), 'Password must be at most 72 bytes long.'];
        yield 'empty' => ['', 'Password must be at least 8 characters long.'];
    }

    #[DataProvider('passwords')]
    public function testValidateNewPasswordLength(string $password, ?string $expected): void
    {
        self::assertSame($expected, validate_new_password($password, $password));
    }

    public function testValidateNewPasswordRequiresMatchingConfirmation(): void
    {
        self::assertSame('Passwords do not match.', validate_new_password('long-enough-1', 'long-enough-2'));
        self::assertSame('Passwords do not match.', validate_new_password('long-enough', 'LONG-ENOUGH'));
        self::assertSame('Passwords do not match.', validate_new_password('long-enough', ''));
        self::assertNull(validate_new_password('long-enough', 'long-enough'));
    }

    /* -----------------------------------------------------------------
     * Date of birth
     * --------------------------------------------------------------- */

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function datesOfBirth(): iterable
    {
        yield 'empty (optional)' => ['', true];
        yield 'regular' => ['2000-01-31', true];
        yield 'leap day' => ['2024-02-29', true];
        yield '1900-01-01' => ['1900-01-01', true];
        yield '1899-12-31' => ['1899-12-31', false];
        yield 'non-leap Feb 29' => ['2023-02-29', false];
        yield 'Feb 30' => ['2024-02-30', false];
        yield 'month 13' => ['2000-13-01', false];
        yield 'day/month/year' => ['31/01/2000', false];
        yield 'missing zero padding' => ['2000-1-5', false];
        yield 'with time' => ['2000-01-31 10:00', false];
        yield 'trailing space' => ['2000-01-31 ', false];
        yield 'text' => ['yesterday', false];
        yield 'year 10000' => ['10000-01-01', false];
    }

    #[DataProvider('datesOfBirth')]
    public function testValidateDateOfBirth(string $date, bool $valid): void
    {
        $error = validate_date_of_birth($date);

        $valid ? self::assertNull($error) : self::assertIsString($error);
    }

    public function testDateOfBirthRelativeToToday(): void
    {
        $today = new DateTimeImmutable('today');

        self::assertNull(validate_date_of_birth($today->format('Y-m-d')), 'Today is not in the future');
        self::assertSame(
            'Date of birth must be a past date after 1900.',
            validate_date_of_birth($today->modify('+1 day')->format('Y-m-d'))
        );
        self::assertSame(
            'Date of birth must be a past date after 1900.',
            validate_date_of_birth($today->modify('+20 years')->format('Y-m-d'))
        );
    }

    /* -----------------------------------------------------------------
     * Genres and ratings
     * --------------------------------------------------------------- */

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function genres(): iterable
    {
        yield 'single word' => ['Mystery', true];
        yield 'two words' => ['Science Fiction', true];
        yield 'ampersand' => ['Rock & Roll', true];
        yield 'apostrophe' => ["Children's", true];
        yield 'hyphen' => ['Sci-Fi', true];
        yield 'digits' => ['1984 Classics', true];
        yield 'Arabic' => ['روايات', true];
        yield '100 characters' => [str_repeat('a', 100), true];
        yield '101 characters' => [str_repeat('a', 101), false];
        yield 'script tag' => ['<script>alert(1)</script>', false];
        yield 'script tag after a word' => ['Horror<script>alert(1)</script>', false];
        yield 'quote' => ['"Horror"', false];
        yield 'semicolon' => ['Horror; DROP TABLE books', false];
        yield 'leading space' => [' Horror', false];
        yield 'leading hyphen' => ['-Horror', false];
        yield 'slash' => ['Horror/Thriller', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('genres')]
    public function testValidateGenreName(string $genre, bool $valid): void
    {
        $error = validate_genre_name($genre);

        $valid ? self::assertNull($error) : self::assertIsString($error);
    }

    public function testGenreErrorUsesTheLabel(): void
    {
        self::assertSame('Genre is required.', validate_genre_name(''));
        self::assertSame('Favorite genre is required.', validate_genre_name('', 'Favorite genre'));
        self::assertStringStartsWith('Favorite genre may only contain', (string) validate_genre_name('<b>', 'Favorite genre'));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: int|null}>
     */
    public static function ratings(): iterable
    {
        yield '1' => ['1', 1];
        yield '5' => ['5', 5];
        yield 'int 3' => [3, 3];
        yield '0' => ['0', null];
        yield '6' => ['6', null];
        yield '3.5' => ['3.5', null];
        yield '-1' => ['-1', null];
        yield 'text' => ['five', null];
        yield 'null' => [null, null];
        yield 'array' => [['5'], null];
    }

    #[DataProvider('ratings')]
    public function testValidateRating(mixed $rating, ?int $expected): void
    {
        self::assertSame($expected, validate_rating($rating));
    }

    /* -----------------------------------------------------------------
     * Cover images
     * --------------------------------------------------------------- */

    public function testSeedCoverFixtureExists(): void
    {
        self::assertFileExists(BASE_PATH . '/public/' . self::SEED_COVER);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function coverImages(): iterable
    {
        yield 'existing seed path' => [self::SEED_COVER, true];
        yield 'https URL' => ['https://covers.example.com/book.png', true];
        yield 'https URL with query' => ['https://covers.example.com/book?id=1', true];
        yield 'plain http URL (blocked by CSP)' => ['http://covers.example.com/book.png', false];
        yield 'javascript scheme' => ['javascript:alert(1)', false];
        yield 'empty' => ['', false];
        yield 'dotenv traversal' => ['../.env', false];
        yield 'traversal inside imges' => ['imges/../../.env', false];
        yield 'traversal to an image' => ['imges/../imges/All_section/biology.png', false];
        yield 'missing file' => ['imges/All_section/does-not-exist.png', false];
        yield 'not an image extension' => ['imges/All_section/biology.php', false];
        yield 'double extension' => ['imges/All_section/biology.png.php', false];
        yield 'outside allowed dirs' => ['css/common.css', false];
        yield 'leading slash' => ['/' . self::SEED_COVER, false];
        yield 'backslashes' => ['imges\\All_section\\biology.png', false];
        yield 'javascript URL' => ['javascript:alert(1)', false];
        yield 'javascript URL with image suffix' => ['javascript:alert(1)//x.png', false];
        yield 'data URL' => ['data:image/png;base64,iVBORw0KGgo=', false];
        yield 'ftp URL' => ['ftp://covers.example.com/book.png', false];
        yield 'http without host' => ['http://', false];
        yield 'URL with space' => ['https://covers example.com/x.png', false];
        yield 'quote' => ['imges/"onerror=x.png', false];
        yield '256 characters' => ['https://covers.example.com/' . str_repeat('a', 225) . '.png', false];
    }

    #[DataProvider('coverImages')]
    public function testValidateCoverImage(string $value, bool $valid): void
    {
        $error = validate_cover_image($value);

        $valid ? self::assertNull($error) : self::assertIsString($error);
    }

    public function testCoverImageMessages(): void
    {
        self::assertSame('Cover image is required.', validate_cover_image(''));
        self::assertSame(256, strlen('https://covers.example.com/' . str_repeat('a', 225) . '.png'));
        self::assertStringStartsWith('No image exists at', (string) validate_cover_image('imges/All_section/does-not-exist.png'));
    }

    /* -----------------------------------------------------------------
     * Admin book form
     * --------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function bookInput(array $overrides = []): array
    {
        return $overrides + [
            'title' => '  The Test Book  ',
            'author' => 'Ada Author',
            'genre' => 'Mystery',
            'description' => "Line one.\nLine two.",
            'price' => '19.99',
            'cover_image' => self::SEED_COVER,
        ];
    }

    public function testValidBookInputIsTrimmedAndNormalized(): void
    {
        [$book, $errors] = validate_book_input(self::bookInput(['price' => ' 19.9 ']));

        self::assertSame([], $errors);
        self::assertSame([
            'title' => 'The Test Book',
            'author' => 'Ada Author',
            'genre' => 'Mystery',
            'description' => "Line one.\nLine two.",
            'price' => '19.90',
            'cover_image' => self::SEED_COVER,
        ], $book);
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function prices(): iterable
    {
        yield 'two decimals' => ['19.99', '19.99'];
        yield 'one decimal' => ['19.9', '19.90'];
        yield 'integer' => ['5', '5.00'];
        yield 'minimum' => ['1.00', '1.00'];
        yield 'minimum without decimals' => ['1', '1.00'];
        yield 'maximum' => ['10000', '10000.00'];
        yield 'maximum with decimals' => ['10000.00', '10000.00'];
        yield 'below minimum' => ['0.99', null];
        yield 'zero' => ['0', null];
        yield 'above maximum' => ['10000.01', null];
        yield 'five-digit above maximum' => ['99999.99', null];
        yield 'six digits' => ['100000', null];
        yield 'three decimals' => ['19.999', null];
        yield 'trailing dot' => ['19.', null];
        yield 'leading dot' => ['.99', null];
        yield 'comma decimal' => ['19,99', null];
        yield 'negative' => ['-5', null];
        yield 'exponent' => ['1e3', null];
        yield 'currency sign' => ['$19.99', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('prices')]
    public function testBookPrice(string $price, ?string $normalized): void
    {
        [$book, $errors] = validate_book_input(self::bookInput(['price' => $price]));

        if ($normalized === null) {
            self::assertArrayHasKey('price', $errors);
            self::assertSame(['price'], array_keys($errors));
        } else {
            self::assertSame([], $errors);
            self::assertSame($normalized, $book['price']);
        }
    }

    public function testBookFieldErrors(): void
    {
        [, $errors] = validate_book_input([
            'title' => '   ',
            'author' => ['array'],
            'genre' => '<script>',
            'description' => '',
            'price' => 'free',
            'cover_image' => '../.env',
        ]);

        self::assertSame(['title', 'author', 'genre', 'description', 'price', 'cover_image'], array_keys($errors));
        self::assertSame('Title is required.', $errors['title']);
        self::assertSame('Author is required.', $errors['author']);
        self::assertSame('Description is required.', $errors['description']);
    }

    public function testBookLengthLimits(): void
    {
        [, $ok] = validate_book_input(self::bookInput([
            'title' => str_repeat('t', 255),
            'author' => str_repeat('ع', 255),
            'description' => str_repeat('d', 5000),
        ]));
        self::assertSame([], $ok);

        [, $errors] = validate_book_input(self::bookInput([
            'title' => str_repeat('t', 256),
            'author' => str_repeat('ع', 256),
            'description' => str_repeat('d', 5001),
        ]));
        self::assertSame(['title', 'author', 'description'], array_keys($errors));
    }

    public function testMissingFieldsAreReportedNotFatal(): void
    {
        [$book, $errors] = validate_book_input([]);

        self::assertSame(['title', 'author', 'genre', 'description', 'price', 'cover_image'], array_keys($errors));
        self::assertSame('', $book['title']);
    }
}
