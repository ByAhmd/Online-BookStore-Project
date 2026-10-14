<?php

declare(strict_types=1);

/**
 * Prepares the database configured in .env:
 *  - empty database: creates the latest schema (database/schema.sql) and, with
 *    --seed, loads the sample catalog (database/seed.sql);
 *  - existing BookWise database: applies pending migrations instead.
 * Optionally creates or updates an administrator from environment variables
 * (BOOKWISE_ADMIN_EMAIL + BOOKWISE_ADMIN_PASSWORD), which is how containers
 * are set up without an interactive prompt.
 *
 * Usage: php bin/install.php [--seed]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

$seed = in_array('--seed', $argv, true);

if (!db_table_exists('users')) {
    $statements = db_execute_sql_file(base_path('database/schema.sql'));
    echo "Created schema ($statements statements)." . PHP_EOL;
    if ($seed) {
        db_execute_sql_file(base_path('database/seed.sql'));
        echo 'Loaded the sample catalog (' . book_count() . ' books).' . PHP_EOL;
    }
} else {
    $applied = run_migrations(static function (string $line): void {
        echo $line . PHP_EOL;
    });
    echo $applied === [] ? 'Database schema is up to date.' . PHP_EOL : 'Applied ' . count($applied) . ' migration(s).' . PHP_EOL;
    if ($seed && book_count() === 0) {
        db_execute_sql_file(base_path('database/seed.sql'));
        echo 'Loaded the sample catalog (' . book_count() . ' books).' . PHP_EOL;
    }
}

$adminEmail = normalize_email((string) env('BOOKWISE_ADMIN_EMAIL', ''));
$adminPassword = (string) env('BOOKWISE_ADMIN_PASSWORD', '');
if ($adminEmail !== '' && $adminPassword !== '') {
    if (($error = validate_email($adminEmail) ?? validate_new_password($adminPassword, $adminPassword)) !== null) {
        fwrite(STDERR, "Administrator not created: $error" . PHP_EOL);
        exit(1);
    }
    $exists = db_value('SELECT 1 FROM admins WHERE Eaddress = ?', [$adminEmail]) !== null;
    if (!$exists) {
        db_run('INSERT INTO admins (Eaddress, name, password) VALUES (?, ?, ?)', [$adminEmail, 'Administrator', hash_password($adminPassword)]);
        echo "Created administrator $adminEmail." . PHP_EOL;
    } elseif (!check_password($adminPassword, (string) db_value('SELECT password FROM admins WHERE Eaddress = ?', [$adminEmail]))['valid']) {
        db_run(
            'UPDATE admins SET password = ?, session_version = session_version + 1 WHERE Eaddress = ?',
            [hash_password($adminPassword), $adminEmail]
        );
        echo "Updated the password of administrator $adminEmail." . PHP_EOL;
    }
}
