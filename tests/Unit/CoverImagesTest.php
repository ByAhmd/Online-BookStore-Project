<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use BookWise\Tests\Support\HtmlDocument;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * WebP copies of covers (create_webp_variants() and its helpers in
 * src/uploads.php), the copies committed for the catalog, and the <picture>
 * markup that offers them (cover_picture()).
 */
#[RequiresPhpExtension('gd')]
#[RequiresFunction('imagewebp')]
final class CoverImagesTest extends TestCase
{
    private const SEED_COVER = 'imges/All_section/biology.png';

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bookwise-webp-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /**
     * Writes a test image: red, with a transparent top-left corner (PNG, GIF, WebP).
     */
    private function image(string $name, int $width, int $height, bool $palette = false): string
    {
        $image = $palette ? imagecreate($width, $height) : imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        if (!$palette) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        $red = imagecolorallocatealpha($image, 198, 40, 40, 0);
        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
        self::assertNotFalse($red);
        self::assertNotFalse($clear);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $red);
        imagefilledrectangle($image, 0, 0, 3, 3, $clear);
        if ($palette) {
            imagecolortransparent($image, $clear);
        }

        $file = $this->directory . '/' . $name;
        match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'png' => imagepng($image, $file),
            'jpg', 'jpeg' => imagejpeg($image, $file),
            'gif' => imagegif($image, $file),
            'webp' => imagewebp($image, $file),
        };
        return $file;
    }

    /**
     * @return array{0: int, 1: int, 2: string} width, height, MIME type
     */
    private static function dimensions(string $file): array
    {
        clearstatcache(true, $file);
        $info = getimagesize($file);
        self::assertIsArray($info, "$file is not an image");
        return [$info[0], $info[1], $info['mime']];
    }

    /* -----------------------------------------------------------------
     * File names
     * --------------------------------------------------------------- */

    public function testCopiesAreNamedAfterTheImage(): void
    {
        self::assertSame(
            ['full' => 'imges/All_section/biology.webp', 'thumb' => 'imges/All_section/biology-w190.webp'],
            webp_variant_paths(self::SEED_COVER)
        );
        self::assertSame(['full' => '/srv/a/b.webp', 'thumb' => '/srv/a/b-w190.webp'], webp_variant_paths('/srv/a/b.jpeg'));
        self::assertSame(['full' => 'my cover.webp', 'thumb' => 'my cover-w190.webp'], webp_variant_paths('my cover.JPG'));

        // A WebP image is its own full-size copy.
        $upload = 'uploads/covers/' . str_repeat('ab', 16) . '.webp';
        self::assertSame($upload, webp_variant_paths($upload)['full']);
        self::assertSame('x.WEBP', webp_variant_paths('x.WEBP')['full']);
    }

    /* -----------------------------------------------------------------
     * Writing and deleting copies
     * --------------------------------------------------------------- */

    public function testACoverGetsAFullSizeCopyAndAThumbnail(): void
    {
        $cover = $this->image('cover.png', 380, 605);
        $paths = webp_variant_paths($cover);

        self::assertSame([$paths['full'], $paths['thumb']], create_webp_variants($cover));
        self::assertSame([380, 605, 'image/webp'], self::dimensions($paths['full']));
        self::assertSame([COVER_THUMB_WIDTH, 303, 'image/webp'], self::dimensions($paths['thumb']), 'Same proportions, 190 pixels wide');
        self::assertLessThan(filesize($cover), filesize($paths['thumb']));
    }

    public function testTheFullSizeCopyCanBeLimitedInWidth(): void
    {
        $large = $this->image('large.png', 1000, 1600);
        $paths = webp_variant_paths($large);
        create_webp_variants($large, false, 500);
        self::assertSame([500, 800, 'image/webp'], self::dimensions($paths['full']));
        self::assertSame([190, 304, 'image/webp'], self::dimensions($paths['thumb']));

        // Smaller images are never enlarged.
        $small = $this->image('small.png', 300, 480);
        create_webp_variants($small, false, 500);
        self::assertSame([300, 480, 'image/webp'], self::dimensions(webp_variant_paths($small)['full']));
    }

    public function testImagesNoWiderThanTheThumbnailGetOnlyTheFullSizeCopy(): void
    {
        $icon = $this->image('icon.png', COVER_THUMB_WIDTH, 60);
        $paths = webp_variant_paths($icon);

        self::assertSame([$paths['full']], create_webp_variants($icon));
        self::assertFileDoesNotExist($paths['thumb']);
    }

    public function testJpegAndGifImagesAreCopiedToo(): void
    {
        foreach (['photo.jpg' => 'jpeg', 'anim.gif' => 'gif'] as $name => $type) {
            $image = $this->image($name, 400, 300);
            $paths = webp_variant_paths($image);
            self::assertSame([$paths['full'], $paths['thumb']], create_webp_variants($image), $type);
            self::assertSame([400, 300, 'image/webp'], self::dimensions($paths['full']), $type);
            self::assertSame([190, 143, 'image/webp'], self::dimensions($paths['thumb']), $type);
        }
    }

    public function testAWebpImageOnlyGetsAThumbnail(): void
    {
        $image = $this->image('upload.webp', 400, 640);
        $before = (string) file_get_contents($image);
        $paths = webp_variant_paths($image);

        self::assertSame([$paths['thumb']], create_webp_variants($image));
        self::assertSame($before, file_get_contents($image), 'The image itself is not rewritten');
        self::assertSame([190, 304, 'image/webp'], self::dimensions($paths['thumb']));
    }

    public function testTransparencyIsKept(): void
    {
        foreach (['truecolor.png' => false, 'palette.png' => true] as $name => $palette) {
            $paths = webp_variant_paths($this->image($name, 380, 605, $palette));
            create_webp_variants($this->directory . '/' . $name);
            foreach ($paths as $kind => $copy) {
                $image = imagecreatefromwebp($copy);
                self::assertNotFalse($image);
                $corner = imagecolorsforindex($image, imagecolorat($image, 0, 0));
                $middle = imagecolorsforindex($image, imagecolorat($image, (int) (imagesx($image) / 2), (int) (imagesy($image) / 2)));
                self::assertGreaterThan(100, $corner['alpha'], "$name $kind: the transparent corner stays transparent");
                self::assertSame(0, $middle['alpha'], "$name $kind: the cover stays opaque");
                self::assertGreaterThan(150, $middle['red'], "$name $kind");
            }
        }
    }

    public function testCopiesAreRewrittenOnlyWhenTheImageChanges(): void
    {
        $cover = $this->image('cover.png', 380, 605);
        $paths = webp_variant_paths($cover);
        create_webp_variants($cover);
        $past = time() - 3600;
        touch($cover, $past - 60);
        touch($paths['full'], $past);
        touch($paths['thumb'], $past);

        self::assertSame([], create_webp_variants($cover), 'Copies newer than the image are kept');
        clearstatcache();
        self::assertSame($past, filemtime($paths['full']));

        // Same second as the image: still up to date.
        touch($cover, $past);
        self::assertSame([], create_webp_variants($cover));

        // A newer image (e.g. a replaced cover) is copied again.
        touch($cover, $past + 60);
        self::assertSame([$paths['full'], $paths['thumb']], create_webp_variants($cover));

        // A missing copy is written again on its own.
        unlink($paths['thumb']);
        self::assertSame([$paths['thumb']], create_webp_variants($cover));

        self::assertSame([$paths['full'], $paths['thumb']], create_webp_variants($cover, true), '--force rewrites everything');
        self::assertSame([], glob($this->directory . '/*.tmp') ?: [], 'No temporary files are left behind');
    }

    public function testUnreadableImagesAreReported(): void
    {
        $file = $this->directory . '/not-an-image.png';
        file_put_contents($file, "<?php echo 'not an image'; ?>");

        $this->expectException(RuntimeException::class);
        create_webp_variants($file);
    }

    public function testDeletingCopiesNeverDeletesTheImage(): void
    {
        $cover = $this->image('cover.png', 380, 605);
        create_webp_variants($cover);
        delete_webp_variants($cover);
        self::assertSame(['cover.png'], array_map('basename', glob($this->directory . '/*') ?: []));

        $webp = $this->image('upload.webp', 400, 640);
        create_webp_variants($webp);
        delete_webp_variants($webp);
        self::assertFileExists($webp, 'A WebP image is its own full-size copy, but never deleted as one');
        self::assertFileDoesNotExist(webp_variant_paths($webp)['thumb']);

        delete_webp_variants($this->directory . '/missing.png');
    }

    /* -----------------------------------------------------------------
     * The committed catalog copies
     * --------------------------------------------------------------- */

    public function testEveryCatalogImageHasCurrentWebpCopies(): void
    {
        $root = BASE_PATH . '/public/imges';
        $checked = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !in_array(strtolower($file->getExtension()), ['png', 'jpg', 'jpeg', 'gif'], true)) {
                continue;
            }
            $image = str_replace('\\', '/', $file->getPathname());
            $paths = webp_variant_paths($image);
            [$width, $height] = self::dimensions($image);
            $hint = "Run php bin/optimize-images.php and commit the copies of $image.";

            self::assertFileExists($paths['full'], $hint);
            self::assertSame([$width, $height, 'image/webp'], self::dimensions($paths['full']), $hint);
            if ($width > COVER_THUMB_WIDTH) {
                self::assertFileExists($paths['thumb'], $hint);
                self::assertSame(
                    [COVER_THUMB_WIDTH, max(1, (int) round($height * COVER_THUMB_WIDTH / $width)), 'image/webp'],
                    self::dimensions($paths['thumb']),
                    $hint
                );
            } else {
                self::assertFileDoesNotExist($paths['thumb']);
            }
            $checked++;
        }
        self::assertGreaterThanOrEqual(self::seedCoverCount(), $checked);
    }

    private static function seedCoverCount(): int
    {
        return substr_count((string) file_get_contents(BASE_PATH . '/database/seed.sql'), "', 'imges/");
    }

    /* -----------------------------------------------------------------
     * srcset and <picture>
     * --------------------------------------------------------------- */

    public function testSrcsetListsTheThumbnailAndTheFullSizeCopy(): void
    {
        self::assertSame(
            asset('imges/All_section/biology-w190.webp') . ' 190w, ' . asset('imges/All_section/biology.webp') . ' 380w',
            webp_srcset(self::SEED_COVER)
        );
        self::assertMatchesRegularExpression('/^imges\/All_section\/biology-w190\.webp\?v=\d+ 190w, /', webp_srcset(self::SEED_COVER));
        // An image no wider than the thumbnail has only its full-size copy.
        self::assertSame(asset('imges/eye.webp') . ' 44w', webp_srcset('imges/eye.png'));
    }

    public function testSrcsetIsEmptyWithoutCopies(): void
    {
        foreach (['', 'https://images.example.com/cover.png', 'imges/missing.png', 'imges/../imges/All_section/biology.png', 'uploads/covers/' . str_repeat('0', 32) . '.png'] as $src) {
            self::assertSame('', webp_srcset($src), $src);
        }
    }

    public function testCoverPictureOffersTheWebpCopiesWithTheOriginalAsFallback(): void
    {
        $html = cover_picture(self::SEED_COVER, '(max-width: 720px) 220px, 260px', ['class' => 'pic', 'width' => 260, 'height' => 414, 'loading' => 'lazy']);

        self::assertStringStartsWith('<picture><source type="image/webp" srcset="', $html);
        self::assertStringEndsWith('></picture>', $html);
        $document = new HtmlDocument($html);
        $source = $document->first('//picture/source');
        self::assertNotNull($source);
        self::assertSame('image/webp', $source->getAttribute('type'));
        self::assertSame(webp_srcset(self::SEED_COVER), $source->getAttribute('srcset'));
        self::assertSame('(max-width: 720px) 220px, 260px', $source->getAttribute('sizes'));

        // libxml does not know <source> is empty and nests the <img> in it.
        $img = $document->first('//picture//img');
        self::assertNotNull($img);
        self::assertSame(asset(self::SEED_COVER), $img->getAttribute('src'));
        self::assertMatchesRegularExpression('/\?v=\d+$/', $img->getAttribute('src'));
        self::assertSame(
            ['class' => 'pic', 'width' => '260', 'height' => '414', 'loading' => 'lazy', 'alt' => ''],
            array_diff_key(self::attributes($img), ['src' => true])
        );
    }

    public function testAttributesAreEscaped(): void
    {
        $html = cover_picture(self::SEED_COVER, '50vw" onerror="x', ['alt' => 'Cover of "1984" <b>&</b>']);

        self::assertStringContainsString('alt="Cover of &quot;1984&quot; &lt;b&gt;&amp;&lt;/b&gt;"', $html);
        self::assertStringContainsString('sizes="50vw&quot; onerror=&quot;x"', $html);
        self::assertSame([], (new HtmlDocument($html))->eventHandlerAttributes());
    }

    public function testInvalidAttributeNamesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        cover_picture(self::SEED_COVER, '50vw', ['onerror="alert(1)" x' => '']);
    }

    public function testExternalCoversAreShownWithoutCopies(): void
    {
        $url = 'https://images.example.com/cover.png?size=large&v=2';

        self::assertSame(
            '<picture><img src="https://images.example.com/cover.png?size=large&amp;v=2" alt=""></picture>',
            cover_picture($url, '50vw')
        );
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(\DOMElement $element): array
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[$attribute->nodeName] = $attribute->value;
        }
        return $attributes;
    }
}
