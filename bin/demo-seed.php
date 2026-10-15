<?php

declare(strict_types=1);

/**
 * Adds the public demo's showcase (src/demo.php): clearly fictional
 * customers with made-up Saudi delivery addresses, their orders over the
 * last 60 days at every delivery stage (and one refund and one abandoned
 * checkout), reviews of the books they received, likes, dislikes and
 * favorites. The home page's best sellers and ratings and the admin
 * dashboard then look like a store in use. The same can be run from code:
 * seed_demo_showcase().
 *
 * It runs only in demo mode (APP_DEMO=true) and adds nothing when the
 * showcase is already there, so it is safe to run again. bin/install.php
 * runs it in demo mode. It prints one line (and logs what it added to
 * app.log); the exit status is 0, or 1 when the store is not a demo or the
 * database cannot be used.
 *
 * Usage: php bin/demo-seed.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

if (!config('app.demo')) {
    fwrite(STDERR, 'Refusing to add the showcase: it is only for a public demo (APP_DEMO=true), and this store is not one.' . PHP_EOL);
    exit(1);
}
if (!demo_database_ready()) {
    fwrite(STDERR, 'The database "' . config('db.name') . '" is not up to date. Run: php bin/install.php' . PHP_EOL);
    exit(1);
}

try {
    $result = seed_demo_showcase();
} catch (DemoStoreException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($result['status'] === 'seeded') {
    app_log('info', 'Demo showcase added', $result);
}
echo '[' . date('Y-m-d H:i:s') . '] ' . demo_showcase_report($result) . PHP_EOL;
