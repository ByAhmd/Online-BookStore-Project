<?php

declare(strict_types=1);

/**
 * Server-side validation rules shared by all forms. Each validator returns an
 * error message, or null when the value is acceptable.
 */

const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_BYTES = 72; // bcrypt ignores bytes beyond 72
const NAME_MAX_LENGTH = 100;
const COMMENT_MAX_LENGTH = 2000;
const BOOK_PRICE_MIN = 1.00;
const BOOK_PRICE_MAX = 10000.00;

/** Payment preferences a customer may pick. Card details are entered on Stripe only. */
const PAYMENT_PREFERENCES = ['Credit / Debit Card', 'Apple Pay', 'Google Pay', 'Mada'];

/** Genre names reserved for Explore page tabs. */
const RESERVED_GENRE_NAMES = ['all', 'favorites'];

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function validate_email(string $email): ?string
{
    if ($email === '') {
        return 'Please enter your email address.';
    }
    if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return 'Please enter a valid email address.';
    }
    return null;
}

function validate_person_name(string $name): ?string
{
    if ($name === '') {
        return 'Please enter your name.';
    }
    if (mb_strlen($name) > NAME_MAX_LENGTH) {
        return 'Name must be ' . NAME_MAX_LENGTH . ' characters or fewer.';
    }
    if (preg_match('/[\x00-\x1F\x7F<>]/u', $name)) {
        return 'Name contains characters that are not allowed.';
    }
    return null;
}

function validate_new_password(string $password, string $confirmation): ?string
{
    if (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
        return 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters long.';
    }
    if (strlen($password) > PASSWORD_MAX_BYTES) {
        return 'Password must be at most ' . PASSWORD_MAX_BYTES . ' bytes long.';
    }
    if (!hash_equals($password, $confirmation)) {
        return 'Passwords do not match.';
    }
    return null;
}

/**
 * Accepts an empty value (field is optional) or a real past date in Y-m-d.
 */
function validate_date_of_birth(string $date): ?string
{
    if ($date === '') {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
        return 'Please enter a valid date of birth.';
    }
    if ($parsed > new DateTimeImmutable('today') || (int) $parsed->format('Y') < 1900) {
        return 'Date of birth must be a past date after 1900.';
    }
    return null;
}

function validate_genre_name(string $genre, string $label = 'Genre'): ?string
{
    if ($genre === '') {
        return "$label is required.";
    }
    if (mb_strlen($genre) > 100 || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} &\'\-]*$/uD', $genre)) {
        return "$label may only contain letters, numbers, spaces, '&', apostrophes and hyphens (max 100 characters).";
    }
    if (in_array(mb_strtolower($genre), RESERVED_GENRE_NAMES, true)) {
        return "\"$genre\" is reserved. Please choose a different $label name.";
    }
    return null;
}

/**
 * A customer's free-text favorite genre (profile): more permissive than catalog
 * genres so legacy values like "Sci-Fi/Fantasy" or "Children’s" stay valid.
 */
function validate_favorite_genre(string $genre): ?string
{
    if ($genre === '') {
        return 'Favorite genre is required.';
    }
    if (mb_strlen($genre) > 100) {
        return 'Favorite genre must be 100 characters or fewer.';
    }
    if (preg_match('/[\x00-\x1F\x7F<>]/u', $genre)) {
        return 'Favorite genre contains characters that are not allowed.';
    }
    return null;
}

function validate_rating(mixed $rating): ?int
{
    return parse_int($rating, 1, 5);
}

/**
 * Validates a cover image reference: an existing image under public/imges or
 * public/uploads, or an absolute https URL (the Content-Security-Policy
 * blocks plain-http images).
 */
function validate_cover_image(string $value): ?string
{
    if ($value === '') {
        return 'Cover image is required.';
    }
    if (strlen($value) > 255) {
        return 'Cover image path must be 255 characters or fewer.';
    }
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $value)) {
        $parts = parse_url($value);
        if (
            !preg_match('#^https://#i', $value)
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || empty($parts['host'])
        ) {
            return 'Cover image URLs must start with https:// and be valid.';
        }
        return null;
    }
    if (
        str_contains($value, '..')
        || !preg_match('#^(imges|uploads)/[A-Za-z0-9 _\-./]+\.(png|jpe?g|gif|webp)$#iD', $value)
    ) {
        return 'Cover image must be a path like "imges/All_section/book.png" or an https:// URL.';
    }
    if (!is_file(base_path('public/' . $value))) {
        return 'No image exists at "' . $value . '" (paths are case-sensitive).';
    }
    return null;
}

/**
 * Validates and normalizes the admin book form.
 *
 * @param array<string, mixed> $input
 * @return array{0: array<string, string>, 1: array<string, string>} [clean values, errors by field]
 */
function validate_book_input(array $input): array
{
    $book = [
        'title' => input_string($input, 'title', 300),
        'author' => input_string($input, 'author', 300),
        'genre' => input_string($input, 'genre', 150),
        'description' => input_string($input, 'description', 6000),
        'price' => input_string($input, 'price', 20),
        'cover_image' => input_string($input, 'cover_image', 300),
    ];
    $errors = [];

    foreach (['title' => 'Title', 'author' => 'Author'] as $field => $label) {
        if ($book[$field] === '') {
            $errors[$field] = "$label is required.";
        } elseif (mb_strlen($book[$field]) > 255) {
            $errors[$field] = "$label must be 255 characters or fewer.";
        }
    }

    if (($error = validate_genre_name($book['genre'])) !== null) {
        $errors['genre'] = $error;
    }

    if ($book['description'] === '') {
        $errors['description'] = 'Description is required.';
    } elseif (mb_strlen($book['description']) > 5000) {
        $errors['description'] = 'Description must be 5000 characters or fewer.';
    }

    if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/D', $book['price'])) {
        $errors['price'] = 'Price must be a number with at most two decimals.';
    } elseif ((float) $book['price'] < BOOK_PRICE_MIN || (float) $book['price'] > BOOK_PRICE_MAX) {
        $errors['price'] = sprintf('Price must be between %.2f and %.2f.', BOOK_PRICE_MIN, BOOK_PRICE_MAX);
    } else {
        $book['price'] = number_format((float) $book['price'], 2, '.', '');
    }

    if (($error = validate_cover_image($book['cover_image'])) !== null) {
        $errors['cover_image'] = $error;
    }

    return [$book, $errors];
}
