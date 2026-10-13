<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use BookWise\Tests\Support\EnvironmentOverrides;
use BookWise\Tests\Support\IsolatedPhp;
use PHPUnit\Framework\TestCase;

/**
 * Drivers that write files run in an isolated process whose BASE_PATH is a
 * temporary directory, so the real storage/logs files are never touched.
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

    public function testSendgridWithoutApiKeyFailsWithoutThrowing(): void
    {
        $run = IsolatedPhp::run('send_mail', self::MESSAGE, ['MAIL_DRIVER' => 'sendgrid', 'SENDGRID_API_KEY' => null]);

        self::assertSame(['ok' => true, 'returned' => false], $run['result']);
        self::assertStringContainsString('SENDGRID_API_KEY is not set', $run['files']['storage/logs/app.log'] ?? '');
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
