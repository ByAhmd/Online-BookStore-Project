<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

use FilesystemIterator;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * The real application, served by PHP's built-in web server for the HTTP
 * end-to-end suite (tests/Http):
 *
 *     php -S 127.0.0.1:<free port> -t public tests/Support/http_router.php
 *
 * The server gets a hermetic configuration. Every variable from .env.example
 * (plus LOG_DIR and STRIPE_API_BASE) is set explicitly, and load_env() only
 * fills variables that are missing, so the developer's .env never leaks into
 * the tests; see http_router.php for the empty-value case on Windows.
 * Sessions (session.save_path) and the app.log, mail.log and php-error.log
 * files (LOG_DIR) live in a private temporary directory that is removed when
 * the server stops.
 *
 * Uploaded avatars are the one exception: the application stores them under
 * public/uploads/avatars of the checkout. HttpTestCase deletes the ones its
 * tests created.
 */
final class AppServer
{
    public const STRIPE_SECRET_KEY = 'sk_test_http_suite';
    public const STRIPE_WEBHOOK_SECRET = 'whsec_http_suite_secret';
    public const STRIPE_CURRENCY = 'sar';
    public const MAIL_FROM_ADDRESS = 'no-reply@example.com';

    /** @var resource|null */
    private $process;
    private bool $stopped = false;

    /**
     * @param resource $process
     * @param array<string, string> $configuration
     */
    private function __construct(
        public readonly string $baseUrl,
        public readonly string $runDirectory,
        public readonly string $logDirectory,
        public readonly array $configuration,
        private readonly string $outputFile,
        $process,
    ) {
        $this->process = $process;
    }

    /**
     * @param array{host: string, port: int, name: string, user: string, pass: string} $database
     */
    public static function start(array $database, string $stripeApiBase): self
    {
        if (!str_ends_with($database['name'], '_test')) {
            throw new LogicException(sprintf(
                'Refusing to start the HTTP test server against database "%s": the name must end with "_test".',
                $database['name']
            ));
        }

        $runDirectory = self::makeRunDirectory();
        $logDirectory = $runDirectory . DIRECTORY_SEPARATOR . 'logs';
        $sessionDirectory = $runDirectory . DIRECTORY_SEPARATOR . 'sessions';
        foreach ([$logDirectory, $sessionDirectory] as $directory) {
            if (!mkdir($directory, 0700) && !is_dir($directory)) {
                throw new RuntimeException("Cannot create $directory");
            }
        }

        $port = self::freePort();
        $baseUrl = 'http://127.0.0.1:' . $port;
        $configuration = self::configuration($baseUrl, $logDirectory, $database, $stripeApiBase);

        $environmentFile = $runDirectory . DIRECTORY_SEPARATOR . 'environment.json';
        file_put_contents($environmentFile, json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $environment = self::inheritedEnvironment(array_keys($configuration));
        foreach ($configuration as $key => $value) {
            if ($value !== '') {
                $environment[$key] = $value;
            }
        }
        $environment['BOOKWISE_HTTP_ENV_FILE'] = $environmentFile;

        $outputFile = $runDirectory . DIRECTORY_SEPARATOR . 'server-output.log';
        $command = [
            PHP_BINARY,
            '-d', 'session.save_path=' . $sessionDirectory,
            '-d', 'log_errors=1',
            '-d', 'error_log=' . $logDirectory . DIRECTORY_SEPARATOR . 'php-error.log',
            '-S', '127.0.0.1:' . $port,
            '-t', BASE_PATH . DIRECTORY_SEPARATOR . 'public',
            BASE_PATH . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'http_router.php',
        ];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', $outputFile, 'a'], 2 => ['file', $outputFile, 'a']],
            $pipes,
            BASE_PATH,
            $environment
        );
        if (!is_resource($process)) {
            self::removeDirectory($runDirectory);
            throw new RuntimeException('Could not start the HTTP test server.');
        }
        fclose($pipes[0]);

        $server = new self($baseUrl, $runDirectory, $logDirectory, $configuration, $outputFile, $process);
        register_shutdown_function([$server, 'stop']);
        $server->waitUntilReady($port);

        return $server;
    }

