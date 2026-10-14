<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;
use DOMElement;

/**
 * Covers are shown as <picture> elements with WebP copies and the stored
 * image as the fallback, on every page that shows one; pages link their CSS,
 * scripts and images with versioned URLs and are never cached themselves.
 */
final class CoverPicturesTest extends HttpTestCase
{
    /** Book 7 is "Biology" (imges/All_section/biology.png). */
    private const BOOK_ID = 7;
    private const COVER = 'imges/All_section/biology.png';

    /**
     * Pictures in the page (optionally below an element with the given class),
     * as [source, img]; source is null when the picture has no WebP copies.
     *
     * @return list<array{0: DOMElement|null, 1: DOMElement}>
     */
    private static function pictures(HttpResponse $page, ?string $within = null): array
    {
        $document = $page->document();
        $scope = $within === null ? '' : '//*[' . HtmlDocument::hasClass($within) . ']';
        $pictures = [];
        foreach ($document->elements($scope . '//picture') as $picture) {
            $img = $document->first('.//img', $picture);
            self::assertNotNull($img, "A <picture> without <img> on {$page->url}");
            $pictures[] = [$document->first('./source', $picture), $img];
        }
        self::assertNotSame([], $pictures, "No covers on {$page->url}" . ($within !== null ? " in .$within" : ''));
        return $pictures;
    }

    /**
     * Every picture offers the WebP copies of a catalog cover with $sizes, and
     * its <img> is the versioned original with the given attributes.
     *
     * @param array<string, string> $attributes
     */
    private function assertCatalogCovers(HttpResponse $page, string $within, string $sizes, array $attributes): void
    {
        foreach (self::pictures($page, $within) as [$source, $img]) {
            self::assertNotNull($source, "Missing WebP <source> on {$page->url}");
            self::assertSame('image/webp', $source->getAttribute('type'));
            self::assertSame($sizes, $source->getAttribute('sizes'), $page->url);
            self::assertMatchesRegularExpression('#^imges/\S+-w190\.webp\?v=\d+ 190w, imges/\S+\.webp\?v=\d+ 380w$#', $source->getAttribute('srcset'));
            self::assertMatchesRegularExpression('#^imges/\S+\.(png|jpe?g)\?v=\d+$#', $img->getAttribute('src'));
            // The WebP copies belong to the same image as the fallback.
            $stem = (string) preg_replace('/\.(png|jpe?g)\?v=\d+$/', '', $img->getAttribute('src'));
            self::assertSame([$stem . '-w190.webp', $stem . '.webp'], array_map(
                static fn (string $url): string => (string) strtok($url, '?'),
                HtmlDocument::srcsetUrls($source->getAttribute('srcset'))
            ));
            foreach ($attributes as $name => $value) {
                self::assertSame($value, $img->getAttribute($name), "<img $name> on {$page->url}");
            }
        }
    }

    public function testStorefrontPagesShowWebpCovers(): void
    {
        [$customer] = $this->signedInCustomer();

        $home = $customer->get('Home_Page.php');
        $this->assertHtmlPage($home); // also loads every srcset URL
        $this->assertCatalogCovers($home, 'home-book-cover', '(min-width: 500px) 174px, calc(40vw - 26px)', [
            'alt' => '', 'width' => '380', 'height' => '605', 'loading' => 'lazy', 'decoding' => 'async',
        ]);

        $explore = $customer->get('Explore_Page.php');
        $this->assertHtmlPage($explore);
        $sizes = (string) $explore->document()->first('//*[@data-explore]')?->getAttribute('data-cover-sizes');
        self::assertSame('(min-width: 600px) 240px, 50vw', $sizes, 'explore.js gets the same sizes as the page');
        $this->assertCatalogCovers($explore, 'explore-card-cover', $sizes, ['alt' => '', 'width' => '380', 'height' => '605', 'loading' => 'lazy']);
        self::assertCount(CATALOG_PAGE_SIZE, self::pictures($explore, 'explore-grid'));

        $book = $this->bookPage($customer, self::BOOK_ID);
        $this->assertHtmlPage($book);
        $this->assertCatalogCovers($book, 'book-cover', '(max-width: 720px) 220px, 260px', [
            'class' => 'pic', 'alt' => 'Cover of Biology', 'width' => '260', 'height' => '414', 'fetchpriority' => 'high', 'loading' => '',
        ]);
        self::assertSame(asset(self::COVER), $book->document()->first('//img[' . HtmlDocument::hasClass('pic') . ']')?->getAttribute('src'));
        $this->assertCatalogCovers($book, 'similar-books-container', '(max-width: 480px) 50vw, 150px', ['class' => 'book-cover', 'loading' => 'lazy']);

        $this->assertRedirect($this->addToCart($customer, self::BOOK_ID, 1), 'cart.php');
        $cart = $customer->get('cart.php');
        $this->assertHtmlPage($cart);
        $this->assertCatalogCovers($cart, 'cart-book-link', '56px', ['class' => 'cart-cover', 'width' => '56', 'height' => '80']);
    }

