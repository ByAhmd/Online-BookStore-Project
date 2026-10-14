<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Runs one action of tests/Support/isolated_probe.php in a fresh PHP process
 * whose BASE_PATH is a throwaway directory. Use it for code that writes to
 * storage/logs (app_log, the "log" mail driver) or keeps per-process caches
 * (stripe_client()), so tests neither pollute the real project logs nor
 * depend on test order.
 */
final class IsolatedPhp
{
    /** Configuration keys copied from the current test process into the child. */
    private const CONFIG_KEYS = [
        'APP_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_TIMEZONE', 'APP_DEMO',
        'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS',
        'STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'STRIPE_CURRENCY', 'STRIPE_API_BASE',
        'MAIL_DRIVER', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
        'SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_ENCRYPTION', 'SMTP_TIMEOUT',
    ];

    /**
     * @param array<string, mixed> $args Action arguments (JSON-encodable)
     * @param array<string, string|null> $env Environment overrides; null removes a variable
     * @return array{result: array<string, mixed>, files: array<string, string>, stderr: string}
     *         files: contents of every file the child wrote below its BASE_PATH, keyed by relative path
     */
    public static function run(string $action, array $args = [], array $env = []): array
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bookwise-probe-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0700) && !is_dir($base)) {
            throw new RuntimeException("Cannot create $base");
        }

        $environment = getenv();
        foreach (self::CONFIG_KEYS as $key) {
            $value = $_ENV[$key] ?? getenv($key);
            if (is_string($value) && $value !== '') {
                $environment[$key] = $value;
            } else {
                unset($environment[$key]);
            }
        }
        foreach ($env as $key => $value) {
            if ($value === null) {
                unset($environment[$key]);
            } else {
                $environment[$key] = $value;
            }
        }
        if (!array_key_exists('LOG_DIR', $env)) {
            // Relative to the child's throwaway BASE_PATH, so logs land in $base/storage/logs.
            $environment['LOG_DIR'] = 'storage/logs';
        }
        $environment['BOOKWISE_PROBE_BASE'] = $base;
        $environment['BOOKWISE_PROBE_ACTION'] = $action;
        $environment['BOOKWISE_PROBE_ARGS'] = base64_encode(json_encode($args, JSON_THROW_ON_ERROR));

        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', BASE_PATH . '/tests/Support/isolated_probe.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                BASE_PATH,
                $environment
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start the isolated PHP process.');
            }
            fclose($pipes[0]);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $result = json_decode($stdout, true);
            if ($exitCode !== 0 || !is_array($result)) {
                throw new RuntimeException("Isolated probe '$action' failed (exit $exitCode).\nSTDOUT: $stdout\nSTDERR: $stderr");
            }

            return ['result' => $result, 'files' => self::collectFiles($base), 'stderr' => $stderr];
        } finally {
            self::removeDirectory($base);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function collectFiles(string $base): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);
        return $files;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
