<?php

declare(strict_types=1);

/**
 * Prepares the database configured in .env:
 *  - empty database: creates the latest schema (database/schema.sql) and, with
 *    --seed, loads the sample catalog (database/seed.sql);
 *  - existing BookWise database: applies pending migrations instead.
 * In demo mode (APP_DEMO=true) it also restocks the catalog to at least the
 * sample levels, so visitors' simulated purchases never sell it out for good,
 * and adds the demo's showcase (fictional customers with their orders and
 * reviews, see src/demo.php) when the store has none yet, unless
 * --no-showcase is given.
 * Optionally creates or updates an administrator from environment variables
 * (BOOKWISE_ADMIN_EMAIL + BOOKWISE_ADMIN_PASSWORD), which is how containers
 * are set up without an interactive prompt.
 *
 * Usage: php bin/install.php [--seed] [--no-showcase]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

// The command-line arguments (bin/setup.php sets them too when it includes this script).
$seed = in_array('--seed', (array) ($GLOBALS['argv'] ?? []), true);
$addShowcase = !in_array('--no-showcase', (array) ($GLOBALS['argv'] ?? []), true);

if (!db_table_exists('users')) {
    $leftovers = array_values(array_filter([...MIGRATION_BASELINE_TABLES, 'email_verifications', 'schema_migrations'], 'db_table_exists'));
    if ($leftovers !== []) {
        fwrite(STDERR, 'The database "' . config('db.name') . '" was only partly installed (it has ' . implode(', ', $leftovers) . ' but no users table). '
            . 'Drop its tables (or create a new, empty database) and run php bin/install.php again.' . PHP_EOL);
        exit(1);
    }
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
}

if (config('app.demo')) {
    // Public demo: put back the copies visitors bought, so the shared catalog
    // never stays sold out (runs at every container start and wake-up).
    $restocked = db_run('UPDATE books SET stock = GREATEST(stock, 3 + ((id * 7) % 28))')->rowCount();
    echo "Demo: restocked $restocked book(s)." . PHP_EOL;

    // A demo without its showcase looks unused: add it once (as bin/demo-seed.php does).
    if ($addShowcase) {
        try {
            $showcase = seed_demo_showcase();
            if ($showcase['status'] !== 'present') {
                echo demo_showcase_report($showcase) . PHP_EOL;
            }
        } catch (DemoStoreException $e) {
            // A reset is running right now: it adds the showcase itself.
            echo 'Demo showcase: skipped. ' . $e->getMessage() . PHP_EOL;
        }
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