    /**
     * Stops the server (and, on Windows, its whole process tree) and removes
     * the run directory. Safe to call more than once.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;

        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                if (PHP_OS_FAMILY === 'Windows') {
                    exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' >NUL 2>&1');
                }
                proc_terminate($this->process);
            }
            proc_close($this->process);
        }
        $this->process = null;

        // Windows may hold the log files open for a moment after the process exits.
        for ($attempt = 0; $attempt < 20 && is_dir($this->runDirectory); $attempt++) {
            self::removeDirectory($this->runDirectory);
            if (is_dir($this->runDirectory)) {
                usleep(100_000);
            }
        }
    }

    public function isRunning(): bool
    {
        return is_resource($this->process) && proc_get_status($this->process)['running'];
    }

    public function logPath(string $file): string
    {
        return $this->logDirectory . DIRECTORY_SEPARATOR . $file;
    }

    public function logSize(string $file): int
    {
        return self::fileSize($this->logPath($file));
    }

    /**
     * Contents of app.log, mail.log or php-error.log from a byte offset.
     */
    public function readLog(string $file, int $offset = 0): string
    {
        return self::readFrom($this->logPath($file), $offset);
    }

    public function outputSize(): int
    {
        return self::fileSize($this->outputFile);
    }

    /**
     * What the built-in server printed (access log, startup messages).
     */
    public function output(int $offset = 0): string
    {
        return self::readFrom($this->outputFile, $offset);
    }

    /**
     * @param array{host: string, port: int, name: string, user: string, pass: string} $database
     * @return array<string, string>
     */
    private static function configuration(string $baseUrl, string $logDirectory, array $database, string $stripeApiBase): array
    {
        $configuration = [
            'APP_NAME' => 'BookWise',
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'true',
            'APP_URL' => $baseUrl,
            'APP_TIMEZONE' => (string) config('app.timezone', 'Asia/Riyadh'),
            'DB_HOST' => $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_NAME' => $database['name'],
            'DB_USER' => $database['user'],
            'DB_PASS' => $database['pass'],
            'STRIPE_SECRET_KEY' => self::STRIPE_SECRET_KEY,
            'STRIPE_WEBHOOK_SECRET' => self::STRIPE_WEBHOOK_SECRET,
            'STRIPE_CURRENCY' => self::STRIPE_CURRENCY,
            'STRIPE_API_BASE' => $stripeApiBase,
            'MAIL_DRIVER' => 'log',
            'MAIL_FROM_ADDRESS' => self::MAIL_FROM_ADDRESS,
            'MAIL_FROM_NAME' => 'BookWise',
            'SENDGRID_API_KEY' => '',
            'LOG_DIR' => $logDirectory,
        ];

        $missing = array_diff(self::exampleKeys(), array_keys($configuration));
        if ($missing !== []) {
            throw new LogicException(sprintf(
                '.env.example defines %s, which the HTTP test server does not set. Add %s to AppServer::configuration() '
                . 'so the developer\'s .env file cannot leak into the tests.',
                implode(', ', $missing),
                count($missing) === 1 ? 'it' : 'them'
            ));
        }

        return $configuration;
    }

    /**
     * Variable names assigned in .env.example.
     *
     * @return list<string>
     */
    private static function exampleKeys(): array
    {
        $example = (string) file_get_contents(BASE_PATH . '/.env.example');
        preg_match_all('/^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=/m', $example, $matches);
        $keys = array_values(array_unique($matches[1]));
        if ($keys === []) {
            throw new RuntimeException('Could not read any variable names from .env.example.');
        }
        return $keys;
    }

    /**
     * The current process environment (PATH, SystemRoot, TEMP, ...) without
     * any application setting, compared case-insensitively as Windows does.
     *
     * @param list<string> $applicationKeys
     * @return array<string, string>
     */
    private static function inheritedEnvironment(array $applicationKeys): array
    {
        $blocked = array_map('strtoupper', $applicationKeys);
        $environment = [];
        foreach (getenv() as $key => $value) {
            $upper = strtoupper((string) $key);
            if (in_array($upper, $blocked, true) || str_starts_with($upper, 'BOOKWISE_')) {
                continue;
            }
            $environment[(string) $key] = (string) $value;
        }
        return $environment;
    }

    private function waitUntilReady(int $port): void
    {
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            if (!$this->isRunning()) {
                $output = $this->output();
                $this->stop();
                throw new RuntimeException("The HTTP test server exited during startup. Output:\n" . $output);
            }
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        }
        $output = $this->output();
        $this->stop();
        throw new RuntimeException("The HTTP test server did not accept connections on port $port. Output:\n" . $output);
    }

    private static function makeRunDirectory(): string
    {
        $directory = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'bookwise-http-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create $directory");
        }
        return $directory;
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new RuntimeException("Cannot find a free port: $errstr");
        }
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function fileSize(string $path): int
    {
        clearstatcache(true, $path);
        return is_file($path) ? (int) filesize($path) : 0;
    }

    private static function readFrom(string $path, int $offset): string
    {
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return '';
        }
        $contents = file_get_contents($path, false, null, max(0, $offset));
        return $contents === false ? '' : $contents;
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
