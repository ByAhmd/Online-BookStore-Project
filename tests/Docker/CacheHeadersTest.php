<?php

declare(strict_types=1);

namespace BookWise\Tests\Docker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Browser caching rules for Apache, in public/.htaccess (classic Apache
 * hosts) and docker/apache/site.conf (the image). The built-in PHP server of
 * the HTTP suite ignores both, so the rules are checked as configuration:
 * versioned URLs (asset(): ?v=<modification time>) for a year, uploads for
 * an hour, other static files for a day, and never PHP pages.
 */
final class CacheHeadersTest extends TestCase
{
    private const VERSIONED = 'Header set Cache-Control "public, max-age=31536000, immutable" "expr=%{QUERY_STRING} =~ /^v=[0-9]+$/"';
    private const DEFAULT = 'Header set Cache-Control "public, max-age=86400"';

    /**
     * @return array<string, array{0: string, 1: string, 2: string}> file, IfModule name, uploads rule
     */
    public static function configurations(): array
    {
        return [
            'public/.htaccess' => ['public/.htaccess', 'mod_headers.c', 'Header set Cache-Control "public, max-age=3600" "expr=%{REQUEST_URI} =~ m#/uploads/#"'],
            'docker/apache/site.conf' => ['docker/apache/site.conf', 'headers_module', 'Header set Cache-Control "public, max-age=3600" "expr=%{REQUEST_URI} =~ m#^/uploads/#"'],
        ];
    }

    #[DataProvider('configurations')]
    public function testStaticFilesGetTheirLifetimeInTheRightOrder(string $file, string $module, string $uploadsRule): void
    {
        $block = self::cacheBlock($file, $module);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), static fn (string $line): bool => str_starts_with($line, 'Header ')));

        // Later "Header set" lines win: the uploads rule must come after the versioned one.
        self::assertSame([self::DEFAULT, self::VERSIONED, $uploadsRule], $lines, "$file: cache rules");
    }

    /**
     * PHPUnit warns about data sets with more values than the test takes.
     *
     * @return array<string, array{0: string, 1: string}> file, IfModule name
     */
    public static function cacheBlocks(): array
    {
        return array_map(static fn (array $configuration): array => [$configuration[0], $configuration[1]], self::configurations());
    }

    #[DataProvider('cacheBlocks')]
    public function testOnlyStaticFilesAreCached(string $file, string $module): void
    {
        self::assertSame(1, preg_match('/<FilesMatch "([^"]+)">/', self::cacheBlock($file, $module), $match), $file);
        $pattern = '/' . str_replace('/', '\/', $match[1]) . '/';

        foreach (['common.css', 'explore.js', 'biology.png', 'biology-w190.webp', 'header.jpeg', 'backgroound.jpg', 'COVER.PNG', 'logo.svg'] as $static) {
            self::assertSame(1, preg_match($pattern, $static), "$file must cache $static");
        }
        foreach (['index.php', 'Home_Page.php', 'fetch_books.php', 'health.php', 'cover.png.php', '.htaccess'] as $page) {
            self::assertSame(0, preg_match($pattern, $page), "$file must never cache $page");
        }
    }

    public function testTheVersionRuleMatchesTheUrlsAssetBuilds(): void
    {
        foreach (['css/common.css', 'js/common.js', 'imges/All_section/biology.png'] as $path) {
            $query = (string) parse_url(asset($path), PHP_URL_QUERY);
            self::assertSame(1, preg_match('/^v=[0-9]+$/', $query), "asset('$path') gives ?$query");
        }
    }

    public function testHostsWithoutModHeadersFallBackToModExpires(): void
    {
        $htaccess = str_replace("\r\n", "\n", self::read('public/.htaccess'));
        self::assertSame(1, preg_match('/<IfModule !mod_headers\.c>\s*<IfModule mod_expires\.c>(.*?)<\/IfModule>\s*<\/IfModule>/s', $htaccess, $match));
        self::assertStringContainsString('ExpiresActive On', $match[1]);
        foreach (['text/css', 'image/png', 'image/jpeg', 'image/webp'] as $type) {
            self::assertStringContainsString("ExpiresByType $type \"access plus 1 hour\"", $match[1]);
        }
        self::assertStringNotContainsString('text/html', $match[1]);
    }

    public function testTheImageEnablesModHeaders(): void
    {
        self::assertMatchesRegularExpression('/a2enmod [^;\n]*\bheaders\b/', self::read('Dockerfile'));
    }

    /**
     * The <IfModule $module> block that holds the Cache-Control rules.
     */
    private static function cacheBlock(string $file, string $module): string
    {
        $contents = str_replace("\r\n", "\n", self::read($file));
        preg_match_all('/<IfModule ' . preg_quote($module, '/') . '>(.*?)\n\s*<\/IfModule>/s', $contents, $matches);
        foreach ($matches[1] as $block) {
            if (str_contains($block, 'Cache-Control')) {
                return $block;
            }
        }
        self::fail("$file has no <IfModule $module> block with Cache-Control rules");
    }

    private static function read(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/' . $file;
        self::assertFileExists($path);
        return (string) file_get_contents($path);
    }
}
