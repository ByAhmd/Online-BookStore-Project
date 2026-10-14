<?php

declare(strict_types=1);

/**
 * Writes the WebP copies of the images in public/ (see create_webp_variants()
 * in src/uploads.php): for every PNG, JPEG and GIF file, a full-size .webp
 * next to it and, for images wider than 190 pixels, a -w190.webp thumbnail
 * for book cards. Pages offer the copies to browsers that support WebP and
 * the original to the others.
 *
 * Copies at least as new as their image are skipped, so the script is safe to
 * run repeatedly. Commit what it writes under public/imges. Uploaded covers
 * get their copies when they are uploaded; run it on uploads/covers once for
 * covers uploaded before that. As at upload time, the larger copy of an
 * upload is at most COVER_COPY_MAX_WIDTH pixels wide, and an uploaded WebP
 * cover, its own full-size copy, gets the thumbnail and, when wider than
 * that, the capped -w780.webp copy.
 *
 * Usage: php bin/optimize-images.php [--force] [directory ...]
 *   directory  folders to scan, relative to public/ (default: imges)
 *   --force    rewrite every copy, e.g. after changing WEBP_QUALITY
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

$options = array_values(array_filter(array_slice($argv, 1), static fn (string $arg): bool => str_starts_with($arg, '-')));
$directories = array_values(array_filter(array_slice($argv, 1), static fn (string $arg): bool => !str_starts_with($arg, '-')));
$unknown = array_diff($options, ['--force']);
if ($unknown !== []) {
    fwrite(STDERR, 'Unknown option ' . implode(', ', $unknown) . '. Usage: php bin/optimize-images.php [--force] [directory ...]' . PHP_EOL);
    exit(1);
}
$force = in_array('--force', $options, true);
if (!function_exists('imagewebp')) {
    fwrite(STDERR, 'This PHP cannot write WebP images: the gd extension with WebP support is required.' . PHP_EOL);
    exit(1);
}

/**
 * Images under public/$directory that get WebP copies, as paths relative to
 * public/, sorted: PNG, JPEG and GIF files, and uploaded WebP covers (their
 * own full-size copy; they only need the thumbnail and, when wide, the capped
 * copy). The copies themselves are not images to copy.
 *
 * @return list<string>
 */
$findImages = static function (string $directory): array {
    $root = base_path('public');
    $images = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $extension = strtolower($file->getExtension());
        $uploadedWebp = $extension === 'webp' && str_starts_with($relative, 'uploads/') && webp_copy_source(base_path('public/' . $relative)) === null;
        if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif'], true) || $uploadedWebp) {
            $images[] = $relative;
        }
    }
    sort($images, SORT_STRING);
    return $images;
};

$images = [];
foreach ($directories === [] ? ['imges'] : $directories as $directory) {
    $directory = trim(str_replace('\\', '/', $directory), '/');
    if ($directory === '' || str_contains($directory, '..') || !is_dir(base_path('public/' . $directory))) {
        fwrite(STDERR, "No folder public/$directory to scan." . PHP_EOL);
        exit(1);
    }
    $images = array_merge($images, $findImages($directory));
}

// "cover.png" and "cover.jpg" in one folder would share "cover.webp".
$owners = [];
foreach ($images as $image) {
    $owners[webp_variant_paths($image)['full']][] = $image;
}
$clashes = array_filter($owners, static fn (array $sources): bool => count($sources) > 1);
if ($clashes !== []) {
    foreach ($clashes as $copy => $sources) {
        fwrite(STDERR, implode(' and ', $sources) . " would both be copied to $copy. Rename one of them." . PHP_EOL);
    }
    exit(1);
}

$written = 0;
$upToDate = 0;
$bytesBefore = 0;
$bytesAfter = 0;
foreach ($images as $image) {
    $file = base_path('public/' . $image);
    // Uploads get the same copies as at upload time (store_cover_upload()).
    $copies = create_webp_variants($file, $force, str_starts_with($image, 'uploads/') ? COVER_COPY_MAX_WIDTH : null);
    if ($copies === []) {
        $upToDate++;
        continue;
    }
    foreach ($copies as $copy) {
        $size = (int) filesize($copy);
        echo sprintf('  %s (%s KB)', substr($copy, strlen(base_path('public')) + 1), number_format($size / 1024, 1)), PHP_EOL;
        $written++;
    }
    $full = webp_variant_paths($file)['full'];
    if ($full !== $file && in_array($full, $copies, true)) {
        $bytesBefore += (int) filesize($file);
        $bytesAfter += (int) filesize($full);
    }
}

echo sprintf('%d image(s) checked: %d WebP cop%s written, %d image(s) already up to date.', count($images), $written, $written === 1 ? 'y' : 'ies', $upToDate), PHP_EOL;
if ($bytesBefore > 0) {
    echo sprintf('Full-size copies: %.1f MB as WebP instead of %.1f MB.', $bytesAfter / 1048576, $bytesBefore / 1048576), PHP_EOL;
}
