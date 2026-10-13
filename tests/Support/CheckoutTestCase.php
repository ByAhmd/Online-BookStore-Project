<?php

declare(strict_types=1);

namespace BookWise\Tests\Support;

/**
 * Integration tests that talk to "Stripe" through the local mock router.
 */
abstract class CheckoutTestCase extends DatabaseTestCase
{
    protected const CREATE_SESSION = '#^/v1/checkout/sessions$#';
    protected const RETRIEVE_SESSION = '#^/v1/checkout/sessions/cs_[A-Za-z0-9_]+$#';
    protected const EXPIRE_SESSION = '#^/v1/checkout/sessions/cs_[A-Za-z0-9_]+/expire$#';

    protected StripeMock $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = StripeMock::start();
        $this->stripe->reset();
    }

    /**
     * A new customer whose cart holds the given books.
     *
     * @param array<int, int> $quantities book id => quantity
     * @return array<string, mixed>
     */
    protected function customerWithCart(array $quantities): array
    {
        $user = $this->createUser();
        foreach ($quantities as $bookId => $quantity) {
            self::assertTrue(cart_add((int) $user['id'], $bookId, $quantity), "Could not add book $bookId to the cart");
        }
        return $user;
    }

    protected static function sessionIdFromUrl(string $url): string
    {
        self::assertMatchesRegularExpression('#/pay/cs_test_[A-Za-z0-9]+$#', $url, 'Expected a (mock) Stripe hosted checkout URL');
        return substr($url, strrpos($url, '/') + 1);
    }

    /**
     * @return array<string, mixed>
     */
    protected function orderForSession(string $sessionId): array
    {
        $order = db_one('SELECT * FROM orders WHERE stripe_session_id = ?', [$sessionId]);
        self::assertNotNull($order, "No order stores session $sessionId");
        return $order;
    }

    /**
     * Starts checkout for the user and returns [session id, order row].
     *
     * @param array<string, mixed> $user
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function checkout(array $user): array
    {
        $sessionId = self::sessionIdFromUrl(start_checkout($user));
        return [$sessionId, $this->orderForSession($sessionId)];
    }

    /**
     * @return array<string, mixed>
     */
    protected function reloadOrder(int $orderId): array
    {
        $order = find_order($orderId);
        self::assertNotNull($order);
        return $order;
    }

    /**
     * @return array<int, int> book id => quantity in the user's cart
     */
    protected function cartQuantities(int $userId): array
    {
        $quantities = [];
        foreach (cart_lines($userId) as $line) {
            $quantities[$line['book_id']] = $line['quantity'];
        }
        return $quantities;
    }

    /**
     * @return array<int, int> book id => quantity recorded in sales for the order
     */
    protected function salesForOrder(int $orderId): array
    {
        $sales = [];
        foreach (db_all('SELECT book_id, quantity FROM sales WHERE order_id = ? ORDER BY book_id', [$orderId]) as $row) {
            $sales[(int) $row['book_id']] = (int) $row['quantity'];
        }
        return $sales;
    }
}
