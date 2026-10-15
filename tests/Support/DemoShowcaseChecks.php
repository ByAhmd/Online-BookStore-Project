<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

use DateInterval;
use DateTimeImmutable;

/**
 * Checks of the demo's showcase (src/demo.php) shared by its tests and the
 * reset's: the rows must follow every rule the store's own code keeps, so
 * that the pages, the admin actions and the reports treat them like real
 * customers' activity.
 */
trait DemoShowcaseChecks
{
    /**
     * @return array<int, int> book id => copies in stock
     */
    protected static function stockByBook(): array
    {
        $stock = [];
        foreach (db_all('SELECT id, stock FROM books ORDER BY id') as $row) {
            $stock[(int) $row['id']] = (int) $row['stock'];
        }
        return $stock;
    }

    protected static function databaseNow(): DateTimeImmutable
    {
        return new DateTimeImmutable((string) db_value('SELECT NOW()'));
    }

    /**
     * The showcase's customers: fictional, at the reserved domain, confirmed,
     * not demo guests, each with one valid, made-up delivery address.
     */
    protected static function assertShowcaseCustomers(): void
    {
        $expected = demo_showcase_customers();
        $rows = db_all('SELECT id, Eaddress, name, locale, pass, email_verified_at, demo_guest_at FROM users ORDER BY id');
        self::assertCount(count($expected), $rows, 'Only the showcase customers');
        self::assertSame(12, count($rows));

        $languages = [];
        foreach ($rows as $index => $row) {
            $customer = $expected[$index];
            $email = (string) $row['Eaddress'];
            self::assertSame('showcase-' . $customer['handle'] . '@demo.invalid', $email);
            self::assertTrue(is_demo_showcase_email($email));
            self::assertNotNull(email_error($email), 'Nobody can sign up with, or ask for links to, a showcase address');
            self::assertSame($customer['name'], $row['name']);
            self::assertSame($customer['locale'], $row['locale']);
            self::assertNotNull($row['email_verified_at']);
            self::assertNull($row['demo_guest_at'], 'Not a demo guest: bin/demo-cleanup.php leaves the showcase alone');
            self::assertSame('bcrypt', password_get_info((string) $row['pass'])['algoName'], 'The hash of a password nobody knows');
            self::assertFalse(check_password('', (string) $row['pass'])['valid']);
            self::assertSame(preg_match('/\p{Arabic}/u', (string) $row['name']), $row['locale'] === 'ar' ? 1 : 0, 'Arabic names for Arabic-speaking customers');
            $languages[(string) $row['locale']] = true;

            $addresses = customer_addresses((int) $row['id']);
            self::assertCount(1, $addresses, $email);
            $address = $addresses[0];
            self::assertSame(1, (int) $address['is_default']);
            self::assertSame($customer['name'], $address['recipient_name']);
            self::assertMatchesRegularExpression('/^\+9665000001\d\d$/D', (string) $address['phone'], 'A placeholder phone number');
            [$clean, $errors] = validate_address_input($address);
            self::assertSame([], $errors, "The address of $email is a valid National Address");
            self::assertSame(address_column_values($clean), address_column_values($address), 'Stored as a customer would have saved it');

            self::assertGreaterThan(0, (int) db_value('SELECT COUNT(*) FROM favorites WHERE user_id = ?', [$row['id']]), "$email has favorites");
        }
        self::assertSame(['en' => true, 'ar' => true], $languages);
    }