    public function testAdminPagesShowWebpCovers(): void
    {
        [$admin] = $this->signedInAdmin();

        $dashboard = $admin->get('admin_dashboard.php');
        $this->assertHtmlPage($dashboard);
        $this->assertCatalogCovers($dashboard, 'cover-cell', '48px', ['width' => '48', 'height' => '68', 'loading' => 'lazy']);

        $edit = $admin->get('edit_book.php?id=' . self::BOOK_ID);
        $this->assertHtmlPage($edit);
        $this->assertCatalogCovers($edit, 'cover-preview', '220px', ['alt' => 'Current cover of “Biology”', 'width' => '220', 'height' => '320']);
    }

    public function testCoversWithoutCopiesFallBackToAPlainImage(): void
    {
        $remote = $this->insertBook(['title' => 'Remote Cover', 'genre' => 'Science', 'cover_image' => 'https://images.example.com/cover.png']);
        db_run('UPDATE books SET stock = 5 WHERE id = ?', [$remote]);
        [$customer] = $this->signedInCustomer();

        $page = $this->bookPage($customer, $remote);
        $this->assertHtmlPage($page);
        [[$source, $img]] = self::pictures($page, 'book-cover');
        self::assertNull($source, 'An external cover has no WebP copies');
        self::assertSame('https://images.example.com/cover.png', $img->getAttribute('src'));
    }

    public function testWebpCopiesAreServedAsWebp(): void
    {
        $client = $this->client();
        foreach (HtmlDocument::srcsetUrls(webp_srcset(self::COVER)) as $url) {
            $response = $client->get($url);
            $this->assertStatus(200, $response);
            self::assertStringStartsWith('image/webp', $response->contentType(), $url);
            self::assertStringStartsWith('RIFF', $response->body);
        }
    }

    public function testPagesLinkVersionedAssetsAndAreNotCached(): void
    {
        $guest = $this->client();
        $page = $guest->get('index.php?form=login');
        $document = $this->assertHtmlPage($page);

        $stylesheets = array_map(static fn (DOMElement $link): string => $link->getAttribute('href'), $document->elements('//link[@rel="stylesheet"][not(starts-with(@href, "https:"))]'));
        $scripts = array_map(static fn (DOMElement $script): string => $script->getAttribute('src'), $document->elements('//script[@src]'));
        self::assertSame([asset('css/common.css'), asset('css/sign.css')], $stylesheets);
        self::assertSame([asset('js/common.js'), asset('js/index.js')], $scripts);
        foreach ([...$stylesheets, ...$scripts, $document->first('//button[' . HtmlDocument::hasClass('password-toggle') . ']//img')?->getAttribute('src')] as $url) {
            self::assertMatchesRegularExpression('#^(css|js|imges)/[\w.-]+\?v=\d+$#', (string) $url);
        }

        // HTML is never cached: only the versioned static files are (Apache rules).
        foreach ([$page, $guest->get('Home_Page.php')] as $html) {
            self::assertStringContainsString('no-store', (string) $html->header('Cache-Control'), $html->url);
        }
    }
}
