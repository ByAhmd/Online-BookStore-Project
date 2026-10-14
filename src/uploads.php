<?php

declare(strict_types=1);

const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
const AVATAR_DIR = 'uploads/avatars';
const DEFAULT_AVATAR = 'imges/profileImage2.png';
const COVER_MAX_BYTES = 5 * 1024 * 1024;
const COVER_DIR = 'uploads/covers';
/** Largest accepted width and height of an uploaded image, in pixels. */
const UPLOAD_MAX_DIMENSION = 4000;
/** Width in pixels of the small WebP copy of a cover (book cards, the cart, admin tables). */
const COVER_THUMB_WIDTH = 190;
/**
 * Widest WebP copy of an uploaded cover. Covers are displayed at most 260
 * pixels wide, so this is sharp on 3x screens, and it bounds the memory GD
 * needs to encode the copy of a large upload.
 */
const COVER_COPY_MAX_WIDTH = 780;
/** Quality (0-100) of the WebP copies; 80 keeps covers sharp at a fraction of the PNG size. */
const WEBP_QUALITY = 80;

final class UploadException extends RuntimeException
{
}

/**
 * Validates an uploaded profile picture and stores it under
 * public/uploads/avatars with a random name (see store_image_upload()).
 *
 * @param array<string, mixed> $file An entry from $_FILES
 * @return string Path relative to public/, e.g. "uploads/avatars/ab12....png"
 */
function store_avatar_upload(array $file): string
{
    return store_image_upload($file, AVATAR_DIR, AVATAR_MAX_BYTES, 'shop.upload');
}

/**
 * Validates an uploaded book cover and stores it under public/uploads/covers
 * with a random name (see store_image_upload()), next to its WebP copies
 * (see create_webp_variants()).
 *
 * @param array<string, mixed> $file An entry from $_FILES
 * @return string Path relative to public/, e.g. "uploads/covers/ab12....jpg"
 */
function store_cover_upload(array $file): string
{
    $path = store_image_upload($file, COVER_DIR, COVER_MAX_BYTES, 'admin.upload');
    try {
        create_webp_variants(base_path('public/' . $path), false, COVER_COPY_MAX_WIDTH);
    } catch (Throwable $e) {
        // The copies only make pages lighter: pages show the stored cover
        // itself when a copy is missing, so the upload still succeeds.
        delete_webp_variants(base_path('public/' . $path));
        app_log('warning', 'Could not create the WebP copies of an uploaded cover: ' . $e->getMessage(), ['cover' => $path]);
    }
    return $path;
}

/**
 * True when the form sent a file for $field (an empty file input sends
 * UPLOAD_ERR_NO_FILE, which means "no upload", not an error).
 *
 * @param array<string, mixed> $files Usually $_FILES
 */
function has_uploaded_file(array $files, string $field): bool
{
    $file = $files[$field] ?? null;
    return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

/**
 * Validates an uploaded image and stores it in public/$dir with a random name.
 * The file type is detected from the file contents (never the client-supplied
 * name or MIME type), and the image is re-encoded with GD when available to
 * strip metadata and any embedded payload.
 *
 * $messages is a translation prefix with the keys too_large, failed, empty,
 * type, dimensions and invalid (e.g. "shop.upload" for profile pictures).
 *
 * @param array<string, mixed> $file An entry from $_FILES
 * @return string Path relative to public/
 */
function store_image_upload(array $file, string $dir, int $maxBytes, string $messages): string
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw upload_failure($messages, 'too_large');
    }
    if ($error !== UPLOAD_ERR_OK || !isset($file['tmp_name']) || !is_string($file['tmp_name'])) {
        throw upload_failure($messages, 'failed');
    }
    $tmp = $file['tmp_name'];
    if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') {
        throw upload_failure($messages, 'failed');
    }
    $size = @filesize($tmp);
    if ($size === false || $size === 0) {
        throw upload_failure($messages, 'empty');
    }
    if ($size > $maxBytes) {
        throw upload_failure($messages, 'too_large');
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (extension_loaded('gd') && !function_exists('imagecreatefromwebp')) {
        unset($allowed['image/webp']); // cannot be re-encoded on this server
    }
    try {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $info = getimagesize($tmp);
    } catch (ErrorException) {
        // Unreadable temp file (e.g. quarantined by antivirus) or not an image at all.
        throw upload_failure($messages, 'type');
    }
    if (!is_string($mime) || !isset($allowed[$mime]) || $info === false || $info['mime'] !== $mime) {
        throw upload_failure($messages, 'type');
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] > UPLOAD_MAX_DIMENSION || $info[1] > UPLOAD_MAX_DIMENSION) {
        throw upload_failure($messages, 'dimensions', ['max' => UPLOAD_MAX_DIMENSION . '×' . UPLOAD_MAX_DIMENSION]);
    }

    $targetDir = base_path('public/' . $dir);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Upload directory is not writable: ' . $dir);
    }

    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $target = $targetDir . '/' . $name;

    if (extension_loaded('gd')) {
        // Only re-encoded pixels are stored; anything GD cannot decode is not a usable image.
        if (!reencode_image($tmp, $target, $mime)) {
            @unlink($target);
            throw upload_failure($messages, 'invalid');
        }
    } else {
        $moved = PHP_SAPI === 'cli' ? copy($tmp, $target) : move_uploaded_file($tmp, $target);
        if (!$moved) {
            throw new RuntimeException('Could not store uploaded image in ' . $dir);
        }
    }
    @chmod($target, 0644);

    return $dir . '/' . $name;
}