    /**
     * Every order: a showcase customer's, within DEMO_SHOWCASE_DAYS, with a
     * copy of the delivery address, catalog prices in minor units and times
     * that follow each other, at every stage of a delivery, with one refund
     * and one abandoned checkout.
     *
     * @param array<int, array{title: string, price: string}> $catalog Book id => title and price when the showcase was added
     */
    protected static function assertShowcaseOrders(array $catalog): void
    {
        $now = self::databaseNow();
        $oldest = $now->sub(new DateInterval('P' . DEMO_SHOWCASE_DAYS . 'D'));
        $orders = db_all('SELECT o.*, u.Eaddress FROM orders o LEFT JOIN users u ON u.id = o.user_id ORDER BY o.id');
        self::assertNotSame([], $orders);

        $stages = [];
        $previous = null;
        foreach ($orders as $order) {
            $id = (int) $order['id'];
            self::assertTrue(is_demo_showcase_email((string) $order['Eaddress']), "Order $id belongs to a showcase customer");
            $created = new DateTimeImmutable((string) $order['created_at']);
            self::assertGreaterThanOrEqual($oldest, $created, "Order $id is within the last " . DEMO_SHOWCASE_DAYS . ' days');
            self::assertLessThanOrEqual($now, $created);
            if ($previous !== null) {
                self::assertGreaterThanOrEqual($previous, $created, 'Orders were written in the order they were placed');
            }
            $previous = $created;

            // Amounts: catalog prices (VAT included) in minor units, as create_pending_order() records them.
            $items = db_all('SELECT book_id, title, unit_price, quantity, stock_taken FROM order_items WHERE order_id = ? ORDER BY id', [$id]);
            self::assertNotSame([], $items);
            $total = 0;
            foreach ($items as $item) {
                $bookId = (int) $item['book_id'];
                self::assertArrayHasKey($bookId, $catalog);
                self::assertSame($catalog[$bookId]['title'], $item['title']);
                self::assertSame($catalog[$bookId]['price'], $item['unit_price']);
                self::assertGreaterThanOrEqual(1, (int) $item['quantity']);
                self::assertLessThanOrEqual(CART_MAX_QUANTITY, (int) $item['quantity']);
                $total += money_to_minor((string) $item['unit_price']) * (int) $item['quantity'];
            }
            self::assertSame($total, (int) $order['amount_minor'], "Order $id: the total of its lines");
            self::assertSame(minor_to_decimal($total), $order['total_amount']);
            self::assertGreaterThanOrEqual(CHECKOUT_MIN_TOTAL_MINOR, $total);
            self::assertSame(config('stripe.currency'), $order['currency']);
            $breakdown = order_vat_breakdown($order);
            self::assertSame($total, $breakdown['net'] + $breakdown['vat']);

            // The delivery address the customer had saved, copied into the order.
            $saved = db_one('SELECT ' . implode(', ', ADDRESS_FIELDS) . ' FROM addresses WHERE user_id = ? AND is_default = 1', [$order['user_id']]);
            self::assertNotNull($saved);
            self::assertSame($saved, order_address($id), "Order $id has a copy of the delivery address");

            self::assertMatchesRegularExpression('/^cs_test_[0-9a-f]{24}$/D', (string) $order['stripe_session_id']);
            $stage = (string) $order['status'] === 'paid' ? (string) $order['fulfilment_status'] : (string) $order['status'];
            $stages[$stage] = ($stages[$stage] ?? 0) + 1;
            self::assertSame($stage, order_display_status($order));

            $times = self::orderTimes($order);
            foreach ($times as $column => $time) {
                if ($time !== null) {
                    self::assertGreaterThanOrEqual($created, $time, "Order $id: $column follows the order");
                    self::assertLessThanOrEqual($now, $time, "Order $id: $column is not in the future");
                    self::assertGreaterThanOrEqual($time, new DateTimeImmutable((string) $order['updated_at']), "Order $id: updated at its last change");
                }
            }

            if ($order['status'] === 'expired') {
                self::assertNull($order['paid_at']);
                self::assertNull($order['stripe_payment_intent']);
                self::assertNull($order['fulfilment_status']);
                self::assertSame([0], array_values(array_unique(array_map(static fn (array $item): int => (int) $item['stock_taken'], $items))), 'An abandoned checkout took no copies');
                continue;
            }
            self::assertNotNull($times['paid_at']);
            self::assertMatchesRegularExpression('/^pi_test_[0-9a-f]{24}$/D', (string) $order['stripe_payment_intent']);
            foreach ($items as $item) {
                self::assertSame((int) $item['quantity'], (int) $item['stock_taken'], "Order $id took its copies out of stock");
            }

            if ($order['status'] === 'refunded') {
                self::assertSame('processing', $order['fulfilment_status'], 'Refunded before it was shipped');
                self::assertNull($order['shipped_at']);
                self::assertNotNull($times['refunded_at']);
                self::assertGreaterThan($times['paid_at'], $times['refunded_at']);
                self::assertMatchesRegularExpression('/^re_test_[0-9a-f]{24}$/D', (string) $order['stripe_refund_id']);
                self::assertTrue(order_refund_restocks($order));
                continue;
            }
            self::assertSame('paid', $order['status']);
            self::assertTrue(order_is_refundable($order), 'Staff can refund it (recorded locally in a demo)');
            self::assertNull($order['refunded_at']);
            self::assertContains($order['fulfilment_status'], FULFILMENT_STATUSES);
            $shipped = $order['fulfilment_status'] !== 'processing';
            self::assertSame($shipped, $times['shipped_at'] !== null);
            self::assertSame($order['fulfilment_status'] === 'delivered', $times['delivered_at'] !== null);
            if ($shipped) {
                self::assertGreaterThan($times['paid_at'], $times['shipped_at']);
                [, $errors] = validate_shipment_input(['carrier' => (string) $order['carrier'], 'tracking_number' => (string) $order['tracking_number']]);
                self::assertSame([], $errors, "Order $id has a valid carrier and tracking number");
                self::assertNotSame('', (string) $order['carrier']);
                self::assertNotSame('', (string) $order['tracking_number']);
            } else {
                self::assertNull($order['carrier']);
                self::assertNull($order['tracking_number']);
            }
            if ($times['delivered_at'] !== null) {
                self::assertGreaterThan($times['shipped_at'], $times['delivered_at']);
            }
        }

        foreach (['processing', 'shipped', 'delivered'] as $stage) {
            self::assertGreaterThan(0, $stages[$stage] ?? 0, "Some paid orders are $stage");
        }
        self::assertSame(1, $stages['refunded'] ?? 0, 'One refunded order');
        self::assertSame(1, $stages['expired'] ?? 0, 'One abandoned checkout');
    }

