// A visitor tries the store as a one-click demo customer and buys a book:
// cart, the checkout page with the demo customer's saved address chosen, the
// payment simulator's page, the confirmation, the order page, and the
// confirmation email in the demo inbox. Customers who signed up (and type
// their address at checkout) try the simulator's Decline, Cancel and Slow payment.
import { test, expect } from '@playwright/test';
import {
    addBookToCart,
    checkOutToPaymentPage,
    expectNoHorizontalScroll,
    labels,
    paidOrderId,
    signUp,
    startDemoCustomer,
} from './support/store.js';

/** Order confirmation emails, as in lang/<language>/shop.php (APP_NAME is BookWise). */
const confirmationSubject = {
    en: (id) => `Your BookWise order #${id} is confirmed`,
    ar: (id) => `تم تأكيد طلبك رقم ${id} من BookWise`,
};
/** The city of the demo customer's made-up address (lang/<language>/auth.php) and of sampleAddress. */
const city = { en: 'Riyadh', ar: 'الرياض' };

/** The order page of a paid order: paid, now processing, not shipped yet. */
async function expectProcessingOrder(page, locale, id, title) {
    await expect(page).toHaveURL(new RegExp(`order\\.php\\?id=${id}$`));
    await expect(page.getByRole('heading', { level: 1 })).toContainText(String(id));
    await expect(page.locator('.order-timeline .timeline-paid')).toHaveClass(/is-done/);
    await expect(page.locator('.order-timeline .timeline-processing')).toHaveClass(/is-done/);
    await expect(page.locator('.order-timeline [aria-current="step"]')).toHaveClass(/timeline-processing/);
    await expect(page.locator('.order-timeline .timeline-shipped')).toHaveClass(/is-upcoming/);
    await expect(page.locator('.order-timeline .timeline-delivered')).toHaveClass(/is-upcoming/);
    await expect(page.locator('.order-delivery .address-text')).toContainText(city[locale]);
    await expect(page.locator('.receipt-table')).toContainText(title);
}

/** The demo inbox lists the order's confirmation email, which names the book. */
async function expectConfirmationEmail(page, locale, id, title) {
    await page.getByRole('link', { name: labels[locale].inbox, exact: true }).click();
    await expect(page).toHaveURL(/inbox\.php$/);
    const subject = confirmationSubject[locale](id);
    await page.getByRole('link', { name: subject }).click();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(subject);
    await expect(page.locator('.inbox-message-body')).toContainText(title);
}

for (const locale of ['en', 'ar']) {
    test(`a demo customer buys a book and gets the confirmation email (${locale})`, async ({ page }) => {
        // The Arabic journey runs in a phone-sized window, where right-to-left layouts break first.
        const mobile = locale === 'ar';
        if (mobile) {
            await page.setViewportSize({ width: 375, height: 812 });
        }
        const fits = async (name) => {
            if (mobile) {
                await expectNoHorizontalScroll(page, name);
            }
        };

        await startDemoCustomer(page, locale);
        const title = await addBookToCart(page, locale);
        await fits('cart');

        await page.getByRole('link', { name: labels[locale].proceedToCheckout }).click();
        await expect(page).toHaveURL(/checkout\.php$/);
        await fits('checkout');
        // The demo customer's saved address is chosen already.
        const saved = page.locator('.address-option input[name="address"]:checked');
        await expect(saved).toHaveCount(1);
        await expect(saved).not.toHaveValue('new');
        await expect(page.locator(`label[for="${await saved.getAttribute('id')}"]`)).toContainText(city[locale]);
        await expect(page.locator('.checkout-items')).toContainText(title);

        await page.getByRole('button', { name: labels[locale].continueToPayment }).click();
        await expect(page).toHaveURL(/pay\.php\?session=cs_test_\w+$/);
        await fits('payment page');
        await expect(page.locator('.pay-card .order-table')).toContainText(title);
        await page.getByRole('button', { name: labels[locale].pay }).click();

        await expect(page).toHaveURL(/success_pay\.php\?session_id=cs_test_\w+$/);
        await fits('confirmation');
        const id = await paidOrderId(page, locale);
        await expect(page.locator('.order-table')).toContainText(title);

        await page.getByRole('link', { name: labels[locale].viewOrder }).click();
        await expectProcessingOrder(page, locale, id, title);
        await fits('order page');

        await expectConfirmationEmail(page, locale, id, title);
        await fits('inbox');
    });
}

test('a declined card keeps the payment page open for another try', async ({ page }) => {
    await signUp(page);
    const title = await addBookToCart(page, 'en', 1);
    const paymentUrl = await checkOutToPaymentPage(page);

    await page.getByRole('button', { name: labels.en.decline }).click();
    await expect(page).toHaveURL(paymentUrl);
    await expect(page.getByRole('alert')).toContainText('Your card was declined (simulated).');
    // Nothing was paid: the book is still in the cart, and paying now works.
    await page.getByRole('button', { name: labels.en.pay }).click();
    const id = await paidOrderId(page, 'en');
    await page.getByRole('link', { name: labels.en.viewOrder }).click();
    await expectProcessingOrder(page, 'en', id, title);
});

test('canceling on the payment page charges nothing and keeps the cart', async ({ page }) => {
    await signUp(page);
    const title = await addBookToCart(page, 'en', 3);
    await checkOutToPaymentPage(page);

    await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(page).toHaveURL(/cancel_pay\.php\?order=\d+$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Checkout canceled');
    await page.getByRole('link', { name: 'Return to cart' }).click();
    await expect(page.locator('.cart-table')).toContainText(title);
});

test('a slow payment is confirmed after a while', async ({ page }) => {
    // The simulator confirms a slow payment after about 15 seconds.
    test.setTimeout(90_000);
    await signUp(page);
    const title = await addBookToCart(page, 'en', 2);
    await checkOutToPaymentPage(page);

    await page.getByRole('button', { name: labels.en.slowPayment }).click();
    await expect(page).toHaveURL(/success_pay\.php\?session_id=cs_test_\w+$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText("We're confirming your payment…");

    await expect(async () => {
        await page.getByRole('link', { name: labels.en.refreshStatus }).click();
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(labels.en.paidHeading, { timeout: 1_000 });
    }).toPass({ intervals: [2_000], timeout: 45_000 });

    const id = await paidOrderId(page, 'en');
    await expect(page.locator('.order-table')).toContainText(title);
    await page.getByRole('link', { name: labels.en.viewOrder }).click();
    await expectProcessingOrder(page, 'en', id, title);
});
