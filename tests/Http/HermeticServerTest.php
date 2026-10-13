<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\AppServer;
use BookWise\Tests\Support\HttpTestCase;

/**
 * Self-check of the HTTP harness: the server under test uses the test
 * database, its own LOG_DIR and session directory, the Stripe mock and the
 * test APP_URL, whatever the developer's .env file says.
 */
final class HermeticServerTest extends HttpTestCase
{
    public function testEveryEnvExampleVariableIsSetExplicitly(): void
    {
        preg_match_all('/^[ \t]*([A-Za-z_][A-Za-z0-9_]*)[ \t]*=/m', (string) file_get_contents(BASE_PATH . '/.env.example'), $matches);
        $configuration = self::server()->configuration;
        foreach ([...$matches[1], 'LOG_DIR', 'STRIPE_API_BASE'] as $key) {
            self::assertArrayHasKey($key, $configuration, "$key must be set for the test server");
        }
        self::assertSame('testing', $configuration['APP_ENV']);
        self::assertSame(self::server()->baseUrl, $configuration['APP_URL']);
        self::assertStringEndsWith('_test', $configuration['DB_NAME']);
        self::assertSame((string) config('db.name'), $configuration['DB_NAME']);
        self::assertSame($this->stripe->baseUrl, $configuration['STRIPE_API_BASE']);
        self::assertStringStartsWith('sk_test_', $configuration['STRIPE_SECRET_KEY']);
        self::assertStringStartsWith('whsec_', $configuration['STRIPE_WEBHOOK_SECRET']);
        self::assertSame('log', $configuration['MAIL_DRIVER']);
        self::assertStringStartsWith(self::server()->runDirectory, $configuration['LOG_DIR']);
    }

    public function testServerWritesOnlyToTheTestDatabaseAndItsOwnDirectories(): void
    {
        $projectLogs = [];
        foreach (['app.log', 'mail.log', 'php-error.log'] as $file) {
            $path = BASE_PATH . '/storage/logs/' . $file;
            $projectLogs[$path] = is_file($path) ? (string) filesize($path) . ':' . md5_file($path) : null;
        }

        $client = $this->client();
        $email = self::uniqueEmail('hermetic');
        $this->assertRedirect($this->signUp($client, $email, 'Hermetic Reader', 'hermetic-password-1'), 'Home_Page.php');

        // The account lands in the test database the suite reads.
        self::assertSame(1, $this->countRows('users', 'Eaddress = ?', [$email]));

        // The welcome mail lands in the run's LOG_DIR, with links built from the test APP_URL.
        $mails = $this->mailsTo($email);
        self::assertCount(1, $mails);
        self::assertStringContainsString(self::server()->baseUrl . '/', $mails[0]['body']);

        // app.log too: a rejected webhook is logged as a warning there.
        $payload = '{"id":"evt_hermetic","object":"event","type":"checkout.session.completed","data":{"object":{}}}';
        $this->assertStatus(400, $client->post('stripe_webhook.php', $payload, ['Stripe-Signature' => self::stripeSignature($payload, null, 'whsec_wrong')]));
        self::assertNotSame([], array_filter($this->appLogLines(), static fn (string $line): bool => str_contains($line, 'WARNING: Rejected Stripe webhook')));

        // The webhook secret is the harness's, not the developer's.
        $this->assertStatus(200, $client->post('stripe_webhook.php', $payload, ['Stripe-Signature' => self::stripeSignature($payload, null, AppServer::STRIPE_WEBHOOK_SECRET)]));

        // Sessions are files in the run's private session directory.
        $sessionId = (string) $client->cookie(self::SESSION_COOKIE);
        self::assertFileExists(self::server()->runDirectory . DIRECTORY_SEPARATOR . 'sessions' . DIRECTORY_SEPARATOR . 'sess_' . $sessionId);

        // The developer's storage/logs is untouched.
        foreach ($projectLogs as $path => $fingerprint) {
            if ($fingerprint === null) {
                continue; // other local processes may create it; we only check existing files are not ours
            }
            self::assertStringNotContainsString($email, (string) file_get_contents($path), "$path must not receive test output");
        }

        // The server answers on the expected origin and the built-in server log is captured.
        self::assertStringContainsString('/Register.php', self::server()->output());
    }
}
