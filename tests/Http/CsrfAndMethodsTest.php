<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpTestCase;

/**
 * Every state-changing endpoint rejects requests without the session's CSRF
 * token (419, nothing changes), and POST-only endpoints refuse GET (405).
 */
final class CsrfAndMethodsTest extends HttpTestCase
{
    /**
     * Posts $fields three ways (no token, a made-up token, another visitor's
     * valid token) and asserts 419 and an unchanged database each time.
     *
     * @param array<string, scalar> $fields
     * @param callable(): mixed $state Snapshot of everything the request could change
     */
    private function assertRejectedWithoutToken(HttpClient $client, string $endpoint, array $fields, callable $state): void
    {
        $before = $state();
        $foreignToken = $this->client()->csrfToken();
        $variants = [
            'no token' => $fields,
            'invented token' => ['_token' => bin2hex(random_bytes(32))] + $fields,
            'token of another session' => ['_token' => $foreignToken] + $fields,
            'empty token' => ['_token' => ''] + $fields,
        ];
        foreach ($variants as $label => $body) {
            $response = $client->post($endpoint, $body);
            $this->assertStatus(419, $response, "POST $endpoint with $label");
            $this->assertHtmlPage($response, 419);
            $this->assertPageText('Page expired', $response);
            self::assertSame($before, $state(), "POST $endpoint with $label must not change anything");
        }
    }

    public function testGuestEndpointsRequireTheToken(): void
    {
        $guest = $this->client();
        $user = $this->createUser(self::uniqueEmail('csrf-guest'));
        $email = (string) $user['Eaddress'];

        $newEmail = self::uniqueEmail('csrf-signup');
        $this->assertRejectedWithoutToken(
            $guest,
            'Register.php',
            ['action' => 'register', 'email' => $newEmail, 'name' => 'Forged', 'password' => 'forged-password', 'password_confirmation' => 'forged-password'],
            fn (): int => $this->countRows('users')
        );

        $sessionBefore = $guest->cookie(self::SESSION_COOKIE);
        $this->assertRejectedWithoutToken(
            $guest,
            'Register.php',
            ['action' => 'login', 'email' => $email, 'password' => self::DEFAULT_PASSWORD],
            fn (): array => [$this->countRows('login_attempts'), $guest->cookie(self::SESSION_COOKIE)]
        );
        self::assertSame($sessionBefore, $guest->cookie(self::SESSION_COOKIE));
        self::assertFalse($this->isSignedInCustomer($guest), 'Login CSRF must not sign the victim in');

        $this->assertRejectedWithoutToken(
            $guest,
            'reset_password.php',
            ['email' => $email],
            fn (): array => [$this->countRows('reset_tokens'), $this->newMail()]
        );

        // update_password.php with a genuine, unused reset token.
        $resetClient = $this->client();
        $this->assertRedirect($resetClient->postWithToken('reset_password.php', ['email' => $email]), 'index.php?form=login');
        self::assertSame(1, preg_match('/reset_form\.php\?token=([a-f0-9]{64})/', $this->newMail(), $match));
        $hashBefore = db_value('SELECT pass FROM users WHERE id = ?', [$user['id']]);
        $this->assertRejectedWithoutToken(
            $guest,
            'update_password.php',
            ['token' => $match[1], 'password' => 'forged-password-1', 'password_confirmation' => 'forged-password-1'],
            fn (): array => [db_value('SELECT pass FROM users WHERE id = ?', [$user['id']]), $this->countRows('reset_tokens')]
        );
        self::assertSame($hashBefore, db_value('SELECT pass FROM users WHERE id = ?', [$user['id']]));
        $this->assertStatus(200, $guest->get('reset_form.php?token=' . $match[1]), 'The reset link is still valid');
    }

    public function testCustomerEndpointsRequireTheToken(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $userId = (int) $user['id'];
        $this->assertRedirect($this->addToCart($customer, 7, 2), 'cart.php');
        $lineId = $this->cartLineId($userId, 7);

        // Make the review and reaction paths reachable: a purchase and an existing review.
        db_run('INSERT INTO sales (book_id, user_id, quantity) VALUES (?, ?, 1)', [51, $userId]);
        $reviewer = $this->createUser(self::uniqueEmail('csrf-reviewer'));
        db_run('INSERT INTO comments (book_id, username, comment, rating) VALUES (?, ?, ?, ?)', [51, $reviewer['Eaddress'], 'Great.', 5]);
        $commentId = (int) db()->lastInsertId();

        $selected = fn (): array => [
            $this->cartQuantities($userId),
            $this->countRows('favorites'),
            $this->countRows('comments'),
            $this->countRows('comment_interactions'),
        ];
        $this->assertRejectedWithoutToken($customer, 'Selected.php?id=51', ['action' => 'add_to_cart', 'quantity' => '1'], $selected);
        $this->assertRejectedWithoutToken($customer, 'Selected.php?id=51', ['action' => 'toggle_favorite'], $selected);
        $this->assertRejectedWithoutToken($customer, 'Selected.php?id=51', ['action' => 'add_review', 'rating' => '5', 'comment' => 'Forged review'], $selected);
        $this->assertRejectedWithoutToken($customer, 'Selected.php?id=51', ['action' => 'react', 'comment_id' => (string) $commentId, 'reaction' => 'like'], $selected);

        $cart = fn (): array => $this->cartQuantities($userId);
        $this->assertRejectedWithoutToken($customer, 'update_cart.php', ['cart_id' => (string) $lineId, 'quantity' => '9'], $cart);
        $this->assertRejectedWithoutToken($customer, 'delete_from_cart.php', ['cart_id' => (string) $lineId], $cart);

        $this->assertRejectedWithoutToken(
            $customer,
            'checkout.php',
            [],
            fn (): array => [$this->countRows('orders'), count($this->stripe->sessions())]
        );

        $profile = fn (): ?array => find_user($userId);
        $this->assertRejectedWithoutToken(
            $customer,
            'profilePage.php',
            ['action' => 'update_profile', 'name' => 'Forged Name', 'favorite_genre' => 'Drama', 'date_of_birth' => '1990-01-01'],
            $profile
        );
        $this->assertRejectedWithoutToken($customer, 'profilePage.php', ['action' => 'update_payment', 'payment_method' => 'Apple Pay'], $profile);

        $this->assertRejectedWithoutToken($customer, 'logout.php', [], fn (): bool => $this->isSignedInCustomer($customer));
        self::assertTrue($this->isSignedInCustomer($customer));

        // The real forms (with the token) still work, so the 419s above were about the token.
        $this->assertRedirect($customer->postWithToken('update_cart.php', ['cart_id' => (string) $lineId, 'quantity' => '3']), 'cart.php');
        self::assertSame([7 => 3], $this->cartQuantities($userId));
    }

