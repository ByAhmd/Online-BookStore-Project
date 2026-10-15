<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use BookWise\Tests\Support\HtmlDocument;
use PHPUnit\Framework\TestCase;

/**
 * The landmark rules of the HTTP suite's page contract (assertHtmlPage()):
 * HtmlDocument::contentOutsideLandmarks() and duplicateLandmarks(), which
 * mirror axe-core's "region" and "landmark-unique" rules.
 */
final class HtmlLandmarksTest extends TestCase
{
    private static function page(string $body): HtmlDocument
    {
        return new HtmlDocument('<!DOCTYPE html><html lang="en"><head><title>Test</title></head><body>' . $body . '</body></html>');
    }

    public function testAPageMadeOfLandmarksPasses(): void
    {
        $page = self::page(
            '<a class="skip-link" href="#main">Skip</a>'
            . '<header><p class="demo-banner" role="note">Demo</p><nav aria-label="Main"><a href="/">Home</a></nav></header>'
            . '<main id="main"><section aria-labelledby="a"><h2 id="a">Books</h2>'
            . '<div role="region" aria-label="Table: Books" tabindex="0"><table></table></div></section>'
            . '<section aria-labelledby="b"><h2 id="b">Orders</h2></section><section><h2>Unnamed sections are no landmarks</h2></section>'
            . '<nav aria-label="Pages"></nav><article><header><h3>A card header is no banner</h3></header></article></main>'
            . '<footer><p>Footer</p></footer><script src="js/common.js"></script>'
        );

        self::assertSame([], $page->contentOutsideLandmarks());
        self::assertSame([], $page->duplicateLandmarks());
    }

    public function testContentOutsideLandmarksIsReported(): void
    {
        $page = self::page('<a class="skip-link" href="#main">Skip</a><p class="demo-banner" role="note">Demo</p><header></header><main id="main"></main><div>Loose</div>');

        self::assertSame(['p.demo-banner', 'div'], $page->contentOutsideLandmarks());
    }

    public function testLandmarksWithTheSameRoleAndNameAreReported(): void
    {
        $page = self::page(
            '<main><section aria-labelledby="books"><h2 id="books">Manage Books</h2>'
            . '<div role="region" aria-labelledby="books" tabindex="0"></div></section>'
            . '<nav></nav><nav></nav><nav aria-label="Main"></nav><form role="search" aria-label="Find"></form></main>'
        );

        self::assertSame(['region: Manage Books', 'navigation: '], $page->duplicateLandmarks());
    }
}
