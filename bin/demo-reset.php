<?php

declare(strict_types=1);

/**
 * Returns a public demo to its starting state (reset_demo_store() in
 * src/demo.php):
 *  - deletes every customer (sign-ups, one-click demo customers and the
 *    showcase's customers) with their carts, favorites, addresses, reviews
 *    and orders, every other order, the demo inbox, the demo-guest records,
 *    password-reset links and failed sign-ins;
 *  - deletes the books that administrators added, and puts the 55 sample
 *    books back as database/seed.sql has them (text, prices, covers, stock);
 *  - deletes the uploaded profile pictures and book covers of those rows,
 *    and the payment simulator's checkout sessions and refunds;
 *  - adds the showcase again (bin/demo-seed.php).
 * Administrators are kept, the read-only demo administrator too. The
 * database part is one transaction: visitors never see a store that is half
 * reset, and a failure changes nothing.
 *
 * It runs only in demo mode (APP_DEMO=true) and changes nothing without
 * --yes. Meant for a daily scheduled task (docs/DEPLOYMENT.md): it prints a
 * short summary, also written to app.log; the exit status is 0, or 1 when
 * nothing was reset (not a demo, no --yes, or the database cannot be used).
 *
 * Usage: php bin/demo-reset.php --yes
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

$usage = 'Usage: php bin/demo-reset.php --yes' . PHP_EOL;
$arguments = array_map('strval', array_slice((array) ($GLOBALS['argv'] ?? []), 1));
$unknown = array_diff($arguments, ['--yes']);
if ($unknown !== []) {
    fwrite(STDERR, $usage . 'Unknown argument: ' . implode(', ', $unknown) . PHP_EOL);
    exit(1);
}

if (!config('app.demo')) {
    fwrite(STDERR, 'Refusing to reset: only a public demo (APP_DEMO=true) is reset, and this store is not one. Nothing was changed.' . PHP_EOL);
    exit(1);
}
if (!in_array('--yes', $arguments, true)) {
    fwrite(STDERR, $usage . 'This deletes every customer, order and review of the demo "' . config('db.name') . '", the books administrators added and the uploaded pictures,'
        . ' puts the sample catalog back and adds the showcase again. Administrators are kept. Run it with --yes to reset the demo.' . PHP_EOL);
    exit(1);
}
if (!demo_database_ready()) {
    fwrite(STDERR, 'The database "' . config('db.name') . '" is not up to date. Run: php bin/install.php' . PHP_EOL);
    exit(1);
}

try {
    $summary = reset_demo_store();
} catch (DemoStoreException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

app_log('info', 'Demo reset', $summary);

$time = '[' . date('Y-m-d H:i:s') . '] ';
echo $time . sprintf(
    'Demo reset: deleted %d customer(s), %d order(s), %d review(s), %d demo inbox email(s) and %d uploaded picture(s); '
    . 'removed %d book(s) added by administrators and put back the %d sample books.',
    $summary['customers'],
    $summary['orders'],
    $summary['reviews'],
    $summary['mail'],
    $summary['pictures'],
    $summary['books_removed'],
    $summary['books']
) . PHP_EOL;
if ($summary['payment_simulator'] === 'failed') {
    fwrite(STDERR, $time . 'Warning: the payment simulator\'s state could not be cleared (see app.log).' . PHP_EOL);
}
echo $time . demo_showcase_report(['status' => 'seeded'] + $summary['showcase']) . PHP_EOL;
