<?php

declare(strict_types=1);

/**
 * Creates an administrator account, or resets an existing admin's password.
 *
 * Usage:
 *   php bin/create-admin.php admin@example.com "Store Admin"
 *
 * The password is read from the BOOKWISE_ADMIN_PASSWORD environment variable
 * if set, otherwise prompted for on standard input. It is stored with
 * password_hash() and never printed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

$email = normalize_email((string) ($argv[1] ?? ''));
$name = trim((string) ($argv[2] ?? 'Administrator'));

if (($error = validate_email($email)) !== null) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <email> [\"Display Name\"]\n$error\n");
    exit(1);
}
if (($error = validate_person_name($name)) !== null) {
    fwrite(STDERR, $error . PHP_EOL);
    exit(1);
}

$password = getenv('BOOKWISE_ADMIN_PASSWORD');
if ($password === false || $password === '') {
    fwrite(STDOUT, 'Password (min ' . PASSWORD_MIN_LENGTH . ' characters; input is visible): ');
    $password = rtrim((string) fgets(STDIN), "\r\n");
    fwrite(STDOUT, 'Confirm password: ');
    $confirm = rtrim((string) fgets(STDIN), "\r\n");
} else {
    $confirm = $password;
}

if (($error = validate_new_password($password, $confirm)) !== null) {
    fwrite(STDERR, $error . PHP_EOL);
    exit(1);
}

$exists = db_value('SELECT 1 FROM admins WHERE Eaddress = ?', [$email]) !== null;
if ($exists) {
    // A new password signs out existing sessions and lifts any sign-in lockout.
    db_run(
        'UPDATE admins SET name = ?, password = ?, session_version = session_version + 1 WHERE Eaddress = ?',
        [$name, hash_password($password), $email]
    );
    clear_failed_logins($email);
    echo "Updated administrator $email (other sessions signed out, sign-in lockout cleared)." . PHP_EOL;
} else {
    db_run('INSERT INTO admins (Eaddress, name, password) VALUES (?, ?, ?)', [$email, $name, hash_password($password)]);
    echo "Created administrator $email." . PHP_EOL;
}
