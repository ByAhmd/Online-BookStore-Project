// The keyboard alone: on the profile page, the skip link, the section menu
// and the main controls of every section are reached with Tab, opened with
// Enter, and always show where the focus is; so does the demo banner's link.
import { test, expect } from '@playwright/test';
import { signUp } from './support/store.js';

/** A short description of the focused element, for failure messages. */
const describeFocus = (page) => page.evaluate(() => {
    const element = document.activeElement;
    if (!element || element === document.body) {
        return '(nothing)';
    }
    const id = element.id ? `#${element.id}` : '';
    const text = (element.getAttribute('aria-label') || element.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40);
    return `${element.tagName.toLowerCase()}${id}${text ? ` "${text}"` : ''}`;
});

/** Presses Tab (or Shift+Tab) until $target has the focus. */
async function tabTo(page, target, { backward = false, max = 60 } = {}) {
    const trail = [];
    for (let presses = 0; presses < max; presses++) {
        if (await target.evaluate((element) => element === document.activeElement)) {
            return trail;
        }
        await page.keyboard.press(backward ? 'Shift+Tab' : 'Tab');
        trail.push(await describeFocus(page));
    }
    throw new Error(`Not reached with ${backward ? 'Shift+Tab' : 'Tab'} after ${max} presses. Focus went through:\n  ${trail.join('\n  ')}`);
}

/**
 * The focused element shows a focus indicator that it does not have without
 * the focus: an outline of 2 px or more, or another box shadow. Some
 * elements have a box shadow anyway (the current section's marker in the
 * profile menu), so the element is compared with itself after blur(), then
 * focused again.
 */
async function expectVisibleFocus(locator, name) {
    await expect(locator, `${name} has the focus`).toBeFocused();
    await expect(locator, `${name} is on screen`).toBeInViewport();
    const style = await locator.evaluate((element) => {
        const indicator = () => {
            const computed = window.getComputedStyle(element);
            const width = parseFloat(computed.outlineWidth);
            return {
                outline: computed.outlineStyle === 'none' || width === 0 ? 'none' : `${width}px ${computed.outlineStyle} ${computed.outlineColor}`,
                outlineWidth: width,
                boxShadow: computed.boxShadow,
            };
        };
        const focusVisible = element.matches(':focus-visible');
        const focused = indicator();
        element.blur();
        const unfocused = indicator();
        element.focus({ preventScroll: true });
        return { focusVisible, focused, unfocused, refocused: document.activeElement === element };
    });
    expect(style.focusVisible, `${name} matches :focus-visible`).toBe(true);
    expect(style.refocused, `${name} has the focus again after the comparison`).toBe(true);
    const outline = style.focused.outline !== 'none' && style.focused.outlineWidth >= 2 && style.focused.outline !== style.unfocused.outline;
    const ring = style.focused.boxShadow !== 'none' && style.focused.boxShadow !== style.unfocused.boxShadow;
    expect(outline || ring, `${name} shows a focus outline or ring it does not have without the focus: ${JSON.stringify(style)}`).toBe(true);
}

test('the demo banner in the dark header shows a dark focus ring on its yellow', async ({ page }) => {
    await page.goto('Home_Page.php');
    await page.keyboard.press('Tab');
    await expect(page.locator('a.skip-link')).toBeFocused();
    await page.keyboard.press('Tab');
    const link = page.locator('header .demo-banner a');
    await expectVisibleFocus(link, 'the demo banner link');
    // The header's own links get a yellow ring, which would vanish on the yellow banner.
    expect(await link.evaluate((element) => window.getComputedStyle(element).outlineColor)).toBe('rgb(11, 12, 12)');
});

/** Each section and the main controls a keyboard user must reach in it. */
const sections = [
    { key: 'addresses', controls: ['#address-recipient_name', '#address-phone', '#address-short_address', '#section-addresses button[type="submit"]'] },
    { key: 'payment', controls: ['#payment_method', '#section-payment button[type="submit"]'] },
    { key: 'orders', controls: ['#section-orders a.btn'] },
    { key: 'security', controls: ['#current_password', '#new_password', '#new_password_confirmation', '#section-security button[type="submit"]'] },
    { key: 'delete', controls: ['#delete_password', '#confirm_delete', '#section-delete button[type="submit"]'] },
    { key: 'account', controls: ['#profile_image', '#name', '#favorite_genre', '#locale', '#section-account .profile-subsection button[type="submit"]'] },
];

test('every profile section works with the keyboard alone', async ({ page }) => {
    await signUp(page);
    await page.goto('profilePage.php');

    // The first Tab shows the skip link, which jumps over the header.
    await page.keyboard.press('Tab');
    const skipLink = page.locator('a.skip-link');
    await expectVisibleFocus(skipLink, 'the skip link');
    await page.keyboard.press('Enter');
    await expect(page.locator('#main-content')).toBeFocused();

    // From there, Tab goes through the section menu in order.
    const menu = page.locator('.profile-menu');
    const menuLinks = menu.locator('a.profile-menu-link');
    await expect(menuLinks).toHaveCount(sections.length);
    for (let i = 0; i < sections.length; i++) {
        const link = menuLinks.nth(i);
        await tabTo(page, link, { max: 3 });
        await expectVisibleFocus(link, `menu link ${i + 1}`);
    }
    const signOut = menu.locator('button.profile-menu-link');
    await tabTo(page, signOut, { max: 2 });
    await expectVisibleFocus(signOut, 'the sign-out button');

    for (const section of sections) {
        // Back to the section's menu link, then Enter opens the section and
        // puts the focus on its heading.
        const link = menu.locator(`a[data-section="${section.key}"]`);
        await tabTo(page, link, { backward: true });
        await expectVisibleFocus(link, `the "${section.key}" menu link`);
        await page.keyboard.press('Enter');
        const panel = page.locator(`#section-${section.key}`);
        await expect(panel).toBeVisible();
        await expect(page.locator('.profile-section:visible')).toHaveCount(1);
        await expect(link).toHaveAttribute('aria-current', 'page');
        await expect(page).toHaveURL(new RegExp(`section=${section.key}`));
        const heading = panel.locator('.profile-section-title');
        await expectVisibleFocus(heading, `the "${section.key}" heading`);

        for (const selector of section.controls) {
            const control = page.locator(selector);
            await tabTo(page, control);
            await expectVisibleFocus(control, `${selector} in "${section.key}"`);
        }
    }

    // A form sent with the keyboard: the name, saved with Enter.
    const name = page.locator('#name');
    await tabTo(page, name, { backward: true });
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.type('Keyboard Reader');
    await page.keyboard.press('Enter');
    await expect(page.locator('.flash-success')).toBeVisible();
    await expect(page.locator('#name')).toHaveValue('Keyboard Reader');
});
