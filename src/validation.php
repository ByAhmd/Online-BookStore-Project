<?php

declare(strict_types=1);

/**
 * Server-side validation rules shared by all forms. Each validator returns an
 * error message in the visitor's language (shop.validation.* and
 * admin.validation.*), or null when the value is acceptable.
 */

const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_BYTES = 72; // bcrypt ignores bytes beyond 72
const NAME_MAX_LENGTH = 100;
const COMMENT_MAX_LENGTH = 2000;
const BOOK_PRICE_MIN = 1.00;
const BOOK_PRICE_MAX = 10000.00;
const BOOK_STOCK_MAX = 100000;

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
        return t('shop.validation.email_required');
    }
    if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return t('shop.validation.email_invalid');
    }
    return null;
}

function validate_person_name(string $name): ?string
{
    if ($name === '') {
        return t('shop.validation.name_required');
    }
    if (mb_strlen($name) > NAME_MAX_LENGTH) {
        return t('shop.validation.name_too_long', ['max' => NAME_MAX_LENGTH]);
    }
    if (preg_match('/[\x00-\x1F\x7F<>]/u', $name)) {
        return t('shop.validation.name_invalid');
    }
    return null;
}

function validate_new_password(string $password, string $confirmation): ?string
{
    if (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
        return t('shop.validation.password_too_short', ['min' => PASSWORD_MIN_LENGTH]);
    }
    if (strlen($password) > PASSWORD_MAX_BYTES) {
        return t('shop.validation.password_too_long', ['max' => PASSWORD_MAX_BYTES]);
    }
    if (!hash_equals($password, $confirmation)) {
        return t('shop.validation.password_mismatch');
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
        return t('shop.validation.dob_invalid');
    }
    if ($parsed > new DateTimeImmutable('today') || (int) $parsed->format('Y') < 1900) {
        return t('shop.validation.dob_range');
    }
    return null;
}

/**
 * A catalog genre name. $label names the field in messages (default: "Genre").
 */
function validate_genre_name(string $genre, ?string $label = null): ?string
{
    $label ??= t('admin.validation.labels.genre');
    if ($genre === '') {
        return t('admin.validation.required', ['label' => $label]);
    }
    if (mb_strlen($genre) > 100 || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} &\'\-]*$/uD', $genre)) {
        return t('admin.validation.genre_invalid', ['label' => $label]);
    }
    if (in_array(mb_strtolower($genre), RESERVED_GENRE_NAMES, true)) {
        return t('admin.validation.genre_reserved', ['genre' => $genre, 'label' => $label]);
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
        return t('shop.validation.favorite_genre_required');
    }
    if (mb_strlen($genre) > 100) {
        return t('shop.validation.favorite_genre_too_long');
    }
    if (preg_match('/[\x00-\x1F\x7F<>]/u', $genre)) {
        return t('shop.validation.favorite_genre_invalid');
    }
    return null;
}

function validate_rating(mixed $rating): ?int
{
    return parse_int($rating, 1, 5);
}

/**
 * Validates a cover image reference: an existing image under public/imges or
 * public/uploads/covers (uploaded covers), or an absolute https URL (the
 * Content-Security-Policy blocks plain-http images).
 */
function validate_cover_image(string $value): ?string
{
    if ($value === '') {
        return t('admin.validation.cover_required');
    }
    if (strlen($value) > 255) {
        return t('admin.validation.cover_too_long');
    }
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $value)) {
        $parts = parse_url($value);
        if (
            !preg_match('#^https://#i', $value)
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || empty($parts['host'])
        ) {
            return t('admin.validation.cover_url');
        }
        return null;
    }
    if (
        str_contains($value, '..')
        || !preg_match('#^(imges/|uploads/covers/)[A-Za-z0-9 _\-./]+\.(png|jpe?g|gif|webp)$#iD', $value)
    ) {
        return t('admin.validation.cover_path');
    }
    if (!is_file(base_path('public/' . $value))) {
        return t('admin.validation.cover_missing', ['path' => $value]);
    }
    return null;
}

/**
 * Validates and normalizes the admin book form. When $coverUploaded is true a
 * cover file accompanies the form and replaces the cover_image field, which
 * is then not validated (the caller stores the upload and fills in its path).
 *
 * @param array<string, mixed> $input
 * @return array{0: array<string, string>, 1: array<string, string>} [clean values, errors by field]
 */
function validate_book_input(array $input, bool $coverUploaded = false): array
{
    $book = [
        'title' => input_string($input, 'title', 300),
        'author' => input_string($input, 'author', 300),
        'genre' => input_string($input, 'genre', 150),
        'description' => input_string($input, 'description', 6000),
        'price' => input_string($input, 'price', 20),
        'stock' => input_string($input, 'stock', 20),
        'cover_image' => input_string($input, 'cover_image', 300),
    ];
    $errors = [];

    foreach (['title', 'author'] as $field) {
        $label = t('admin.validation.labels.' . $field);
        if ($book[$field] === '') {
            $errors[$field] = t('admin.validation.required', ['label' => $label]);
        } elseif (mb_strlen($book[$field]) > 255) {
            $errors[$field] = t('admin.validation.too_long', ['label' => $label, 'max' => 255]);
        }
    }

    if (($error = validate_genre_name($book['genre'])) !== null) {
        $errors['genre'] = $error;
    }

    if ($book['description'] === '') {
        $errors['description'] = t('admin.validation.required', ['label' => t('admin.validation.labels.description')]);
    } elseif (mb_strlen($book['description']) > 5000) {
        $errors['description'] = t('admin.validation.too_long', ['label' => t('admin.validation.labels.description'), 'max' => 5000]);
    }

    if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/D', $book['price'])) {
        $errors['price'] = t('admin.validation.price_format');
    } elseif ((float) $book['price'] < BOOK_PRICE_MIN || (float) $book['price'] > BOOK_PRICE_MAX) {
        $errors['price'] = t('admin.validation.price_range', [
            'min' => number_format(BOOK_PRICE_MIN, 2, '.', ''),
            'max' => number_format(BOOK_PRICE_MAX, 2, '.', ''),
        ]);
    } else {
        $book['price'] = number_format((float) $book['price'], 2, '.', '');
    }

    if (!preg_match('/^\d{1,6}$/D', $book['stock']) || (int) $book['stock'] > BOOK_STOCK_MAX) {
        $errors['stock'] = t('admin.validation.stock', ['max' => BOOK_STOCK_MAX]);
    } else {
        $book['stock'] = (string) (int) $book['stock'];
    }

    // An uploaded file replaces whatever the path field says.
    if (!$coverUploaded && ($error = validate_cover_image($book['cover_image'])) !== null) {
        $errors['cover_image'] = $error;
    }

    return [$book, $errors];
}
