// Profile page: switches sections without a reload and previews a chosen avatar.
// Without JavaScript the menu links load ?section=... and the server shows that section.
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
                const label = link.textContent.trim();
                document.title = document.title.replace(/^.*?(?= · BookWise$)/, label);
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
    preview.title = 'Choose a new profile picture';
    preview.addEventListener('click', () => input.click());

    const resetPreview = (message) => {
        preview.src = originalSrc;
        preview.alt = originalAlt;
        status.textContent = message;
        status.classList.toggle('is-error', message !== '');
    };

    input.addEventListener('change', () => {
        const file = input.files && input.files[0];
        if (!file) {
            resetPreview('');
            return;
        }
        if (!allowedTypes.includes(file.type)) {
            input.value = '';
            resetPreview('Please choose a JPEG, PNG, GIF or WebP image.');
            return;
        }
        if (file.size > maxBytes) {
            input.value = '';
            resetPreview('That picture is larger than 2 MB. Please choose a smaller one.');
            return;
        }

        // Data URLs are allowed by the page's Content-Security-Policy (blob: URLs are not).
        const reader = new FileReader();
        reader.addEventListener('load', () => {
            if (typeof reader.result === 'string') {
                preview.src = reader.result;
                preview.alt = 'Preview of your new profile picture';
            }
        });
        reader.readAsDataURL(file);
        status.classList.remove('is-error');
        status.textContent = 'New picture selected. Select "Update Profile" to save it.';
    });
});