    /**
     * @param array<string, mixed> $order
     * @return array{paid_at: DateTimeImmutable|null, shipped_at: DateTimeImmutable|null, delivered_at: DateTimeImmutable|null, refunded_at: DateTimeImmutable|null}
     */
    private static function orderTimes(array $order): array
    {
        $times = [];
        foreach (['paid_at', 'shipped_at', 'delivered_at', 'refunded_at'] as $column) {
            $times[$column] = is_string($order[$column]) ? new DateTimeImmutable($order[$column]) : null;
        }
        return $times;
    }

    /**
     * Sales, sold counts and stock: one sales row per line of a paid order
     * (none for refunded or abandoned orders), "sold" counts that add up those
     * lines, and every book's stock lower by exactly the copies paid orders
     * took ($stockBefore: the stock before the showcase was added; the
     * refunded order put its copies back).
     *
     * @param array<int, int> $stockBefore
     */
    protected static function assertShowcaseBookkeeping(array $stockBefore): void
    {
        self::assertSame(
            db_all(
                "SELECT oi.order_id, oi.book_id, o.user_id, oi.quantity, o.paid_at
                 FROM order_items oi JOIN orders o ON o.id = oi.order_id
                 WHERE o.status = 'paid' ORDER BY oi.order_id, oi.book_id"
            ),
            db_all('SELECT order_id, book_id, user_id, quantity, purchase_date AS paid_at FROM sales ORDER BY order_id, book_id'),
            'One sales row for each line of a paid order, at its payment'
        );

        $sold = [];
        foreach (db_all("SELECT oi.book_id, SUM(oi.quantity) AS sold FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.status = 'paid' GROUP BY oi.book_id") as $row) {
            $sold[(int) $row['book_id']] = (int) $row['sold'];
        }
        self::assertNotSame([], $sold);
        foreach (array_keys($stockBefore) as $bookId) {
            self::assertSame($sold[$bookId] ?? 0, book_sales_count($bookId), "Copies of book $bookId sold");
        }
        foreach (best_selling_books(50) as $book) {
            self::assertSame($sold[(int) $book['id']] ?? 0, (int) $book['sold']);
        }

        $expected = $stockBefore;
        foreach (db_all(
            "SELECT oi.book_id, SUM(oi.stock_taken) AS taken
             FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE o.status = 'paid' OR (o.status = 'refunded' AND o.fulfilment_status IN ('shipped', 'delivered'))
             GROUP BY oi.book_id"
        ) as $row) {
            $expected[(int) $row['book_id']] -= (int) $row['taken'];
        }
        self::assertSame($expected, self::stockByBook(), 'The stock went down by the copies the paid orders took');
        self::assertGreaterThanOrEqual(DEMO_MIN_STOCK, min(self::stockByBook()), 'Never below the demo minimum (and never negative)');
    }

