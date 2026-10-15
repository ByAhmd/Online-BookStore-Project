<?php

declare(strict_types=1);

/**
 * Creates an administrator account, or resets an existing admin's password.
 *
 * Usage:
 *   php bin/create-admin.php admin@example.com "Store Admin"
 *   php bin/create-admin.php reviewer@example.com "Reviewer" --viewer
 *
 * New administrators are owners, who may change everything. With --viewer
 * the account is read-only instead: it can open every admin page but change
 * nothing (customers' names and email addresses, and payment references,
 * are shown shortened). Running the command again for the same email resets
 * the password and keeps the administrator's role, unless --viewer or
 * --owner asks for another one; a changed role is always reported.
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

$usage = "Usage: php bin/create-admin.php <email> [\"Display Name\"] [--viewer | --owner]\n";
$commandLine = array_map('strval', array_slice((array) ($GLOBALS['argv'] ?? []), 1));
$options = array_values(array_filter($commandLine, static fn (string $argument): bool => str_starts_with($argument, '--')));
$arguments = array_values(array_filter($commandLine, static fn (string $argument): bool => !str_starts_with($argument, '--')));
$unknown = array_diff($options, ['--viewer', '--owner']);
if ($unknown !== []) {
    fwrite(STDERR, $usage . 'Unknown option: ' . implode(', ', $unknown) . PHP_EOL);
    exit(1);
}
if (in_array('--viewer', $options, true) && in_array('--owner', $options, true)) {
    fwrite(STDERR, $usage . 'Give either --viewer or --owner, not both.' . PHP_EOL);
    exit(1);
}
// The role asked for, if any. Without an option a new administrator is an
// owner and an existing one keeps its role, so that resetting a read-only
// administrator's password never gives it write access.
$role = match (true) {
    in_array('--viewer', $options, true) => ADMIN_ROLE_VIEWER,
    in_array('--owner', $options, true) => ADMIN_ROLE_OWNER,
    default => null,
};

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

$describe = static fn (string $role): string => $role === ADMIN_ROLE_VIEWER ? 'read-only administrator' : 'administrator';
$previous = db_value('SELECT role FROM admins WHERE Eaddress = ?', [$email]);
if ($previous !== null) {
    $previous = (string) $previous;
    $role ??= $previous;
    // A new password signs out existing sessions and lifts any sign-in lockout.
    db_run(
        'UPDATE admins SET name = ?, password = ?, role = ?, session_version = session_version + 1 WHERE Eaddress = ?',
        [$name, hash_password($password), $role, $email]
    );
    clear_failed_logins($email);
    $changed = $role === $previous ? '' : "role changed from $previous to $role; ";
    echo 'Updated ' . $describe($role) . " $email ({$changed}other sessions signed out, sign-in lockout cleared)." . PHP_EOL;
} else {
    $role ??= ADMIN_ROLE_OWNER;
    db_run('INSERT INTO admins (Eaddress, name, password, role) VALUES (?, ?, ?, ?)', [$email, $name, hash_password($password), $role]);
    echo 'Created ' . $describe($role) . " $email." . PHP_EOL;
}
