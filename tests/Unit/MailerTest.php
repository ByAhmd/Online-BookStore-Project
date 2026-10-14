<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use BookWise\Tests\Support\EnvironmentOverrides;
use BookWise\Tests\Support\FakeSmtpServer;
use BookWise\Tests\Support\IsolatedPhp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Drivers that write files run in an isolated process whose BASE_PATH is a
 * temporary directory, so the real storage/logs files are never touched.
 * SMTP is tested against tests/Support/fake_smtp_server.php on 127.0.0.1;
 * no email ever leaves the machine.
 */
final class MailerTest extends TestCase
{
    use EnvironmentOverrides;

    private const MESSAGE = [
        'to' => 'reader@example.com',
        'name' => 'Reader Name',
        'subject' => 'Reset your BookWise password',
        'html' => '<p>Hello <strong>HTML</strong> part</p>',
        'text' => "Hello,\nplain text part with https://example.com/reset_form.php?token=abc",
    ];

    private const ARABIC_MESSAGE = [
        'to' => 'reader@example.com',
        'name' => 'قارئ الكتب',
        'subject' => 'تأكيد عنوان بريدك الإلكتروني في BookWise',
        'html' => '<div lang="ar" dir="rtl"><p>مرحبًا،</p><p><a href="https://example.com/verify_email.php?token=abc">تأكيد عنوان بريدي الإلكتروني</a></p></div>',
        'text' => "مرحبًا،\n\nتأكيد عنوان بريدي الإلكتروني:\nhttps://example.com/verify_email.php?token=abc",
    ];

    private const SMTP_USERNAME = 'shop-mailer';
    private const SMTP_PASSWORD = 'smtp-Secret-9f3k-never-logged';

    /**
     * Settings for the fake server: plain SMTP (it offers no STARTTLS) and no sign-in.
     *
     * @param array<string, string|null> $overrides
     * @return array<string, string|null>
     */
    private static function smtpEnv(int $port, array $overrides = []): array
    {
        return $overrides + [
            'MAIL_DRIVER' => 'smtp',
            'MAIL_FROM_ADDRESS' => 'shop@example.com',
            'MAIL_FROM_NAME' => 'BookWise Shop',
            'SMTP_HOST' => '127.0.0.1',
            'SMTP_PORT' => (string) $port,
            'SMTP_USERNAME' => null,
            'SMTP_PASSWORD' => null,
            'SMTP_ENCRYPTION' => 'none',
            'SMTP_TIMEOUT' => '5',
        ];
    }

    /**
     * A local port that nothing listens on.
     */
    private static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($socket, $errstr);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * Headers (unfolded) and decoded text parts of a raw MIME message.
     *
     * @return array{headers: string, parts: array<string, array{charset: string, encoding: string, body: string}>}
     */
    private static function parseMessage(string $message): array
    {
        [$headers, $body] = explode("\r\n\r\n", $message, 2) + [1 => ''];
        $headers = (string) preg_replace('/\r\n[ \t]+/', ' ', $headers);
        self::assertSame(1, preg_match('/^Content-Type: multipart\/alternative;\s*boundary="([^"]+)"/mi', $headers, $match), "Not multipart/alternative:\n$headers");

        $parts = [];
        foreach (explode('--' . $match[1], $body) as $section) {
            if (preg_match('/^\r\nContent-Type: (text\/[a-z]+); charset=([^\r\n]+)\r\nContent-Transfer-Encoding: ([^\r\n]+)\r\n\r\n(.*)$/s', $section, $part)) {
                $content = rtrim($part[4], "\r\n");
                $parts[$part[1]] = [
                    'charset' => $part[2],
                    'encoding' => $part[3],
                    'body' => str_replace("\r\n", "\n", $part[3] === 'quoted-printable' ? quoted_printable_decode($content) : $content),
                ];
            }
        }
        return ['headers' => $headers, 'parts' => $parts];
    }

    private static function header(string $headers, string $name): string
    {
        self::assertSame(1, preg_match('/^' . preg_quote($name, '/') . ': ([^\r\n]*)/mi', $headers, $match), "No $name header in:\n$headers");
        return $match[1];
    }

