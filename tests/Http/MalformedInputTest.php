<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Hostile but harmless input (array-valued parameters, invalid UTF-8) must be
 * rejected or handled, never crash a page with a 500.
 *
 * Invalid UTF-8 is stored unchecked (APP BUG, group "app-bug"): text fields
 * are validated for length and characters but never for UTF-8 validity.
 * input_string() keeps malformed byte sequences, and preg_match() with the /u
 * modifier returns false on them, which validate_person_name() reads as "no
 * forbidden characters". In strict SQL mode (the MySQL 8 / MariaDB default)
 * the INSERT/UPDATE then fails with SQLSTATE 22007 / error 1366 "Incorrect
 * string value"; the PDOException is unhandled, so the visitor gets HTTP 500
 * and app.log an ERROR (with APP_DEBUG=true the page also shows the table and
 * column name). Affected: sign-up name, login email (login_attempts.email),
 * profile name, review text, admin book fields. Expected: a validation error
 * like for any other unacceptable value. Fix idea: reject
 * !mb_check_encoding($value, "UTF-8") in input_string() or the validators.
 */
final class MalformedInputTest extends HttpTestCase
{
    /** Invalid UTF-8: a lone continuation byte and an overlong encoding. */
    private const BAD_UTF8 = "Bad\x80Name\xC0\xAF";

    private function assertHandled(HttpResponse $response, string $label): void
    {
        self::assertLessThan(500, $response->status, "$label must not crash.\n" . $response->describe());
        if ($response->isHtml() && $response->status !== 303) {
            $this->assertHtmlPage($response, $response->status);
        }
    }

    /**
     * Raw form body, so parameter names like "id[]" reach PHP as arrays.
     *
     * @param array<string, string> $fields
     */
    private static function body(array $fields): string
    {
        $pairs = [];
        foreach ($fields as $name => $value) {
            $pairs[] = str_replace(['%5B', '%5D'], ['[', ']'], rawurlencode($name)) . '=' . rawurlencode($value);
        }
        return implode('&', $pairs);
    }

    private function postRaw(HttpClient $client, string $target, array $fields): HttpResponse
    {
        return $client->post($target, self::body(['_token' => $client->csrfToken()] + $fields), ['Content-Type' => 'application/x-www-form-urlencoded']);
    }

    public function testArrayValuedQueryParametersAreHandled(): void
    {
        $guest = $this->client();
        foreach (['index.php?form[]=login', 'Home_Page.php?x[]=1', 'fetch_books.php?genre[]=Mystery', 'fetch_books.php?q[]=a', 'reset_form.php?token[]=abc'] as $url) {
            $this->assertHandled($guest->get($url), "Guest GET $url");
        }

        [$customer] = $this->signedInCustomer();
        foreach ([
            'Explore_Page.php?genre[]=Mystery', 'Explore_Page.php?q[]=Olivia', 'Selected.php?id[]=7', 'profilePage.php?section[]=orders',
            'success_pay.php?session_id[]=cs_test_x', 'cancel_pay.php?order[]=1',
        ] as $url) {
            $this->assertHandled($customer->get($url), "Customer GET $url");
        }

        [$admin] = $this->signedInAdmin();
        $this->assertHandled($admin->get('edit_book.php?id[]=7'), 'Admin GET edit_book.php?id[]=7');
    }

