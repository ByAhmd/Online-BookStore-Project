<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * bin/optimize-images.php, run as a command on a scratch folder inside
 * public/uploads/covers (git-ignored), never on the committed catalog images.
 */
#[RequiresPhpExtension('gd')]
#[RequiresFunction('imagewebp')]
final class OptimizeImagesScriptTest extends TestCase
{
    private string $folder = '';
    private string $logDirectory = '';

    protected function setUp(): void
    {
        $this->folder = 'uploads/covers/optimize-test-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($this->path(''), 0775));
        $this->logDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bookwise-optimize-logs-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach ([$this->path(''), $this->logDirectory] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function path(string $name): string
    {
        return BASE_PATH . '/public/' . $this->folder . ($name === '' ? '' : '/' . $name);
    }

    private function image(string $name, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 30, 90, 160));
        match (pathinfo($name, PATHINFO_EXTENSION)) {
            'png' => imagepng($image, $this->path($name)),
            'jpg' => imagejpeg($image, $this->path($name)),
        };
    }

    /**
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function runScript(string ...$arguments): array
    {
        $environment = [];
        foreach (getenv() as $name => $value) {
            $environment[(string) $name] = (string) $value;
        }
        // Keep the script's error log out of storage/logs.
        $environment['LOG_DIR'] = $this->logDirectory;

        $process = proc_open(
            [PHP_BINARY, BASE_PATH . '/bin/optimize-images.php', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            BASE_PATH,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not run bin/optimize-images.php');
        }
        fclose($pipes[0]);
        $stdout = str_replace("\r\n", "\n", (string) stream_get_contents($pipes[1]));
        $stderr = str_replace("\r\n", "\n", (string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * @return list<string> File names in the scratch folder
     */
    private function files(): array
    {
        $files = array_map('basename', glob($this->path('*')) ?: []);
        sort($files);
        return $files;
    }

    public function testWritesTheCopiesOnceAndSkipsThemWhenUpToDate(): void
    {
        $this->image('cover.png', 380, 605);
        $this->image('photo.jpg', 400, 300);
        $this->image('icon.png', 60, 60);

        $first = $this->runScript($this->folder);
        self::assertSame(0, $first['status'], $first['stderr']);
        self::assertSame('', $first['stderr']);
        self::assertStringContainsString("  {$this->folder}/cover.webp (", $first['stdout']);
        self::assertStringContainsString("  {$this->folder}/cover-w190.webp (", $first['stdout']);
        self::assertStringContainsString('3 image(s) checked: 5 WebP copies written, 0 image(s) already up to date.', $first['stdout']);
        self::assertMatchesRegularExpression('/^Full-size copies: [\d.]+ MB as WebP instead of [\d.]+ MB\.$/m', $first['stdout']);
        self::assertSame([
            'cover-w190.webp', 'cover.png', 'cover.webp',
            'icon.png', 'icon.webp',
            'photo-w190.webp', 'photo.jpg', 'photo.webp',
        ], $this->files());

        $second = $this->runScript($this->folder);
        self::assertSame(0, $second['status'], $second['stderr']);
        self::assertSame("3 image(s) checked: 0 WebP copies written, 3 image(s) already up to date.\n", $second['stdout']);

        // A replaced image is copied again; --force rewrites every copy.
        touch($this->path('icon.png'), time() + 60);
        self::assertStringContainsString('1 WebP copy written, 2 image(s) already up to date.', $this->runScript($this->folder)['stdout']);
        self::assertStringContainsString('5 WebP copies written, 0 image(s) already up to date.', $this->runScript('--force', $this->folder)['stdout']);
    }

    public function testImagesThatWouldShareACopyAreRefused(): void
    {
        $this->image('cover.png', 380, 605);
        $this->image('cover.jpg', 380, 605);

        $result = $this->runScript($this->folder);
        self::assertSame(1, $result['status']);
        self::assertSame("{$this->folder}/cover.jpg and {$this->folder}/cover.png would both be copied to {$this->folder}/cover.webp. Rename one of them.\n", $result['stderr']);
        self::assertSame(['cover.jpg', 'cover.png'], $this->files(), 'Nothing is written');
    }

    public function testBadArgumentsAreRefused(): void
    {
        $unknown = $this->runScript('--quality=50', $this->folder);
        self::assertSame(1, $unknown['status']);
        self::assertStringStartsWith('Unknown option --quality=50. Usage: php bin/optimize-images.php [--force] [directory ...]', $unknown['stderr']);

        foreach (['missing-folder', '../src', ''] as $folder) {
            $result = $this->runScript($folder === '' ? '/' : $folder);
            self::assertSame(1, $result['status'], $folder);
            self::assertStringStartsWith('No folder public/', $result['stderr'], $folder);
        }
    }
}
