<?php

declare(strict_types=1);

/**
 * The public demo (APP_DEMO=true): its showcase and its daily reset.
 *
 * A demo that starts empty looks abandoned: no best sellers, no ratings and
 * an empty admin dashboard. seed_demo_showcase() (bin/demo-seed.php, and
 * bin/install.php in demo mode) adds clearly fictional activity:
 *  - twelve customers with made-up names in English and Arabic, addresses at
 *    the reserved DEMO_EMAIL_DOMAIN (showcase-<name>@demo.invalid) and a
 *    password that nobody knows, so nobody can sign in to them;
 *  - a made-up Saudi delivery address each, with a placeholder phone number;
 *  - orders over the last DEMO_SHOWCASE_DAYS days at every delivery stage
 *    (processing, shipped with a carrier and tracking number, delivered),
 *    one order refunded before it was shipped and one abandoned checkout;
 *  - reviews (3 to 5 stars, in the customer's language) only of books the
 *    reviewer bought and received, likes and dislikes of those reviews by
 *    the other customers, and favorites.
 *
 * The store's own functions stamp everything with the current time and email
 * the customer, so the rows are written here directly, with the bookkeeping
 * of create_pending_order(), fulfill_order() and apply_order_refund(): a copy
 * of the delivery address for every order, amounts in minor units from the
 * catalog's VAT-inclusive prices, one sales row per line of a paid order,
 * stock_taken, and the stock (never below zero, and in demo mode never below
 * DEMO_MIN_STOCK). tests/Integration/DemoShowcaseTest checks these invariants.
 * Everything is written in one transaction, so a failure adds nothing.
 *
 * The showcase customers' addresses mark the showcase: only the store's own
 * code creates customers at DEMO_EMAIL_DOMAIN (email_error() refuses the
 * domain in every form), demo guests are "reader-<random>@" and the shared
 * demo administrator is not a customer. The unique email key also makes
 * the showcase impossible to add twice.
 *
 * reset_demo_store() (bin/demo-reset.php, a daily scheduled task) returns a
 * demo to that starting state: every customer goes with their data and
 * every order, the catalog is put back to the 55 sample books, and the
 * showcase is added again (or left out, to empty a demo before it becomes
 * a real store). Administrators are kept.
 */

/** Showcase customers' email addresses: this prefix, a name, "@" and DEMO_EMAIL_DOMAIN. */
const DEMO_SHOWCASE_EMAIL_PREFIX = 'showcase-';
/** The showcase's orders reach back at most this many days. */
const DEMO_SHOWCASE_DAYS = 60;
/** Hours from a showcase order's payment to its shipment, and from the shipment to the delivery. */
const DEMO_SHOWCASE_SHIP_HOURS = 20;
const DEMO_SHOWCASE_DELIVERY_HOURS = 46;
/** Hours from the payment to the refund of the showcase's refunded order (before it was shipped). */
const DEMO_SHOWCASE_REFUND_HOURS = 5;
/** Percentages of the other showcase customers who like, and who dislike, a showcase review. */
const DEMO_SHOWCASE_LIKE_PERCENT = 25;
const DEMO_SHOWCASE_DISLIKE_PERCENT = 4;
/** Named lock that lets one showcase seed or demo reset run at a time (demo_showcase_lock_name() adds the database). */
const DEMO_SHOWCASE_LOCK = 'bookwise-demo-showcase';

/** A demo operation that cannot run right now; its message is safe to print. */
final class DemoStoreException extends RuntimeException
{
}

/* ---------------------------------------------------------------------
 * Checks
 * ------------------------------------------------------------------- */

/**
 * Stops with a DemoStoreException unless this store is a public demo
 * (APP_DEMO=true). $what names the operation, e.g. "The demo reset".
 */
function require_demo_mode(string $what): void
{
    if (!config('app.demo')) {
        throw new DemoStoreException("$what is only for a public demo (APP_DEMO=true), and this store is not one.");
    }
}

/**
 * True when the database has every migration (the showcase and the reset
 * need the latest schema).
 */
function demo_database_ready(): bool
{
    if (!db_table_exists('schema_migrations')) {
        return false;
    }
    return array_diff(array_keys(available_migrations()), applied_migrations()) === [];
}

function is_demo_showcase_email(string $email): bool
{
    return str_starts_with($email, DEMO_SHOWCASE_EMAIL_PREFIX) && str_ends_with($email, '@' . DEMO_EMAIL_DOMAIN);
}

/**
 * True when the store already has the showcase (or a customer of it).
 */
function demo_showcase_present(): bool
{
    return db_value('SELECT 1 FROM users WHERE Eaddress LIKE ? LIMIT 1', [DEMO_SHOWCASE_EMAIL_PREFIX . '%@' . DEMO_EMAIL_DOMAIN]) !== null;
}

/**
 * The name of this store's showcase lock: DEMO_SHOWCASE_LOCK and the
 * database's name (store_lock_name()), so that no other database on the
 * server can hold up this store's nightly reset.
 */
function demo_showcase_lock_name(): string
{
    return store_lock_name(DEMO_SHOWCASE_LOCK);
}

