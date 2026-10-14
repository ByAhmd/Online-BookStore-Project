<?php

declare(strict_types=1);

namespace BookWise\Tests\Docker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards for the container and hosting files (Dockerfile, .dockerignore,
 * docker/, docker-compose.yml, render.yaml). The image itself is built and
 * exercised by the "Docker image" job in .github/workflows/ci.yml.
 */
final class DeploymentFilesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function linuxTextFiles(): array
    {
        $files = ['Dockerfile', '.dockerignore', 'docker-compose.yml', 'render.yaml'];
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
        self::assertNotContains('tests', $allowed, 'The test suite must stay out of the build context');

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

        $ini = self::read('docker/php/bookwise.ini');
        self::assertMatchesRegularExpression('/^display_errors = Off$/m', $ini);
        self::assertMatchesRegularExpression('/^expose_php = Off$/m', $ini);
    }

    public function testThePaymentSimulatorIsOnlyServedInDemoMode(): void
    {
        $site = self::read('docker/apache/site.conf');
        $depth = 0;
        foreach (preg_split('/\R/', $site) ?: [] as $number => $line) {
            if (preg_match('/^\s*<IfDefine BOOKWISE_STRIPE_SIM>/', $line)) {
                $depth++;
            } elseif (preg_match('/^\s*<\/IfDefine>/', $line)) {
                $depth--;
            } elseif (!preg_match('/^\s*#/', $line) && preg_match('/stripe-sim|tests\/Support/', $line)) {
                self::assertSame(1, $depth, 'Line ' . ($number + 1) . ' must be inside <IfDefine BOOKWISE_STRIPE_SIM>: ' . trim($line));
            }
        }
        self::assertSame(0, $depth);
        self::assertStringContainsString('SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1', $site);
        self::assertStringContainsString('DocumentRoot /var/www/bookwise/public', $site);
        self::assertStringContainsString('<VirtualHost *:${PORT}>', $site);
        self::assertStringContainsString('Listen ${PORT}', self::read('docker/apache/ports.conf'));

        $entrypoint = self::read('docker/entrypoint.sh');
        self::assertStringContainsString('set -- "$@" -D BOOKWISE_STRIPE_SIM', $entrypoint);
        self::assertStringContainsString('unset STRIPE_SIM_TEST_API', $entrypoint);
    }

    public function testRenderBlueprintRunsTheDemoAndKeepsSecretsOutOfTheRepository(): void
    {
        $blueprint = self::read('render.yaml');
        foreach (['type: web', 'runtime: docker', 'plan: free', 'healthCheckPath: /health.php', 'branch: main', 'autoDeployTrigger: commit'] as $setting) {
            self::assertStringContainsString($setting, $blueprint);
        }

        $variables = self::renderVariables($blueprint);
        self::assertSame('production', $variables['APP_ENV'] ?? null);
        self::assertSame('false', $variables['APP_DEBUG'] ?? null);
        self::assertSame('true', $variables['APP_DEMO'] ?? null);
        self::assertSame('false', $variables['REQUIRE_EMAIL_VERIFICATION'] ?? null);
        self::assertSame('null', $variables['MAIL_DRIVER'] ?? null);
        self::assertSame('sar', $variables['STRIPE_CURRENCY'] ?? null);
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_SSL_CA_PEM', 'BOOKWISE_ADMIN_EMAIL', 'BOOKWISE_ADMIN_PASSWORD'] as $secret) {
            self::assertSame('sync: false', $variables[$secret] ?? null, "$secret must be entered in the Render Dashboard");
        }
        self::assertArrayNotHasKey('STRIPE_SECRET_KEY', $variables, 'The demo generates its own sk_test_ key');
        self::assertArrayNotHasKey('STRIPE_SIM_TEST_API', $variables);
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
            $environment[$match[1]] = trim($match[2], '"');
        }
        self::assertSame('true', $environment['APP_DEMO'] ?? null);
        self::assertSame('false', $environment['REQUIRE_EMAIL_VERIFICATION'] ?? null);
        self::assertSame('log', $environment['MAIL_DRIVER'] ?? null);
        self::assertSame('http://localhost:8080', $environment['APP_URL'] ?? null);
        self::assertSame('db', $environment['DB_HOST'] ?? null);
        self::assertStringContainsString('local', $environment['DB_PASS'] ?? '', 'Use an obviously local-only database password');
        self::assertStringContainsString('local', $environment['BOOKWISE_ADMIN_PASSWORD'] ?? '', 'Use an obviously local-only admin password');
        self::assertArrayNotHasKey('STRIPE_SECRET_KEY', $environment);
    }

    public function testTheDeploymentGuideMentionsEveryConfiguredVariable(): void
    {
        $guide = self::read('docs/DEPLOYMENT.md');
        $names = array_keys(self::renderVariables(self::read('render.yaml')));
        preg_match_all('/\$\{?([A-Z][A-Z0-9_]+)/', self::read('docker/entrypoint.sh'), $matches);
        $names = array_merge($names, array_diff($matches[1], ['SECONDS', 'PORT', 'BASH_SOURCE', 'SIMULATOR', 'SIMULATOR_PATH', 'SIMULATOR_STORE', 'BOOKWISE_ROOT']), ['PORT']);
        foreach (array_unique($names) as $name) {
            self::assertStringContainsString($name, $guide, "docs/DEPLOYMENT.md must explain $name");
        }
    }

    /**
     * envVars of render.yaml: key => value, or "sync: false" / "generateValue: true".
     *
     * @return array<string, string>
     */
    private static function renderVariables(string $blueprint): array
    {
        preg_match_all('/^\s*- key: ([A-Z0-9_]+)\n\s+(value: "?([^"\n]*)"?|sync: false|generateValue: true)$/m', $blueprint, $matches, PREG_SET_ORDER);
        $variables = [];
        foreach ($matches as $match) {
            $variables[$match[1]] = str_starts_with($match[2], 'value:') ? $match[3] : $match[2];
        }
        return $variables;
    }

    private static function read(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/' . $file;
        self::assertFileExists($path);
        return (string) file_get_contents($path);
    }
}
