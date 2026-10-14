<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;

/**
 * Guests browse without an account: the Explore page, search, genres, book
 * pages and reviews. Buying, favorites, reviews and reactions are links to
 * the sign-in form that bring them back to the same page afterwards, and the
 * Favorites tab asks them to log in. Nothing changes until they have.
 */
final class GuestBrowsingTest extends HttpTestCase
{
    private const BOOK = 13;
    private const BOOK_URL = 'Selected.php?id=13';
    private const LOGIN_URL = 'index.php?form=login&return=Selected.php%3Fid%3D13';

    /**
     * The href of the link with the given text, as screen readers name it
     * (without aria-hidden icons); exactly one must exist.
     */
    private static function linkTo(HttpResponse $page, string $text): string
    {
        $document = $page->document();
        $links = [];
        foreach ($document->elements('//a') as $link) {
            $hidden = implode('', array_map(static fn (\DOMElement $icon): string => $icon->textContent, $document->elements('.//*[@aria-hidden="true"]', $link)));
            if (HtmlDocument::normalize(str_replace($hidden, '', $link->textContent)) === $text) {
                $links[] = $link;
            }
        }
        self::assertCount(1, $links, "One link \"$text\" on {$page->url}");
        return $links[0]->getAttribute('href');
    }

    /**
     * Signs in through the sign-in form the guest was sent to; returns the POST response.
     */
    private function logInFrom(HttpClient $guest, HttpResponse $loginPage, string $email): HttpResponse
    {
        $this->assertHtmlPage($loginPage);
        return $guest->submitForm($loginPage, 'Register.php', ['email' => $email, 'password' => self::DEFAULT_PASSWORD], ['action' => 'login']);
    }

    public function testGuestsBrowseTheCatalogSearchAndGenres(): void
    {
        $guest = $this->client();

        $explore = $this->assertHtmlPage($guest->get('Explore_Page.php'));
        self::assertSame('Showing 1–24 of 55 books in all genres.', $explore->text('//*[@id="explore-status"]'));
        self::assertCount(24, $explore->elements('//li[' . HtmlDocument::hasClass('explore-card') . ']'));

        $mystery = $this->assertHtmlPage($guest->get('Explore_Page.php?genre=Mystery&q=' . rawurlencode('the')));
        $cards = $mystery->elements('//a[' . HtmlDocument::hasClass('explore-card-link') . ']');
        self::assertNotSame([], $cards);
        foreach ($cards as $card) {
            self::assertMatchesRegularExpression('/^Selected\.php\?id=\d+$/D', $card->getAttribute('href'));
        }
        self::assertSame(['Mystery'], array_values(array_unique(array_column(
            db_all('SELECT genre FROM books WHERE id IN (' . implode(',', array_map(static fn (\DOMElement $card): int => (int) substr($card->getAttribute('href'), strlen('Selected.php?id=')), $cards)) . ')'),
            'genre'
        ))));

        $json = $guest->get('fetch_books.php?genre=Mystery');
        $this->assertStatus(200, $json);
        self::assertSame('Mystery', $json->json()['genre']);
        self::assertSame(0, $this->countRows('users'), 'Browsing creates nothing');
    }

