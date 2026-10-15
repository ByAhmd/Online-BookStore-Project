<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use UploadException;

#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
final class UploadsTest extends TestCase
{
    // Deliberately harmless: real web-shell strings get temp files blocked by antivirus scanners.
    private const PHP_PAYLOAD = "<?php echo 'bookwise-test-payload'; ?>\n";

    /** @var list<string> Temporary source files */
    private array $tempFiles = [];

    /** @var list<string> Stored avatar paths (relative to public/) to remove */
    private array $storedPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->storedPaths as $path) {
            delete_uploaded_avatar($path);
            delete_uploaded_cover($path); // with its WebP copies
            if (is_file(BASE_PATH . '/public/' . $path)) {
                unlink(BASE_PATH . '/public/' . $path);
            }
        }
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function tempFile(string $contents): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'bookwise-upload-');
        file_put_contents($file, $contents);
        $this->tempFiles[] = $file;
        return $file;
    }

    private function image(string $type, int $width = 12, int $height = 8): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 25, 57, 62));
        ob_start();
        match ($type) {
            'png' => imagepng($image),
            'jpeg' => imagejpeg($image),
            'gif' => imagegif($image),
            'webp' => imagewebp($image),
        };
        return (string) ob_get_clean();
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(string $contents, string $clientName = 'avatar.png', string $clientType = 'image/png'): array
    {
        $tmp = $this->tempFile($contents);
        return ['name' => $clientName, 'type' => $clientType, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contents)];
    }

    private function store(array $file): string
    {
        $path = store_avatar_upload($file);
        $this->storedPaths[] = $path;
        return $path;
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function imageTypes(): iterable
    {
        yield 'PNG' => ['png', 'png', 'image/png'];
        yield 'JPEG' => ['jpeg', 'jpg', 'image/jpeg'];
        yield 'GIF' => ['gif', 'gif', 'image/gif'];
        if (function_exists('imagewebp')) {
            yield 'WebP' => ['webp', 'webp', 'image/webp'];
        }
    }

    #[DataProvider('imageTypes')]
    public function testRealImagesAreStoredWithRandomNames(string $type, string $extension, string $mime): void
    {
        $path = $this->store($this->upload($this->image($type), "my photo.$extension", 'application/octet-stream'));

        self::assertMatchesRegularExpression('#^uploads/avatars/[a-f0-9]{32}\.' . $extension . '$#', $path);
        $stored = BASE_PATH . '/public/' . $path;
        self::assertFileExists($stored);
        $info = getimagesize($stored);
        self::assertIsArray($info);
        self::assertSame($mime, $info['mime']);
        self::assertSame([12, 8], [$info[0], $info[1]]);
        self::assertSame($path, avatar_url($path), 'A stored avatar is served from its own path');
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2: int, 3: int, 4: int}>
     */
    public static function largeProfilePictures(): iterable
    {
        yield 'landscape JPEG' => ['jpeg', 2000, 1500, AVATAR_MAX_DIMENSION, 384];
        yield 'portrait PNG' => ['png', 600, 1200, 256, AVATAR_MAX_DIMENSION];
        yield 'square GIF' => ['gif', 4000, 4000, AVATAR_MAX_DIMENSION, AVATAR_MAX_DIMENSION];
        if (function_exists('imagewebp')) {
            yield 'WebP, rounded' => ['webp', 1024, 513, AVATAR_MAX_DIMENSION, 257];
        }
    }

    #[DataProvider('largeProfilePictures')]
    public function testLargeProfilePicturesAreScaledDownInProportion(string $type, int $width, int $height, int $storedWidth, int $storedHeight): void
    {
        $path = $this->store($this->upload($this->image($type, $width, $height)));

        self::assertSame([$storedWidth, $storedHeight], array_slice(self::dimensions(BASE_PATH . '/public/' . $path), 0, 2));
    }

    public function testProfilePicturesNoLargerThanTheLimitKeepTheirSize(): void
    {
        $path = $this->store($this->upload($this->image('png', AVATAR_MAX_DIMENSION, 300)));

        self::assertSame([AVATAR_MAX_DIMENSION, 300, 'image/png'], self::dimensions(BASE_PATH . '/public/' . $path));
    }

    public function testAScaledDownProfilePictureKeepsItsTransparency(): void
    {
        $image = imagecreatetruecolor(1000, 1000);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, 500, 500, 600, 600, (int) imagecolorallocatealpha($image, 200, 30, 30, 0));
        ob_start();
        imagepng($image);
        $path = $this->store($this->upload((string) ob_get_clean()));

        $stored = imagecreatefrompng(BASE_PATH . '/public/' . $path);
        self::assertNotFalse($stored);
        self::assertSame(127, (imagecolorat($stored, 2, 2) >> 24) & 0x7F, 'The corner stays transparent');
        self::assertSame(0, (imagecolorat($stored, 256, 256) >> 24) & 0x7F, 'The middle stays opaque');
    }

    public function testAPhotoAtTheUploadLimitTakesLittleDiskSpace(): void
    {
        // Noise compresses badly: a 1600×1600 photo of it is close to the 2 MB upload limit,
        // and GD's re-encoding at full size would be larger than the upload.
        mt_srand(20261015);
        $image = imagecreatetruecolor(1600, 1600);
        for ($y = 0; $y < 1600; $y += 2) {
            for ($x = 0; $x < 1600; $x += 2) {
                imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, mt_rand(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagejpeg($image, null, 75);
        $photo = (string) ob_get_clean();
        self::assertLessThan(AVATAR_MAX_BYTES, strlen($photo));
        self::assertGreaterThan(AVATAR_MAX_BYTES / 2, strlen($photo));

        $path = $this->store($this->upload($photo, 'photo.jpg', 'image/jpeg'));

        self::assertLessThan(strlen($photo) / 4, (int) filesize(BASE_PATH . '/public/' . $path));
    }

    public function testEveryUploadGetsADifferentName(): void
    {
        $png = $this->image('png');

        self::assertNotSame($this->store($this->upload($png)), $this->store($this->upload($png)));
    }

    public function testTypeComesFromContentNotFromTheClient(): void
    {
        $path = $this->store($this->upload($this->image('png'), 'shell.php', 'application/x-php'));

        self::assertStringEndsWith('.png', $path);
        self::assertStringNotContainsString('shell', $path);
    }

    public function testPhpScriptNamedLikeAnImageIsRejected(): void
    {
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('Please upload a JPEG, PNG, GIF or WebP image.');

        $this->store($this->upload(self::PHP_PAYLOAD, 'avatar.png', 'image/png'));
    }

    public function testEmbeddedPhpPayloadIsStrippedByReencoding(): void
    {
        $polyglot = $this->image('png') . self::PHP_PAYLOAD;
        $path = $this->store($this->upload($polyglot, 'avatar.png'));

        $stored = (string) file_get_contents(BASE_PATH . '/public/' . $path);
        self::assertStringNotContainsString('<?php', $stored);
        self::assertStringStartsWith("\x89PNG", $stored);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function nonImages(): iterable
    {
        yield 'plain text' => ['just some text, definitely not an image'];
        yield 'HTML' => ['<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'];
        yield 'SVG' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="1" height="1"/></svg>'];
        yield 'truncated PNG header' => ["\x89PNG\r\n\x1a\n"];
        yield 'PDF' => ["%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"];
    }

    #[DataProvider('nonImages')]
    public function testNonImagesAreRejected(string $contents): void
    {
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('Please upload a JPEG, PNG, GIF or WebP image.');

        $this->store($this->upload($contents));
    }

    public function testOversizeFileIsRejected(): void
    {
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('Profile pictures must be 2 MB or smaller.');

        $this->store($this->upload($this->image('png') . str_repeat("\0", AVATAR_MAX_BYTES)));
    }

    public function testFileAtTheSizeLimitIsNotRejectedForSize(): void
    {
        $png = $this->image('png');
        $path = $this->store($this->upload($png . str_repeat("\0", AVATAR_MAX_BYTES - strlen($png))));

        self::assertStringEndsWith('.png', $path);
    }

    public function testOversizeDimensionsAreRejected(): void
    {
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('Images must be at most 4000×4000 pixels.');

        $this->store($this->upload($this->image('png', 4001, 1)));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function uploadErrors(): iterable
    {
        yield 'ini size' => [['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => ''], 'Profile pictures must be 2 MB or smaller.'];
        yield 'form size' => [['error' => UPLOAD_ERR_FORM_SIZE, 'tmp_name' => ''], 'Profile pictures must be 2 MB or smaller.'];
        yield 'partial' => [['error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => ''], 'The picture could not be uploaded. Please try again.'];
        yield 'no file' => [['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => ''], 'The picture could not be uploaded. Please try again.'];
        yield 'missing error key' => [[], 'The picture could not be uploaded. Please try again.'];
        yield 'error as string' => [['error' => '0', 'tmp_name' => __FILE__], 'The picture could not be uploaded. Please try again.'];
        yield 'tmp_name array' => [['error' => UPLOAD_ERR_OK, 'tmp_name' => ['x']], 'The picture could not be uploaded. Please try again.'];
    }

    /**
     * @param array<string, mixed> $file
     */
    #[DataProvider('uploadErrors')]
    public function testUploadErrors(array $file, string $message): void
    {
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage($message);

        $this->store($file);
    }

    public function testEmptyFileIsRejected(): void
    {
        $this->expectException(UploadException::class);

        $this->store($this->upload(''));
    }

    public function testDeleteOnlyRemovesGeneratedAvatarNames(): void
    {
        $dir = BASE_PATH . '/public/uploads/avatars';
        $decoy = $dir . '/keep-me-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($decoy, $this->image('png'));
        try {
            delete_uploaded_avatar('uploads/avatars/' . basename($decoy));
            delete_uploaded_avatar('uploads/avatars/../avatars/' . basename($decoy));
            delete_uploaded_avatar(DEFAULT_AVATAR);
            delete_uploaded_avatar(null);

            self::assertFileExists($decoy, 'Files without a generated name are never deleted');
            self::assertFileExists(BASE_PATH . '/public/' . DEFAULT_AVATAR);
        } finally {
            unlink($decoy);
        }

        $path = $this->store($this->upload($this->image('png')));
        delete_uploaded_avatar($path);
        self::assertFileDoesNotExist(BASE_PATH . '/public/' . $path);
    }

    /* -----------------------------------------------------------------
     * Book covers
     * --------------------------------------------------------------- */

    private function storeCover(array $file): string
    {
        $path = store_cover_upload($file);
        $this->storedPaths[] = $path;
        return $path;
    }

    #[DataProvider('imageTypes')]
    public function testCoversAreReencodedIntoTheirOwnDirectory(string $type, string $extension, string $mime): void
    {
        $path = $this->storeCover($this->upload($this->image($type, 30, 48), "cover.$extension", 'application/octet-stream'));

        self::assertMatchesRegularExpression('#^uploads/covers/[a-f0-9]{32}\.' . $extension . '$#', $path);
        $info = getimagesize(BASE_PATH . '/public/' . $path);
        self::assertIsArray($info);
        self::assertSame([$mime, 30, 48], [$info['mime'], $info[0], $info[1]]);
        self::assertSame($path, image_url($path, 'fallback.png'));
        self::assertNull(validate_cover_image($path), 'A stored cover is a valid cover_image value');
        self::assertTrue(is_generated_upload($path, COVER_DIR));
        self::assertFalse(is_generated_upload($path, AVATAR_DIR));
    }

    public function testCoversMayBeLargerThanProfilePictures(): void
    {
        $png = $this->image('png');
        $contents = $png . str_repeat("\0", 3 * 1024 * 1024 - strlen($png));

        self::assertStringEndsWith('.png', $this->storeCover($this->upload($contents)));
        try {
            $this->store($this->upload($contents));
            self::fail('A 3 MB profile picture must be rejected');
        } catch (UploadException $e) {
            self::assertSame('Profile pictures must be 2 MB or smaller.', $e->getMessage());
        }
    }

    public function testOversizeCoverIsRejected(): void
    {
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('Cover images must be 5 MB or smaller.');

        $this->storeCover($this->upload($this->image('png') . str_repeat("\0", COVER_MAX_BYTES)));
    }

    public function testPhpScriptNamedLikeACoverIsRejected(): void
    {
        $before = glob(BASE_PATH . '/public/uploads/covers/*') ?: [];
        try {
            $this->storeCover($this->upload(self::PHP_PAYLOAD, 'cover.png', 'image/png'));
            self::fail('Expected UploadException');
        } catch (UploadException $e) {
            self::assertSame('Please upload a JPEG, PNG, GIF or WebP image.', $e->getMessage());
        }
        self::assertSame($before, glob(BASE_PATH . '/public/uploads/covers/*') ?: [], 'Nothing is stored');
    }

    public function testPhpPayloadInsideACoverImageIsStripped(): void
    {
        $path = $this->storeCover($this->upload($this->image('jpeg') . self::PHP_PAYLOAD, 'cover.jpg', 'image/jpeg'));

        $stored = (string) file_get_contents(BASE_PATH . '/public/' . $path);
        self::assertStringNotContainsString('<?php', $stored);
        self::assertStringStartsWith("\xFF\xD8", $stored);
    }

    public function testCoverUploadErrorsHaveCoverMessages(): void
    {
        foreach ([
            [['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => ''], 'Cover images must be 5 MB or smaller.'],
            [['error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => ''], 'The cover image could not be uploaded. Please try again.'],
            [['error' => UPLOAD_ERR_OK, 'tmp_name' => ['x']], 'The cover image could not be uploaded. Please try again.'],
        ] as [$file, $message]) {
            try {
                $this->storeCover($file);
                self::fail('Expected UploadException: ' . $message);
            } catch (UploadException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testDeleteUploadedCoverOnlyRemovesGeneratedCoverNames(): void
    {
        $decoy = BASE_PATH . '/public/uploads/covers/keep-me-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($decoy, $this->image('png'));
        $avatar = $this->store($this->upload($this->image('png')));
        try {
            delete_uploaded_cover('uploads/covers/' . basename($decoy));
            delete_uploaded_cover($avatar);
            delete_uploaded_cover('imges/All_section/biology.png');
            delete_uploaded_cover(null);

            self::assertFileExists($decoy);
            self::assertFileExists(BASE_PATH . '/public/' . $avatar, 'Avatars are not covers');
            self::assertFileExists(BASE_PATH . '/public/imges/All_section/biology.png');
        } finally {
            unlink($decoy);
        }

        $cover = $this->storeCover($this->upload($this->image('png')));
        delete_uploaded_cover($cover);
        self::assertFileDoesNotExist(BASE_PATH . '/public/' . $cover);
    }

    public function testCoversGetWebpCopiesAndProfilePicturesDoNot(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD cannot write WebP here.');
        }
        $cover = $this->storeCover($this->upload($this->image('png', 400, 640)));
        $copies = webp_variant_paths(BASE_PATH . '/public/' . $cover);
        self::assertSame([400, 640, 'image/webp'], self::dimensions($copies['full']));
        self::assertSame([COVER_THUMB_WIDTH, 304, 'image/webp'], self::dimensions($copies['thumb']));
        self::assertSame(
            asset(substr($copies['thumb'], strlen(BASE_PATH . '/public/'))) . ' 190w, ' . asset(substr($copies['full'], strlen(BASE_PATH . '/public/'))) . ' 400w',
            webp_srcset($cover)
        );

        $avatar = $this->store($this->upload($this->image('png', 400, 640)));
        foreach (webp_variant_paths(BASE_PATH . '/public/' . $avatar) as $copy) {
            self::assertFileDoesNotExist($copy, 'Profile pictures are shown small; they get no copies');
        }
    }

    public function testTheCopyOfALargeCoverIsScaledDown(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD cannot write WebP here.');
        }
        $cover = $this->storeCover($this->upload($this->image('jpeg', 1000, 1600), 'large.jpg', 'image/jpeg'));
        $copies = webp_variant_paths(BASE_PATH . '/public/' . $cover);

        self::assertSame([1000, 1600, 'image/jpeg'], self::dimensions(BASE_PATH . '/public/' . $cover), 'The cover itself keeps its size');
        self::assertSame([COVER_COPY_MAX_WIDTH, 1248, 'image/webp'], self::dimensions($copies['full']));
        self::assertSame([COVER_THUMB_WIDTH, 304, 'image/webp'], self::dimensions($copies['thumb']));
        self::assertStringEndsWith('.webp 780w', webp_srcset($cover));
        self::assertFileDoesNotExist($copies['capped'], 'Only a WebP cover gets the capped copy');
    }

    public function testALargeWebpCoverIsOfferedAsAScaledDownCopy(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD cannot write WebP here.');
        }
        $cover = $this->storeCover($this->upload($this->image('webp', 1000, 1600), 'large.webp', 'image/webp'));
        $file = BASE_PATH . '/public/' . $cover;
        $copies = webp_variant_paths($file);

        self::assertSame($file, $copies['full'], 'A WebP cover is its own full-size copy');
        self::assertSame([1000, 1600, 'image/webp'], self::dimensions($file), 'The cover itself keeps its size');
        self::assertSame([COVER_COPY_MAX_WIDTH, 1248, 'image/webp'], self::dimensions($copies['capped']));
        self::assertSame([COVER_THUMB_WIDTH, 304, 'image/webp'], self::dimensions($copies['thumb']));
        $relative = static fn (string $path): string => substr($path, strlen(BASE_PATH . '/public/'));
        self::assertSame(
            asset($relative($copies['thumb'])) . ' 190w, ' . asset($relative($copies['capped'])) . ' 780w',
            webp_srcset($cover),
            'Browsers get the 780-pixel copy, never the 1000-pixel upload'
        );

        // A WebP cover within the limit is offered itself.
        $small = $this->storeCover($this->upload($this->image('webp', 400, 640), 'small.webp', 'image/webp'));
        self::assertFileDoesNotExist(webp_variant_paths(BASE_PATH . '/public/' . $small)['capped']);
        self::assertStringEndsWith($small . ' 400w', webp_srcset($small));
    }

    public function testDeletingACoverDeletesItsWebpCopies(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD cannot write WebP here.');
        }
        foreach ([['png', 400, 2], ['webp', 400, 1], ['webp', 1000, 2]] as [$type, $width, $copyCount]) {
            $cover = $this->storeCover($this->upload($this->image($type, $width, (int) ($width * 1.6)), "cover.$type", "image/$type"));
            $file = BASE_PATH . '/public/' . $cover;
            $copies = array_filter(webp_variant_paths($file), static fn (string $copy): bool => $copy !== $file && is_file($copy));
            self::assertCount($copyCount, $copies, "A $width-pixel $type cover");

            delete_uploaded_cover($cover);
            self::assertFileDoesNotExist($file, $type);
            foreach ($copies as $copy) {
                self::assertFileDoesNotExist($copy, $type);
            }
        }
    }

    /**
     * @return array{0: int, 1: int, 2: string} width, height, MIME type
     */
    private static function dimensions(string $file): array
    {
        $info = getimagesize($file);
        self::assertIsArray($info, "$file is not an image");
        return [$info[0], $info[1], $info['mime']];
    }

    public function testHasUploadedFileIgnoresEmptyFileInputs(): void
    {
        self::assertFalse(has_uploaded_file([], 'cover_upload'));
        self::assertFalse(has_uploaded_file(['cover_upload' => ['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '']], 'cover_upload'));
        self::assertFalse(has_uploaded_file(['cover_upload' => 'not-an-upload'], 'cover_upload'));
        self::assertTrue(has_uploaded_file(['cover_upload' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/x']], 'cover_upload'));
        self::assertTrue(has_uploaded_file(['cover_upload' => ['error' => UPLOAD_ERR_INI_SIZE]], 'cover_upload'), 'A failed upload is still an upload (and reported)');
    }

    public function testUploadMessagesFollowTheActiveLanguage(): void
    {
        use_locale('ar');
        try {
            $this->storeCover($this->upload(self::PHP_PAYLOAD));
            self::fail('Expected UploadException');
        } catch (UploadException $e) {
            self::assertSame('يُرجى رفع صورة بصيغة JPEG أو PNG أو GIF أو WebP.', $e->getMessage());
        } finally {
            use_locale(null);
        }
    }

    public function testAvatarUrlFallsBackToTheDefault(): void
    {
        self::assertSame(DEFAULT_AVATAR, avatar_url(null));
        self::assertSame(DEFAULT_AVATAR, avatar_url(''));
        self::assertSame(DEFAULT_AVATAR, avatar_url('uploads/avatars/' . str_repeat('0', 32) . '.png'));
        self::assertSame(DEFAULT_AVATAR, avatar_url('../.env'));
    }
}