    public function testArrayValuedFormFieldsAreHandled(): void
    {
        $guest = $this->client();
        $this->assertHandled($this->postRaw($guest, 'Register.php', ['action' => 'register', 'email[]' => 'a@example.com', 'name[]' => 'x', 'password[]' => 'p', 'password_confirmation[]' => 'p']), 'register with arrays');
        $this->assertHandled($this->postRaw($guest, 'Register.php', ['action[]' => 'login', 'email' => 'a@example.com', 'password' => 'x']), 'action array');
        $this->assertHandled($this->postRaw($guest, 'Register.php', ['action' => 'login', 'email[]' => 'a@example.com', 'password[]' => 'x']), 'login with arrays');
        $this->assertHandled($this->postRaw($guest, 'reset_password.php', ['email[]' => 'a@example.com']), 'reset with array');
        $this->assertHandled($this->postRaw($guest, 'update_password.php', ['token[]' => 'x', 'password[]' => 'p']), 'update password with arrays');
        $this->assertHandled($guest->post('logout.php', self::body(['_token[]' => 'x']), ['Content-Type' => 'application/x-www-form-urlencoded']), 'token array');

        [$customer, $user] = $this->signedInCustomer();
        db_run('INSERT INTO sales (book_id, user_id, quantity) VALUES (7, ?, 1)', [$user['id']]);
        $this->assertRedirect($this->addToCart($customer, 7, 1), 'cart.php');
        foreach ([
            ['Selected.php?id=7', ['action' => 'add_to_cart', 'quantity[]' => '2']],
            ['Selected.php?id=7', ['action' => 'add_review', 'rating[]' => '5', 'comment[]' => 'x']],
            ['Selected.php?id=7', ['action' => 'react', 'comment_id[]' => '1', 'reaction[]' => 'like']],
            ['update_cart.php', ['cart_id[]' => '1', 'quantity[]' => '2']],
            ['delete_from_cart.php', ['cart_id[]' => '1']],
            ['profilePage.php', ['action' => 'update_profile', 'name[]' => 'x', 'favorite_genre[]' => 'y', 'date_of_birth[]' => 'z']],
            ['profilePage.php', ['action' => 'update_payment', 'payment_method[]' => 'Apple Pay']],
        ] as [$target, $fields]) {
            $this->assertHandled($this->postRaw($customer, $target, $fields), "POST $target " . json_encode($fields));
        }
        self::assertSame([7 => 1], $this->cartQuantities((int) $user['id']));
        self::assertSame(0, $this->countRows('comments'));

        [$admin] = $this->signedInAdmin();
        $this->assertHandled($this->postRaw($admin, 'admin_dashboard.php', ['action' => 'add_book', 'title[]' => 'x', 'price[]' => '1', 'cover_image[]' => 'y']), 'add book with arrays');
        $this->assertHandled($this->postRaw($admin, 'admin_dashboard.php', ['action' => 'delete_book', 'id[]' => '7']), 'delete with array id');
        $this->assertHandled($this->postRaw($admin, 'edit_book.php?id=7', ['action' => 'update_book', 'title[]' => 'x']), 'edit with arrays');
        self::assertNotNull(find_book(7));
        self::assertSame(self::SEED_BOOK_COUNT, $this->countRows('books'));
    }

    public function testInvalidUtf8InSearchesIsHandled(): void
    {
        [$customer] = $this->signedInCustomer();
        $page = $customer->get('Explore_Page.php?q=' . rawurlencode(self::BAD_UTF8));
        $this->assertHandled($page, 'Explore search with invalid UTF-8');
        $this->assertHandled($customer->get('Explore_Page.php?genre=Favorites&q=' . rawurlencode(self::BAD_UTF8)), 'Favorites search with invalid UTF-8');
    }

    /**
     * The JSON endpoint echoes the search term back in "q".
     *
     * APP BUG: fetch_books.php?q=<invalid UTF-8> answers HTTP 500. The raw term
     * is echoed back in the "q" field and json_response() encodes with
     * JSON_THROW_ON_ERROR (without JSON_INVALID_UTF8_SUBSTITUTE), so json_encode()
     * throws JsonException, which the exception handler turns into an HTML 500
     * page on a JSON endpoint (and an ERROR in app.log). Explore_Page.php handles
     * the same term fine because e() uses ENT_SUBSTITUTE. Expected: a JSON
     * response (the term substituted or rejected with 400).
     */
    #[Group('app-bug')]
    public function testInvalidUtf8InTheJsonApiIsHandled(): void
    {
        $response = $this->client()->get('fetch_books.php?q=' . rawurlencode(self::BAD_UTF8));
        $this->assertHandled($response, 'fetch_books.php with invalid UTF-8');
        self::assertIsArray($response->json());
    }

