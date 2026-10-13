<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Runs `php -l` on every PHP file in the project (excluding vendor/).
 * Usage: php bin/lint.php   (or: composer lint)
 */

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $file): bool => !in_array($file->getFilename(), ['vendor', '.git', 'node_modules', 'storage'], true)
    )
);

$failures = 0;
$checked = 0;
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $checked++;
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        $failures++;
        echo implode(PHP_EOL, $output), PHP_EOL;
    }
}

echo sprintf('%d PHP files checked, %d with syntax errors.%s', $checked, $failures, PHP_EOL);
exit($failures === 0 ? 0 : 1);
