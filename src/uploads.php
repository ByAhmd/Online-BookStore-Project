<?php

declare(strict_types=1);

const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
const AVATAR_DIR = 'uploads/avatars';
const DEFAULT_AVATAR = 'imges/profileImage2.png';

final class UploadException extends RuntimeException
{
}

/**
 * Validates an uploaded profile picture and stores it under
 * public/uploads/avatars with a random name. The file type is detected from
 * the file contents (never the client-supplied name or MIME type), and the
 * image is re-encoded with GD when available to strip metadata and any
 * embedded payload.
 *
 * @param array<string, mixed> $file An entry from $_FILES
 * @return string Path relative to public/, e.g. "uploads/avatars/ab12....png"
 */
function store_avatar_upload(array $file): string
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new UploadException('Profile pictures must be 2 MB or smaller.');
    }
    if ($error !== UPLOAD_ERR_OK || !isset($file['tmp_name']) || !is_string($file['tmp_name'])) {
        throw new UploadException('The picture could not be uploaded. Please try again.');
    }
    $tmp = $file['tmp_name'];
    if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') {
        throw new UploadException('The picture could not be uploaded. Please try again.');
    }
    $size = @filesize($tmp);
    if ($size === false || $size === 0) {
        throw new UploadException('The selected file is empty. Please choose an image.');
    }
    if ($size > AVATAR_MAX_BYTES) {
        throw new UploadException('Profile pictures must be 2 MB or smaller.');
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
        throw new UploadException('Please upload a JPEG, PNG, GIF or WebP image.');
    }
    if (!is_string($mime) || !isset($allowed[$mime]) || $info === false || $info['mime'] !== $mime) {
        throw new UploadException('Please upload a JPEG, PNG, GIF or WebP image.');
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] > 4000 || $info[1] > 4000) {
        throw new UploadException('Images must be at most 4000×4000 pixels.');
    }

    $dir = base_path('public/' . AVATAR_DIR);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Avatar directory is not writable');
    }

    $extension = $allowed[$mime];
    $name = bin2hex(random_bytes(16)) . '.' . $extension;
    $target = $dir . '/' . $name;

    if (extension_loaded('gd')) {
        // Only re-encoded pixels are stored; anything GD cannot decode is not a usable image.
        if (!reencode_image($tmp, $target, $mime)) {
            @unlink($target);
            throw new UploadException('Please upload a valid JPEG, PNG, GIF or WebP image.');
        }
    } else {
        $moved = PHP_SAPI === 'cli' ? copy($tmp, $target) : move_uploaded_file($tmp, $target);
        if (!$moved) {
            throw new RuntimeException('Could not store uploaded avatar');
        }
    }
    @chmod($target, 0644);

    return AVATAR_DIR . '/' . $name;
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
 * Deletes a previously uploaded avatar. Only files inside uploads/avatars with
 * generated names are ever removed (never catalog images or the default).
 */
function delete_uploaded_avatar(?string $path): void
{
    if (!is_string($path) || !preg_match('#^uploads/avatars/[a-f0-9]{32}\.(jpg|png|gif|webp)$#D', $path)) {
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
