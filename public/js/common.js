// Shared behaviour loaded on every page. Pages must not use inline scripts or
// on* attributes (the Content-Security-Policy only allows same-origin files).
document.addEventListener('DOMContentLoaded', () => {
    // Language links switch the page as it is now. Some pages change their
    // address in place (explore filters, sign-in tabs, profile sections), so
    // the query string is taken from the current address just before use.
    // Links the server pointed at another page (error pages) are left alone.
    document.querySelectorAll('a.lang-switch[hreflang]').forEach((link) => {
        const refresh = () => {
            const target = new URL(link.href);
            const here = window.location;
            const samePage = target.pathname === here.pathname
                || (here.pathname.endsWith('/') && target.pathname === `${here.pathname}index.php`);
            if (!samePage) {
                return;
            }
            const url = new URL(target.pathname + here.search, here.origin);
            url.searchParams.set('lang', link.getAttribute('hreflang'));
            link.href = url.href;
        };
        ['pointerenter', 'focus', 'click'].forEach((type) => link.addEventListener(type, refresh));
    });

    // Dismissible flash messages. When the focused close button disappears with
    // its message, focus moves to the next remaining close button (or the
    // previous one after the last message), else to the main content.
    document.querySelectorAll('.flash-close').forEach((button) => {
        button.addEventListener('click', () => {
            const flash = button.closest('.flash');
            if (!flash) {
                return;
            }
            const hadFocus = document.activeElement === button;
            const closeButtons = Array.from(document.querySelectorAll('.flash-close'));
            const index = closeButtons.indexOf(button);
            const stack = flash.parentElement;

            flash.remove();
            if (stack && stack.classList.contains('flash-stack') && !stack.querySelector('.flash')) {
                stack.remove();
            }
            if (!hadFocus) {
                return;
            }

            const remaining = closeButtons.filter((other) => other !== button && other.isConnected);
            const next = closeButtons.slice(index + 1).find((other) => other.isConnected)
                || remaining[remaining.length - 1];
            if (next) {
                next.focus();
                return;
            }
            const main = document.getElementById('main-content');
            if (main) {
                if (!main.hasAttribute('tabindex')) {
                    main.setAttribute('tabindex', '-1');
                }
                main.focus();
            }
        });
    });

    // Confirmation prompts: <form data-confirm="Are you sure?">.
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    // Prevent double submission of forms that start payments or other slow actions:
    // <form data-once> disables its submit buttons after the first submit.
    document.querySelectorAll('form[data-once]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (event.defaultPrevented) {
                return;
            }
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
                button.disabled = true;
                button.setAttribute('aria-disabled', 'true');
            });
        });
    });
});

// Pages restored from the back/forward cache keep their DOM state, so re-enable
// buttons that a data-once form disabled before navigating away.
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) {
        return;
    }
    document.querySelectorAll('form[data-once] [aria-disabled="true"]').forEach((button) => {
        button.disabled = false;
        button.removeAttribute('aria-disabled');
    });
});
