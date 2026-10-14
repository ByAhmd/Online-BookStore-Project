<?php

declare(strict_types=1);

namespace BookWise\Tests\Docker;

use BookWise\Tests\Support\AppServer;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * public/health.php (Docker HEALTHCHECK, Render's healthCheckPath) and the
 * docker/healthcheck.php command that the image's HEALTHCHECK runs.
 */
final class HealthEndpointTest extends HttpTestCase
{
    use RunsProjectScripts;

    public function testReportsOkWhileTheDatabaseAnswers(): void
    {
        $response = $this->client()->get('health.php');

        $this->assertStatus(200, $response);
        self::assertSame('{"status":"ok"}', $response->body);
        self::assertStringStartsWith('application/json', $response->contentType());
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame([], $response->headerValues('Set-Cookie'), 'The health check must not start a session');
    }

    public function testAnswersHeadRequests(): void
    {
        $response = $this->client()->request('HEAD', 'health.php');

        $this->assertStatus(200, $response);
        self::assertSame('no-store', $response->header('Cache-Control'));
    }

    #[DataProvider('otherMethods')]
    public function testRefusesOtherMethods(string $method): void
    {
        $response = $this->client()->request($method, 'health.php', ['status' => 'down']);

        $this->assertStatus(405, $response);
        self::assertSame('GET, HEAD', $response->header('Allow'));
        self::assertSame(['status' => 'method_not_allowed'], $response->json());
        self::assertSame([], $response->headerValues('Set-Cookie'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherMethods(): array
    {
        return ['POST' => ['POST'], 'PUT' => ['PUT'], 'DELETE' => ['DELETE'], 'PATCH' => ['PATCH']];
    }

    public function testDockerHealthcheckCommandPassesAgainstAHealthyServer(): void
    {
        $result = self::runProjectScript('docker/healthcheck.php', ['PORT' => self::portOf(self::server()->baseUrl)]);

        self::assertSame(0, $result['status'], $result['stdout'] . $result['stderr']);
        self::assertSame('', $result['stdout'] . $result['stderr']);
    }

    public function testDockerHealthcheckCommandFailsWhenNothingListens(): void
    {
        $result = self::runProjectScript('docker/healthcheck.php', ['PORT' => (string) self::closedPort()]);

        self::assertSame(1, $result['status']);
    }

    public function testReportsUnavailableWithoutDetailsWhileTheDatabaseIsDown(): void
    {
        $secret = 'health-db-secret-' . bin2hex(random_bytes(4));
        $down = AppServer::start([
            'host' => '127.0.0.1',
            'port' => self::closedPort(),
            'name' => (string) config('db.name'),
            'user' => 'health_probe',
            'pass' => $secret,
        ], $this->stripe->baseUrl);

        try {
            $response = (new HttpClient($down->baseUrl))->get('health.php');

            self::assertSame(503, $response->status, $response->describe());
            self::assertSame('{"status":"unavailable"}', $response->body, 'No error details may reach the caller');
            self::assertSame('no-store', $response->header('Cache-Control'));
            self::assertSame([], $response->headerValues('Set-Cookie'));

            $log = $down->readLog('app.log');
            self::assertMatchesRegularExpression('/\] WARNING: Health check: database unavailable \(/', $log);
            self::assertStringNotContainsString($secret, $log);

            $result = self::runProjectScript('docker/healthcheck.php', ['PORT' => self::portOf($down->baseUrl)]);
            self::assertSame(1, $result['status'], 'The Docker HEALTHCHECK must fail while the database is down');
        } finally {
            $down->stop();
        }
    }

    private static function portOf(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PORT);
    }
}
