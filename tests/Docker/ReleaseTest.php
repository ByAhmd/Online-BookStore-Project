<?php

declare(strict_types=1);

namespace BookWise\Tests\Docker;

use PHPUnit\Framework\TestCase;

/**
 * The release: CHANGELOG.md (Keep a Changelog 1.1.0, Semantic Versioning),
 * bin/release-notes.php, which turns a version's section into the notes of
 * its GitHub release, and .github/workflows/release.yml, which publishes the
 * image and the release when a version tag is pushed. That every action is
 * pinned is checked for all workflows in DeploymentFilesTest.
 */
final class ReleaseTest extends TestCase
{
    use RunsProjectScripts;

    private const REPOSITORY = 'https://github.com/ByAhmd/Online-BookStore-Project';

    /** @var list<string> Scratch changelog files, deleted after each test */
    private array $files = [];

    private static function read(string $file): string
    {
        $path = BASE_PATH . '/' . $file;
        self::assertFileExists($path);
        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    /**
     * @return list<array{version: string, date: string, body: string}> The released versions, newest first
     */
    private static function releasedVersions(string $changelog): array
    {
        preg_match_all('/^## \[(\d+\.\d+\.\d+)\] - (\d{4}-\d{2}-\d{2})\n(.*?)(?=^## |^\[[^\]]+\]: |\z)/ms', $changelog, $matches, PREG_SET_ORDER);
        return array_map(static fn (array $match): array => ['version' => $match[1], 'date' => $match[2], 'body' => $match[3]], $matches);
    }

    public function testTheChangelogFollowsKeepAChangelog(): void
    {
        $changelog = self::read('CHANGELOG.md');
        self::assertStringStartsWith("# Changelog\n", $changelog);
        self::assertStringContainsString('[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/)', $changelog);
        self::assertStringContainsString('[Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html)', $changelog);

        preg_match_all('/^## (.*)$/m', $changelog, $headings);
        self::assertSame('[Unreleased]', $headings[1][0] ?? null, 'The first section collects unreleased changes');
        $versions = self::releasedVersions($changelog);
        self::assertCount(count($headings[1]) - 1, $versions, 'Every other section is "## [x.y.z] - YYYY-MM-DD"');
        self::assertNotEmpty($versions);
        self::assertSame(['version' => '1.0.0', 'date' => '2026-10-15'], array_intersect_key(end($versions), ['version' => 0, 'date' => 0]), 'The first release');

        $previous = null;
        foreach ($versions as $release) {
            ['version' => $version, 'date' => $date, 'body' => $body] = $release;
            self::assertNotFalse(\DateTimeImmutable::createFromFormat('!Y-m-d', $date), "$version: a real date");
            if ($previous !== null) {
                self::assertTrue(version_compare($version, $previous['version'], '<'), 'Newest version first');
                self::assertLessThanOrEqual($previous['date'], $date, 'Newest date first');
            }
            preg_match_all('/^### (.*)$/m', $body, $groups);
            self::assertNotEmpty($groups[1], "$version lists its changes in groups");
            foreach ($groups[1] as $group) {
                self::assertContains($group, ['Added', 'Changed', 'Deprecated', 'Removed', 'Fixed', 'Security'], "$version: a group of Keep a Changelog");
            }
            // The section becomes the notes of a GitHub release, where relative links lead nowhere.
            preg_match_all('/\]\(([^)]*)\)/', $body, $links);
            foreach ($links[1] as $link) {
                self::assertStringStartsWith('https://', $link, "$version links to $link");
            }
            self::assertStringContainsString("\n[$version]: " . self::REPOSITORY . '/', $changelog, "$version has a link definition");
            $previous = $release;
        }
        self::assertStringContainsString("\n[unreleased]: " . self::REPOSITORY . '/compare/v' . $versions[0]['version'] . "...HEAD\n", $changelog);
    }

    public function testTheReleaseNotesAreTheVersionsSection(): void
    {
        $versions = self::releasedVersions(self::read('CHANGELOG.md'));
        self::assertNotEmpty($versions);
        foreach ($versions as ['version' => $version, 'body' => $body]) {
            foreach ([$version, "v$version"] as $argument) {
                $run = self::runProjectScript('bin/release-notes.php', [], [$argument]);
                self::assertSame(0, $run['status'], $run['stderr']);
                self::assertSame(trim($body) . "\n", str_replace("\r\n", "\n", $run['stdout']));
                self::assertStringNotContainsString('## [', $run['stdout'], 'Without the headings');
            }
        }
    }

    public function testTheReleaseNotesEndAtTheNextSectionOrTheLinkDefinitions(): void
    {
        $file = $this->changelog(
            "# Changelog\n\n## [Unreleased]\n\n- Not yet.\n\n## [2.0.0] - 2026-11-02\n\n### Removed\n\n- The old thing.\n\n"
            . "## [1.10.0] - 2026-10-20\r\n\r\n### Added\r\n\r\n- A new thing.\r\n\r\n"
            . "[unreleased]: https://example.com/compare/v2.0.0...HEAD\n[2.0.0]: https://example.com/2\n[1.10.0]: https://example.com/1\n"
        );
        $run = self::runProjectScript('bin/release-notes.php', [], ['v2.0.0', $file]);
        self::assertSame(0, $run['status'], $run['stderr']);
        self::assertSame("### Removed\n\n- The old thing.\n", $run['stdout']);

        $run = self::runProjectScript('bin/release-notes.php', [], ['1.10.0', $file]);
        self::assertSame(0, $run['status'], $run['stderr']);
        self::assertSame("### Added\n\n- A new thing.\n", $run['stdout']);
    }

    public function testAVersionWithoutNotesIsRefused(): void
    {
        $file = $this->changelog("# Changelog\n\n## [Unreleased]\n\n## [1.1.0] - 2026-11-01\n\n## [1.0.0] - 2026-10-15\n\n- First.\n");
        $cases = [
            'no section' => [['1.2.0', $file], 'has no section "## [1.2.0] - YYYY-MM-DD"'],
            'a prefix of a version' => [['1.0', $file], 'Not a version of the form 1.2.3'],
            'an empty section' => [['1.1.0', $file], 'The section of 1.1.0'],
            'a pre-release' => [['v1.0.0-rc.1', $file], 'Not a version of the form 1.2.3'],
            'a missing file' => [['1.0.0', $file . '.missing'], 'Cannot read'],
            'no version' => [[], 'Usage: php bin/release-notes.php'],
        ];
        foreach ($cases as $case => [$arguments, $message]) {
            $run = self::runProjectScript('bin/release-notes.php', [], $arguments);
            self::assertSame(1, $run['status'], $case);
            self::assertSame('', $run['stdout'], $case);
            self::assertStringContainsString($message, $run['stderr'], $case);
        }
    }

    public function testTheReleaseWorkflowPublishesTheImageAndTheRelease(): void
    {
        $workflow = self::read('.github/workflows/release.yml');

        self::assertStringContainsString("on:\n  push:\n    tags: ['v*.*.*']\n\n", $workflow, 'Only version tags start a release');
        self::assertSame(1, preg_match('/^permissions:\n((?:  .*\n)+)/m', $workflow, $permissions));
        self::assertSame(
            ['contents: write', 'packages: write'],
            array_map(static fn (string $line): string => trim((string) preg_replace('/#.*$/', '', $line)), explode("\n", trim($permissions[1]))),
            'Only the permissions the release needs'
        );
        self::assertSame(1, substr_count($workflow, 'permissions:'), 'No job asks for more');

        $steps = [
            'php bin/release-notes.php "$GITHUB_REF_NAME" > "$RUNNER_TEMP/release-notes.md"',
            'uses: docker/metadata-action@',
            'uses: docker/setup-buildx-action@',
            'uses: docker/login-action@',
            'uses: docker/build-push-action@',
            'gh release create "$GITHUB_REF_NAME" --verify-tag',
        ];
        $positions = [];
        foreach ($steps as $step) {
            $position = strpos($workflow, $step);
            self::assertNotFalse($position, "release.yml: $step");
            $positions[] = $position;
        }
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'The notes are checked before anything is published, and the release comes last');

        self::assertStringContainsString("images: ghcr.io/byahmd/online-bookstore-project\n", $workflow);
        foreach (['{{version}}', '{{major}}.{{minor}}', '{{major}}'] as $pattern) {
            self::assertStringContainsString("type=semver,pattern=$pattern\n", $workflow);
        }
        self::assertStringNotContainsString('latest=false', $workflow, 'Releases are also tagged latest');
        // The action moves the major tag and latest to every release it
        // publishes, so the guides say to release in increasing order, and
        // that an anonymous pull needs a public package.
        self::assertStringContainsString('Releases are made from `main` only, each with a higher version than the one before', self::read('CONTRIBUTING.md'));
        self::assertStringContainsString('Versions are released in increasing order', self::read('docs/DEPLOYMENT.md'));
        self::assertStringContainsString('Pulling without signing in works only once the package is public', self::read('docs/DEPLOYMENT.md'));
        self::assertStringContainsString("registry: ghcr.io\n          username: \${{ github.actor }}\n          password: \${{ secrets.GITHUB_TOKEN }}\n", $workflow);
        foreach (['push: true', 'tags: ${{ steps.meta.outputs.tags }}', 'labels: ${{ steps.meta.outputs.labels }}', 'provenance: mode=max', 'sbom: true'] as $setting) {
            self::assertStringContainsString("          $setting\n", $workflow, "build-push-action: $setting");
        }
        self::assertStringContainsString('--notes-file "$RUNNER_TEMP/release-notes.md"', $workflow);
        self::assertStringContainsString('GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}', $workflow);
        self::assertDoesNotMatchRegularExpression('/secrets\.(?!GITHUB_TOKEN\b)/', $workflow, 'No secret other than the workflow token');
    }

    private function changelog(string $contents): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'bookwise-changelog-');
        file_put_contents($file, $contents);
        $this->files[] = $file;
        return $file;
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }
}
