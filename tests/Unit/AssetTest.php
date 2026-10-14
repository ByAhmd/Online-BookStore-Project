<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * asset(): versioned URLs for the files pages link (cached for a year by the
 * web server, see public/.htaccess).
 */
final class AssetTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function localFiles(): iterable
    {
        yield 'stylesheet' => ['css/common.css'];
        yield 'script' => ['js/explore.js'];
        yield 'image' => ['imges/cart-icon.png'];
        yield 'cover in a sub-folder' => ['imges/All_section/biology.png'];
        yield 'photo' => ['imges/header.jpeg'];
    }

    #[DataProvider('localFiles')]
    public function testLocalFilesCarryTheirModificationTime(string $path): void
    {
        $modified = filemtime(BASE_PATH . '/public/' . $path);
        self::assertIsInt($modified);

        self::assertSame($path . '?v=' . $modified, asset($path));
    }

    public function testAChangedFileGetsANewUrl(): void
    {
        $path = 'css/asset-test-' . bin2hex(random_bytes(4)) . '.css';
        $file = BASE_PATH . '/public/' . $path;
        file_put_contents($file, 'body {}');
        try {
            touch($file, 1_700_000_000);
            clearstatcache();
            self::assertSame($path . '?v=1700000000', asset($path));
            touch($file, 1_700_000_100);
            clearstatcache();
            self::assertSame($path . '?v=1700000100', asset($path));
        } finally {
            unlink($file);
        }
    }

    public function testUploadsKeepTheirPlainUrl(): void
    {
        // Random names never get new contents; uploads are cached briefly instead.
        self::assertSame('uploads/covers/' . str_repeat('a', 32) . '.png', asset('uploads/covers/' . str_repeat('a', 32) . '.png'));
        self::assertSame('uploads/avatars/x.png', asset('uploads/avatars/x.png'));
    }

    public function testMissingFilesAndUnsafePathsGetNoVersion(): void
    {
        self::assertSame('css/missing.css', asset('css/missing.css'));
        self::assertSame('../composer.json', asset('../composer.json'));
        self::assertSame('imges/../../.env', asset('imges/../../.env'));
        self::assertSame('imges', asset('imges'), 'A directory is not a file');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function externalUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'https' => ['https://images.example.com/cover.png'];
        yield 'protocol-relative' => ['//cdn.example.com/a.css'];
        yield 'data URL' => ['data:image/png;base64,AAAA'];
    }

    #[DataProvider('externalUrls')]
    public function testExternalUrlsAreReturnedAsGiven(string $url): void
    {
        self::assertSame($url, asset($url));
    }

    public function testPathSegmentsArePercentEncoded(): void
    {
        // Spaces and commas would split a srcset candidate; "#" would start a fragment.
        self::assertSame('uploads/covers/my%20cover%2C%20v2%231.png', asset('uploads/covers/my cover, v2#1.png'));
        self::assertSame('imges/%D9%83%D8%AA%D8%A7%D8%A8.png', asset('imges/كتاب.png'));
    }
}
