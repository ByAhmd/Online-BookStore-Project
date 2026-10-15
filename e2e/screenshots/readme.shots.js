// The README screenshots: npm run screenshots (see docs/TESTING.md).
//
// Takes them from the store at BASE_URL (or the local store), as a guest,
// a one-click demo customer who buys a book, and an administrator, and writes
// them as JPEG files into docs/screenshots/: home, explore, book, cart,
// checkout, payment-simulator, order, inbox, admin-dashboard, admin-orders,
// the Arabic home-ar and order-ar, and on a phone (375 px wide) mobile (the
// home page) and mobile-order-ar (the order page in Arabic). With
// SCREENSHOT_ADMIN_EMAIL and SCREENSHOT_ADMIN_PASSWORD (an owner
// administrator of that store, such as the local one of docker-compose.yml),
// the order is marked shipped first, so its timeline shows the carrier and
// tracking number, and the admin pages are the owner's; otherwise the
// read-only demo administrator's.
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';
import { addBookToCart, checkOutToPaymentPage, labels, openIn, paidOrderId, startDemoAdmin, startDemoCustomer } from '../support/store.js';

const folder = fileURLToPath(new URL('../../docs/screenshots/', import.meta.url));
const desktop = { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 };
const phone = { viewport: { width: 375, height: 812 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true };
const owner = { email: process.env.SCREENSHOT_ADMIN_EMAIL || '', password: process.env.SCREENSHOT_ADMIN_PASSWORD || '' };

/** Saves what the window shows, once fonts and images have loaded, as docs/screenshots/<name>.jpg. */
async function shoot(page, name) {
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: `${folder}${name}.jpg`, type: 'jpeg', quality: 78, animations: 'disabled', caret: 'hide' });
}

/** Signs an owner administrator in with the sign-in form. */
async function signInOwner(page) {
    await openIn(page, 'index.php?form=login', 'en');
    const form = page.locator('#panel-login form');
    await form.locator('input[name="email"]').fill(owner.email);
    await form.locator('input[name="password"]').fill(owner.password);
    await form.locator('button[type="submit"]').click();
    await expect(page, 'SCREENSHOT_ADMIN_EMAIL and SCREENSHOT_ADMIN_PASSWORD must belong to an owner administrator').toHaveURL(/admin_dashboard\.php/);
}

test.use(desktop);

test('README screenshots', async ({ browser, page }) => {
    mkdirSync(folder, { recursive: true });

    // A guest.
    await openIn(page, 'Home_Page.php', 'en');
    await shoot(page, 'home');
    await page.goto('Explore_Page.php');
    await shoot(page, 'explore');
    await openIn(page, 'Home_Page.php', 'ar');
    await shoot(page, 'home-ar');

    // A demo customer buys a book.
    await startDemoCustomer(page, 'en');
    await page.locator('.home-book-link').first().click();
    await expect(page.locator('#book-title')).toBeVisible();
    await shoot(page, 'book');
    await addBookToCart(page, 'en');
    await shoot(page, 'cart');
    await page.getByRole('link', { name: labels.en.proceedToCheckout }).click();
    await shoot(page, 'checkout');
    await page.goBack();
    await checkOutToPaymentPage(page, 'en');
    await shoot(page, 'payment-simulator');
    await page.getByRole('button', { name: labels.en.pay }).click();
    const orderId = await paidOrderId(page, 'en');

    // An administrator (the owner ships the order first, when given).
    const adminContext = await browser.newContext(desktop);
    const admin = await adminContext.newPage();
    if (owner.email !== '' && owner.password !== '') {
        await signInOwner(admin);
        await admin.goto(`admin_order.php?id=${orderId}`);
        await admin.locator('#shipment-carrier').fill('SMSA Express');
        await admin.locator('#shipment-tracking_number').fill('SA1234567890');
        await admin.getByRole('button', { name: 'Mark as shipped' }).click();
        await expect(admin.locator('.order-fulfilment .fulfilment-badge')).toHaveClass(/status-shipped/);
    } else {
        await startDemoAdmin(admin, 'en');
    }
    // Without the welcome message.
    await admin.goto('admin_dashboard.php');
    await shoot(admin, 'admin-dashboard');
    // The showcase's orders at every stage of their delivery.
    await admin.goto('admin_orders.php');
    await expect(admin.locator('.order-list-table tbody tr').first()).toBeVisible();
    await shoot(admin, 'admin-orders');
    await adminContext.close();

    await page.goto(`order.php?id=${orderId}`);
    await expect(page.locator('.order-timeline')).toBeVisible();
    await shoot(page, 'order');
    await page.goto('inbox.php');
    await expect(page.locator('.inbox-item').first()).toBeVisible();
    await shoot(page, 'inbox');
    // Last, as switching the language also changes the account's language.
    await openIn(page, `order.php?id=${orderId}`, 'ar');
    await expect(page.locator('.order-timeline')).toBeVisible();
    await shoot(page, 'order-ar');

    // A phone: a guest on the home page, and the customer's order page in Arabic.
    const phoneContext = await browser.newContext(phone);
    const mobile = await phoneContext.newPage();
    await openIn(mobile, 'Home_Page.php', 'en');
    await shoot(mobile, 'mobile');
    await phoneContext.close();
    const customerPhone = await browser.newContext({ ...phone, storageState: await page.context().storageState() });
    const customerMobile = await customerPhone.newPage();
    await openIn(customerMobile, `order.php?id=${orderId}`, 'ar');
    await expect(customerMobile.locator('.order-timeline')).toBeVisible();
    await shoot(customerMobile, 'mobile-order-ar');
    await customerPhone.close();
});