    public function testGuestsReadBookPagesAndReviewsButGetSignInLinksInsteadOfForms(): void
    {
        $reviewer = $this->createUser(self::uniqueEmail('reviewer'), 'Nora Reviewer');
        $reactor = $this->createUser(self::uniqueEmail('reactor'));
        db_run("INSERT INTO comments (book_id, username, comment, rating) VALUES (?, ?, 'A gripping read.', 5)", [self::BOOK, $reviewer['Eaddress']]);
        db_run("INSERT INTO comment_interactions (comment_id, user_id, reaction_type) VALUES (?, ?, 'like')", [(int) db()->lastInsertId(), $reactor['id']]);

        $guest = $this->client();
        $page = $guest->get(self::BOOK_URL);
        $document = $this->assertHtmlPage($page);

        self::assertSame((string) find_book(self::BOOK)['title'], $document->text('//h1'));
        $this->assertPageText('A gripping read.', $page);
        $this->assertPageText('Nora Reviewer', $page);
        self::assertSame(
            ["\u{1F44D} 1 like", "\u{1F44E} 0 dislikes"],
            $document->texts('//li[' . HtmlDocument::hasClass('review') . ']//span[' . HtmlDocument::hasClass('reaction-tally') . ']'),
            'Reactions are shown as counts'
        );
        self::assertStringNotContainsString((string) $reviewer['Eaddress'], $page->body, 'Reviews never show email addresses');

        // No form a guest could send: links to the sign-in form instead, coming back to this book.
        self::assertSame(0, $document->count('//form[translate(@method, "post", "POST") = "POST"]'), 'No cart, favorite, review or reaction forms');
        self::assertSame(0, $document->count('//button[' . HtmlDocument::hasClass('reaction-button') . ']'));
        self::assertSame(self::LOGIN_URL, self::linkTo($page, 'Log in to buy'));
        self::assertSame(self::LOGIN_URL, self::linkTo($page, 'Log in to save favorites'));
        self::assertSame(self::LOGIN_URL, self::linkTo($page, 'Log in'), 'In "Log in and buy this book to review it."');
        $this->assertPageText('Log in and buy this book to review it.', $page);

        // Out of stock: the sign-in link stays, with the stock note.
        db_run('UPDATE books SET stock = 0 WHERE id = ?', [self::BOOK]);
        $soldOut = $guest->get(self::BOOK_URL);
        $this->assertHtmlPage($soldOut);
        $this->assertPageText('This book is out of stock right now. Please check back later.', $soldOut);
        self::assertSame(self::LOGIN_URL, self::linkTo($soldOut, 'Log in to buy'));
    }

    public function testLogInToBuyBringsTheCustomerBackToTheBook(): void
    {
        $user = $this->createUser(self::uniqueEmail('returning'));
        $guest = $this->client();

        $loginPage = $guest->get(self::linkTo($guest->get(self::BOOK_URL), 'Log in to buy'));
        $response = $this->logInFrom($guest, $loginPage, (string) $user['Eaddress']);

        $this->assertRedirect($response, self::BOOK_URL);
        $book = $guest->follow($response);
        $this->assertHtmlPage($book);
        self::assertTrue($book->document()->hasForm(self::BOOK_URL, ['action' => 'add_to_cart']), 'Now the customer can buy');
        $this->assertRedirect($this->addToCart($guest, self::BOOK, 2), 'cart.php');
        self::assertSame([self::BOOK => 2], $this->cartQuantities((int) $user['id']));
    }

    public function testSigningUpFromTheLinkAlsoComesBackToTheBook(): void
    {
        $guest = $this->client();
        $guest->get(self::linkTo($guest->get(self::BOOK_URL), 'Log in to save favorites'));

        // The visitor has no account yet and switches to the sign-up form.
        $response = $this->signUp($guest, self::uniqueEmail('new-reader'), 'New Reader', 'a-Strong-passphrase-1');

        $this->assertRedirect($response, self::BOOK_URL);
        $this->assertFlash($guest->follow($response), 'success', 'Welcome to BookWise, New Reader! Your account is ready.');
    }