/**
 * An upload error with a message from the given translation prefix.
 *
 * @param array<string, string|int|float> $replace
 */
function upload_failure(string $messages, string $key, array $replace = []): UploadException
{
    return new UploadException(t($messages . '.' . $key, $replace));
}

/**
 * Re-encodes an image with GD. Returns false when GD cannot handle the type.
 */
function reencode_image(string $source, string $target, string $mime): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }
    $image = decode_image($source, $mime);
    if ($image === false) {
        return false;
    }
    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }
    return match ($mime) {
        'image/jpeg' => imagejpeg($image, $target, 88),
        'image/png' => imagepng($image, $target, 6),
        'image/gif' => imagegif($image, $target),
        'image/webp' => imagewebp($image, $target, 88),
        default => false,
    };
}

/**
 * Decodes a JPEG, PNG, GIF or WebP file with GD; false when GD cannot.
 */
function decode_image(string $file, string $mime): GdImage|false
{
    return match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file),
        'image/png' => @imagecreatefrompng($file),
        'image/gif' => @imagecreatefromgif($file),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
        default => false,
    };
}

/* ---------------------------------------------------------------------
 * WebP copies of covers
 * ------------------------------------------------------------------- */

/**
 * Where the WebP copies of an image go, next to it: "imges/a/b.png" has the
 * full-size copy "imges/a/b.webp" and the thumbnail "imges/a/b-w190.webp"
 * (COVER_THUMB_WIDTH pixels wide). Works on absolute paths and on paths
 * relative to public/. A WebP image is its own full-size copy; an uploaded
 * one wider than COVER_COPY_MAX_WIDTH also gets the capped copy "b-w780.webp"
 * (that wide), which pages offer in its place. Other images never get a
 * capped copy: their full-size copy is scaled down instead.
 *
 * @return array{full: string, thumb: string, capped: string}
 */
function webp_variant_paths(string $image): array
{
    $stem = (string) preg_replace('/\.[A-Za-z0-9]+$/D', '', $image);
    return [
        'full' => preg_match('/\.webp$/iD', $image) === 1 ? $image : $stem . '.webp',
        'thumb' => $stem . '-w' . COVER_THUMB_WIDTH . '.webp',
        'capped' => $stem . '-w' . COVER_COPY_MAX_WIDTH . '.webp',
    ];
}

/**
 * The image that $file (an absolute path) is a WebP copy of, see
 * webp_variant_paths(); null when it is not a copy. "b.webp" and
 * "b-w190.webp" are copies of a "b.png", "b.jpg", "b.jpeg" or "b.gif" next to
 * them (with a lower- or upper-case extension), and "b-w190.webp" and
 * "b-w780.webp" of a WebP "b.webp". An uploaded WebP cover has no such image
 * next to it: it is an image of its own.
 */
