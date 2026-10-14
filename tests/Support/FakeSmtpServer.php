<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

use RuntimeException;

/**
 * A local SMTP server for the mailer tests (tests/Support/fake_smtp_server.php
 * in its own PHP process). Nothing leaves the machine: it records the
 * conversation in a transcript file instead of delivering anything.
 */
final class FakeSmtpServer
{
    /** @var resource|null */
    private $process;

    /**
     * @param resource $process
     */
    private function __construct(
        public readonly int $port,
        private readonly string $transcriptFile,
        $process,
    ) {
        $this->process = $process;
    }

    public static function start(): self
    {
        $transcript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bookwise-smtp-' . bin2hex(random_bytes(6)) . '.log';
        $process = proc_open(
            [PHP_BINARY, BASE_PATH . '/tests/Support/fake_smtp_server.php', $transcript],
            [0 => ['pipe', 'r'], 1 => ['file', $transcript . '.out', 'a'], 2 => ['file', $transcript . '.out', 'a']],
            $pipes,
            BASE_PATH
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the fake SMTP server.');
        }
        fclose($pipes[0]);

        // The server writes the port it listens on once it is ready.
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $transcript . '.port');
            $port = is_file($transcript . '.port') ? (int) file_get_contents($transcript . '.port') : 0;
            if ($port > 0) {
                $server = new self($port, $transcript, $process);
                register_shutdown_function([$server, 'stop']);
                return $server;
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(50_000);
        }
        $output = is_file($transcript . '.out') ? (string) file_get_contents($transcript . '.out') : '';
        proc_terminate($process);
        proc_close($process);
        throw new RuntimeException("The fake SMTP server did not start.\n" . $output);
    }

    /**
     * The conversation so far: "C: ..." lines from the client, "S: ..." replies.
     */
    public function transcript(): string
    {
        clearstatcache(true, $this->transcriptFile);
        return is_file($this->transcriptFile) ? (string) file_get_contents($this->transcriptFile) : '';
    }

    /**
     * Every message received with DATA, as the raw text the client sent
     * (headers and MIME body, dot-stuffing removed).
     *
     * @return list<string>
     */
    public function messages(): array
    {
        $messages = [];
        $current = null;
        foreach (preg_split('/\n/', $this->transcript()) ?: [] as $line) {
            if ($current === null) {
                if ($line === 'S: 354 End data with <CR><LF>.<CR><LF>') {
                    $current = [];
                }
                continue;
            }
            if ($line === 'C: .') {
                $messages[] = implode("\r\n", $current);
                $current = null;
                continue;
            }
            $data = substr($line, 3);
            $current[] = str_starts_with($data, '..') ? substr($data, 1) : $data;
        }
        return $messages;
    }

    public function stop(): void
    {
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
        foreach ([$this->transcriptFile, $this->transcriptFile . '.port', $this->transcriptFile . '.out'] as $file) {
            @unlink($file);
        }
    }
}