/**
 * Runs $callback while holding this store's showcase lock
 * (demo_showcase_lock_name()), so that two seeds (or a seed and a reset)
 * never run at the same time.
 *
 * @template T
 * @param callable(): T $callback
 * @return T
 */
function with_demo_showcase_lock(callable $callback): mixed
{
    $lock = demo_showcase_lock_name();
    if ((int) db_value('SELECT GET_LOCK(?, 30)', [$lock]) !== 1) {
        throw new DemoStoreException('Another showcase seed or demo reset is running. Try again in a minute.');
    }
    try {
        return $callback();
    } finally {
        db_value('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

/* ---------------------------------------------------------------------
 * The showcase
 * ------------------------------------------------------------------- */

/**
 * Adds the showcase (see the top of this file) unless the store has it
 * already ("present") or none of the sample books it refers to is in the
 * catalog ("no_catalog"); then nothing is added. Only in demo mode.
 *
 * @return array{status: 'seeded'|'present'|'no_catalog', customers: int, orders: int, reviews: int, reactions: int, favorites: int}
 * @throws DemoStoreException outside demo mode, or while another seed or reset runs
 */
function seed_demo_showcase(): array
{
    require_demo_mode('The showcase');
    return with_demo_showcase_lock(static function (): array {
        $none = ['customers' => 0, 'orders' => 0, 'reviews' => 0, 'reactions' => 0, 'favorites' => 0];
        if (demo_showcase_present()) {
            return ['status' => 'present'] + $none;
        }
        $bookIds = demo_showcase_book_ids();
        $known = (int) db_value('SELECT COUNT(*) FROM books WHERE id IN (' . implode(', ', array_fill(0, count($bookIds), '?')) . ')', $bookIds);
        if ($known === 0) {
            return ['status' => 'no_catalog'] + $none;
        }
        return ['status' => 'seeded'] + db_transaction(static fn (): array => insert_demo_showcase());
    });
}

/**
 * One line about the result of seed_demo_showcase(), for the command line.
 *
 * @param array{status: 'seeded'|'present'|'no_catalog', customers: int, orders: int, reviews: int, reactions: int, favorites: int} $result
 */
function demo_showcase_report(array $result): string
{
    return match ($result['status']) {
        'seeded' => sprintf(
            'Demo showcase: added %d customer(s), %d order(s), %d review(s), %d like(s) and dislike(s) and %d favorite(s).',
            $result['customers'],
            $result['orders'],
            $result['reviews'],
            $result['reactions'],
            $result['favorites']
        ),
        'present' => 'Demo showcase: already there, nothing added.',
        // bin/install.php loads database/seed.sql only into an empty database; the reset loads it into any.
        'no_catalog' => 'Demo showcase: nothing added, as the sample books are not in the catalog. '
            . 'php bin/demo-reset.php --yes puts them back and adds the showcase (it also deletes every customer and order).',
    };
}

/**
 * Writes the showcase and returns what it added. The caller holds a
 * transaction (and the showcase lock), and has made sure that the showcase
 * is not there yet. Order lines, favorites and reviews of books that are no
 * longer in the catalog are left out, like orders that are left without a
 * line. Every time is relative to the database's NOW(), and the orders are
 * written in the order they were placed.
 *
 * @return array{customers: int, orders: int, reviews: int, reactions: int, favorites: int}
 */
function insert_demo_showcase(): array
{
    $customers = demo_showcase_customers();
    $now = (new DateTimeImmutable((string) db_value('SELECT NOW()')))->getTimestamp();
    $at = static fn (int $timestamp): string => date('Y-m-d H:i:s', $timestamp);
    $currency = (string) config('stripe.currency');
    // The hash of a random password that is thrown away: nobody can sign in as these customers.
    $passwordHash = hash_password(bin2hex(random_bytes(32)));
    $counts = ['customers' => 0, 'orders' => 0, 'reviews' => 0, 'reactions' => 0, 'favorites' => 0];

    // The books, locked in id order as fulfill_order() locks them.
    $bookIds = demo_showcase_book_ids();
    $books = [];
    $stock = [];
    foreach (db_all('SELECT id, title, price, stock FROM books WHERE id IN (' . implode(', ', array_fill(0, count($bookIds), '?')) . ') ORDER BY id FOR UPDATE', $bookIds) as $row) {
        $books[(int) $row['id']] = ['title' => (string) $row['title'], 'price' => (string) $row['price']];
        $stock[(int) $row['id']] = (int) $row['stock'];
    }
    $stockBefore = $stock;

    // The customers (their email addresses already confirmed), each with a default delivery address and a few favorites.
    $userIds = [];
    $timeline = [];
    foreach ($customers as $index => $customer) {
        $handle = $customer['handle'];
        // How long ago each order was placed: its days and hours, and a few minutes.
        $ages = [];
        foreach ($customer['orders'] as $number => $order) {
            $ages[$number] = $order['days'] * 86400 + $order['hours'] * 3600 + demo_showcase_jitter("$handle#$number", 60) * 60;
        }
        // Confirmed a few days before the first order.
        $joined = $now - max([0, ...$ages]) - 2 * 86400 - demo_showcase_jitter($handle, 86400);

        db_run(
            'INSERT INTO users (Eaddress, name, pass, favorite_genre, payment_method, locale, email_verified_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [DEMO_SHOWCASE_EMAIL_PREFIX . $handle . '@' . DEMO_EMAIL_DOMAIN, $customer['name'], $passwordHash, $customer['genre'], $customer['payment'], $customer['locale'], $at($joined)]
        );
        $userId = (int) db()->lastInsertId();
        $userIds[$index] = $userId;
        $counts['customers']++;

        [$address, $errors] = validate_address_input($customer['address'] + [
            'recipient_name' => $customer['name'],
            // Made-up mobile numbers in the store's format: +966 50 000 01NN.
            'phone' => sprintf('05000001%02d', $index + 1),
        ]);
        if ($errors !== []) {
            throw new LogicException("The showcase address of $handle is not valid: " . implode(', ', array_keys($errors)));
        }
        db_run(
            'INSERT INTO addresses (user_id, ' . implode(', ', ADDRESS_FIELDS) . ', is_default, created_at)
             VALUES (?' . str_repeat(', ?', count(ADDRESS_FIELDS)) . ', 1, ?)',
            [$userId, ...address_column_values($address), $at($joined + 3600)]
        );

        foreach ($customer['favorites'] as $number => $bookId) {
            if (isset($books[$bookId])) {
                db_run('INSERT INTO favorites (user_id, book_id, created_at) VALUES (?, ?, ?)', [$userId, $bookId, $at($joined + ($number + 1) * 7200)]);
                $counts['favorites']++;
            }
        }

        foreach ($customer['orders'] as $number => $order) {
            $timeline[] = ['created' => $now - $ages[$number], 'customer' => $index, 'user_id' => $userId, 'address' => $address, 'order' => $order, 'key' => $handle . '#' . $number];
        }
    }
    usort($timeline, static fn (array $a, array $b): int => $a['created'] <=> $b['created']);

    // The orders, oldest first.
    $reviews = [];
    foreach ($timeline as $entry) {
        $order = $entry['order'];
        $lines = [];
        foreach ($order['books'] as $bookId => $quantity) {
            if (isset($books[$bookId])) {
                $lines[] = [
                    'book_id' => $bookId,
                    'title' => $books[$bookId]['title'],
                    'price' => $books[$bookId]['price'],
                    'quantity' => $quantity,
                    'line_minor' => money_to_minor($books[$bookId]['price']) * $quantity,
                ];
            }
        }
        // As create_pending_order(): prices from the catalog, in minor units, and the minimum charge.
        $amountMinor = cart_total_minor($lines);
        if ($lines === [] || $amountMinor < CHECKOUT_MIN_TOTAL_MINOR) {
            continue;
        }

        $stage = $order['stage'];
        $created = $entry['created'];
        $paid = $stage !== 'expired' ? $created + 180 + demo_showcase_jitter($entry['key'], 120) : null;
        $shipped = $paid !== null && in_array($stage, ['shipped', 'delivered'], true) ? $paid + DEMO_SHOWCASE_SHIP_HOURS * 3600 : null;
        $delivered = $shipped !== null && $stage === 'delivered' ? $shipped + DEMO_SHOWCASE_DELIVERY_HOURS * 3600 : null;
        $refunded = $paid !== null && $stage === 'refunded' ? $paid + DEMO_SHOWCASE_REFUND_HOURS * 3600 : null;
        // An abandoned checkout's session expired after CHECKOUT_SESSION_TTL_SECONDS.
        $updated = $paid === null ? $created + CHECKOUT_SESSION_TTL_SECONDS : max($paid, $shipped ?? 0, $delivered ?? 0, $refunded ?? 0);
        $status = match ($stage) {
            'expired' => 'expired',
            'refunded' => 'refunded',
            default => 'paid',
        };
        $carrier = $shipped !== null ? ($order['carrier'] ?? null) : null;
        $tracking = $shipped !== null ? '29' . str_pad((string) crc32($entry['key']), 10, '0', STR_PAD_LEFT) : null;

        db_run(
            'INSERT INTO orders (user_id, status, currency, total_amount, amount_minor, stripe_session_id, stripe_payment_intent, stripe_refund_id,
                                 created_at, updated_at, paid_at, refunded_at, fulfilment_status, shipped_at, delivered_at, carrier, tracking_number)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $entry['user_id'],
                $status,
                $currency,
                minor_to_decimal($amountMinor),
                $amountMinor,
                // The payment simulator's formats; it does not know these payments, so a demo
                // records a refund of one of them locally (see refund_order()).
                'cs_test_' . bin2hex(random_bytes(12)),
                $paid !== null ? 'pi_test_' . bin2hex(random_bytes(12)) : null,
                $refunded !== null ? 're_test_' . bin2hex(random_bytes(12)) : null,
                $at($created),
                $at($updated),
                $paid !== null ? $at($paid) : null,
                $refunded !== null ? $at($refunded) : null,
                // A refunded order keeps the stage it had reached.
                $paid === null ? null : ($stage === 'refunded' ? 'processing' : $stage),
                $shipped !== null ? $at($shipped) : null,
                $delivered !== null ? $at($delivered) : null,
                $carrier,
                $tracking,
            ]
        );
        $orderId = (int) db()->lastInsertId();
        $counts['orders']++;
        db_run(
            'INSERT INTO order_addresses (order_id, ' . implode(', ', ADDRESS_FIELDS) . ')
             VALUES (?' . str_repeat(', ?', count(ADDRESS_FIELDS)) . ')',
            [$orderId, ...address_column_values($entry['address'])]
        );

        foreach ($lines as $line) {
            $taken = 0;
            if ($paid !== null) {
                // As fulfill_order() in demo mode: never take a book below DEMO_MIN_STOCK
                // (a book already below it is topped up). As apply_order_refund(): an
                // order refunded before it was shipped puts its copies back.
                $copies = $stock[$line['book_id']];
                $taken = max(0, min($line['quantity'], $copies - DEMO_MIN_STOCK));
                $stock[$line['book_id']] = max($copies - $taken, DEMO_MIN_STOCK) + ($refunded !== null ? $taken : 0);
            }
            db_run(
                'INSERT INTO order_items (order_id, book_id, title, unit_price, quantity, stock_taken) VALUES (?, ?, ?, ?, ?, ?)',
                [$orderId, $line['book_id'], $line['title'], $line['price'], $line['quantity'], $taken]
            );
            if ($status === 'paid') {
                db_run(
                    'INSERT INTO sales (book_id, user_id, order_id, quantity, purchase_date) VALUES (?, ?, ?, ?, ?)',
                    [$line['book_id'], $entry['user_id'], $orderId, $line['quantity'], $at((int) $paid)]
                );
            }
        }

        // Reviews of the books this order delivered, a day or more after the delivery.
        if ($delivered !== null) {
            $orderedBooks = array_column($lines, 'book_id');
            $number = 0;
            foreach ($order['reviews'] ?? [] as $bookId => [$rating, $text]) {
                if (!in_array($bookId, $orderedBooks, true)) {
                    continue;
                }
                $reviewed = min($delivered + (++$number) * 86400 + demo_showcase_jitter($entry['key'] . $bookId, 36000), $now - 3600);
                db_run(
                    'INSERT INTO comments (book_id, username, comment, rating, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$bookId, DEMO_SHOWCASE_EMAIL_PREFIX . $customers[$entry['customer']]['handle'] . '@' . DEMO_EMAIL_DOMAIN, $text, $rating, $at($reviewed)]
                );
                $reviews[] = ['id' => (int) db()->lastInsertId(), 'customer' => $entry['customer'], 'key' => $entry['key'] . ':' . $bookId];
                $counts['reviews']++;
            }
        }
    }

    // Likes and dislikes of the reviews by the other customers.
    foreach ($reviews as $review) {
        foreach ($userIds as $index => $userId) {
            if ($index === $review['customer']) {
                continue;
            }
            $roll = demo_showcase_jitter($review['key'] . '>' . $customers[$index]['handle'], 100);
            $reaction = $roll < DEMO_SHOWCASE_LIKE_PERCENT ? 'like' : ($roll >= 100 - DEMO_SHOWCASE_DISLIKE_PERCENT ? 'dislike' : null);
            if ($reaction !== null) {
                db_run('INSERT INTO comment_interactions (comment_id, user_id, reaction_type) VALUES (?, ?, ?)', [$review['id'], $userId, $reaction]);
                $counts['reactions']++;
            }
        }
    }

    foreach ($stock as $bookId => $copies) {
        if ($copies !== $stockBefore[$bookId]) {
            db_run('UPDATE books SET stock = ? WHERE id = ?', [$copies, $bookId]);
        }
    }
    return $counts;
}

/**
 * A number from 0 to $modulo - 1 that is always the same for $key, so the
 * showcase looks the same every time without looking regular.
 */
function demo_showcase_jitter(string $key, int $modulo): int
{
    return crc32($key) % max(1, $modulo);
}

/**
 * Every sample book the showcase refers to, in id order.
 *
 * @return list<int>
 */
function demo_showcase_book_ids(): array
{
    $ids = [];
    foreach (demo_showcase_customers() as $customer) {
        array_push($ids, ...$customer['favorites']);
        foreach ($customer['orders'] as $order) {
            array_push($ids, ...array_keys($order['books']));
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
}

/**
 * The showcase's customers: who they are, their delivery address (the
 * recipient is the customer, the phone number a placeholder), favorites
 * (sample book ids) and orders. An order was placed 'days' and 'hours' ago;
 * 'stage' is where it is now: 'processing', 'shipped', 'delivered',
 * 'refunded' (paid, then refunded before it was shipped) or 'expired' (an
 * abandoned checkout). 'books' maps sample book ids to copies, and the
 * 'reviews' of a delivered order map its books to a rating and a text.
 * Every name, address and text is made up.
 *
 * The orders sell no book with an id divisible by 4: those start with
 * DEMO_MIN_STOCK copies in database/seed.sql (the demo's "Only 3 left").
 * The others never sell more than their sample stock minus DEMO_MIN_STOCK,
 * so the showcase leaves every book exactly as many copies as it took.
 *
 * @return list<array{handle: string, name: string, locale: string, genre: string, payment: string, address: array<string, string>, favorites: list<int>, orders: list<array{days: int, hours: int, stage: 'processing'|'shipped'|'delivered'|'refunded'|'expired', books: array<int, int>, carrier?: string, reviews?: array<int, array{0: int, 1: string}>}>}>
 */
function demo_showcase_customers(): array
{
    return [
        [
            'handle' => 'maya',
            'name' => 'Maya Collins',
            'locale' => 'en',
            'genre' => 'Mystery',
            'payment' => 'Apple Pay',
            'address' => ['city' => 'Riyadh', 'district' => 'Al Yasmin', 'street' => 'Bookbinder Street', 'building_number' => '2817', 'postal_code' => '13325', 'additional_number' => '7341', 'short_address' => 'RYMC2817'],
            'favorites' => [23, 28, 45],
            'orders' => [
                ['days' => 58, 'hours' => 5, 'stage' => 'delivered', 'books' => [23 => 1, 26 => 1], 'carrier' => 'SMSA Express', 'reviews' => [
                    23 => [5, 'Still chilling after all these years. I read it in two evenings and kept thinking about it for days.'],
                    26 => [4, 'Beautiful photographs and clear writing. A lovely book to leave on the coffee table.'],
                ]],
                ['days' => 21, 'hours' => 2, 'stage' => 'delivered', 'books' => [47 => 1, 59 => 1], 'carrier' => 'Aramex', 'reviews' => [
                    47 => [4, 'A gentle, old-fashioned romance. The last chapter had me in tears.'],
                ]],
                ['days' => 0, 'hours' => 20, 'stage' => 'processing', 'books' => [35 => 1]],
            ],
        ],
        [
            'handle' => 'daniel',
            'name' => 'Daniel Hart',
            'locale' => 'en',
            'genre' => 'Fantasy',
            'payment' => 'Mada',
            'address' => ['city' => 'Jeddah', 'district' => 'Al Rawdah', 'street' => 'Inkwell Road', 'building_number' => '4096', 'postal_code' => '23435', 'additional_number' => '2210', 'short_address' => ''],
            'favorites' => [21, 33, 35],
            'orders' => [
                ['days' => 52, 'hours' => 9, 'stage' => 'delivered', 'books' => [35 => 2], 'carrier' => 'SPL (Saudi Post)', 'reviews' => [
                    35 => [5, 'Bought one for me and one for my brother. Clever magic, and the pace never drops.'],
                ]],
                ['days' => 30, 'hours' => 4, 'stage' => 'delivered', 'books' => [23 => 1, 29 => 1], 'carrier' => 'SMSA Express', 'reviews' => [
                    29 => [4, 'Scary in the best way. My nephew and I took turns reading it aloud.'],
                ]],
                ['days' => 4, 'hours' => 6, 'stage' => 'shipped', 'books' => [55 => 1, 7 => 1], 'carrier' => 'Aramex'],
            ],
        ],
        [
            'handle' => 'grace',
            'name' => 'Grace Bennett',
            'locale' => 'en',
            'genre' => 'Romantic',
            'payment' => 'Credit / Debit Card',
            'address' => ['city' => 'Al Khobar', 'district' => 'Al Ulaya', 'street' => 'Paper Mill Street', 'building_number' => '3158', 'postal_code' => '34447', 'additional_number' => '', 'short_address' => 'EKGB3158'],
            'favorites' => [47, 51, 61],
            'orders' => [
                ['days' => 45, 'hours' => 7, 'stage' => 'delivered', 'books' => [47 => 2, 51 => 1], 'carrier' => 'Naqel Express', 'reviews' => [
                    47 => [5, 'I gave the second copy to my sister, and she loved it just as much as I did.'],
                    51 => [3, 'Well written, though the middle was slower than I expected.'],
                ]],
                ['days' => 9, 'hours' => 3, 'stage' => 'refunded', 'books' => [34 => 1]],
                ['days' => 3, 'hours' => 1, 'stage' => 'shipped', 'books' => [23 => 1, 21 => 1], 'carrier' => 'SMSA Express'],
            ],
        ],
        [
            'handle' => 'omar',
            'name' => 'Omar Haddad',
            'locale' => 'en',
            'genre' => 'Crime',
            'payment' => 'Mada',
            'address' => ['city' => 'Riyadh', 'district' => 'Al Malqa', 'street' => 'Chapter Road', 'building_number' => '6620', 'postal_code' => '13524', 'additional_number' => '4118', 'short_address' => ''],
            'favorites' => [14, 34, 44],
            'orders' => [
                ['days' => 40, 'hours' => 8, 'stage' => 'delivered', 'books' => [34 => 2, 14 => 1], 'carrier' => 'Aramex', 'reviews' => [
                    34 => [4, 'A proper detective story with a satisfying twist. I am looking forward to the next one.'],
                    14 => [3, 'Fun premise and a quick read, but the humour was not always for me.'],
                ]],
                ['days' => 16, 'hours' => 5, 'stage' => 'expired', 'books' => [41 => 1]],
                ['days' => 12, 'hours' => 2, 'stage' => 'delivered', 'books' => [59 => 2], 'carrier' => 'J&T Express', 'reviews' => [
                    59 => [5, 'Moving and honest. I bought a second copy for my book club.'],
                ]],
            ],
        ],
        [
            'handle' => 'lina',
            'name' => 'Lina Park',
            'locale' => 'en',
            'genre' => 'Drama',
            'payment' => 'Google Pay',
            'address' => ['city' => 'Dammam', 'district' => 'Al Faisaliyah', 'street' => 'Library Lane', 'building_number' => '7703', 'postal_code' => '32272', 'additional_number' => '5524', 'short_address' => 'DALP7703'],
            'favorites' => [22, 26, 30],
            'orders' => [
                ['days' => 33, 'hours' => 6, 'stage' => 'delivered', 'books' => [26 => 2, 30 => 1], 'carrier' => 'SMSA Express', 'reviews' => [
                    26 => [5, 'Gorgeous illustrations. My kids ask for it every night.'],
                    30 => [4, 'A touching story about grief and friendship, told with real warmth.'],
                ]],
                ['days' => 2, 'hours' => 4, 'stage' => 'shipped', 'books' => [47 => 1], 'carrier' => 'SPL (Saudi Post)'],
            ],
        ],
        [
            'handle' => 'noah',
            'name' => 'Noah Fischer',
            'locale' => 'en',
            'genre' => 'Science',
            'payment' => 'Credit / Debit Card',
            'address' => ['city' => 'Riyadh', 'district' => 'Al Nakheel', 'street' => 'Quill Street', 'building_number' => '5342', 'postal_code' => '12382', 'additional_number' => '', 'short_address' => ''],
            'favorites' => [7, 27, 36],
            'orders' => [
                ['days' => 26, 'hours' => 3, 'stage' => 'delivered', 'books' => [23 => 2, 39 => 1], 'carrier' => 'Aramex', 'reviews' => [
                    23 => [4, 'A classic for a reason. I ordered a second copy as a gift.'],
                    39 => [3, 'Entertaining enough for a weekend, though I guessed the culprit early on.'],
                ]],
                ['days' => 0, 'hours' => 9, 'stage' => 'processing', 'books' => [59 => 1, 31 => 1]],
            ],
        ],
        [
            'handle' => 'layla',
            'name' => 'ليلى الحربي',
            'locale' => 'ar',
            'genre' => 'Romantic',
            'payment' => 'Apple Pay',
            'address' => ['city' => 'الرياض', 'district' => 'حي الياسمين', 'street' => 'شارع الورّاقين', 'building_number' => '8214', 'postal_code' => '13326', 'additional_number' => '3390', 'short_address' => 'RYLH8214'],
            'favorites' => [47, 53, 56],
            'orders' => [
                ['days' => 56, 'hours' => 4, 'stage' => 'delivered', 'books' => [47 => 1, 53 => 1], 'carrier' => 'سمسا إكسبرس', 'reviews' => [
                    47 => [5, 'قصة حب رقيقة بأسلوب سلس، أنهيتها في جلسة واحدة ولم أستطع تركها.'],
                    53 => [4, 'رواية دافئة عن علاقة الأم بابنتها، أحببت وصف الأماكن وإن طالت بعض الفصول.'],
                ]],
                ['days' => 18, 'hours' => 6, 'stage' => 'delivered', 'books' => [35 => 1, 59 => 1], 'carrier' => 'أرامكس', 'reviews' => [
                    35 => [4, 'مغامرة ممتعة وشخصيات مرسومة بعناية، والنهاية تمهّد لجزء ثانٍ أنتظره بشوق.'],
                    59 => [5, 'من أصدق ما قرأت هذا العام؛ تتناول الزواج والأمومة بعمق وصدق.'],
                ]],
                ['days' => 5, 'hours' => 2, 'stage' => 'shipped', 'books' => [55 => 1], 'carrier' => 'سمسا إكسبرس'],
            ],
        ],
        [
            'handle' => 'fahad',
            'name' => 'فهد العتيبي',
            'locale' => 'ar',
            'genre' => 'Crime',
            'payment' => 'Mada',
            'address' => ['city' => 'الرياض', 'district' => 'حي الملقا', 'street' => 'شارع القلم', 'building_number' => '4471', 'postal_code' => '13521', 'additional_number' => '6612', 'short_address' => ''],
            'favorites' => [23, 25, 41],
            'orders' => [
                ['days' => 49, 'hours' => 7, 'stage' => 'delivered', 'books' => [23 => 2, 7 => 1], 'carrier' => 'سبل (البريد السعودي)', 'reviews' => [
                    23 => [5, 'رواية تجعلك تعيد التفكير في معنى الحرية، وما زال أسلوبها مدهشًا رغم مرور السنين.'],
                    7 => [4, 'مدخل واضح ومنظّم إلى علم الأحياء، ومفيد لطلاب المرحلة الثانوية.'],
                ]],
                ['days' => 15, 'hours' => 3, 'stage' => 'delivered', 'books' => [34 => 1, 21 => 2], 'carrier' => 'ناقل إكسبرس', 'reviews' => [
                    21 => [5, 'اشتريت نسختين لابنيّ فلم يتركا الكتاب حتى أنهياه. خيال جميل ولغة سهلة.'],
                ]],
                ['days' => 1, 'hours' => 2, 'stage' => 'processing', 'books' => [29 => 2]],
            ],
        ],
        [
            'handle' => 'noura',
            'name' => 'نورة القحطاني',
            'locale' => 'ar',
            'genre' => 'Mystery',
            'payment' => 'Apple Pay',
            'address' => ['city' => 'جدة', 'district' => 'حي الروضة', 'street' => 'شارع الحبر', 'building_number' => '2659', 'postal_code' => '23432', 'additional_number' => '8045', 'short_address' => 'JENQ2659'],
            'favorites' => [13, 35, 38],
            'orders' => [
                ['days' => 42, 'hours' => 5, 'stage' => 'delivered', 'books' => [26 => 1, 51 => 1, 47 => 1], 'carrier' => 'أرامكس', 'reviews' => [
                    26 => [4, 'صور رائعة ومعلومات ممتعة عن أعماق البحار، كتاب يناسب العائلة كلها.'],
                    51 => [4, 'قصة مؤثرة عن الفقد والتصالح مع الذات، وقد أعجبني أسلوب الكاتبة الهادئ.'],
                ]],
                ['days' => 8, 'hours' => 4, 'stage' => 'delivered', 'books' => [35 => 2, 17 => 1], 'carrier' => 'سمسا إكسبرس', 'reviews' => [
                    35 => [5, 'من أمتع روايات الخيال التي قرأتها، والحبكة مشوّقة من الصفحة الأولى.'],
                    17 => [4, 'قصة مخيفة قصيرة تناسب اليافعين، قرأتها مع ابنتي واستمتعنا بها معًا.'],
                ]],
            ],
        ],
        [
            'handle' => 'abdullah',
            'name' => 'عبدالله الشهري',
            'locale' => 'ar',
            'genre' => 'Horror',
            'payment' => 'Mada',
            'address' => ['city' => 'أبها', 'district' => 'حي المنسك', 'street' => 'شارع الكتاب', 'building_number' => '6038', 'postal_code' => '62521', 'additional_number' => '', 'short_address' => ''],
            'favorites' => [24, 29, 42],
            'orders' => [
                ['days' => 37, 'hours' => 2, 'stage' => 'delivered', 'books' => [34 => 1, 29 => 1, 42 => 1], 'carrier' => 'سبل (البريد السعودي)', 'reviews' => [
                    29 => [5, 'حكايات مرعبة متقنة داخل حكاية واحدة، فكرة ذكية ونهاية مُرضية.'],
                    42 => [3, 'بداية قوية، لكن الأحداث تباطأت كثيرًا في النصف الثاني.'],
                ]],
                ['days' => 3, 'hours' => 5, 'stage' => 'shipped', 'books' => [23 => 1, 45 => 1], 'carrier' => 'ناقل إكسبرس'],
            ],
        ],
        [
            'handle' => 'reem',
            'name' => 'ريم الدوسري',
            'locale' => 'ar',
            'genre' => 'Fantasy',
            'payment' => 'Google Pay',
            'address' => ['city' => 'الدمام', 'district' => 'حي الشاطئ', 'street' => 'شارع المكتبة', 'building_number' => '3927', 'postal_code' => '32414', 'additional_number' => '1176', 'short_address' => 'DARD3927'],
            'favorites' => [21, 28, 55],
            'orders' => [
                ['days' => 28, 'hours' => 8, 'stage' => 'delivered', 'books' => [47 => 2, 59 => 1], 'carrier' => 'سمسا إكسبرس', 'reviews' => [
                    59 => [4, 'رواية جميلة ومؤلمة في آن واحد، تستحق القراءة رغم حزنها.'],
                ]],
                ['days' => 10, 'hours' => 1, 'stage' => 'delivered', 'books' => [55 => 2, 31 => 1], 'carrier' => 'أرامكس', 'reviews' => [
                    55 => [5, 'عالم سحري متكامل وبطلتان لا تُنسيان، واشتريت نسخة إضافية هدية لصديقتي.'],
                    31 => [4, 'قصة لطيفة عن الصداقة والزمن، قرأتها في عطلة نهاية الأسبوع.'],
                ]],
            ],
        ],
        [
            'handle' => 'salman',
            'name' => 'سلمان الغامدي',
            'locale' => 'ar',
            'genre' => 'Historical',
            'payment' => 'Credit / Debit Card',
            'address' => ['city' => 'مكة المكرمة', 'district' => 'حي العزيزية', 'street' => 'شارع الرواية', 'building_number' => '5186', 'postal_code' => '24243', 'additional_number' => '9902', 'short_address' => ''],
            'favorites' => [15, 19, 20],
            'orders' => [
                ['days' => 23, 'hours' => 4, 'stage' => 'delivered', 'books' => [35 => 1, 23 => 1, 19 => 1], 'carrier' => 'سبل (البريد السعودي)', 'reviews' => [
                    35 => [4, 'خيال ممتع وحبكة متماسكة، وإن كانت بعض الشخصيات تحتاج إلى عمق أكبر.'],
                    19 => [3, 'معلومات تاريخية جيدة عن السيوف، لكن الرسوم أقل مما توقعت.'],
                ]],
                ['days' => 6, 'hours' => 3, 'stage' => 'shipped', 'books' => [34 => 1, 17 => 1], 'carrier' => 'أرامكس'],
                ['days' => 0, 'hours' => 4, 'stage' => 'processing', 'books' => [26 => 1, 14 => 1]],
            ],
        ],
    ];
}

/* ---------------------------------------------------------------------
 * The daily reset
 * ------------------------------------------------------------------- */

/**
 * Returns a public demo to its starting state (bin/demo-reset.php). In one
 * transaction, so visitors never see a store that is half reset:
 *  - every customer is deleted (sign-ups, demo guests and the showcase's
 *    customers) with their carts, favorites, addresses, reviews and
 *    reactions, and every order (also those kept anonymised from deleted
 *    accounts), with password-reset links, failed sign-ins, the demo inbox
 *    and the demo-guest creations;
 *  - the catalog is put back to database/seed.sql: books that
 *    administrators added are deleted, and the 55 sample books come back
 *    with their text, prices, covers and stock (also those an administrator
 *    deleted or changed);
 *  - the showcase is added again (insert_demo_showcase()), unless
 *    $withShowcase is false: that leaves a demo without any customer or
 *    order, for example before it becomes a real store.
 * Administrators are kept. Once that is committed, the uploaded profile
 * pictures and book covers that the deleted rows referred to are deleted,
 * and the payment simulator forgets every checkout session and refund. Rows
 * are deleted, never truncated: ids keep counting up, so a browser session
 * that still remembers a deleted account (the demo inbox) can never point at
 * a new one. Only in demo mode.
 *
 * @return array{customers: int, orders: int, reviews: int, mail: int, pictures: int, books_removed: int, books_restored: int, books: int, payment_simulator: 'cleared'|'none'|'failed', showcase: array{customers: int, orders: int, reviews: int, reactions: int, favorites: int}|null}
 * @throws DemoStoreException outside demo mode, or while another seed or reset runs
 */
function reset_demo_store(bool $withShowcase = true): array
{
    require_demo_mode('The demo reset');
    return with_demo_showcase_lock(static function () use ($withShowcase): array {
        $avatars = array_map('strval', db_run("SELECT profile_image FROM users WHERE profile_image LIKE 'uploads/%'")->fetchAll(PDO::FETCH_COLUMN));
        $covers = array_map('strval', db_run("SELECT DISTINCT cover_image FROM books WHERE cover_image LIKE 'uploads/%'")->fetchAll(PDO::FETCH_COLUMN));

        $summary = db_transaction(static function () use ($withShowcase): array {
            $booksBefore = array_map('intval', db_run('SELECT id FROM books')->fetchAll(PDO::FETCH_COLUMN));
            // Order items and delivery address copies go with the orders; sales, carts,
            // favorites, reactions, addresses and verification links with the customers.
            $orders = db_run('DELETE FROM orders')->rowCount();
            $customers = db_run('DELETE FROM users')->rowCount();
            // Reviews name their author by email, not by id.
            $reviews = db_run('DELETE FROM comments')->rowCount();
            db_run('DELETE FROM reset_tokens');
            db_run('DELETE FROM login_attempts');
            $mail = db_run('DELETE FROM demo_mail')->rowCount();
            db_run('DELETE FROM demo_guest_creations');

            db_run('DELETE FROM books');
            db_execute_sql_file(base_path('database/seed.sql'));
            $booksAfter = array_map('intval', db_run('SELECT id FROM books')->fetchAll(PDO::FETCH_COLUMN));

            return [
                'customers' => $customers,
                'orders' => $orders,
                'reviews' => $reviews,
                'mail' => $mail,
                'pictures' => 0,
                'books_removed' => count(array_diff($booksBefore, $booksAfter)),
                'books_restored' => count(array_diff($booksAfter, $booksBefore)),
                'books' => count($booksAfter),
                'payment_simulator' => 'none',
                'showcase' => $withShowcase ? insert_demo_showcase() : null,
            ];
        });

        // Files are not part of the transaction: they go once the rows are gone.
        foreach ($avatars as $path) {
            if (is_generated_upload($path, AVATAR_DIR) && is_file(base_path('public/' . $path))) {
                delete_uploaded_avatar($path);
                $summary['pictures']++;
            }
        }
        foreach ($covers as $path) {
            if (is_generated_upload($path, COVER_DIR) && is_file(base_path('public/' . $path))) {
                // Deleted with its WebP copies, as no book refers to it any more.
                release_book_cover($path);
                $summary['pictures']++;
            }
        }
        $summary['payment_simulator'] = forget_payment_simulator_state();
        return $summary;
    });
}

/**
 * Empties the payment simulator's state (PAYMENT_SIMULATOR_STORE) under its
 * lock: every checkout session and refund is forgotten, so a payment page
 * that was open before a reset can no longer be paid. "none" when there is no
 * state file; "failed" (logged) when it cannot be saved, for example on a
 * full disk.
 *
 * @return 'cleared'|'none'|'failed'
 */
function forget_payment_simulator_state(): string
{
    if (!is_file(payment_simulator_store_path())) {
        return 'none';
    }
    try {
        payment_simulator_transaction(static function (array &$state): void {
            $state = payment_simulator_normalize_state([]);
        });
    } catch (RuntimeException $e) {
        app_log('warning', 'Demo reset: the payment simulator state could not be cleared: ' . $e->getMessage());
        return 'failed';
    }
    return 'cleared';
}
