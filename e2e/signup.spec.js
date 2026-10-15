// A visitor signs up through the sign-up form and finds the store's email in
// the demo inbox: the welcome email, or, where new customers must confirm
// their address (REQUIRE_EMAIL_VERIFICATION=true), the confirmation email.
import { randomBytes } from 'node:crypto';
import { test, expect } from '@playwright/test';
import { labels, openIn } from './support/store.js';

const form = {
    en: { email: 'Email Address', name: 'Name', password: 'Password', confirmation: 'Confirm Password', submit: 'Sign Up' },
    ar: { email: 'البريد الإلكتروني', name: 'الاسم', password: 'كلمة المرور', confirmation: 'تأكيد كلمة المرور', submit: 'إنشاء حساب' },
};
/** Subjects of the welcome and the confirmation email (lang/<language>/auth.php). */
const subjects = {
    en: /^(Welcome to BookWise!|Confirm your BookWise email address)$/,
    ar: /^(مرحبًا بك في BookWise!|أكّد عنوان بريدك الإلكتروني في BookWise)$/,
};

for (const locale of ['en', 'ar']) {
    test(`signing up shows the store's email in the demo inbox (${locale})`, async ({ page }) => {
        const email = `e2e-${randomBytes(6).toString('hex')}@example.com`;
        const password = `E2e-${randomBytes(9).toString('base64url')}`;

        await openIn(page, 'index.php?form=signup', locale);
        const panel = page.locator('#panel-signup');
        await panel.getByLabel(form[locale].email).fill(email);
        await panel.getByLabel(form[locale].name, { exact: true }).fill(locale === 'ar' ? 'قارئة الاختبار' : 'Test Reader');
        await panel.getByLabel(form[locale].password, { exact: true }).fill(password);
        await panel.getByLabel(form[locale].confirmation).fill(password);
        await panel.getByRole('button', { name: form[locale].submit }).click();

        // Signed in (welcome email), or asked to confirm the address first.
        await expect(page).toHaveURL(/Home_Page\.php|index\.php\?form=verify/);
        await expect(page.locator('.flash-success')).toBeVisible();

        await page.getByRole('link', { name: labels[locale].inbox, exact: true }).click();
        await expect(page).toHaveURL(/inbox\.php$/);
        await expect(page.locator('.inbox-addresses')).toContainText(email);
        const message = page.locator('.inbox-item').filter({ hasText: email });
        await expect(message).toHaveCount(1);
        await expect(message.locator('.inbox-item-subject')).toHaveText(subjects[locale]);
        await message.click();
        await expect(page.getByRole('heading', { level: 1 })).toHaveText(subjects[locale]);
        await expect(page.locator('.inbox-message-meta')).toContainText(email);
        // Links to the store can be followed from the inbox.
        await expect(page.locator('.inbox-message-body a').first()).toHaveAttribute('href', new RegExp(`^${new URL(page.url()).origin}/`));
    });
}
