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

    public function testAvatarUrlFallsBackToTheDefault(): void
    {
        self::assertSame(DEFAULT_AVATAR, avatar_url(null));
        self::assertSame(DEFAULT_AVATAR, avatar_url(''));
        self::assertSame(DEFAULT_AVATAR, avatar_url('uploads/avatars/' . str_repeat('0', 32) . '.png'));
        self::assertSame(DEFAULT_AVATAR, avatar_url('../.env'));
    }
}
