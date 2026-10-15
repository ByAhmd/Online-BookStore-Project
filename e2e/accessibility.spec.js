// axe-core on the main pages, in English and Arabic: a serious or critical
// violation fails the test (see expectAccessible() in support/store.js).
import { test, expect } from '@playwright/test';
import {
    addBookToCart,
    buyBook,
    checkOutToPaymentPage,
    expectAccessible,
    labels,
    openIn,
    paidOrderId,
    signUp,
    startDemoAdmin,
    startDemoCustomer,
} from './support/store.js';

const profileSections = ['account', 'addresses', 'payment', 'orders', 'security', 'delete'];

for (const locale of ['en', 'ar']) {
    test.describe(`accessibility (${locale})`, () => {
        test('pages a guest sees', async ({ page }) => {
            await openIn(page, 'Home_Page.php', locale);
            await expectAccessible(page, 'home');
            const bookHref = await page.locator('.home-book-link').first().getAttribute('href');

            await page.goto('Explore_Page.php');
            await expectAccessible(page, 'explore');
            // The cards and the pager explore.js renders after a genre tab.
            await page.locator('.genre-tabs a.genre-tab').nth(1).click();
            await expect(page.locator('.genre-tabs a.genre-tab').nth(1)).toHaveAttribute('aria-current', 'page');
            await expect(page.locator('#explore-results')).toHaveAttribute('aria-busy', 'false');
            await expectAccessible(page, 'explore, genre tab');

            const pages = {
                book: bookHref,
                'sign-in': 'index.php?form=login',
                'sign-up': 'index.php?form=signup',
                'password reset': 'index.php?form=reset',
                'demo inbox (guest)': 'inbox.php',
                'error 404': 'Selected.php?id=999999999',
            };
            for (const [name, path] of Object.entries(pages)) {
                await page.goto(path);
                await expectAccessible(page, name);
            }

            // A form sent back with errors.
            await page.goto('index.php?form=signup');
            const form = page.locator('#panel-signup form');
            await form.evaluate((element) => element.setAttribute('novalidate', ''));
            await form.locator('input[name="email"]').fill('not-an-email');
            await form.locator('button[type="submit"]').click();
            await expect(page.locator('#signup-email')).toHaveAttribute('aria-invalid', 'true');
            await expectAccessible(page, 'sign-up with errors');
        });

        test('pages a customer sees', async ({ page }) => {
            // English: a customer who signed up types an address at checkout;
            // Arabic: a demo customer, whose saved address is chosen.
            if (locale === 'en') {
                await signUp(page, locale);
            } else {
                await startDemoCustomer(page, locale);
            }
            await expectAccessible(page, 'home, signed in');

            await addBookToCart(page, locale);
            await expectAccessible(page, 'cart');
            await page.getByRole('link', { name: labels[locale].proceedToCheckout }).click();
            await expectAccessible(page, 'checkout');
            await page.goBack();
            await checkOutToPaymentPage(page, locale);
            await expectAccessible(page, 'payment page');
            await page.getByRole('button', { name: labels[locale].decline }).click();
            await expect(page.getByRole('alert')).toBeVisible();
            await expectAccessible(page, 'payment page, declined');
            await page.getByRole('button', { name: labels[locale].pay }).click();
            await paidOrderId(page, locale);
            await expectAccessible(page, 'payment confirmation');
            await page.getByRole('link', { name: labels[locale].viewOrder }).click();
            await expectAccessible(page, 'order page');

            for (const section of profileSections) {
                await page.goto(`profilePage.php?section=${section}`);
                await expectAccessible(page, `profile: ${section}`);
            }

            await page.goto('inbox.php');
            await expectAccessible(page, 'demo inbox');
            await page.locator('.inbox-item').first().click();
            await expect(page.locator('.inbox-message')).toBeVisible();
            await expectAccessible(page, 'demo inbox message');
        });

        test('pages a read-only administrator sees', async ({ browser, page }) => {
            const customerContext = await browser.newContext();
            const customer = await customerContext.newPage();
            await signUp(customer);
            const orderId = await buyBook(customer);
            await customerContext.close();

            await startDemoAdmin(page, locale);
            await expectAccessible(page, 'admin dashboard');
            await page.goto('admin_orders.php');
            await expectAccessible(page, 'admin orders');
            await page.goto(`admin_order.php?id=${orderId}`);
            await expectAccessible(page, 'admin order');
        });
    });
}
