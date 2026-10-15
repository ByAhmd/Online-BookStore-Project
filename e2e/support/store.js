// Shared steps of the browser tests: the one-click demo accounts, the
// language switch, shopping and the accessibility check. Interface text is
// matched in the visitor's language (labels below), so the Arabic tests also
// check that the Arabic interface is really shown.
import { randomBytes } from 'node:crypto';
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import axe from 'axe-core';

// axe skips text drawn with an icon font's ligatures (a word that becomes one
// symbol), and it recognises them by comparing a word's width with the width
// of its letters drawn one by one. Arabic letters join, so every Arabic word
// looks like a ligature to it, and axe would check the contrast of no Arabic
// text at all. Arabic text is never an icon, so the check is skipped for it.
const ligatureCheck = 'function _isIconLigature(textVNode) {';
if (!axe.source.includes(ligatureCheck)) {
    throw new Error('axe-core changed: update the Arabic ligature workaround in e2e/support/store.js.');
}
const axeSource = axe.source.replace(
    ligatureCheck,
    `${ligatureCheck} if (/[\\u0600-\\u06FF]/.test(textVNode.actualNode.nodeValue)) { return false; }`
);

/** Visible labels the tests look for, as in lang/en and lang/ar. */
export const labels = {
    en: {
        tryCustomer: 'Try as a customer',
        tryAdmin: 'Try as an administrator (read-only)',
        logInToBuy: 'Log in to buy',
        addToCart: 'Add to cart',
        proceedToCheckout: 'Proceed to Checkout',
        continueToPayment: 'Continue to payment',
        pay: /^Pay /,
        decline: 'Decline',
        slowPayment: 'Slow payment',
        refreshStatus: 'Refresh status',
        viewOrder: 'View order details',
        inbox: 'Demo inbox',
        paidHeading: 'Thank you! Your payment was received.',
        language: 'العربية',
    },
    ar: {
        tryCustomer: 'جرّب بصفتك عميلًا',
        tryAdmin: 'جرّب بصفتك مشرفًا (للقراءة فقط)',
        logInToBuy: 'سجّل الدخول للشراء',
        addToCart: 'أضف إلى السلة',
        proceedToCheckout: 'المتابعة لإتمام الطلب',
        continueToPayment: 'المتابعة إلى الدفع',
        pay: /^ادفع /,
        decline: 'رفض البطاقة',
        slowPayment: 'دفع بطيء',
        refreshStatus: 'تحديث الحالة',
        viewOrder: 'عرض تفاصيل الطلب',
        inbox: 'صندوق البريد التجريبي',
        paidHeading: 'شكرًا لك! تم استلام دفعتك.',
        language: 'English',
    },
};

/**
 * Opens $path in the given language. The language is kept in a cookie, so
 * later pages stay in it until it is switched again.
 */
export async function openIn(page, path, locale = 'en') {
    const separator = path.includes('?') ? '&' : '?';
    await page.goto(`${path}${separator}lang=${locale}`);
    await expect(page.locator('html')).toHaveAttribute('lang', locale);
}

/**
 * Presses "Try as a customer" on the sign-in page: a fresh demo customer
 * with a made-up delivery address in Riyadh, signed in on the home page.
 */
export async function startDemoCustomer(page, locale = 'en') {
    await openIn(page, 'index.php?form=login', locale);
    await page.getByRole('button', { name: labels[locale].tryCustomer }).click();
    // A welcome message on the home page, or the reason on the sign-in page.
    await expect(page.locator('.flash').first()).toBeVisible();
    const refusal = await page.locator('.flash-error').allInnerTexts();
    if (refusal.length > 0) {
        throw new Error(`No demo customer was created: "${refusal.join(' ')}" The demo allows 10 demo customers per visitor `
            + 'address and hour, and a run of the suite creates 3. e2e/local-server.js starts on a fresh database.');
    }
    await expect(page).toHaveURL(/Home_Page\.php/);
}

/**
 * Signs up a new customer through the sign-up form (an address at
 * example.com, a random password) and returns its email address. Needs a
 * store without email confirmation (REQUIRE_EMAIL_VERIFICATION=false), as
 * docker-compose.yml and e2e/local-server.js set it up. Sign-ups have no
 * limit, unlike the demo customers.
 */
export async function signUp(page, locale = 'en', name = 'Test Reader') {
    const email = `e2e-${randomBytes(6).toString('hex')}@example.com`;
    const password = `E2e-${randomBytes(9).toString('base64url')}`;
    await openIn(page, 'index.php?form=signup', locale);
    const form = page.locator('#panel-signup form');
    await form.locator('input[name="email"]').fill(email);
    await form.locator('input[name="name"]').fill(name);
    await form.locator('input[name="password"]').fill(password);
    await form.locator('input[name="password_confirmation"]').fill(password);
    await form.locator('button[type="submit"]').click();
    await expect(page, 'signed in after signing up (REQUIRE_EMAIL_VERIFICATION must be false)').toHaveURL(/Home_Page\.php/);
    return email;
}