    public function testNullDriverDiscardsMessages(): void
    {
        $this->setEnv('MAIL_DRIVER', 'null');

        self::assertTrue(send_mail('reader@example.com', 'Reader', 'Subject', '<p>x</p>', 'x'));

        $run = IsolatedPhp::run('send_mail', self::MESSAGE, ['MAIL_DRIVER' => 'null']);
        self::assertSame(['ok' => true, 'returned' => true], $run['result']);
        self::assertSame([], $run['files'], 'The null driver writes nothing');
    }

    public function testLogDriverAppendsTheTextPartToMailLog(): void
    {
        $run = IsolatedPhp::run('send_mail', self::MESSAGE, ['MAIL_DRIVER' => 'log']);

        self::assertSame(['ok' => true, 'returned' => true], $run['result']);
        self::assertSame(['storage/logs/mail.log'], array_keys($run['files']), 'storage/logs is created on demand');
        $log = $run['files']['storage/logs/mail.log'];
        self::assertStringContainsString('To: Reader Name <reader@example.com>', $log);
        self::assertStringContainsString('Subject: Reset your BookWise password', $log);
        self::assertStringContainsString(self::MESSAGE['text'], $log);
        self::assertStringNotContainsString('<strong>', $log, 'Only the text part is logged');
    }

    public function testDriverNameIsCaseInsensitive(): void
    {
        $run = IsolatedPhp::run('send_mail', self::MESSAGE, ['MAIL_DRIVER' => 'LOG']);

        self::assertTrue($run['result']['returned']);
        self::assertArrayHasKey('storage/logs/mail.log', $run['files']);
    }

    public function testUnknownDriverFailsAndIsLogged(): void
    {
        $run = IsolatedPhp::run('send_mail', self::MESSAGE, ['MAIL_DRIVER' => 'carrier-pigeon']);

        self::assertSame(['ok' => true, 'returned' => false], $run['result']);
        self::assertArrayNotHasKey('storage/logs/mail.log', $run['files']);
        self::assertStringContainsString('ERROR: Unknown MAIL_DRIVER {"driver":"carrier-pigeon"}', $run['files']['storage/logs/app.log'] ?? '');
    }

    public function testTheDemoInboxDriverIsRefusedOutsideDemoMode(): void
    {
        // Refused before the database is touched: the inbox page is public, so only a demo may store mail for it.
        $run = IsolatedPhp::run('send_mail', self::MESSAGE, ['MAIL_DRIVER' => 'database', 'APP_DEMO' => 'false', 'DB_PORT' => (string) self::closedPort()]);

        self::assertSame(['ok' => true, 'returned' => false], $run['result']);
        $log = $run['files']['storage/logs/app.log'] ?? '';
        self::assertStringContainsString('ERROR: MAIL_DRIVER=database is only available in demo mode (APP_DEMO=true)', $log);
        self::assertStringNotContainsString('Email delivery failed', $log);
    }

    public function testTheDemoInboxIsActiveOnlyInDemoModeWithTheDatabaseDriver(): void
    {
        foreach ([['true', 'database', true], ['true', 'DATABASE', true], ['false', 'database', false], ['true', 'log', false], ['true', 'null', false]] as [$demo, $driver, $expected]) {
            $this->setEnv('APP_DEMO', $demo);
            $this->setEnv('MAIL_DRIVER', $driver);
            self::assertSame($expected, demo_inbox_enabled(), "APP_DEMO=$demo MAIL_DRIVER=$driver");
        }
    }

    public function testSmtpSettingsHaveSafeDefaults(): void
    {
        foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_ENCRYPTION', 'SMTP_TIMEOUT'] as $key) {
            $this->setEnv($key, null);
        }
        self::assertSame(
            ['host' => null, 'port' => 587, 'username' => null, 'password' => null, 'encryption' => 'tls', 'timeout' => 10],
            config('mail.smtp')
        );

