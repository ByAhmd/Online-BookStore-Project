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

    // The orders page: its filters only search, so they still work.
    await page.locator('.site-nav').getByRole('link', { name: 'Orders' }).click();
    await expect(page).toHaveURL(/admin_orders\.php$/);
    await page.getByLabel('Order number').fill(orderId);
    await page.getByRole('button', { name: 'Show orders' }).click();
    await expect(page).toHaveURL(new RegExp(`admin_orders\\.php\\?.*q=${orderId}`));
    const row = page.locator('.order-list-table tbody tr');
    await expect(row).toHaveCount(1);
    // Customers' details are shortened for read-only administrators.
    await expect(row.locator('.customer-email')).toContainText('…');

    await row.getByRole('link', { name: `#${orderId}` }).click();
    await expect(page).toHaveURL(new RegExp(`admin_order\\.php\\?id=${orderId}$`));
    await expect(page.locator('.shipment-form')).toBeVisible();
    await expectFormsSwitchedOff(page, 'order page');

    // The same in Arabic.
    await openIn(page, `admin_order.php?id=${orderId}`, 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expectFormsSwitchedOff(page, 'Arabic order page');
    await openIn(page, 'admin_dashboard.php', 'ar');
    await expectFormsSwitchedOff(page, 'Arabic dashboard');
});
