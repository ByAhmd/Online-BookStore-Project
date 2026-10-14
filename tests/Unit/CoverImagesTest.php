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

    /**
     * Largest difference (0-255) of one colour in one cell of two thumbprints
     * that still show the same picture. The committed copies differ from
     * their images by at most 3; the two closest covers of the catalog by 64.
     */
    private const PICTURE_TOLERANCE = 16;

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
            ['full' => 'imges/All_section/biology.webp', 'thumb' => 'imges/All_section/biology-w190.webp', 'capped' => 'imges/All_section/biology-w780.webp'],
            webp_variant_paths(self::SEED_COVER)
        );
        self::assertSame(['full' => '/srv/a/b.webp', 'thumb' => '/srv/a/b-w190.webp', 'capped' => '/srv/a/b-w780.webp'], webp_variant_paths('/srv/a/b.jpeg'));
        self::assertSame(['full' => 'my cover.webp', 'thumb' => 'my cover-w190.webp', 'capped' => 'my cover-w780.webp'], webp_variant_paths('my cover.JPG'));

        // A WebP image is its own full-size copy.
        $upload = 'uploads/covers/' . str_repeat('ab', 16) . '.webp';
        self::assertSame($upload, webp_variant_paths($upload)['full']);
        self::assertSame('x.WEBP', webp_variant_paths('x.WEBP')['full']);
    }

    public function testCopiesKnowTheImageTheyWereMadeFrom(): void
    {
        $cover = $this->image('cover.png', 380, 605);
        create_webp_variants($cover);
        $upload = $this->image('upload.webp', 1000, 1600);
        create_webp_variants($upload, false, 500);
        // An image whose own name looks like a thumbnail.
        $odd = $this->image('odd-w190.jpg', 400, 300);
        create_webp_variants($odd);
        $scan = $this->image('scan.PNG', 300, 400);
        create_webp_variants($scan);

        foreach (webp_variant_paths($cover) as $kind => $copy) {
            if ($kind !== 'capped') {
                self::assertSame($cover, webp_copy_source($copy), $kind);
            }
        }
        self::assertSame($upload, webp_copy_source(webp_variant_paths($upload)['thumb']));
        self::assertSame($upload, webp_copy_source(webp_variant_paths($upload)['capped']));
        self::assertSame($odd, webp_copy_source($this->directory . '/odd-w190.webp'));
        self::assertSame($odd, webp_copy_source($this->directory . '/odd-w190-w190.webp'));
        // Windows finds "scan.png" for "scan.PNG": file names are case-insensitive there.
        self::assertEqualsIgnoringCase($scan, webp_copy_source($this->directory . '/scan.webp'));

        // Images are not copies, and neither are WebP files without their image.
        foreach ([$cover, $upload, $odd, $scan] as $image) {
            self::assertNull(webp_copy_source($image), basename($image));
        }
        foreach (['lonely.webp', 'stray-w190.webp', 'odd.webp', 'cover-w300.webp'] as $name) {
            file_put_contents($this->directory . '/' . $name, 'not checked');
            self::assertNull(webp_copy_source($this->directory . '/' . $name), $name);
        }
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
        self::assertFileDoesNotExist($paths['capped']);
    }

    public function testAWebpImageWiderThanTheLimitGetsACappedCopy(): void
    {
        $image = $this->image('upload.webp', 1000, 1600);
        $before = (string) file_get_contents($image);
        $paths = webp_variant_paths($image);

        self::assertSame([$paths['capped'], $paths['thumb']], create_webp_variants($image, false, 500));
        self::assertSame($before, file_get_contents($image), 'The image itself is not rewritten');
        self::assertSame([500, 800, 'image/webp'], self::dimensions($paths['capped']));
        self::assertSame([190, 304, 'image/webp'], self::dimensions($paths['thumb']));
        self::assertSame([], create_webp_variants($image, false, 500), 'Up to date');

        // A WebP image within the limit is its own full-size copy.
        $narrow = $this->image('narrow.webp', 400, 640);
        self::assertSame([webp_variant_paths($narrow)['thumb']], create_webp_variants($narrow, false, 500));

        delete_webp_variants($image);
        self::assertSame(['narrow-w190.webp', 'narrow.webp', 'upload.webp'], self::fileNames($this->directory));
    }

    /**
     * @return list<string> Sorted names of the files in $directory
     */
    private static function fileNames(string $directory): array
    {
        $names = array_map('basename', glob($directory . '/*') ?: []);
        sort($names);
        return $names;
    }

    public function testTransparencyIsKept(): void
    {
        foreach (['truecolor.png' => false, 'palette.png' => true] as $name => $palette) {
            $paths = webp_variant_paths($this->image($name, 380, 605, $palette));
            create_webp_variants($this->directory . '/' . $name);
            foreach (['full' => $paths['full'], 'thumb' => $paths['thumb']] as $kind => $copy) {
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
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            $image = str_replace('\\', '/', $file->getPathname());
            if (strtolower($file->getExtension()) === 'webp') {
                self::assertNotNull(webp_copy_source($image), "$image is not the copy of an image: delete it, or add its image.");
            }
            if (!in_array(strtolower($file->getExtension()), ['png', 'jpg', 'jpeg', 'gif'], true)) {
                continue;
            }
            $paths = webp_variant_paths($image);
            [$width, $height] = self::dimensions($image);
            $hint = "Run php bin/optimize-images.php and commit the copies of $image.";
            $picture = self::thumbprint($image);

            self::assertFileExists($paths['full'], $hint);
            self::assertSame([$width, $height, 'image/webp'], self::dimensions($paths['full']), $hint);
            self::assertSamePicture($picture, $paths['full'], $hint);
            if ($width > COVER_THUMB_WIDTH) {
                self::assertFileExists($paths['thumb'], $hint);
                self::assertSame(
                    [COVER_THUMB_WIDTH, max(1, (int) round($height * COVER_THUMB_WIDTH / $width)), 'image/webp'],
                    self::dimensions($paths['thumb']),
                    $hint
                );
                self::assertSamePicture($picture, $paths['thumb'], $hint);
            } else {
                self::assertFileDoesNotExist($paths['thumb']);
            }
            self::assertFileDoesNotExist($paths['capped']);
            $checked++;
        }
        self::assertGreaterThanOrEqual(self::seedCoverCount(), $checked);
    }

    public function testCopiesOfAReplacedImageNoLongerShowIt(): void
    {
        $cover = $this->image('cover.png', 380, 605);
        create_webp_variants($cover);
        $copy = webp_variant_paths($cover)['full'];
        self::assertLessThanOrEqual(self::PICTURE_TOLERANCE, self::pictureDifference(self::thumbprint($cover), self::thumbprint($copy)));

        // A new picture under the same name and size, its copies not rewritten.
        $replacement = imagecreatetruecolor(380, 605);
        self::assertNotFalse($replacement);
        imagefilledrectangle($replacement, 0, 0, 379, 302, (int) imagecolorallocate($replacement, 40, 60, 198));
        imagefilledrectangle($replacement, 0, 303, 379, 604, (int) imagecolorallocate($replacement, 198, 40, 40));
        imagepng($replacement, $cover);
        self::assertGreaterThan(self::PICTURE_TOLERANCE, self::pictureDifference(self::thumbprint($cover), self::thumbprint($copy)));

        // The two catalog covers closest to each other are far apart too.
        $a = self::thumbprint(BASE_PATH . '/public/imges/All_section/wonderbloom.png');
        $b = self::thumbprint(BASE_PATH . '/public/imges/Mystery_Section/those.png');
        self::assertGreaterThan(self::PICTURE_TOLERANCE * 2, self::pictureDifference($a, $b));
    }

    private static function seedCoverCount(): int
    {
        return substr_count((string) file_get_contents(BASE_PATH . '/database/seed.sql'), "', 'imges/");
    }

    /**
     * An image reduced to 8×8 cells over grey, as their red, green and blue
     * values: enough to tell two pictures apart without telling a WebP copy
     * from its PNG. Git does not keep file times, so the copies of a replaced
     * image can only be found out of date by their content.
     *
     * @return list<int>
     */
    private static function thumbprint(string $file): array
    {
        $image = imagecreatefromstring((string) file_get_contents($file));
        $cells = imagecreatetruecolor(8, 8);
        self::assertNotFalse($image, "$file is not an image");
        self::assertNotFalse($cells);
        imagefill($cells, 0, 0, (int) imagecolorallocate($cells, 128, 128, 128));
        imagealphablending($cells, true);
        imagecopyresampled($cells, $image, 0, 0, 0, 0, 8, 8, imagesx($image), imagesy($image));

        $values = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $rgb = imagecolorat($cells, $x, $y);
                array_push($values, ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
            }
        }
        return $values;
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     */
    private static function pictureDifference(array $a, array $b): int
    {
        return max(array_map(static fn (int $x, int $y): int => abs($x - $y), $a, $b));
    }

    /**
     * @param list<int> $picture Thumbprint of the image
     */
    private static function assertSamePicture(array $picture, string $copy, string $hint): void
    {
        $difference = self::pictureDifference($picture, self::thumbprint($copy));
        self::assertLessThanOrEqual(self::PICTURE_TOLERANCE, $difference, "$copy no longer shows its image. $hint");
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
