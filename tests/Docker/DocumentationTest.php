<?php

declare(strict_types=1);

namespace BookWise\Tests\Docker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Markdown documentation: the README, CHANGELOG.md, CONTRIBUTING.md,
 * SECURITY.md and docs/*.md. Their links lead to files and headings that
 * exist, the README shows every screenshot in docs/screenshots/ and lists
 * every guide, and docs/CONFIGURATION.md lists every setting the code reads
 * with the default the code uses.
 */
final class DocumentationTest extends TestCase
{
    private static function read(string $file): string
    {
        $path = BASE_PATH . '/' . $file;
        self::assertFileExists($path);
        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    /**
     * @return list<string> The Markdown files of the project, relative to its root
     */
    private static function markdownFiles(): array
    {
        $files = [];
        foreach (array_merge(glob(BASE_PATH . '/*.md') ?: [], glob(BASE_PATH . '/docs/*.md') ?: []) as $path) {
            $files[] = substr(str_replace('\\', '/', $path), strlen(str_replace('\\', '/', BASE_PATH)) + 1);
        }
        sort($files);
        return $files;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function markdownFileCases(): array
    {
        $cases = [];
        foreach (self::markdownFiles() as $file) {
            $cases[$file] = [$file];
        }
        return $cases;
    }

    /**
     * The text without fenced code blocks, inline code and HTML comments,
     * where a link is not a link.
     */
    private static function prose(string $markdown): string
    {
        $markdown = (string) preg_replace('/^```.*?^```/ms', '', $markdown);
        $markdown = (string) preg_replace('/<!--.*?-->/s', '', $markdown);
        return (string) preg_replace('/`[^`\n]*`/', '', $markdown);
    }

    /**
     * @return array<string, true> The anchors GitHub gives the headings of a Markdown file
     */
    private static function anchors(string $markdown): array
    {
        $anchors = [];
        $inCode = false;
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (str_starts_with($line, '```')) {
                $inCode = !$inCode;
                continue;
            }
            if ($inCode || !preg_match('/^#{1,6}\s+(.+?)\s*$/u', $line, $match)) {
                continue;
            }
            $slug = (string) preg_replace('/[^\p{L}\p{N}\s_-]/u', '', mb_strtolower($match[1]));
            $slug = (string) preg_replace('/\s/u', '-', $slug);
            $anchor = $slug;
            for ($i = 1; isset($anchors[$anchor]); $i++) {
                $anchor = $slug . '-' . $i;
            }
            $anchors[$anchor] = true;
        }
        return $anchors;
    }

    public function testHeadingAnchorsAreWorkedOutLikeGitHub(): void
    {
        $anchors = array_keys(self::anchors("# BookWise — Online Book Store\n## (a) Run it locally with Docker\n### Step 7: point the site at `public/`\n```\n# not a heading\n```\n## Notes\n## Notes\n"));
        self::assertSame(['bookwise--online-book-store', 'a-run-it-locally-with-docker', 'step-7-point-the-site-at-public', 'notes', 'notes-1'], $anchors);
    }

    #[DataProvider('markdownFileCases')]
    public function testLinksLeadToAFileAndAHeading(string $file): void
    {
        $text = self::prose(self::read($file));
        preg_match_all('/\]\(([^)\s]+)\)|\b(?:href|src)="([^"]+)"/', $text, $matches, PREG_SET_ORDER);
        $directory = dirname($file) === '.' ? '' : dirname($file) . '/';
        $checked = 0;
        foreach ($matches as $match) {
            $link = ($match[1] ?? '') !== '' ? $match[1] : $match[2];
            if (preg_match('#^(?:https?:|mailto:)#', $link)) {
                continue;
            }
            [$path, $anchor] = array_pad(explode('#', $link, 2), 2, null);
            $target = $path === '' ? $file : self::normalise($directory . $path);
            self::assertTrue(is_file(BASE_PATH . '/' . $target) || is_dir(BASE_PATH . '/' . $target), "$file links to $link: $target does not exist");
            if ($anchor !== null) {
                self::assertStringEndsWith('.md', $target, "$file links to a heading of $target, which is not Markdown");
                self::assertArrayHasKey($anchor, self::anchors(self::read($target)), "$file links to $link, which is not a heading of $target");
            }
            $checked++;
        }
        if (in_array($file, ['README.md', 'docs/ARCHITECTURE.md', 'docs/DEPLOYMENT.md'], true)) {
            self::assertGreaterThan(10, $checked, "$file: the links were found");
        }
    }

    private static function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                self::assertNotEmpty($parts, "$path leaves the project");
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }
        return implode('/', $parts);
    }

    public function testTheReadmeShowsEveryScreenshot(): void
    {
        $readme = self::read('README.md');
        preg_match_all('#<a href="(docs/screenshots/[^"]+)"><img src="([^"]+)" alt="[^"]+" width="\d+"></a>#', $readme, $images, PREG_SET_ORDER);
        $shown = [];
        foreach ($images as [, $link, $source]) {
            self::assertSame($link, $source, 'Each screenshot links to its full-size image');
            $shown[] = $source;
        }
        self::assertSame(array_values(array_unique($shown)), $shown, 'Each screenshot is shown once');
        self::assertSame(2 * count($shown), substr_count($readme, 'docs/screenshots/'), 'Every screenshot is shown the same way');

        $files = [];
        foreach (glob(BASE_PATH . '/docs/screenshots/*') ?: [] as $path) {
            $files[] = 'docs/screenshots/' . basename($path);
            self::assertMatchesRegularExpression('/\.(jpg|webp)$/D', $path, 'Screenshots are compressed JPEG or WebP files');
            self::assertLessThan(300 * 1024, (int) filesize($path), basename($path) . ' is compressed');
        }
        sort($files);
        sort($shown);
        self::assertSame($files, $shown, 'The README shows every file in docs/screenshots/, and only those');
    }

    public function testTheReadmeListsEveryGuide(): void
    {
        $readme = self::read('README.md');
        self::assertSame(1, preg_match('/^## Documentation\n(.*?)^## /ms', $readme, $section));
        foreach (self::markdownFiles() as $file) {
            if ($file !== 'README.md') {
                self::assertStringContainsString("| [$file]($file) |", $section[1], "The README's documentation table lists $file");
            }
        }
        // One line for the address of the public demo, once it is online.
        self::assertSame(1, preg_match_all('/^<!-- LIVE DEMO: .*-->$/m', $readme));
    }

    /**
     * @return array<string, string|null> Setting => the default src/env.php gives it ('' for empty, null for none)
     */
    private static function settingsReadByTheApplication(): array
    {
        $code = self::read('src/env.php');
        preg_match_all("/\benv(?:_bool)?\('([A-Z][A-Z0-9_]*)'(?:, (?:'([^']*)'|(true|false)))?/", $code, $calls, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $settings = [];
        foreach ($calls as $call) {
            // The first call names the setting's default. PAYMENT_SIMULATOR's
            // default is another setting (APP_DEMO), so it has none here.
            if (!array_key_exists((string) $call[1], $settings)) {
                $settings[(string) $call[1]] = $call[3] ?? $call[2] ?? null;
            }
        }
        return $settings;
    }

    public function testTheConfigurationGuideListsEverySettingWithItsDefault(): void
    {
        $settings = self::settingsReadByTheApplication();
        self::assertGreaterThan(25, count($settings));
        self::assertSame('production', $settings['APP_ENV']);
        self::assertSame('false', $settings['APP_DEMO']);
        self::assertSame('', $settings['DB_PASS']);
        self::assertNull($settings['STRIPE_SECRET_KEY']);

        $guide = self::read('docs/CONFIGURATION.md');
        self::assertSame(1, preg_match('/^## Settings reference\n(.*?)^## /ms', $guide, $reference));
        $documented = [];
        preg_match_all('/^\| (`[A-Z][^|]*`) \| ([^|]+) \| [^|]+ \|$/m', $reference[1], $rows, PREG_SET_ORDER);
        foreach ($rows as [, $names, $defaults]) {
            $names = explode(' / ', $names);
            $defaults = explode(' / ', trim($defaults));
            foreach ($names as $i => $name) {
                $documented[trim($name, '`')] = count($defaults) === count($names) ? $defaults[$i] : $defaults[0];
            }
        }

        foreach ($settings as $name => $default) {
            self::assertArrayHasKey($name, $documented, "docs/CONFIGURATION.md lists $name");
            if ($name === 'PAYMENT_SIMULATOR') {
                self::assertSame('follows `APP_DEMO`', $documented[$name]);
                continue;
            }
            $expected = match ($default) {
                null => '—',
                '' => '*(empty)*',
                default => "`$default`",
            };
            self::assertSame($expected, $documented[$name], "docs/CONFIGURATION.md gives the default of $name");
        }

        // The scripts' own settings, in their own table.
        foreach (['BOOKWISE_ADMIN_EMAIL', 'BOOKWISE_ADMIN_PASSWORD'] as $name) {
            self::assertArrayHasKey($name, $documented, "docs/CONFIGURATION.md lists $name");
        }
        $read = [];
        foreach (array_merge(glob(BASE_PATH . '/src/*.php') ?: [], glob(BASE_PATH . '/bin/*.php') ?: [], glob(BASE_PATH . '/public/*.php') ?: []) as $path) {
            preg_match_all("/\b(?:getenv|env|env_bool)\('([A-Z][A-Z0-9_]*)'/", (string) file_get_contents($path), $names);
            $read = array_merge($read, $names[1]);
        }
        $undocumented = array_diff(array_unique($read), array_keys($documented));
        self::assertSame([], array_values($undocumented), 'Every setting the code reads is in docs/CONFIGURATION.md');
    }

    public function testEnvExampleHoldsTheApplicationsSettings(): void
    {
        $example = self::read('.env.example');
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $example, $names);
        $settings = array_keys(self::settingsReadByTheApplication());
        self::assertSame([], array_values(array_diff($names[1], $settings)), '.env.example has no setting the application does not read');
        // STRIPE_API_BASE only points the tests at their local Stripe mock.
        self::assertSame(['STRIPE_API_BASE'], array_values(array_diff($settings, $names[1])));
        self::assertStringContainsString('| `STRIPE_API_BASE` | — | **Tests only:**', self::read('docs/CONFIGURATION.md'));
    }
}
