<?php

declare(strict_types=1);

/**
 * Creates an administrator account, or resets an existing admin's password.
 *
 * Usage:
 *   php bin/create-admin.php admin@example.com "Store Admin"
 *   php bin/create-admin.php reviewer@example.com "Reviewer" --viewer
 *
 * Administrators are owners, who may change everything. With --viewer the
 * account is read-only instead: it can open every admin page but change
 * nothing (customers' names and email addresses are shown shortened).
 * Running the command again for the same email sets the role given (owner
 * unless --viewer) together with the new password.
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

$usage = "Usage: php bin/create-admin.php <email> [\"Display Name\"] [--viewer]\n";
$options = array_values(array_filter(array_slice($argv, 1), static fn (string $argument): bool => str_starts_with($argument, '--')));
$arguments = array_values(array_filter(array_slice($argv, 1), static fn (string $argument): bool => !str_starts_with($argument, '--')));
$unknown = array_diff($options, ['--viewer']);
if ($unknown !== []) {
    fwrite(STDERR, $usage . 'Unknown option: ' . implode(', ', $unknown) . PHP_EOL);
    exit(1);
}
$role = in_array('--viewer', $options, true) ? ADMIN_ROLE_VIEWER : ADMIN_ROLE_OWNER;

$email = normalize_email((string) ($arguments[0] ?? ''));
$name = trim((string) ($arguments[1] ?? 'Administrator'));

if (($error = validate_email($email)) !== null) {
    fwrite(STDERR, "$usage$error\n");
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

$described = $role === ADMIN_ROLE_VIEWER ? 'read-only administrator' : 'administrator';
$exists = db_value('SELECT 1 FROM admins WHERE Eaddress = ?', [$email]) !== null;
if ($exists) {
    // A new password signs out existing sessions and lifts any sign-in lockout.
    db_run(
        'UPDATE admins SET name = ?, password = ?, role = ?, session_version = session_version + 1 WHERE Eaddress = ?',
        [$name, hash_password($password), $role, $email]
    );
    clear_failed_logins($email);
    echo "Updated $described $email (other sessions signed out, sign-in lockout cleared)." . PHP_EOL;
} else {
    db_run('INSERT INTO admins (Eaddress, name, password, role) VALUES (?, ?, ?, ?)', [$email, $name, hash_password($password), $role]);
    echo "Created $described $email." . PHP_EOL;
}