        $this->setEnv('SMTP_PORT', '2525');
        $this->setEnv('SMTP_ENCRYPTION', 'SSL');
        self::assertSame(2525, config('mail.smtp.port'));
        self::assertSame('ssl', config('mail.smtp.encryption'));
    }

    public function testSmtpDeliversArabicMailAsUtf8WithTextAndHtmlParts(): void
    {
        $server = FakeSmtpServer::start();
        try {
            $run = IsolatedPhp::run('send_mail', self::ARABIC_MESSAGE, self::smtpEnv($server->port));
            self::assertSame(['ok' => true, 'returned' => true], $run['result'], $run['stderr']);
            self::assertArrayNotHasKey('storage/logs/app.log', $run['files'], 'A delivered email logs nothing');

            $transcript = $server->transcript();
            self::assertStringContainsString("C: MAIL FROM:<shop@example.com>\n", $transcript);
            self::assertStringContainsString("C: RCPT TO:<reader@example.com>\n", $transcript);
            self::assertStringNotContainsString('C: AUTH', $transcript, 'No sign-in without SMTP_USERNAME');
            self::assertStringNotContainsString('STARTTLS', $transcript, 'SMTP_ENCRYPTION=none never upgrades');

            $messages = $server->messages();
            self::assertCount(1, $messages);
            ['headers' => $headers, 'parts' => $parts] = self::parseMessage($messages[0]);

            self::assertDoesNotMatchRegularExpression('/[\x80-\xFF]/', $headers, 'Headers are plain ASCII');
            $subject = self::header($headers, 'Subject');
            self::assertMatchesRegularExpression('/^=\?utf-8\?[BQ]\?/i', $subject, 'The subject is MIME-encoded');
            self::assertSame(self::ARABIC_MESSAGE['subject'], mb_decode_mimeheader($subject), $subject);
            self::assertSame('BookWise Shop <shop@example.com>', self::header($headers, 'From'));
            self::assertSame(self::ARABIC_MESSAGE['name'] . ' <reader@example.com>', mb_decode_mimeheader(self::header($headers, 'To')));
            self::assertDoesNotMatchRegularExpression('/^X-Mailer:/mi', $headers, 'The library and its version are not advertised');

            self::assertSame(['text/plain', 'text/html'], array_keys($parts));
            foreach ($parts as $type => $part) {
                self::assertSame('utf-8', strtolower($part['charset']), $type);
                self::assertSame('quoted-printable', $part['encoding'], "$type survives servers without 8BITMIME");
            }
            self::assertSame(self::ARABIC_MESSAGE['text'], $parts['text/plain']['body']);
            self::assertSame(self::ARABIC_MESSAGE['html'], $parts['text/html']['body']);
        } finally {
            $server->stop();
        }
    }

    public function testSmtpSignsInOnlyWithAUsernameAndNeverLogsThePassword(): void
    {
        $server = FakeSmtpServer::start();
        try {
            $run = IsolatedPhp::run('send_mail', self::MESSAGE, self::smtpEnv($server->port, [
                'SMTP_USERNAME' => self::SMTP_USERNAME,
                'SMTP_PASSWORD' => self::SMTP_PASSWORD,
            ]));
            self::assertSame(['ok' => true, 'returned' => true], $run['result'], $run['stderr']);

            $transcript = $server->transcript();
            self::assertMatchesRegularExpression('/^C: AUTH (LOGIN|PLAIN)/m', $transcript);
            self::assertStringContainsString(
                str_contains($transcript, 'C: AUTH LOGIN') ? base64_encode(self::SMTP_PASSWORD) : base64_encode("\0" . self::SMTP_USERNAME . "\0" . self::SMTP_PASSWORD),
                $transcript,
                'The configured credentials are sent to the server'
            );
            self::assertCount(1, $server->messages());
            foreach ($run['files'] as $file => $contents) {
                self::assertStringNotContainsString(self::SMTP_PASSWORD, $contents, $file);
            }
            self::assertStringNotContainsString(self::SMTP_PASSWORD, $run['stderr']);
        } finally {
            $server->stop();
        }
    }

    public function testSmtpWithTlsNeverSignsInOnAServerWithoutStarttls(): void
    {
        $server = FakeSmtpServer::start();
        try {
            $run = IsolatedPhp::run('send_mail', self::MESSAGE, self::smtpEnv($server->port, [
                'SMTP_USERNAME' => self::SMTP_USERNAME,
                'SMTP_PASSWORD' => self::SMTP_PASSWORD,
                'SMTP_ENCRYPTION' => 'tls',
            ]));
            self::assertSame(['ok' => true, 'returned' => false], $run['result']);

            $transcript = $server->transcript();
            self::assertStringContainsString('C: STARTTLS', $transcript, 'TLS is required');
            self::assertStringNotContainsString('C: AUTH', $transcript, 'The password is never sent unencrypted');
            self::assertStringNotContainsString('C: MAIL FROM', $transcript);
            self::assertSame([], $server->messages());
            $log = $run['files']['storage/logs/app.log'] ?? '';
            self::assertStringContainsString('ERROR: Email delivery failed', $log);
            self::assertStringNotContainsString(self::SMTP_PASSWORD, $log);
        } finally {
            $server->stop();
        }
    }

    public function testSmtpWithoutEncryptionSignsInOnlyToThisMachine(): void
    {
        // 192.0.2.1 is reserved for documentation: if the check ever let this
        // through, the attempt would fail (after SMTP_TIMEOUT) instead of reaching a server.
        $run = IsolatedPhp::run('send_mail', self::MESSAGE, [
            'MAIL_DRIVER' => 'smtp',
            'SMTP_HOST' => '192.0.2.1',
            'SMTP_PORT' => '587',
            'SMTP_USERNAME' => self::SMTP_USERNAME,
            'SMTP_PASSWORD' => self::SMTP_PASSWORD,
            'SMTP_ENCRYPTION' => 'none',
            'SMTP_TIMEOUT' => '1',
        ]);

        self::assertSame(['ok' => true, 'returned' => false], $run['result'], $run['stderr']);
        $log = $run['files']['storage/logs/app.log'] ?? '';
        self::assertStringContainsString('ERROR: SMTP_ENCRYPTION=none signs in only to a server on this machine (localhost); use tls or ssl', $log);
        self::assertStringNotContainsString('Email delivery failed', $log, 'Refused before connecting');
        foreach ($run['files'] as $file => $contents) {
            self::assertStringNotContainsString(self::SMTP_PASSWORD, $contents, $file);
        }
        self::assertStringNotContainsString(self::SMTP_PASSWORD, $run['stderr']);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function smtpHosts(): iterable
    {
        foreach (['localhost', 'LocalHost', ' localhost ', '127.0.0.1', '127.8.9.10', '[::1]', '[0:0:0:0:0:0:0:1]', '127.0.0.1:2525', 'localhost:1025', '[::1]:25', 'tls://localhost:587', 'ssl://127.0.0.1', '127.0.0.1;localhost'] as $host) {
            yield "loopback $host" => [$host, true];
        }
        foreach (['', 'mailpit', 'smtp.example.com', '192.0.2.1', '10.0.0.5', '0.0.0.0', 'localhost.example.com', '127.0.0.1.example.com', '[::2]', '[::ffff:127.0.0.1]', '::1', 'localhost;smtp.example.com', 'smtp.example.com;127.0.0.1', '127.0.0.1;', 'http://localhost'] as $host) {
            yield "remote $host" => [$host, false];
        }
    }

    #[DataProvider('smtpHosts')]
    public function testOnlyLocalhostAndLoopbackAddressesCountAsThisMachine(string $host, bool $loopback): void
    {
        self::assertSame($loopback, smtp_host_is_loopback($host));
    }

    public function testSmtpFailsFastOnAClosedPortWithoutLoggingThePassword(): void
    {
        $started = microtime(true);
        $run = IsolatedPhp::run('send_mail', self::MESSAGE, [
            'MAIL_DRIVER' => 'smtp',
            'SMTP_HOST' => '127.0.0.1',
            'SMTP_PORT' => (string) self::closedPort(),
            'SMTP_USERNAME' => self::SMTP_USERNAME,
            'SMTP_PASSWORD' => self::SMTP_PASSWORD,
            'SMTP_ENCRYPTION' => 'tls',
            'SMTP_TIMEOUT' => '2',
        ]);
        $elapsed = microtime(true) - $started;

        self::assertSame(['ok' => true, 'returned' => false], $run['result'], $run['stderr']);
        self::assertLessThan(10, $elapsed, 'A refused connection must not keep the page waiting');
        $log = $run['files']['storage/logs/app.log'] ?? '';
        self::assertMatchesRegularExpression('/ERROR: Email delivery failed: .*\{"subject":"Reset your BookWise password"\}/', $log);
        foreach ($run['files'] as $file => $contents) {
            self::assertStringNotContainsString(self::SMTP_PASSWORD, $contents, $file);
        }
        self::assertStringNotContainsString(self::SMTP_PASSWORD, $run['stderr']);
        self::assertStringNotContainsString('SMTP -> ', $run['stderr'], 'SMTP debug output is off');
    }

    public function testSmtpGivesUpOnASilentServerAfterTheTimeout(): void
    {
        // Accepts connections (the system completes them) but never says hello.
        $silent = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($silent, $errstr);
        $name = (string) stream_socket_get_name($silent, false);
        try {
            $started = microtime(true);
            $run = IsolatedPhp::run('send_mail', self::MESSAGE, self::smtpEnv((int) substr($name, strrpos($name, ':') + 1), ['SMTP_TIMEOUT' => '2']));
            $elapsed = microtime(true) - $started;
        } finally {
            fclose($silent);
        }

        self::assertSame(['ok' => true, 'returned' => false], $run['result'], $run['stderr']);
        self::assertGreaterThan(1.5, $elapsed, 'It waited for the greeting');
        self::assertLessThan(15, $elapsed, 'SMTP_TIMEOUT bounds the wait (PHPMailer alone would wait 300 seconds)');
        self::assertStringContainsString('ERROR: Email delivery failed', $run['files']['storage/logs/app.log'] ?? '');
    }

    public function testSmtpWithoutAHostFailsAndIsLogged(): void
    {
        $run = IsolatedPhp::run('send_mail', self::MESSAGE, ['MAIL_DRIVER' => 'smtp', 'SMTP_HOST' => null]);

        self::assertSame(['ok' => true, 'returned' => false], $run['result']);
        self::assertStringContainsString('ERROR: MAIL_DRIVER=smtp but SMTP_HOST is not set', $run['files']['storage/logs/app.log'] ?? '');
    }

    public function testUnknownSmtpEncryptionFailsAndIsLogged(): void
    {
        $run = IsolatedPhp::run('send_mail', self::MESSAGE, ['MAIL_DRIVER' => 'smtp', 'SMTP_HOST' => '127.0.0.1', 'SMTP_ENCRYPTION' => 'starttls']);

        self::assertSame(['ok' => true, 'returned' => false], $run['result']);
        self::assertStringContainsString('ERROR: Unknown SMTP_ENCRYPTION (use tls, ssl or none) {"encryption":"starttls"}', $run['files']['storage/logs/app.log'] ?? '');
    }

    public function testDemoMailTextLinksOnlyToTheStore(): void
    {
        $this->setEnv('APP_URL', 'https://shop.example/');

        $html = demo_mail_text_html(
            "Hello <b>Reader</b> & friends,\n"
            . "Confirm: https://shop.example/verify_email.php?token=ab12&lang=ar.\n"
            . "(https://shop.example/index.php?form=reset)\n"
            . "Elsewhere: https://evil.example/phish https://shop.example.evil.com/x http://shop.example/x\n"
            . 'javascript:alert(1) "https://shop.example/x"onmouseover="alert(1)'
        );

        self::assertSame(
            "Hello &lt;b&gt;Reader&lt;/b&gt; &amp; friends,\n"
            . 'Confirm: <a href="https://shop.example/verify_email.php?token=ab12&amp;lang=ar">https://shop.example/verify_email.php?token=ab12&amp;lang=ar</a>.' . "\n"
            . '(<a href="https://shop.example/index.php?form=reset">https://shop.example/index.php?form=reset</a>)' . "\n"
            . "Elsewhere: https://evil.example/phish https://shop.example.evil.com/x http://shop.example/x\n"
            . 'javascript:alert(1) &quot;<a href="https://shop.example/x">https://shop.example/x</a>&quot;onmouseover=&quot;alert(1)',
            $html
        );
        self::assertSame("a \u{FFFD} &lt;b&gt;", demo_mail_text_html("a \xFF <b>"), 'Invalid UTF-8 is still escaped');
    }

    public function testAppLogWritesOneStructuredLine(): void
    {
        $run = IsolatedPhp::run('app_log', [
            'level' => 'warning',
            'message' => "first line\r\nsecond line",
            'context' => ['order_id' => 7, 'path' => 'a/b', 'name' => 'كتاب'],
        ]);

        $log = $run['files']['storage/logs/app.log'] ?? '';
        self::assertMatchesRegularExpression(
            '#^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] WARNING: first line  second line \{"order_id":7,"path":"a/b","name":"كتاب"\}\n$#u',
            $log
        );
    }
}
