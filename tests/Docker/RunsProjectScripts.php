<?php

declare(strict_types=1);

namespace BookWise\Tests\Docker;

use RuntimeException;

/**
 * Runs a PHP script of the project (e.g. docker/db-ready.php) in a child
 * process, the way docker/entrypoint.sh and the Docker HEALTHCHECK do.
 */
trait RunsProjectScripts
{
    /**
     * @param string $script Path relative to the project root
     * @param array<string, string|null> $env Environment overrides; null removes a variable
     * @param list<string> $arguments Command-line arguments
     * @param string $stdin Text typed into the script (e.g. answers to its questions)
     * @return array{status: int, stdout: string, stderr: string}
     */
    protected static function runProjectScript(string $script, array $env = [], array $arguments = [], string $stdin = ''): array
    {
        $environment = [];
        foreach (getenv() as $name => $value) {
            $environment[(string) $name] = (string) $value;
        }
        foreach ($env as $name => $value) {
            if ($value === null) {
                unset($environment[$name]);
            } else {
                $environment[$name] = $value;
            }
        }

        $process = proc_open(
            [PHP_BINARY, BASE_PATH . '/' . $script, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            BASE_PATH,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException("Could not run $script");
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * A local TCP port that nothing listens on.
     */
    protected static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Cannot find a free port: $errstr");
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