    /**
     * Reviews only by customers who bought and received the book, in their
     * own language, rated 3 to 5; reactions by other customers.
     */
    protected static function assertShowcaseReviews(): void
    {
        $now = self::databaseNow();
        $reviews = db_all('SELECT c.id, c.book_id, c.comment, c.rating, c.created_at, u.id AS user_id, u.locale FROM comments c LEFT JOIN users u ON u.Eaddress = c.username ORDER BY c.id');
        self::assertNotSame([], $reviews);
        foreach ($reviews as $review) {
            $id = (int) $review['id'];
            self::assertNotNull($review['user_id'], "Review $id has an author");
            self::assertTrue(user_has_purchased((int) $review['user_id'], (int) $review['book_id']), "Review $id: the author bought the book");
            $delivered = db_value(
                "SELECT MIN(o.delivered_at) FROM orders o JOIN order_items oi ON oi.order_id = o.id
                 WHERE o.user_id = ? AND oi.book_id = ? AND o.status = 'paid' AND o.fulfilment_status = 'delivered'",
                [$review['user_id'], $review['book_id']]
            );
            self::assertNotNull($delivered, "Review $id: the book was delivered");
            $written = new DateTimeImmutable((string) $review['created_at']);
            self::assertGreaterThan(new DateTimeImmutable((string) $delivered), $written, "Review $id: written after the delivery");
            self::assertLessThanOrEqual($now, $written);
            self::assertGreaterThanOrEqual(3, (int) $review['rating']);
            self::assertLessThanOrEqual(5, (int) $review['rating']);
            self::assertNotSame('', trim((string) $review['comment']));
            self::assertLessThanOrEqual(COMMENT_MAX_LENGTH, mb_strlen((string) $review['comment']));
            self::assertSame($review['locale'] === 'ar' ? 1 : 0, preg_match('/\p{Arabic}/u', (string) $review['comment']), "Review $id is in its author's language");
        }

        self::assertSame(0, (int) db_value(
            'SELECT COUNT(*) FROM comment_interactions ci JOIN comments c ON c.id = ci.comment_id JOIN users u ON u.id = ci.user_id WHERE u.Eaddress = c.username'
        ), 'Nobody reacts to their own review');
        $reactions = [];
        foreach (db_all('SELECT reaction_type, COUNT(*) AS n FROM comment_interactions GROUP BY reaction_type') as $row) {
            $reactions[(string) $row['reaction_type']] = (int) $row['n'];
        }
        self::assertGreaterThan(0, $reactions['like'] ?? 0);
        self::assertGreaterThan(0, $reactions['dislike'] ?? 0);
        self::assertGreaterThan($reactions['dislike'] ?? 0, $reactions['like'] ?? 0, 'Mostly likes');
    }

    /**
     * The home page's lists and the admin dashboard have something to show.
     */
    protected static function assertTheStoreLooksAlive(): void
    {
        foreach (best_selling_books(8) as $book) {
            self::assertGreaterThan(0, (int) $book['sold'], 'Every best seller on the home page has sales: ' . $book['title']);
        }
        foreach (recommended_books(8) as $book) {
            self::assertNotNull($book['avg_rating'], 'Every recommended book on the home page is rated: ' . $book['title']);
        }
        self::assertCount(20, recent_orders(20), 'The dashboard lists 20 recent orders');
        self::assertGreaterThan(0, (float) db_value("SELECT SUM(total_amount) FROM orders WHERE status = 'paid'"), 'The dashboard shows revenue');
    }
}
