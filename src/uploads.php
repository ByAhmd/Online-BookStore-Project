<?php

declare(strict_types=1);

const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
const AVATAR_DIR = 'uploads/avatars';
const DEFAULT_AVATAR = 'imges/profileImage2.png';
const COVER_MAX_BYTES = 5 * 1024 * 1024;
const COVER_DIR = 'uploads/covers';
/** Largest accepted width and height of an uploaded image, in pixels. */
const UPLOAD_MAX_DIMENSION = 4000;

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
 * with a random name (see store_image_upload()).
 *
 * @param array<string, mixed> $file An entry from $_FILES
 * @return string Path relative to public/, e.g. "uploads/covers/ab12....jpg"
 */
function store_cover_upload(array $file): string
{
    return store_image_upload($file, COVER_DIR, COVER_MAX_BYTES, 'admin.upload');
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
    $image = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($source),
        'image/png' => @imagecreatefrompng($source),
        'image/gif' => @imagecreatefromgif($source),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
        default => false,
    };
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
    };
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
 * Deletes a previously uploaded book cover. Only files inside uploads/covers
 * with generated names are ever removed (never the catalog's imges/ files).
 */
function delete_uploaded_cover(?string $path): void
{
    delete_generated_upload($path, COVER_DIR);
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
