<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\AccountHelpers;
use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpTestCase;

/**
 * Accessibility fixes found by the browser tests (axe-core, see
 * e2e/accessibility.spec.js), checked on the server: screen-reader users
 * move between a page's landmarks, so every part of a page belongs to one,
 * and landmarks that share a role have names of their own. assertHtmlPage()
 * checks both rules on every page of the suite as well.
 */
final class AccessibilityMarkupTest extends HttpTestCase
{
    use AccountHelpers;

    public function testAdminTablesScrollInRegionsWithNamesOfTheirOwn(): void
    {
        [$admin] = $this->signedInAdmin();
        $customer = $this->createUser();
        db_run(
            "INSERT INTO orders (user_id, status, currency, total_amount, amount_minor, stripe_session_id, paid_at)
             VALUES (?, 'paid', 'sar', '19.99', 1999, 'cs_test_regions', NOW())",
            [$customer['id']]
        );
        $orderId = (int) db()->lastInsertId();
        db_run("INSERT INTO order_items (order_id, book_id, title, unit_price, quantity) VALUES (?, 51, 'Esabella', '19.99', 1)", [$orderId]);

        $expected = [
            'admin_dashboard.php' => [
                'en' => ['Table: Manage Books', 'Table: Recent Orders'],
                'ar' => ['جدول: إدارة الكتب', 'جدول: أحدث الطلبات'],
            ],
            'admin_orders.php' => ['en' => ['Table: Order list'], 'ar' => ['جدول: قائمة الطلبات']],
            'admin_order.php?id=' . $orderId => ['en' => ['Table: Items'], 'ar' => ['جدول: محتويات الطلب']],
        ];
        foreach (['en', 'ar'] as $locale) {
            $admin->setCookie(LOCALE_COOKIE, $locale);
            foreach ($expected as $path => $names) {
                $document = $this->assertHtmlPage($admin->get($path));
                $regions = $document->elements('//div[' . HtmlDocument::hasClass('table-scroll') . ']');
                self::assertSame($names[$locale], array_map(static fn (\DOMElement $region): string => $region->getAttribute('aria-label'), $regions), "$path ($locale)");
                foreach ($regions as $region) {
                    self::assertSame('region', $region->getAttribute('role'));
                    self::assertSame('0', $region->getAttribute('tabindex'), 'Keyboard users can scroll the table');
                    self::assertFalse($region->hasAttribute('aria-labelledby'), 'Not named after its section, which is a region already');
                }
                self::assertSame([], $document->duplicateLandmarks(), "$path ($locale)");
            }
        }
    }
}
