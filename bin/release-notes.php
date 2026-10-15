<?php

declare(strict_types=1);

/**
 * Prints the release notes of one version: the text of its
 * "## [1.2.3] - YYYY-MM-DD" section in CHANGELOG.md, without the heading.
 * The release workflow (.github/workflows/release.yml) runs it for the tag it
 * publishes (v1.2.3) before anything is published, so a tag whose version
 * has no notes stops the release.
 *
 * The exit status is 0 after printing the notes, and 1 with a message when
 * the version is not of the form 1.2.3, the file cannot be read, or the
 * version has no section or an empty one. It needs no database and no
 * Composer dependencies.
 *
 * Usage: php bin/release-notes.php <version> [<changelog file>]
 *        The version may start with "v" (v1.2.3); the file defaults to
 *        CHANGELOG.md in the project root.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$usage = 'Usage: php bin/release-notes.php <version> [<changelog file>]' . PHP_EOL;
$arguments = array_map('strval', array_slice((array) ($GLOBALS['argv'] ?? []), 1));
if ($arguments === [] || count($arguments) > 2) {
    fwrite(STDERR, $usage);
    exit(1);
}

$version = str_starts_with($arguments[0], 'v') ? substr($arguments[0], 1) : $arguments[0];
if (!preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
    fwrite(STDERR, 'Not a version of the form 1.2.3 or v1.2.3: "' . $arguments[0] . '".' . PHP_EOL . $usage);
    exit(1);
}

$file = $arguments[1] ?? dirname(__DIR__) . '/CHANGELOG.md';
$changelog = is_file($file) ? file_get_contents($file) : false;
if ($changelog === false) {
    fwrite(STDERR, "Cannot read $file." . PHP_EOL);
    exit(1);
}

$heading = '/^## \[' . preg_quote($version, '/') . '\] - \d{4}-\d{2}-\d{2}$/D';
$found = false;
$notes = [];
foreach (preg_split('/\R/', $changelog) ?: [] as $line) {
    if (!$found) {
        $found = preg_match($heading, rtrim($line)) === 1;
        continue;
    }
    // The section ends at the next version's heading, or at the link
    // definitions ("[1.2.3]: https://…") at the end of the file.
    if (str_starts_with($line, '## ') || preg_match('/^\[[^\]]+\]:\s/', $line)) {
        break;
    }
    $notes[] = rtrim($line);
}

if (!$found) {
    fwrite(STDERR, "$file has no section \"## [$version] - YYYY-MM-DD\". Add one before releasing v$version." . PHP_EOL);
    exit(1);
}
$text = trim(implode("\n", $notes));
if ($text === '') {
    fwrite(STDERR, "The section of $version in $file is empty." . PHP_EOL);
    exit(1);
}

echo $text, "\n";
