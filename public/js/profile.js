// Profile page: switches sections without a reload, previews a chosen avatar
// and gives quick feedback on the change-password form.
// Without JavaScript the menu links load ?section=... and the server shows that section.
// Every message comes from data-i18n-* attributes rendered in the page's language.
document.addEventListener('DOMContentLoaded', () => {
    const links = Array.from(document.querySelectorAll('.profile-menu-link[data-section]'));
    const panels = Array.from(document.querySelectorAll('[data-section-panel]'));

    const showSection = (name, moveFocus) => {
        const panel = panels.find((candidate) => candidate.dataset.sectionPanel === name);
        if (!panel) {
            return;
        }
        panels.forEach((candidate) => {
            candidate.hidden = candidate !== panel;
        });
        links.forEach((link) => {
            if (link.dataset.section === name) {
                link.setAttribute('aria-current', 'page');
                if (link.dataset.title) {
                    document.title = link.dataset.title;
                }
            } else {
                link.removeAttribute('aria-current');
            }
        });

        if (moveFocus) {
            const heading = panel.querySelector('.profile-section-title');
            if (heading) {
                heading.focus();
            }
        }

        // Keep the address bar shareable/reloadable; a failure here must not break switching.
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('section', name);
            window.history.replaceState(null, '', url.href);
        } catch (error) {
            // Ignore: the section is already shown.
        }
    };

    links.forEach((link) => {
        link.addEventListener('click', (event) => {
            // Let modified clicks open the section in a new tab as a normal link.
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            event.preventDefault();
            showSection(link.dataset.section, true);
        });
    });

    // After a failed submission, start on the first field the server rejected.
    const invalid = Array.from(document.querySelectorAll('.profile-content [aria-invalid="true"]'))
        .find((field) => !field.closest('[hidden]'));
    if (invalid) {
        invalid.focus();
    }

    // Change password: the confirmation must match and bcrypt only uses 72 bytes
    // (mirrors the server rules, which are checked again on submit).
    document.querySelectorAll('form.password-form').forEach((form) => {
        // A non-empty validity message is required to block submission; '⚠' only shows if the attribute is missing.
        const message = (name) => form.dataset[name] || '⚠';
        form.querySelectorAll('input[data-match]').forEach((confirmation) => {
            const source = form.querySelector(`#${confirmation.dataset.match}`);
            if (!source) {
                return;
            }
            const check = () => {
                const mismatch = confirmation.value !== '' && confirmation.value !== source.value;
                confirmation.setCustomValidity(mismatch ? message('i18nMismatch') : '');
            };
            source.addEventListener('input', check);
            confirmation.addEventListener('input', check);
        });
        if ('TextEncoder' in window) {
            const encoder = new TextEncoder();
            form.querySelectorAll('input[data-max-bytes]').forEach((input) => {
                const max = Number(input.dataset.maxBytes);
                input.addEventListener('input', () => {
                    const tooLong = encoder.encode(input.value).length > max;
                    input.setCustomValidity(tooLong ? message('i18nTooLong').replace(':max', String(max)) : '');
                });
            });
        }
    });

    // Avatar: clicking the picture opens the (labelled) file input; a chosen
    // image is checked and previewed before upload. The server re-validates it.
    const input = document.querySelector('[data-avatar-input]');
    const preview = document.querySelector('[data-avatar-preview]');
    const status = document.querySelector('[data-avatar-status]');
    if (!input || !preview || !status) {
        return;
    }

    const allowedTypes = (input.getAttribute('accept') || '').split(',');
    const maxBytes = Number(input.dataset.maxBytes) || 2 * 1024 * 1024;
    const originalSrc = preview.getAttribute('src');
    const originalAlt = preview.getAttribute('alt');

    preview.classList.add('is-clickable');
    preview.title = input.dataset.i18nChoose || '';
    preview.addEventListener('click', () => input.click());

    const resetPreview = (text) => {
        preview.src = originalSrc;
        preview.alt = originalAlt;
        status.textContent = text;
        status.classList.toggle('is-error', text !== '');
    };

    input.addEventListener('change', () => {
        const file = input.files && input.files[0];
        if (!file) {
            resetPreview('');
            return;
        }
        if (!allowedTypes.includes(file.type)) {
            input.value = '';
            resetPreview(input.dataset.i18nTypeError || '');
            return;
        }
        if (file.size > maxBytes) {
            input.value = '';
            resetPreview(input.dataset.i18nSizeError || '');
            return;
        }

        // Data URLs are allowed by the page's Content-Security-Policy (blob: URLs are not).
        const reader = new FileReader();
        reader.addEventListener('load', () => {
            if (typeof reader.result === 'string') {
                preview.src = reader.result;
                preview.alt = input.dataset.i18nPreviewAlt || originalAlt;
            }
        });
        reader.readAsDataURL(file);
        status.classList.remove('is-error');
        status.textContent = input.dataset.i18nSelected || '';
    });
});
