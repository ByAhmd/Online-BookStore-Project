<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use RuntimeException;

/**
 * Helpers for the account tests (email verification, password change,
 * account deletion, language). Use in DatabaseTestCase subclasses; the HTTP
 * helpers additionally need an HttpTestCase subclass.
 */
trait AccountHelpers
{
    /** environment.json of the HTTP test server before configureServer(), restored after the test. */
    private ?string $serverConfigurationBackup = null;
    private ?string $serverConfigurationFile = null;

    /**
     * Changes the configuration of the running HTTP test server for the rest
     * of this test, e.g. ['REQUIRE_EMAIL_VERIFICATION' => 'true'].
     *
     * AppServer hands its configuration to tests/Support/http_router.php in
     * environment.json, which the router applies to $_ENV on every request,
     * so rewriting the file reconfigures the server without restarting it.
     *
     * @param array<string, string> $overrides
     */
    protected function configureServer(array $overrides): void
    {
        $file = static::server()->runDirectory . DIRECTORY_SEPARATOR . 'environment.json';
        if (!is_file($file)) {
            throw new RuntimeException("The HTTP test server has no $file; AppServer no longer passes its configuration this way.");
        }
        $contents = (string) file_get_contents($file);
        if ($this->serverConfigurationBackup === null) {
            $this->serverConfigurationBackup = $contents;
            $this->serverConfigurationFile = $file;
        }
        $configuration = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($configuration)) {
            throw new RuntimeException("Unexpected contents of $file");
        }
        foreach ($overrides as $key => $value) {
            $configuration[$key] = $value;
        }
        file_put_contents($file, json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    #[After]
    protected function restoreServerConfiguration(): void
    {
        if ($this->serverConfigurationBackup !== null && $this->serverConfigurationFile !== null && is_file($this->serverConfigurationFile)) {
            file_put_contents($this->serverConfigurationFile, $this->serverConfigurationBackup, LOCK_EX);
        }
        $this->serverConfigurationBackup = null;
        $this->serverConfigurationFile = null;
    }

    /**
     * Runs $action in this process with the "log" mail driver writing to a
     * private temporary LOG_DIR and returns the messages it sent.
     *
     * @param callable(): mixed $action
     * @return list<array{to: string, name: string, subject: string, body: string}>
     */
    protected function captureMail(callable $action): array
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bookwise-mail-' . bin2hex(random_bytes(6));
        $previous = ['MAIL_DRIVER' => env('MAIL_DRIVER'), 'LOG_DIR' => env('LOG_DIR')];
        $this->setEnv('MAIL_DRIVER', 'log');
        $this->setEnv('LOG_DIR', $directory);
        try {
            $action();
            $file = $directory . DIRECTORY_SEPARATOR . 'mail.log';
            $log = is_file($file) ? (string) file_get_contents($file) : '';
        } finally {
            foreach ($previous as $name => $value) {
                $this->setEnv($name, $value);
            }
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
        return self::parseMailLog($log);
    }

    /**
     * Messages in a mail.log written by the "log" driver.
     *
     * @return list<array{to: string, name: string, subject: string, body: string}>
     */
    protected static function parseMailLog(string $log): array
    {
        $mails = [];
        foreach (preg_split('/^==== [^\n]* ====\n/m', $log) ?: [] as $block) {
            if (preg_match('/^To: (.*) <([^>\n]+)>\nSubject: ([^\n]*)\n\n(.*)$/s', $block, $match)) {
                $mails[] = ['to' => $match[2], 'name' => $match[1], 'subject' => $match[3], 'body' => rtrim($match[4])];
            }
        }
        return $mails;
    }

    /**
     * The first absolute link to $page?token=... in an email body.
     */
    protected static function tokenLink(string $page, string $body): string
    {
        self::assertSame(
            1,
            preg_match('#(https?://\S+/' . preg_quote($page, '#') . '\?token=[a-f0-9]{64})\b#', $body, $match),
            "No $page link in:\n$body"
        );
        return $match[1];
    }

    protected static function tokenOf(string $link): string
    {
        return substr($link, -64);
    }

    /**
     * Inserts a verification token for the account and returns the raw value an email would carry.
     */
    protected function issueVerificationToken(int $userId, string $expirySql = 'NOW() + INTERVAL 24 HOUR'): string
    {
        $raw = bin2hex(random_bytes(32));
        db_run("INSERT INTO email_verifications (user_id, token, expiry) VALUES (?, ?, $expirySql)", [$userId, hash('sha256', $raw)]);
        return $raw;
    }

    protected function markVerified(int $userId): void
    {
        db_run('UPDATE users SET email_verified_at = NOW() WHERE id = ?', [$userId]);
    }

    protected function isVerified(int $userId): bool
    {
        return db_value('SELECT email_verified_at FROM users WHERE id = ?', [$userId]) !== null;
    }

    /**
     * Pretends that every account email so far was sent longer ago than the cooldown.
     */
    protected function ageAccountEmails(): void
    {
        db_run('UPDATE email_verifications SET created_at = NOW() - INTERVAL ? MINUTE', [ACCOUNT_EMAIL_COOLDOWN_MINUTES + 1]);
    }
}
