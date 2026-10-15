// A guest browses the catalog without an account: the home page, the Explore
// page's live search, genre tabs and pages (rendered by explore.js without a
// reload, with WebP covers), and a book page that asks them to log in to buy.
import { test, expect } from '@playwright/test';
import { labels, openIn } from './support/store.js';

/** Marks the document, so that a full page load (which drops the mark) is noticed. */
const markDocument = (page) => page.evaluate(() => {
    window.bookwiseE2eMark = true;
});
const expectSameDocument = async (page) => {
    expect(await page.evaluate(() => window.bookwiseE2eMark === true), 'the page was reloaded instead of updated in place').toBe(true);
};

const cardTitles = (page) => page.locator('#explore-results .explore-card-title').allInnerTexts();

/**
 * The cards explore.js renders: a link to the book, and the cover as a
 * <picture> whose WebP <source> the browser actually uses.
 */
async function expectScriptRenderedCards(page) {
    const cards = page.locator('#explore-results .explore-card');
    await expect(cards.first()).toBeVisible();
    const sizes = await page.locator('[data-explore]').getAttribute('data-cover-sizes');
    const first = cards.first();
    await expect(first.locator('a.explore-card-link')).toHaveAttribute('href', /^Selected\.php\?id=\d+$/);
    const source = first.locator('picture > source[type="image/webp"]');
    await expect(source).toHaveAttribute('srcset', /\.webp(\?v=\d+)? \d+w/);
    await expect(source).toHaveAttribute('sizes', sizes);
    const image = first.locator('picture > img');
    await expect(image).toHaveAttribute('alt', '');
    await expect(image).toHaveAttribute('src', /\.(png|jpe?g|gif)(\?|$)/);
    await expect.poll(() => image.evaluate((img) => img.currentSrc), { message: 'the browser uses the WebP copy' }).toMatch(/\.webp(\?|$)/);
}

for (const locale of ['en', 'ar']) {
    test.describe(`guest browsing (${locale})`, () => {
        test('the home page shows the catalog and links to it', async ({ page }) => {
            await openIn(page, 'Home_Page.php', locale);
            await expect(page.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr');
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            await expect(page.locator('.home-hero-count')).toContainText(/\d/);
            await expect(page.locator('.genre-chip').first()).toBeVisible();
            await expect(page.locator('.home-book').first()).toBeVisible();

            await page.locator('.genre-chip').first().click();
            await expect(page).toHaveURL(/Explore_Page\.php\?genre=/);
            await expect(page.locator('.genre-tab[aria-current="page"]')).toBeVisible();
        });

        test('live search, genre tabs and pages update the Explore page in place', async ({ page }) => {
            await openIn(page, 'Explore_Page.php', locale);
            await expect(page.locator('#explore-results .explore-card')).toHaveCount(24);
            const firstPage = await cardTitles(page);
            await markDocument(page);

            // Live search: typing is enough, no button press.
            const wanted = firstPage[4];
            await page.getByRole('searchbox').fill(wanted);
            await expect(page).toHaveURL(/[?&]q=/);
            // The live status names the search once its results are shown.
            await expect(page.locator('#explore-status')).toContainText(wanted);
            await expect(page.locator('#explore-results')).toHaveAttribute('aria-busy', 'false');
            expect((await cardTitles(page)).length).toBeLessThan(24);
            await expect(page.locator('#explore-results .explore-card-title').filter({ hasText: wanted }).first()).toBeVisible();
            await expectScriptRenderedCards(page);
            await expectSameDocument(page);

            // Clearing the search lists every book again.
            await page.getByRole('searchbox').fill('');
            await expect(page.locator('#explore-results .explore-card')).toHaveCount(24);

            // A genre tab.
            const tab = page.locator('.genre-tabs a.genre-tab').nth(1);
            const genre = await tab.getAttribute('data-genre');
            const genreLabel = (await tab.innerText()).trim();
            await tab.click();
            await expect(tab).toHaveAttribute('aria-current', 'page');
            await expect(page.locator('.genre-tabs a[aria-current="page"]')).toHaveCount(1);
            await expect(page).toHaveURL(new RegExp(`[?&]genre=${encodeURIComponent(genre).replace(/%20/g, '(\\+|%20)')}(&|$)`));
            await expect(page.locator('#explore-status')).toContainText(genreLabel);
            await expectScriptRenderedCards(page);
            await expectSameDocument(page);

            // Its first book belongs to that genre.
            const href = await page.locator('#explore-results a.explore-card-link').first().getAttribute('href');
            await page.goto(href);
            await expect(page.locator('.genre-link')).toHaveText(genreLabel);
            await page.goBack();

            // The pager of all books.
            await openIn(page, 'Explore_Page.php?genre=all', locale);
            await markDocument(page);
            await page.locator('#explore-pager a[data-page="2"]').first().click();
            await expect(page.locator('#explore-pager a[data-page="2"][aria-current="page"]')).toBeVisible();
            await expect(page).toHaveURL(/[?&]page=2(&|$)/);
            await expect.poll(async () => (await cardTitles(page))[0]).not.toBe(firstPage[0]);
            await expectScriptRenderedCards(page);
            await expectSameDocument(page);
        });

        test('a book page asks a guest to log in to buy', async ({ page }) => {
            await openIn(page, 'Explore_Page.php', locale);
            await page.locator('#explore-results a.explore-card-link').nth(2).click();
            await expect(page).toHaveURL(/Selected\.php\?id=\d+$/);
            const bookId = new URL(page.url()).searchParams.get('id');
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            await expect(page.locator('.book-cover picture source[type="image/webp"]')).toHaveCount(1);
            await expect(page.getByRole('button', { name: labels[locale].addToCart })).toHaveCount(0);

            // The link remembers the book: signing in leads back to it.
            const logInToBuy = page.getByRole('link', { name: labels[locale].logInToBuy });
            await expect(logInToBuy).toBeVisible();
            await logInToBuy.click();
            await expect(page).toHaveURL(new RegExp(`index\\.php\\?form=login&return=Selected\\.php%3Fid%3D${bookId}$`));
            await expect(page.locator('#panel-login')).toBeVisible();
            await expect(page.locator('html')).toHaveAttribute('lang', locale);
        });
    });
}