    /**
     * Sign-up with a name that is not valid UTF-8.
     *
     * APP BUG: HTTP 500 instead of a validation error; see "Invalid UTF-8 is
     * stored unchecked" in the class docblock.
     */
    #[Group('app-bug')]
    public function testInvalidUtf8InSignUpIsHandled(): void
    {
        $client = $this->client();
        $response = $this->signUp($client, self::uniqueEmail('utf8'), self::BAD_UTF8, 'valid-password-1');
        $this->assertHandled($response, 'sign-up with an invalid UTF-8 name');
        $this->assertRedirect($response, 'index.php?form=signup', 303, 'An unstorable name is a validation error');
        self::assertSame(0, $this->countRows('users'));
    }

    /**
     * Login with an email that is not valid UTF-8: the failed attempt cannot be
     * stored in login_attempts.email, so the login form answers 500 instead of
     * "Incorrect email or password." (and the attempt is never counted).
     *
     * APP BUG: HTTP 500 instead of a validation error; see "Invalid UTF-8 is
     * stored unchecked" in the class docblock.
     */
    #[Group('app-bug')]
    public function testInvalidUtf8InLoginIsHandled(): void
    {
        $client = $this->client();
        $response = $this->logIn($client, "reader\x80@example.com", 'whatever-password');
        $this->assertHandled($response, 'login with an invalid UTF-8 email');
        $this->assertRedirect($response, 'index.php?form=login');
    }

    /**
     * Profile update with a name that is not valid UTF-8.
     *
     * APP BUG: HTTP 500 instead of a validation error; see "Invalid UTF-8 is
     * stored unchecked" in the class docblock.
     */
    #[Group('app-bug')]
    public function testInvalidUtf8InProfileIsHandled(): void
    {
        [$customer, $user] = $this->signedInCustomer('Valid Name');
        $page = $customer->get('profilePage.php?section=account');
        $response = $customer->submitForm($page, 'profilePage.php', ['name' => self::BAD_UTF8], ['action' => 'update_profile']);
        $this->assertHandled($response, 'profile update with an invalid UTF-8 name');
        $this->assertRedirect($response, 'profilePage.php?section=account');
        self::assertSame('Valid Name', find_user((int) $user['id'])['name']);
    }

    /**
     * Review text that is not valid UTF-8.
     *
     * APP BUG: HTTP 500 instead of a validation error; see "Invalid UTF-8 is
     * stored unchecked" in the class docblock.
     */
    #[Group('app-bug')]
    public function testInvalidUtf8InReviewIsHandled(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        db_run('INSERT INTO sales (book_id, user_id, quantity) VALUES (7, ?, 1)', [$user['id']]);
        $page = $this->bookPage($customer, 7);
        $response = $customer->submitForm($page, 'Selected.php?id=7', ['rating' => '4', 'comment' => self::BAD_UTF8], ['action' => 'add_review']);
        $this->assertHandled($response, 'review with invalid UTF-8');
        $this->assertStatus(303, $response);
    }

    /**
     * Admin book form with a title that is not valid UTF-8.
     *
     * APP BUG: HTTP 500 instead of a validation error; see "Invalid UTF-8 is
     * stored unchecked" in the class docblock.
     */
    #[Group('app-bug')]
    public function testInvalidUtf8InBookFormIsHandled(): void
    {
        [$admin] = $this->signedInAdmin();
        $dashboard = $admin->get('admin_dashboard.php');
        $response = $admin->submitForm($dashboard, 'admin_dashboard.php', [
            'title' => self::BAD_UTF8,
            'author' => 'Author',
            'genre' => 'Mystery',
            'description' => 'Description',
            'price' => '10.00',
            'cover_image' => 'imges/All_section/dark.png',
        ], ['action' => 'add_book']);
        $this->assertHandled($response, 'add book with an invalid UTF-8 title');
        $this->assertStatus(303, $response);
    }
}
