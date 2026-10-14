<?php

declare(strict_types=1);

/**
 * Deletes the demo guests (the throwaway customers that the demo's "Try as a
 * customer" button creates) that are more than 24 hours old, with their
 * carts, favorites, reviews, demo inbox messages and abandoned checkouts.
 * Their paid orders are kept, anonymised, as when customers delete their
 * accounts. It also forgets old demo-guest creations and expired demo inbox
 * messages. The same cleanup can be run from code: delete_expired_demo_guests().
 *
 * Safe to run at any time and as often as wanted, for example once a day
 * from a scheduled task (docs/DEPLOYMENT.md). It prints one line; the exit
 * status is 0, or 1 when the database cannot be used.
 *
 * Usage: php bin/demo-cleanup.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

if (!db_column_exists('users', 'demo_guest_at')) {
    fwrite(STDERR, 'The database "' . config('db.name') . '" has no demo guests yet. Run: php bin/install.php' . PHP_EOL);
    exit(1);
}

$result = delete_expired_demo_guests(DEMO_GUEST_TTL_HOURS);
app_log('info', 'Demo cleanup', $result);

$line = sprintf('[%s] Demo cleanup: deleted %d demo guest(s) older than %d hours.', date('Y-m-d H:i:s'), $result['deleted'], DEMO_GUEST_TTL_HOURS);
if ($result['kept'] > 0) {
    $line .= sprintf(' Kept %d whose checkout is still being confirmed; the next run tries again.', $result['kept']);
}
echo $line . PHP_EOL;
