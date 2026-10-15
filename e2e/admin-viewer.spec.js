// "Try as an administrator (read-only)": the shared viewer administrator
// sees the dashboard, the orders and an order's page, with every control of
// every form that would change something switched off.
import { test, expect } from '@playwright/test';
import { buyBook, openIn, signUp, startDemoAdmin } from './support/store.js';

/** Every control of the page's POST forms (the header's "Log out" is outside <main>) is disabled. */
async function expectFormsSwitchedOff(page, name) {
    const controls = page.locator('main form[method="post"]').locator('input:not([type="hidden"]), select, textarea, button');
    const count = await controls.count();
    expect(count, `${name} has forms to switch off`).toBeGreaterThan(0);
    for (let i = 0; i < count; i++) {
        await expect(controls.nth(i), `${name}: control ${i + 1} of ${count}`).toBeDisabled();
    }
    await expect(page.locator('.read-only-note')).toBeVisible();
}

/**
 * The characters of `token` in the element's text are drawn from left to
 * right (each one right of the one before it on the same line), as a Latin
 * path or URL must read inside Arabic text too.
 */
async function expectDrawnLeftToRight(locator, token) {
    const boxes = await locator.evaluate((element, token) => {
        const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            const start = node.nodeValue.indexOf(token);
            if (start !== -1) {
                return Array.from(token, (character, i) => {
                    const range = document.createRange();
                    range.setStart(node, start + i);
                    range.setEnd(node, start + i + 1);
                    const { left, top } = range.getBoundingClientRect();
                    return { left, top };
                });
            }
        }
        return null;
    }, token);
    expect(boxes, `"${token}" is shown`).not.toBeNull();
    const sameLine = boxes.slice(1).map((box, i) => [boxes[i], box]).filter(([a, b]) => Math.abs(a.top - b.top) < 2);
    expect(sameLine.length, `"${token}" is mostly on one line`).toBeGreaterThan(token.length / 2);
    for (const [before, after] of sameLine) {
        expect(after.left, `"${token}" reads left to right`).toBeGreaterThan(before.left);
    }
}

/** The orders page's search, as in lang/en/admin.php and lang/ar/admin.php. */
const orderSearch = {
    en: { label: 'Order number', button: 'Show orders' },
    ar: { label: 'رقم الطلب', button: 'عرض الطلبات' },
};

/** Finds the order on the orders page, whose filters only search, so they still work. */
async function findOrder(page, orderId, locale) {
    await page.getByLabel(orderSearch[locale].label, { exact: true }).fill(orderId);
    await page.getByRole('button', { name: orderSearch[locale].button }).click();
    await expect(page).toHaveURL(new RegExp(`admin_orders\\.php\\?.*q=${orderId}`));
    const row = page.locator('.order-list-table tbody tr');
    await expect(row).toHaveCount(1);
    // Customers' details are shortened for read-only administrators.
    await expect(row.locator('.customer-email')).toContainText('…');
    return row;
}

test('a read-only administrator can look at everything and change nothing', async ({ browser, page }) => {
    // A paid order to look at, bought by a new customer in a browser of its own.
    const customerContext = await browser.newContext();
    const customer = await customerContext.newPage();
    await signUp(customer);
    const orderId = String(await buyBook(customer));
    await customerContext.close();

    await startDemoAdmin(page);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expectFormsSwitchedOff(page, 'dashboard');

    await page.locator('.site-nav').getByRole('link', { name: 'Orders' }).click();
    await expect(page).toHaveURL(/admin_orders\.php$/);
    const row = await findOrder(page, orderId, 'en');
    await row.getByRole('link', { name: `#${orderId}` }).click();
    await expect(page).toHaveURL(new RegExp(`admin_order\\.php\\?id=${orderId}$`));
    await expect(page.locator('.shipment-form')).toBeVisible();
    await expectFormsSwitchedOff(page, 'order page');

    // The same in Arabic: the orders page, the order's page and the dashboard.
    await openIn(page, 'admin_orders.php', 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    const arabicRow = await findOrder(page, orderId, 'ar');
    await arabicRow.getByRole('link', { name: `#${orderId}` }).click();
    await expect(page).toHaveURL(new RegExp(`admin_order\\.php\\?id=${orderId}$`));
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.locator('.shipment-form')).toBeVisible();
    await expectFormsSwitchedOff(page, 'Arabic order page');
    await openIn(page, 'admin_dashboard.php', 'ar');
    await expectFormsSwitchedOff(page, 'Arabic dashboard');
    // The cover hint's examples stay readable in the right-to-left text ("https://", not "//:https").
    for (const token of ['public/', 'imges/All_section/biology.png', 'https://']) {
        await expectDrawnLeftToRight(page.locator('#book-cover-hint'), token);
    }
});
