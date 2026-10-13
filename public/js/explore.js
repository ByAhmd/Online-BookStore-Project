// Explore page progressive enhancement: genre tabs, search and live search
// load results from fetch_books.php without a full page reload. The page is
// fully server-rendered, so everything still works when this script fails.
(() => {
    'use strict';

    const root = document.querySelector('[data-explore]');
    if (!root || typeof window.fetch !== 'function' || typeof window.URLSearchParams !== 'function') {
        return;
    }

    const form = root.querySelector('.explore-search');
    const input = form ? form.querySelector('input[name="q"]') : null;
    const genreInput = form ? form.querySelector('input[name="genre"]') : null;
    const status = root.querySelector('#explore-status');
    const results = root.querySelector('#explore-results');
    const tabs = Array.from(root.querySelectorAll('.genre-tab[data-genre]'));
    if (!form || !input || !genreInput || !status || !results) {
        return;
    }

    const MAX_QUERY_LENGTH = 100;
    const TYPING_DELAY_MS = 400;
    const COVER_FALLBACK = 'imges/header.jpeg';

    let state = { genre: root.dataset.genre || 'all', q: input.value.trim() };
    let controller = null;
    let requestSeq = 0;
    let typingTimer = 0;

    const cleanQuery = (value) => String(value || '').trim().slice(0, MAX_QUERY_LENGTH);

    const buildUrl = (base, genre, q) => {
        const params = new URLSearchParams({ genre });
        if (q !== '') {
            params.set('q', q);
        }
        return base + '?' + params.toString();
    };

    const pageUrl = (genre, q) => buildUrl('Explore_Page.php', genre, q);

    // Keep in sync with the summary built in Explore_Page.php.
    const describeResults = (count, genre, q, truncated) => {
        const scope = genre === 'all' ? 'all genres' : (genre === 'Favorites' ? 'your favorites' : genre);
        let text = 'Showing ' + count.toLocaleString('en-US') + ' ' + (count === 1 ? 'book' : 'books') + ' in ' + scope;
        if (q !== '') {
            text += ' matching “' + q + '”';
        }
        text += '.';
        if (truncated) {
            text += ' Only the first ' + count + ' are shown; refine your search to narrow the list.';
        }
        return text;
    };

    const el = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    };

    const bookHref = (book) => {
        if (typeof book.url === 'string' && /^Selected\.php\?id=\d+$/.test(book.url)) {
            return book.url;
        }
        return 'Selected.php?id=' + encodeURIComponent(String(book.id));
    };

    const createCard = (book) => {
        const item = el('li', 'explore-card');
        const link = el('a', 'explore-card-link');
        link.href = bookHref(book);

        const cover = el('div', 'explore-card-cover');
        const img = document.createElement('img');
        img.src = typeof book.cover_image === 'string' && book.cover_image !== '' ? book.cover_image : COVER_FALLBACK;
        img.alt = '';
        img.width = 380;
        img.height = 605;
        img.loading = 'lazy';
        img.decoding = 'async';
        cover.appendChild(img);

        // Catalog text may be right-to-left (e.g. Arabic titles).
        const title = el('h3', 'explore-card-title', String(book.title ?? ''));
        title.dir = 'auto';
        const author = el('p', 'explore-card-author', String(book.author ?? ''));
        author.dir = 'auto';

        const body = el('div', 'explore-card-body');
        body.append(title, author);

        link.append(cover, body, el('span', 'explore-card-price', String(book.price_display ?? '')));
        item.appendChild(link);
        return item;
    };

    // Links that switch the listing: data-genre (required) and optional data-q.
    const createSwitchLink = (label, genre, q) => {
        const link = el('a', 'btn', label);
        link.href = pageUrl(genre, q);
        link.dataset.genre = genre;
        link.dataset.q = q;
        return link;
    };

    const createEmptyState = (genre, q) => {
        const box = el('div', 'empty-state explore-empty');
        if (q !== '') {
            box.append(
                el('p', 'explore-empty-title', 'No books match your search'),
                el('p', '', 'Try a different title or author, or check the spelling.'),
                createSwitchLink('Clear search', genre, '')
            );
        } else if (genre === 'Favorites') {
            box.append(
                el('p', 'explore-empty-title', "You haven't added any favorites yet."),
                el('p', '', 'Open a book and add it to your favorites to find it here quickly.'),
                createSwitchLink('Browse all books', 'all', '')
            );
        } else {
            box.append(
                el('p', 'explore-empty-title', 'No books available yet.'),
                el('p', '', 'New titles are on their way. Please check back soon.')
            );
        }
        return box;
    };

    const renderBooks = (books, genre, q, truncated) => {
        if (books.length === 0) {
            results.replaceChildren(createEmptyState(genre, q));
        } else {
            const grid = el('ul', 'explore-grid');
            grid.setAttribute('role', 'list');
            books.forEach((book) => grid.appendChild(createCard(book)));
            results.replaceChildren(grid);
        }
        status.textContent = describeResults(books.length, genre, q, truncated);
    };

    const renderError = () => {
        const box = el('div', 'explore-error');
        box.setAttribute('role', 'alert');
        const retry = el('button', 'btn', 'Try again');
        retry.type = 'button';
        retry.addEventListener('click', () => {
            // The button is replaced by the reload, so keep focus on the live status
            // message instead of letting it fall back to the top of the page.
            if (!status.hasAttribute('tabindex')) {
                status.setAttribute('tabindex', '-1');
            }
            status.focus();
            load(state.genre, state.q);
        });
        box.append(
            el('p', 'explore-error-title', 'We couldn’t load books right now.'),
            el('p', '', 'Please check your connection and try again.'),
            retry
        );
        results.replaceChildren(box);
        status.textContent = 'Books could not be loaded.';
    };

    const syncControls = (genre, q) => {
        genreInput.value = genre;
        tabs.forEach((tab) => {
            const active = tab.dataset.genre === genre;
            tab.classList.toggle('is-active', active);
            if (active) {
                tab.setAttribute('aria-current', 'page');
            } else {
                tab.removeAttribute('aria-current');
            }
            tab.href = pageUrl(tab.dataset.genre, q);
        });
        try {
            window.history.replaceState(null, '', pageUrl(genre, q));
        } catch (error) {
            // URL sync is a convenience only.
        }
    };

    const setLoading = (loading) => {
        results.setAttribute('aria-busy', loading ? 'true' : 'false');
        results.classList.toggle('is-loading', loading);
        status.classList.toggle('is-loading', loading);
        if (loading) {
            status.textContent = 'Loading books…';
        }
    };

    async function load(genre, q) {
        window.clearTimeout(typingTimer);
        if (controller) {
            controller.abort();
        }
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        const seq = ++requestSeq;

        state = { genre, q };
        syncControls(genre, q);
        setLoading(true);

        try {
            const response = await fetch(buildUrl('fetch_books.php', genre, q), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller ? controller.signal : undefined,
            });
            if (response.status === 401) {
                // Signed out meanwhile: the full page sends the visitor to the login form.
                window.location.assign(pageUrl(genre, q));
                return;
            }
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            const data = await response.json();
            if (seq !== requestSeq) {
                return;
            }
            if (!data || !Array.isArray(data.books)) {
                throw new Error('Unexpected response');
            }
            const resolvedGenre = typeof data.genre === 'string' ? data.genre : genre;
            if (resolvedGenre !== genre) {
                state.genre = resolvedGenre;
                syncControls(resolvedGenre, q);
            }
            renderBooks(data.books, resolvedGenre, q, data.truncated === true);
        } catch (error) {
            if ((error && error.name === 'AbortError') || seq !== requestSeq) {
                return;
            }
            renderError();
        } finally {
            if (seq === requestSeq) {
                setLoading(false);
            }
        }
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const q = cleanQuery(input.value);
        input.value = q;
        load(state.genre, q);
    });

    input.addEventListener('input', () => {
        window.clearTimeout(typingTimer);
        typingTimer = window.setTimeout(() => {
            const q = cleanQuery(input.value);
            if (q !== state.q) {
                load(state.genre, q);
            }
        }, TYPING_DELAY_MS);
    });

    // Tabs, "Clear search" and "Browse all books" links (including ones rendered later).
    root.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a[data-genre]') : null;
        if (!link || !root.contains(link)) {
            return;
        }
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        const q = link.dataset.q !== undefined ? cleanQuery(link.dataset.q) : cleanQuery(input.value);
        input.value = q;
        load(link.dataset.genre || 'all', q);
        if (link.dataset.q !== undefined) {
            input.focus();
        }
    });
})();