    public function testGuestRequestsGoToTheSignInFormAndChangeNothing(): void
    {
        $user = $this->createUser(self::uniqueEmail('late'));
        $reviewer = $this->createUser(self::uniqueEmail('late-reviewer'));
        db_run("INSERT INTO comments (book_id, username, comment, rating) VALUES (?, ?, 'Nice.', 4)", [self::BOOK, $reviewer['Eaddress']]);
        $commentId = (int) db()->lastInsertId();

        $guest = $this->client();
        $guest->get(self::BOOK_URL);
        foreach ([
            ['action' => 'add_to_cart', 'quantity' => '1'],
            ['action' => 'toggle_favorite'],
            ['action' => 'add_review', 'rating' => '5', 'comment' => 'Forged review'],
            ['action' => 'react', 'comment_id' => (string) $commentId, 'reaction' => 'like'],
        ] as $fields) {
            $this->assertRedirect($guest->postWithToken(self::BOOK_URL, $fields), 'index.php?form=login', 303, 'Guest ' . $fields['action']);
        }
        self::assertSame(0, $this->countRows('cart') + $this->countRows('favorites') + $this->countRows('comment_interactions'));
        self::assertSame(1, $this->countRows('comments'));

        // For example a book page left open after logging out: after signing in, back to the book.
        $landing = $guest->get('index.php?form=login');
        $this->assertFlash($landing, 'info', 'Please log in to continue.');
        $this->assertRedirect($this->logInFrom($guest, $landing, (string) $user['Eaddress']), self::BOOK_URL);
    }

    public function testTheFavoritesTabAsksGuestsToLogIn(): void
    {
        $user = $this->createUser(self::uniqueEmail('favorite'));
        db_run('INSERT INTO favorites (user_id, book_id) VALUES (?, ?)', [$user['id'], self::BOOK]);

        $guest = $this->client();
        $page = $guest->get('Explore_Page.php?genre=Favorites');
        $document = $this->assertHtmlPage($page);
        self::assertSame('Please log in as a customer to see your favorites.', $document->text('//*[@id="explore-status"]'));
        self::assertSame('Your favorites are kept in your account', $document->text('//p[' . HtmlDocument::hasClass('explore-empty-title') . ']'));
        self::assertSame(0, $document->count('//li[' . HtmlDocument::hasClass('explore-card') . ']'), "Nobody's favorites are shown");
        self::assertSame('page', $document->first('//a[@data-genre="Favorites"]')?->getAttribute('aria-current'));

        // The page's script gets 401 for the tab and then loads this page instead.
        $this->assertStatus(401, $guest->get('fetch_books.php?genre=Favorites'));

        $loginPage = $guest->get(self::linkTo($page, 'Log in to see your favorites'));
        $response = $this->logInFrom($guest, $loginPage, (string) $user['Eaddress']);
        $this->assertRedirect($response, 'Explore_Page.php?genre=Favorites');
        $favorites = $guest->follow($response)->document();
        self::assertCount(1, $favorites->elements('//li[' . HtmlDocument::hasClass('explore-card') . ']'));
    }

    public function testOnlyLocalPagesAreReturnedTo(): void
    {
        $user = $this->createUser(self::uniqueEmail('safe'));
        foreach (['https://evil.example/', '//evil.example/Selected.php', "Selected.php?id=1\r\nSet-Cookie: evil=1", '/admin_dashboard.php'] as $target) {
            $guest = $this->client();
            $loginPage = $guest->get('index.php?form=login&return=' . rawurlencode($target));
            $this->assertRedirect($this->logInFrom($guest, $loginPage, (string) $user['Eaddress']), 'Home_Page.php', 303, json_encode($target) ?: '');
            self::assertNull($guest->cookie('evil'));
        }
        $this->assertHtmlPage($this->client()->get('index.php?form=login&return[]=Selected.php'));
    }

    public function testGuestPagesAreTranslated(): void
    {
        $guest = $this->client();
        $book = $guest->follow($guest->get(self::BOOK_URL . '&lang=ar'));
        $this->assertHtmlPage($book);
        self::assertSame(self::LOGIN_URL, self::linkTo($book, 'سجّل الدخول للشراء'));
        self::assertSame(self::LOGIN_URL, self::linkTo($book, 'سجّل الدخول لحفظ المفضلة'));
        $this->assertPageText('سجّل الدخول واشترِ هذا الكتاب لتتمكن من كتابة مراجعة.', $book);

        $favorites = $guest->get('Explore_Page.php?genre=Favorites');
        $this->assertHtmlPage($favorites);
        self::assertSame('تُحفظ كتبك المفضلة في حسابك', $favorites->document()->text('//p[' . HtmlDocument::hasClass('explore-empty-title') . ']'));
    }
}
