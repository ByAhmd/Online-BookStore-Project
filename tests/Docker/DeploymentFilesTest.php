<?php

declare(strict_types=1);

namespace BookWise\Tests\Docker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards for the container and hosting files (Dockerfile, .dockerignore,
 * docker/, docker-compose.yml) and the deployment guide. The image itself is
 * built and exercised by the "Docker image" job in .github/workflows/ci.yml.
 */
final class DeploymentFilesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function linuxTextFiles(): array
    {
        $files = ['Dockerfile', '.dockerignore', 'docker-compose.yml'];
        foreach (['docker/*.sh', 'docker/*.php', 'docker/apache/*', 'docker/php/*', 'tests/Docker/*.sh'] as $pattern) {
            foreach (glob(dirname(__DIR__, 2) . '/' . $pattern) ?: [] as $path) {
                $files[] = substr($path, strlen(dirname(__DIR__, 2)) + 1);
            }
        }
        $cases = [];
        foreach ($files as $file) {
            $cases[$file] = [$file];
        }
        return $cases;
    }

    #[DataProvider('linuxTextFiles')]
    public function testFilesUsedInsideTheContainerHaveLfLineEndings(string $file): void
    {
        $contents = self::read($file);
        self::assertStringNotContainsString("\r", $contents, "$file must use LF line endings (see .gitattributes)");
    }

    public function testGitattributesKeepScriptsAndContainerFilesOnLf(): void
    {
        // A Windows checkout may give .gitattributes itself CRLF endings; git reads either.
        $attributes = str_replace("\r\n", "\n", self::read('.gitattributes'));
        foreach (['*.sh', 'docker/**', 'Dockerfile'] as $pattern) {
            self::assertMatchesRegularExpression('/^' . preg_quote($pattern, '/') . '\s+text eol=lf$/m', $attributes, "$pattern must be checked out with LF");
        }
        foreach (['*.png', '*.jpg', '*.jpeg', '*.gif', '*.webp'] as $pattern) {
            self::assertMatchesRegularExpression('/^' . preg_quote($pattern, '/') . '\s+binary$/m', $attributes, "$pattern must be binary");
        }
    }

    public function testShellScriptsRunWithBashInStrictMode(): void
    {
        foreach (['docker/entrypoint.sh', 'tests/Docker/smoke.sh', 'tests/Docker/entrypoint_test.sh'] as $script) {
            $contents = self::read($script);
            self::assertStringStartsWith("#!/usr/bin/env bash\n", $contents, "$script needs a bash shebang");
            self::assertStringContainsString("\nset -euo pipefail\n", $contents, "$script must stop on errors");
        }
    }

    public function testDockerfileCopiesOnlyPathsTheBuildContextAllows(): void
    {
        $allowed = [];
        foreach (preg_split('/\R/', self::read('.dockerignore')) ?: [] as $line) {
            if (str_starts_with($line, '!')) {
                $allowed[] = rtrim(substr($line, 1), '/');
            }
        }
        foreach ($allowed as $entry) {
            self::assertFalse(str_starts_with($entry, 'tests'), "The test suite must stay out of the build context ($entry)");
        }

        preg_match_all('/^COPY\s+(?!--from)(.+)$/m', self::read('Dockerfile'), $matches);
        self::assertNotEmpty($matches[1]);
        foreach ($matches[1] as $arguments) {
            $parts = preg_split('/\s+/', trim($arguments)) ?: [];
            array_pop($parts); // destination
            foreach ($parts as $source) {
                $source = rtrim($source, '/');
                $covered = false;
                foreach ($allowed as $entry) {
                    if ($source === $entry || str_starts_with($source, $entry . '/')) {
                        $covered = true;
                    }
                }
                self::assertTrue($covered, "COPY $source is excluded by .dockerignore");
                self::assertNotSame('.', $source, 'The Dockerfile must not copy the whole project');
            }
        }
    }

    public function testImageRunsAsWwwDataWithAHealthcheckAndTheEntrypoint(): void
    {
        $dockerfile = self::read('Dockerfile');
        self::assertMatchesRegularExpression('/^FROM php:8\.3-apache$/m', $dockerfile);
        self::assertMatchesRegularExpression('/^USER www-data$/m', $dockerfile);
        self::assertMatchesRegularExpression('/^HEALTHCHECK .*\n\s+CMD \["php", "\/var\/www\/bookwise\/docker\/healthcheck\.php"\]$/m', $dockerfile);
        self::assertMatchesRegularExpression('/^ENTRYPOINT \["\/var\/www\/bookwise\/docker\/entrypoint\.sh"\]$/m', $dockerfile);
        self::assertStringContainsString('php.ini-production', $dockerfile);
        self::assertStringNotContainsString('--classmap-authoritative', preg_replace('/^#.*$/m', '', $dockerfile) ?? '');
        // storage/ holds the logs and the payment simulator's state.
        self::assertMatchesRegularExpression('/chown -R www-data:www-data storage public\/uploads/', $dockerfile);

        $ini = self::read('docker/php/bookwise.ini');
        self::assertMatchesRegularExpression('/^display_errors = Off$/m', $ini);
        self::assertMatchesRegularExpression('/^expose_php = Off$/m', $ini);
    }

    public function testThePaymentSimulatorNeedsNoServerWiring(): void
    {
        // The simulator runs inside the application (src/payment_simulator.php) and its
        // page is public/pay.php: no Apache alias, no test files, no loopback API calls.
        $site = self::withoutComments(self::read('docker/apache/site.conf'));
        foreach (['stripe-sim', 'tests/Support', 'AliasMatch', 'IfDefine', 'HTTP_AUTHORIZATION'] as $wiring) {
            self::assertStringNotContainsString($wiring, $site, "site.conf must not contain $wiring");
        }
        self::assertStringContainsString('DocumentRoot /var/www/bookwise/public', $site);
        self::assertStringContainsString('<VirtualHost *:${PORT}>', $site);
        self::assertStringContainsString('Listen ${PORT}', self::read('docker/apache/ports.conf'));

        $dockerfile = self::withoutComments(self::read('Dockerfile'));
        self::assertStringNotContainsString('tests/', $dockerfile, 'Nothing from tests/ goes into the image');
        self::assertStringNotContainsString('BOOKWISE_STRIPE_SIM', $dockerfile);

        $entrypoint = self::withoutComments(self::read('docker/entrypoint.sh'));
        foreach (['BOOKWISE_STRIPE_SIM', 'stripe-sim', 'STRIPE_SIM_', 'STRIPE_MOCK_STORE', 'RENDER_EXTERNAL_URL'] as $removed) {
            self::assertStringNotContainsString($removed, $entrypoint);
        }
        self::assertDoesNotMatchRegularExpression('/^\s*(export\s+)?STRIPE_[A-Z_]+=/m', $entrypoint, 'The entrypoint sets no Stripe settings');
        self::assertStringContainsString('${PAYMENT_SIMULATOR:-${APP_DEMO:-false}}', $entrypoint, 'Same default as src/env.php');
    }

    public function testRenderIsGone(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/render.yaml');
        foreach (['README.md', 'docs/DEPLOYMENT.md', 'SECURITY.md', '.env.example', 'docker-compose.yml', 'Dockerfile', 'docker/entrypoint.sh', 'docker/apache/server.conf', 'docker/apache/ports.conf'] as $file) {
            self::assertDoesNotMatchRegularExpression('/\bRender\b|onrender|Aiven|RENDER_/', self::read($file), "$file still refers to Render or Aiven");
        }
    }

    public function testComposeRunsADemoStoreWithLocalOnlyCredentials(): void
    {
        $compose = self::read('docker-compose.yml');
        self::assertStringContainsString('image: mysql:8.0', $compose);
        self::assertStringContainsString('healthcheck:', $compose);
        self::assertStringContainsString('condition: service_healthy', $compose);
        self::assertStringContainsString('"8080:8080"', $compose);

        $app = substr($compose, (int) strpos($compose, "\n  app:\n"));
        $environment = [];
        preg_match_all('/^      ([A-Z][A-Z0-9_]*): (.*)$/m', $app, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            // ${NAME:-default} is the default unless NAME is set where compose runs.
            $environment[$match[1]] = (string) preg_replace('/^\$\{[A-Z_]+:-(.*)\}$/', '$1', trim($match[2], '"'));
        }
        self::assertSame('true', $environment['APP_DEMO'] ?? null);
        self::assertArrayNotHasKey('PAYMENT_SIMULATOR', $environment, 'Demo mode turns the simulator on by itself');
        self::assertSame('false', $environment['REQUIRE_EMAIL_VERIFICATION'] ?? null);
        self::assertSame('log', $environment['MAIL_DRIVER'] ?? null);
        self::assertSame('http://localhost:8080', $environment['APP_URL'] ?? null);
        self::assertSame('db', $environment['DB_HOST'] ?? null);
        self::assertStringContainsString('local', $environment['DB_PASS'] ?? '', 'Use an obviously local-only database password');
        self::assertStringContainsString('local', $environment['BOOKWISE_ADMIN_PASSWORD'] ?? '', 'Use an obviously local-only admin password');
        self::assertArrayNotHasKey('STRIPE_SECRET_KEY', $environment);

        // The entrypoint refuses exactly this password on a public address.
        self::assertStringContainsString('LOCAL_ADMIN_PASSWORD=' . ($environment['BOOKWISE_ADMIN_PASSWORD'] ?? '') . "\n", self::read('docker/entrypoint.sh'));
        self::assertStringContainsString('APP_URL: ${BOOKWISE_APP_URL:-', $app, 'The public address can be set without editing the file');
    }

    public function testTheDeploymentGuideMentionsEveryConfiguredVariable(): void
    {
        $guide = self::read('docs/DEPLOYMENT.md');
        preg_match_all('/\$\{?([A-Z][A-Z0-9_]+)/', self::withoutComments(self::read('docker/entrypoint.sh')), $entrypoint);
        preg_match_all('/\$\{([A-Z][A-Z0-9_]+):-/', self::read('docker-compose.yml'), $compose);
        $names = array_merge(
            array_diff($entrypoint[1], ['SECONDS', 'BASH_SOURCE', 'BOOKWISE_ROOT', 'LOCAL_ADMIN_PASSWORD']),
            $compose[1],
            ['PORT', 'PAYMENT_SIMULATOR', 'PAYMENT_SIMULATOR_STORE']
        );
        foreach (array_unique($names) as $name) {
            self::assertStringContainsString($name, $guide, "docs/DEPLOYMENT.md must explain $name");
        }
    }

    private static function withoutComments(string $contents): string
    {
        return (string) preg_replace('/^\s*#.*$/m', '', $contents);
    }

    private static function read(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/' . $file;
        self::assertFileExists($path);
        return (string) file_get_contents($path);
    }
}
