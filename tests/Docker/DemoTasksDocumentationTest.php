<?php

declare(strict_types=1);

namespace BookWise\Tests\Docker;

use PHPUnit\Framework\TestCase;

/**
 * The guides' instructions for the public demo's scheduled tasks
 * (docs/DEPLOYMENT.md, README.md): the commands they give are the ones the
 * scripts expect and bin/setup.php names, and the links between the
 * sections of both guides lead to a heading.
 */
final class DemoTasksDocumentationTest extends TestCase
{
    private static function read(string $file): string
    {
        $path = BASE_PATH . '/' . $file;
        self::assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function testTheScheduledTasksRunTheCommandsTheScriptsName(): void
    {
        $guide = self::read('docs/DEPLOYMENT.md');
        $setup = self::read('bin/setup.php');
        foreach (['php bin/demo-reset.php --yes', 'php bin/demo-cleanup.php'] as $command) {
            self::assertStringContainsString('| `cd $HOME/bookwise && ' . $command . '` |', $guide, "docs/DEPLOYMENT.md schedules: $command");
            self::assertStringContainsString('cd $root && ' . $command . '"', $setup, "bin/setup.php names: $command");
        }
        self::assertStringContainsString('Usage: php bin/demo-reset.php --yes', self::read('bin/demo-reset.php'));
        // 04:00 in Riyadh (UTC+3) on a scheduler that keeps UTC, which alwaysdata does not document:
        // the guide says so and shows how to check it.
        self::assertMatchesRegularExpression('/\| Daily reset \| `[^`]+` \| `0 1 \* \* \*` \| 04:00 \|/', $guide);
        self::assertStringContainsString('| Riyadh time, if the scheduler keeps UTC |', $guide);
        self::assertStringContainsString('`TZ=Asia/Riyadh date`', $guide);
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

    public function testLinksBetweenTheGuidesLeadToAHeading(): void
    {
        $files = ['README.md' => ['README.md' => '', 'docs/DEPLOYMENT.md' => 'docs/DEPLOYMENT.md'], 'docs/DEPLOYMENT.md' => ['docs/DEPLOYMENT.md' => '', 'README.md' => '../README.md']];
        $anchors = [];
        foreach (array_keys($files) as $file) {
            $anchors[$file] = self::anchors(self::read($file));
        }
        foreach ($files as $file => $targets) {
            $text = self::read($file);
            foreach ($targets as $target => $prefix) {
                preg_match_all('/\]\(' . preg_quote($prefix, '/') . '#([^)\s]+)\)/u', $text, $links);
                foreach (array_unique($links[1]) as $anchor) {
                    self::assertArrayHasKey($anchor, $anchors[$target], "$file links to $target#$anchor, which is not a heading");
                }
            }
        }
        self::assertArrayHasKey('schedule-the-daily-reset-and-the-cleanup', $anchors['docs/DEPLOYMENT.md']);
        self::assertArrayHasKey('the-showcase-and-the-daily-reset', $anchors['docs/DEPLOYMENT.md']);
        self::assertArrayHasKey('the-demos-showcase-and-daily-reset', $anchors['README.md']);
    }
}