function webp_copy_source(string $file): ?string
{
    if (preg_match('/\.webp$/iD', $file) !== 1) {
        return null;
    }
    $stems = [substr($file, 0, -strlen('.webp'))];
    // "b-w190.webp" is the full-size copy of a "b-w190.png", or the thumbnail of a "b.png".
    if (preg_match('/^(.+)-w(?:' . COVER_THUMB_WIDTH . '|' . COVER_COPY_MAX_WIDTH . ')$/sD', $stems[0], $match) === 1) {
        $stems[] = $match[1];
    }
    foreach ($stems as $stem) {
        foreach (['png', 'jpg', 'jpeg', 'gif', 'webp', 'PNG', 'JPG', 'JPEG', 'GIF', 'WEBP'] as $extension) {
            $image = $stem . '.' . $extension;
            if ($image !== $file && in_array($file, webp_variant_paths($image), true) && is_file($image)) {
                return $image;
            }
        }
    }
    return null;
}

/**
 * Writes the WebP copies of a JPEG, PNG, GIF or WebP file (an absolute path,
 * see webp_variant_paths()): the full-size copy, scaled down to $maxWidth
 * when given (a WebP image is its own full-size copy, so one wider than
 * $maxWidth gets the capped copy instead), and, for images wider than
 * COVER_THUMB_WIDTH, the thumbnail. A copy at least as new as the image is
 * kept unless $force is set. Returns the files written; none when this PHP's
 * GD cannot write WebP.
 *
 * @return list<string>
 */
function create_webp_variants(string $image, bool $force = false, ?int $maxWidth = null): array
{
    if (!function_exists('imagewebp')) {
        return [];
    }
    $info = @getimagesize($image);
    if ($info === false) {
        throw new RuntimeException('Not a readable image: ' . $image);
    }
    [$width, $height] = $info;
    $paths = webp_variant_paths($image);

    // Target file => width.
    $targets = [];
    if ($paths['full'] !== $image) {
        $targets[$paths['full']] = max(1, min($width, $maxWidth ?? $width));
    } elseif ($maxWidth !== null && $width > $maxWidth) {
        $targets[$paths['capped']] = max(1, $maxWidth);
    }
    if ($width > COVER_THUMB_WIDTH) {
        $targets[$paths['thumb']] = COVER_THUMB_WIDTH;
    }
    if (!$force) {
        clearstatcache(true, $image);
        $modified = filemtime($image);
        $targets = array_filter($targets, static function (string $target) use ($modified): bool {
            clearstatcache(true, $target);
            return !is_file($target) || filemtime($target) < $modified;
        }, ARRAY_FILTER_USE_KEY);
    }
    if ($targets === []) {
        return [];
    }

    $source = decode_image($image, $info['mime']);
    if ($source === false) {
        throw new RuntimeException('GD cannot decode ' . $image);
    }
    // WebP needs true colour; transparency (PNG, GIF, WebP) is kept.
    imagepalettetotruecolor($source);
    imagealphablending($source, false);
    imagesavealpha($source, true);

    $written = [];
    foreach ($targets as $target => $targetWidth) {
        $copy = $targetWidth === $width
            ? $source
            : resize_image($source, $targetWidth, max(1, (int) round($height * $targetWidth / $width)));
        write_webp($copy, $target);
        $written[] = $target;
    }
    return $written;
}

/**
 * A resampled copy of a true-colour image, with its transparency.
 */
