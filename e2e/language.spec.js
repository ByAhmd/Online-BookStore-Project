// The language switch in the header: Arabic pages are right-to-left, stay
// Arabic from page to page, keep what the visitor was looking at, show the
// placeholder of an empty search field right to left, and fit narrow and
// wide windows without scrolling sideways.
import { test, expect } from '@playwright/test';
import { expectNoHorizontalScroll, labels, openIn } from './support/store.js';

test('the header switches the store to Arabic and back', async ({ page }) => {
    await openIn(page, 'Explore_Page.php', 'en');
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');

    // The Explore page keeps its search when the language changes.
    await page.getByRole('searchbox').fill('Love');
    await expect(page).toHaveURL(/[?&]q=Love/);
    await page.getByRole('link', { name: labels.en.language, exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page).toHaveURL(/Explore_Page\.php\?genre=all&q=Love$/);
    await expect(page.getByRole('searchbox')).toHaveValue('Love');

    // The choice is remembered on the next page.
    await page.locator('.site-nav .nav-links a[href="Home_Page.php"]').click();
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('link', { name: labels.ar.language, exact: true })).toBeVisible();

    await page.getByRole('link', { name: labels.ar.language, exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
});

test('an empty search field shows its Arabic placeholder right to left', async ({ page }) => {
    const direction = (locator) => locator.evaluate((element) => window.getComputedStyle(element).direction);

    // The Explore search takes its direction from what is typed (dir="auto"),
    // which would make it left-to-right while empty: the placeholder
    // "مثال: Olivia Wilson" would then be read from its Latin example.
    await openIn(page, 'Explore_Page.php', 'ar');
    const search = page.getByRole('searchbox');
    await expect(search).toHaveAttribute('dir', 'auto');
    await expect(search).toHaveAttribute('placeholder', 'مثال: Olivia Wilson');
    expect(await direction(search), 'empty').toBe('rtl');
    await search.fill('Love');
    expect(await direction(search), 'with Latin text').toBe('ltr');
    await search.fill('حب');
    expect(await direction(search), 'with Arabic text').toBe('rtl');

    await openIn(page, 'Explore_Page.php', 'en');
    expect(await direction(page.getByRole('searchbox')), 'empty, in English').toBe('ltr');
});

for (const width of [375, 1280]) {
    test(`Arabic pages do not scroll sideways at ${width} px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 800 });
        await openIn(page, 'Home_Page.php', 'ar');
        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

        const bookHref = await page.locator('.home-book-link').first().getAttribute('href');
        const pages = {
            home: 'Home_Page.php',
            explore: 'Explore_Page.php',
            'explore, second page': 'Explore_Page.php?genre=all&page=2',
            book: bookHref,
            'sign-in': 'index.php?form=login',
            'sign-up': 'index.php?form=signup',
            'password reset': 'index.php?form=reset',
            about: 'About_us.php',
            inbox: 'inbox.php',
            'missing book': 'Selected.php?id=999999999',
        };
        for (const [name, path] of Object.entries(pages)) {
            await page.goto(path);
            await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
            await expectNoHorizontalScroll(page, name);
        }
    });
}