    public function testAdminEndpointsRequireTheToken(): void
    {
        [$admin] = $this->signedInAdmin();
        $book = [
            'title' => 'Forged Book',
            'author' => 'Nobody',
            'genre' => 'Mystery',
            'description' => 'Should never be stored.',
            'price' => '9.99',
            'cover_image' => 'imges/All_section/biology.png',
        ];
        $catalog = fn (): array => db_all('SELECT id, title, author, genre, description, price, cover_image FROM books ORDER BY id');

        $this->assertRejectedWithoutToken($admin, 'admin_dashboard.php', ['action' => 'add_book'] + $book, $catalog);
        $this->assertRejectedWithoutToken($admin, 'admin_dashboard.php', ['action' => 'delete_book', 'id' => '7'], $catalog);
        $this->assertRejectedWithoutToken($admin, 'edit_book.php?id=7', ['action' => 'update_book'] + $book, $catalog);
        self::assertNotNull(find_book(7));

        // Refunds move money: never without the token.
        $customer = $this->createUser();
        db_run(
            "INSERT INTO orders (user_id, status, currency, total_amount, amount_minor, stripe_payment_intent, paid_at)
             VALUES (?, 'paid', 'sar', '19.99', 1999, 'pi_test_csrf', NOW())",
            [$customer['id']]
        );
        $orderId = (int) db()->lastInsertId();
        $this->assertRejectedWithoutToken(
            $admin,
            'admin_order.php?id=' . $orderId,
            ['action' => 'refund'],
            fn (): array => [find_order($orderId), $this->stripe->refunds()]
        );
    }

    public function testTokenMayComeFromTheXCsrfTokenHeader(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        $favorites = fn (): int => $this->countRows('favorites', 'user_id = ?', [$user['id']]);

        $response = $customer->post('Selected.php?id=7', ['action' => 'toggle_favorite'], ['X-CSRF-Token' => $customer->csrfToken()]);
        $this->assertRedirect($response, 'Selected.php?id=7#book');
        self::assertSame(1, $favorites());

        $this->assertStatus(419, $customer->post('Selected.php?id=7', ['action' => 'toggle_favorite'], ['X-CSRF-Token' => str_repeat('f', 64)]));
        $this->assertStatus(419, $customer->post('Selected.php?id=7', ['action' => 'toggle_favorite'], ['X-CSRF-Token' => $this->client()->csrfToken()]));
        self::assertSame(1, $favorites());
    }

    public function testPostOnlyEndpointsRejectGet(): void
    {
        [$customer] = $this->signedInCustomer();
        foreach (['Register.php', 'reset_password.php', 'update_password.php', 'update_cart.php', 'delete_from_cart.php', 'checkout.php'] as $endpoint) {
            $response = $customer->get($endpoint . '?cart_id=1&quantity=2&email=x%40example.com');
            $this->assertHtmlPage($response, 405);
            self::assertSame('POST', $response->header('Allow'), "GET $endpoint must advertise Allow: POST");
        }

        // Logging out by GET is a harmless redirect that keeps the session.
        $this->assertRedirect($customer->get('logout.php'), 'Home_Page.php');
        self::assertTrue($this->isSignedInCustomer($customer));

        $webhook = $customer->get('stripe_webhook.php');
        $this->assertStatus(405, $webhook);
        self::assertSame('POST', $webhook->header('Allow'));
        self::assertSame(['error' => 'Method not allowed'], $webhook->json());

        // GET-only endpoints reject POST.
        $fetch = $customer->postWithToken('fetch_books.php', ['genre' => 'all']);
        $this->assertStatus(405, $fetch);
        self::assertSame('GET, HEAD', $fetch->header('Allow'));
        $resetForm = $customer->postWithToken('reset_form.php?token=' . str_repeat('a', 64));
        $this->assertHtmlPage($resetForm, 405);
        self::assertSame('GET, HEAD', $resetForm->header('Allow'));

        // Pages that accept GET and POST reject other verbs.
        $this->assertHtmlPage($customer->request('PUT', 'profilePage.php', ['action' => 'update_payment']), 405);
        [$admin] = $this->signedInAdmin();
        $this->assertHtmlPage($admin->request('DELETE', 'admin_dashboard.php?id=7'), 405);
        $this->assertHtmlPage($admin->request('PATCH', 'edit_book.php?id=7'), 405);
        self::assertNotNull(find_book(7));
    }
}