function resize_image(GdImage $image, int $width, int $height): GdImage
{
    if ($width < 1 || $height < 1) {
        throw new InvalidArgumentException("Invalid image size {$width}×{$height}");
    }
    $copy = imagecreatetruecolor($width, $height);
    $transparent = $copy !== false ? imagecolorallocatealpha($copy, 0, 0, 0, 127) : false;
    if ($copy === false || $transparent === false) {
        throw new RuntimeException("Could not create a {$width}×{$height} image");
    }
    imagealphablending($copy, false);
    imagesavealpha($copy, true);
    imagefill($copy, 0, 0, $transparent);
    imagecopyresampled($copy, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
    return $copy;
}

/**
 * Encodes an image as WebP at $target. It is written to a temporary file in
 * the same directory first, so a half-written copy is never served.
 */
function write_webp(GdImage $image, string $target): void
{
    $temporary = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
    try {
        if (!imagewebp($image, $temporary, WEBP_QUALITY) || !rename($temporary, $target)) {
            throw new RuntimeException('Could not write ' . $target);
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
    @chmod($target, 0644);
}

/**
 * Deletes the WebP copies of an image (never the image itself).
 */
function delete_webp_variants(string $image): void
{
    foreach (webp_variant_paths($image) as $variant) {
        if ($variant !== $image && is_file($variant)) {
            @unlink($variant);
        }
    }
}

/**
 * srcset of the WebP copies of a local image (a path relative to public/, as
 * image_url() returns it), smallest first, e.g.
 * "imges/a/b-w190.webp?v=… 190w, imges/a/b.webp?v=… 380w". A WebP image
 * with a capped copy is offered as that copy instead of itself. Empty for
 * external URLs and for images without a full-size copy.
 */
function webp_srcset(string $src): string
{
    if ($src === '' || str_contains($src, '..') || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $src) === 1) {
        return '';
    }
    $variants = webp_variant_paths($src);
    $largest = $variants['full'] === $src && is_file(base_path('public/' . $variants['capped'])) ? $variants['capped'] : $variants['full'];
    $file = base_path('public/' . $largest);
    $size = is_file($file) ? @getimagesize($file) : false;
    if ($size === false) {
        return '';
    }
    $candidates = [];
    if ($size[0] > COVER_THUMB_WIDTH && is_file(base_path('public/' . $variants['thumb']))) {
        $candidates[] = asset($variants['thumb']) . ' ' . COVER_THUMB_WIDTH . 'w';
    }
    $candidates[] = asset($largest) . ' ' . $size[0] . 'w';
    return implode(', ', $candidates);
}

/**
 * A cover as <picture>: its WebP copies for browsers that support WebP, and
 * $src itself as the <img> for the others. $src comes from image_url(): a
 * path in public/, or an https URL (shown as it is). $sizes is the width the
 * cover is displayed at, from which the browser picks a copy, e.g.
 * "(max-width: 720px) 220px, 260px". $attributes are the other attributes of
 * the <img> (alt defaults to ""), e.g. ['width' => 260, 'loading' => 'lazy'].
 *
 * @param array<string, string|int> $attributes
 */
function cover_picture(string $src, string $sizes, array $attributes = []): string
{
    $html = '<picture>';
    $srcset = webp_srcset($src);
    if ($srcset !== '') {
        $html .= '<source type="image/webp" srcset="' . e($srcset) . '" sizes="' . e($sizes) . '">';
    }
    $html .= '<img src="' . e(asset($src)) . '"';
    foreach ($attributes + ['alt' => ''] as $name => $value) {
        if (preg_match('/^[a-z][a-z-]*$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid attribute name: ' . $name);
        }
        $html .= ' ' . $name . '="' . e($value) . '"';
    }
    return $html . '></picture>';
}

/**
 * True for a path that store_image_upload() generated inside $dir.
 */
function is_generated_upload(?string $path, string $dir): bool
{
    return is_string($path)
        && preg_match('#^' . preg_quote($dir, '#') . '/[a-f0-9]{32}\.(jpg|png|gif|webp)$#D', $path) === 1;
}

/**
 * Deletes a previously uploaded avatar. Only files inside uploads/avatars with
 * generated names are ever removed (never catalog images or the default).
 */
function delete_uploaded_avatar(?string $path): void
{
    delete_generated_upload($path, AVATAR_DIR);
}

/**
 * Deletes a previously uploaded book cover and its WebP copies. Only files
 * inside uploads/covers with generated names are ever removed (never the
 * catalog's imges/ files).
 */
function delete_uploaded_cover(?string $path): void
{
    delete_generated_upload($path, COVER_DIR);
    if ($path !== null && is_generated_upload($path, COVER_DIR)) {
        delete_webp_variants(base_path('public/' . $path));
    }
}

function delete_generated_upload(?string $path, string $dir): void
{
    if (!is_generated_upload($path, $dir)) {
        return;
    }
    $full = base_path('public/' . $path);
    if (is_file($full)) {
        @unlink($full);
    }
}

function avatar_url(?string $path): string
{
    return image_url($path, DEFAULT_AVATAR);
}