/** Presses "Try as an administrator (read-only)": the shared viewer administrator. */
export async function startDemoAdmin(page, locale = 'en') {
    await openIn(page, 'index.php?form=login', locale);
    await page.getByRole('button', { name: labels[locale].tryAdmin }).click();
    await expect(page).toHaveURL(/admin_dashboard\.php/);
}

/**
 * Opens the page of the $index-th book of the Explore page (the catalog is
 * sorted the same way for every visitor) and returns its URL.
 */
export async function openBook(page, index = 0) {
    await page.goto('Explore_Page.php');
    const link = page.locator('.explore-card-link').nth(index);
    const href = await link.getAttribute('href');
    await link.click();
    await expect(page.locator('#book-title')).toBeVisible();
    return href;
}

/**
 * Adds one copy of the $index-th book of the Explore page to the cart, from
 * its book page, and returns the book's title. Ends on the cart page. Demo
 * mode keeps every book in stock (a sale never takes it below 3 copies).
 */
export async function addBookToCart(page, locale = 'en', index = 0) {
    await openBook(page, index);
    const title = (await page.locator('#book-title').innerText()).trim();
    await page.getByRole('button', { name: labels[locale].addToCart }).click();
    await expect(page).toHaveURL(/cart\.php/);
    await expect(page.locator('.cart-table')).toContainText(title);
    return title;
}

/** A made-up delivery address in the format of a Saudi National Address. */
export const sampleAddress = {
    recipient_name: 'Sara Ahmed',
    phone: '0512345678',
    city: 'Riyadh',
    district: 'Al Olaya',
    street: 'King Fahd Road',
    building_number: '1234',
    postal_code: '12345',
    additional_number: '6789',
    short_address: 'RRRD2929',
};

/**
 * From the cart, through the checkout page, to the payment simulator's page:
 * a demo customer's saved address is chosen already; a customer without
 * addresses types $sampleAddress in. Returns the payment page's URL.
 */
export async function checkOutToPaymentPage(page, locale = 'en') {
    await page.getByRole('link', { name: labels[locale].proceedToCheckout }).click();
    await expect(page).toHaveURL(/checkout\.php/);
    if (await page.locator('.address-option').count() === 0) {
        for (const [field, value] of Object.entries(sampleAddress)) {
            await page.locator(`#new-address-${field}`).fill(value);
        }
    }
    await page.getByRole('button', { name: labels[locale].continueToPayment }).click();
    await expect(page).toHaveURL(/pay\.php\?session=cs_test_/);
    return page.url();
}

/** On the payment confirmation page: the paid order's number, from its "View order details" link. */
export async function paidOrderId(page, locale = 'en') {
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(labels[locale].paidHeading);
    const href = await page.getByRole('link', { name: labels[locale].viewOrder }).getAttribute('href');
    const id = Number(new URL(href, page.url()).searchParams.get('id'));
    expect(id).toBeGreaterThan(0);
    return id;
}

/** Buys the $index-th book of the Explore page with the simulator's Pay button; returns the order's number. */
export async function buyBook(page, locale = 'en', index = 0) {
    await addBookToCart(page, locale, index);
    await checkOutToPaymentPage(page, locale);
    await page.getByRole('button', { name: labels[locale].pay }).click();
    return paidOrderId(page, locale);
}

/** axe for the page, with the Arabic ligature workaround above. */
export function axeFor(page) {
    return new AxeBuilder({ page, axeSource });
}

/**
 * Runs axe on the page and fails on serious or critical violations, listing
 * each rule with the elements it found. Moderate and minor ones are noted in
 * the test's annotations (shown in the HTML report).
 */
export async function expectAccessible(page, name) {
    const results = await axeFor(page).analyze();
    const problems = results.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact));
    for (const violation of results.violations.filter((candidate) => !problems.includes(candidate))) {
        test.info().annotations.push({
            type: `axe ${violation.impact}`,
            description: `${name}: ${violation.id} (${violation.help}) on ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`,
        });
    }
    const report = problems.map((violation) => [
        `${violation.impact}: ${violation.id} (${violation.help})`,
        ...violation.nodes.slice(0, 5).map((node) => `    ${node.target.join(' ')}: ${node.failureSummary.replace(/\s+/g, ' ')}`),
    ].join('\n')).join('\n');
    expect(problems, `${name} (${page.url()}) has accessibility violations:\n${report}`).toEqual([]);
}

/** The page is no wider than the window: nothing scrolls sideways. */
export async function expectNoHorizontalScroll(page, name) {
    const sizes = await page.evaluate(() => ({
        page: document.documentElement.scrollWidth,
        window: document.documentElement.clientWidth,
    }));
    expect(sizes.page, `${name} scrolls sideways at ${sizes.window} px`).toBeLessThanOrEqual(sizes.window);
}
