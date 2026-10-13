<?php

declare(strict_types=1);

/**
 * Customer account persistence.
 */

function user_email_exists(string $email): bool
{
    return db_value('SELECT 1 FROM users WHERE Eaddress = ?', [$email]) !== null
        || db_value('SELECT 1 FROM admins WHERE Eaddress = ?', [$email]) !== null;
}

/**
 * Creates a customer account and returns its ID, or null if the email is
 * already registered (also enforced by the unique key under concurrency).
 */
function register_user(string $email, string $name, string $password): ?int
{
    if (user_email_exists($email)) {
        return null;
    }
    try {
        db_run('INSERT INTO users (Eaddress, name, pass) VALUES (?, ?, ?)', [$email, $name, hash_password($password)]);
    } catch (PDOException $e) {
        if (is_duplicate_key_error($e)) {
            return null;
        }
        throw $e;
    }
    return (int) db()->lastInsertId();
}

/**
 * @return array<string, mixed>|null
 */
function find_user(int $userId): ?array
{
    return db_one(
        'SELECT id, Eaddress, name, favorite_genre, date_of_birth, payment_method, profile_image FROM users WHERE id = ?',
        [$userId]
    );
}

/**
 * Updates profile fields. Pass $profileImage = null to keep the current picture.
 */
function update_user_profile(int $userId, string $name, string $favoriteGenre, ?string $dateOfBirth, ?string $profileImage = null): void
{
    if ($profileImage === null) {
        db_run(
            'UPDATE users SET name = ?, favorite_genre = ?, date_of_birth = ? WHERE id = ?',
            [$name, $favoriteGenre, $dateOfBirth, $userId]
        );
        return;
    }
    db_run(
        'UPDATE users SET name = ?, favorite_genre = ?, date_of_birth = ?, profile_image = ? WHERE id = ?',
        [$name, $favoriteGenre, $dateOfBirth, $profileImage, $userId]
    );
}

/**
 * Stores the customer's preferred payment method label (never card data).
 */
function update_payment_preference(int $userId, ?string $preference): bool
{
    if ($preference !== null && !in_array($preference, PAYMENT_PREFERENCES, true)) {
        return false;
    }
    db_run('UPDATE users SET payment_method = ? WHERE id = ?', [$preference, $userId]);
    return true;
}
