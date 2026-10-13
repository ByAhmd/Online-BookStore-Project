// Auth pages (index.php, reset_form.php): Sign Up / Login / Reset panel
// switching, password visibility toggles and light client-side checks.
// The server validates everything again; these checks only give faster feedback.
document.addEventListener('DOMContentLoaded', () => {
    const panels = Array.from(document.querySelectorAll('.auth-panel[data-panel]'));
    const panelNames = panels.map((panel) => panel.dataset.panel);

    const syncSwitchers = (name) => {
        document.querySelectorAll('button[data-auth-target]').forEach((button) => {
            button.setAttribute('aria-expanded', String(button.dataset.authTarget === name));
        });
    };

    const showPanel = (name) => {
        const target = panels.find((panel) => panel.dataset.panel === name);
        if (!target) {
            return;
        }
        panels.forEach((panel) => {
            panel.hidden = panel !== target;
        });
        syncSwitchers(name);
        if (target.dataset.title) {
            document.title = `${target.dataset.title} · BookWise`;
        }
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('form', name);
            window.history.replaceState(window.history.state, '', url);
        } catch (error) {
            // The URL is cosmetic; switching still works without it.
        }
        const heading = target.querySelector('.auth-title');
        if (heading) {
            heading.focus();
        }
    };

    if (panels.length > 0) {
        // Without JS these are ?form= links; with JS they become in-page buttons.
        document.querySelectorAll('a[data-auth-target]').forEach((link) => {
            const name = link.dataset.authTarget;
            if (!panelNames.includes(name)) {
                return;
            }
            const button = document.createElement('button');
            button.type = 'button';
            button.className = link.className;
            button.dataset.authTarget = name;
            button.setAttribute('aria-controls', `panel-${name}`);
            button.append(...link.childNodes);
            link.replaceWith(button);
            button.addEventListener('click', () => showPanel(name));
        });

        const visible = panels.find((panel) => !panel.hidden);
        syncSwitchers(visible ? visible.dataset.panel : '');

        // The header's Sign Up / Login links switch panels in place on this page.
        document.querySelectorAll('a[href^="index.php?form="]').forEach((link) => {
            const name = new URL(link.href, window.location.href).searchParams.get('form');
            if (!panelNames.includes(name)) {
                return;
            }
            link.addEventListener('click', (event) => {
                if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                    return;
                }
                event.preventDefault();
                showPanel(name);
            });
        });
    }

    // Show / hide password buttons (rendered hidden so they never appear without JS).
    const toggles = Array.from(document.querySelectorAll('.password-toggle[aria-controls]'));
    toggles.forEach((toggle) => {
        const input = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!input) {
            return;
        }
        toggle.hidden = false;
        toggle.addEventListener('click', () => {
            const reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', String(reveal));
        });
    });

    // Confirmation must match its password field (mirrors the server rule).
    document.querySelectorAll('input[data-match]').forEach((confirmation) => {
        const source = document.getElementById(confirmation.dataset.match);
        if (!source) {
            return;
        }
        const check = () => {
            const mismatch = confirmation.value !== '' && confirmation.value !== source.value;
            confirmation.setCustomValidity(mismatch ? 'Passwords do not match.' : '');
        };
        source.addEventListener('input', check);
        confirmation.addEventListener('input', check);
    });

    // bcrypt only uses the first 72 bytes, so the server limits passwords by bytes.
    if ('TextEncoder' in window) {
        const encoder = new TextEncoder();
        document.querySelectorAll('input[data-max-bytes]').forEach((input) => {
            const max = Number(input.dataset.maxBytes);
            input.addEventListener('input', () => {
                const tooLong = encoder.encode(input.value).length > max;
                input.setCustomValidity(tooLong ? `Password must be at most ${max} bytes long.` : '');
            });
        });
    }

    document.querySelectorAll('.auth-form').forEach((form) => {
        form.addEventListener('submit', () => {
            // Never leave a revealed password as plain text once the form is sent.
            toggles.filter((toggle) => form.contains(toggle)).forEach((toggle) => {
                const input = document.getElementById(toggle.getAttribute('aria-controls'));
                if (input) {
                    input.type = 'password';
                }
                toggle.setAttribute('aria-pressed', 'false');
            });
        });
    });

    // Re-enable data-once buttons when the page is restored from the back/forward cache.
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) {
            return;
        }
        document.querySelectorAll('.auth-form button[type="submit"]').forEach((button) => {
            button.disabled = false;
            button.removeAttribute('aria-disabled');
        });
    });

    // After a failed submission, start on the first field the server rejected.
    const invalid = Array.from(document.querySelectorAll('.auth-card [aria-invalid="true"]'))
        .find((field) => !field.closest('[hidden]'));
    if (invalid) {
        invalid.focus();
    }
});
