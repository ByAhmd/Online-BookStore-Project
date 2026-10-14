// Explore page progressive enhancement: genre tabs, search, live search and
// the pager load results from fetch_books.php without a full page reload. The
// page is fully server-rendered, so everything still works when this script
// fails. Interface text comes from the page's data-i18n-* attributes and the
// JSON response (summary, availability), never from this file.
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
    const pager = root.querySelector('#explore-pager');
    const tabs = Array.from(root.querySelectorAll('.genre-tab[data-genre]'));
    if (!form || !input || !genreInput || !status || !results || !pager) {
        return;
    }

    const MAX_QUERY_LENGTH = 100;
    const TYPING_DELAY_MS = 400;
    const COVER_FALLBACK = 'imges/header.jpeg';
    const STOCK_STATUSES = ['in', 'low', 'out'];
    // Displayed cover width, for choosing a WebP copy (EXPLORE_COVER_SIZES in Explore_Page.php).
    const coverSizes = root.dataset.coverSizes || '50vw';

    // data-i18n-error-title="..." -> text('error-title')
    const text = (name) => {
        const key = 'i18n' + name.replace(/-([a-z])/g, (match, letter) => letter.toUpperCase()).replace(/^./, (letter) => letter.toUpperCase());
        return root.dataset[key] || '';
    };

    const toPage = (value) => {
        const page = Number.parseInt(String(value || '1'), 10);
        return Number.isFinite(page) && page > 0 ? page : 1;
    };

    let state = { genre: root.dataset.genre || 'all', q: input.value.trim(), page: toPage(root.dataset.page) };
    let controller = null;
    let requestSeq = 0;
    let typingTimer = 0;

    const cleanQuery = (value) => String(value || '').trim().slice(0, MAX_QUERY_LENGTH);

    const buildUrl = (base, genre, q, page) => {
        const params = new URLSearchParams({ genre });
        if (q !== '') {
            params.set('q', q);
        }
        if (page > 1) {
            params.set('page', String(page));
        }
        return base + '?' + params.toString();
    };

    const pageUrl = (genre, q, page) => buildUrl('Explore_Page.php', genre, q, page || 1);

    const el = (tag, className, content) => {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (content !== undefined) {
            node.textContent = content;
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

        // Same markup as cover_picture() in src/uploads.php: the WebP copies
        // first, the stored cover as the fallback <img>.
        const cover = el('div', 'explore-card-cover');
        const picture = document.createElement('picture');
        if (typeof book.cover_webp_srcset === 'string' && book.cover_webp_srcset !== '') {
            const source = document.createElement('source');
            source.type = 'image/webp';
            source.srcset = book.cover_webp_srcset;
            source.sizes = coverSizes;
            picture.appendChild(source);
        }
        const img = document.createElement('img');
        img.src = typeof book.cover_image === 'string' && book.cover_image !== '' ? book.cover_image : COVER_FALLBACK;
        img.alt = '';
        img.width = 380;
        img.height = 605;
        img.loading = 'lazy';
        img.decoding = 'async';
        picture.appendChild(img);
        cover.appendChild(picture);

        // Catalog text may be right-to-left (e.g. Arabic titles).
        const title = el('h3', 'explore-card-title', String(book.title ?? ''));
        title.dir = 'auto';
        const author = el('p', 'explore-card-author', String(book.author ?? ''));
        author.dir = 'auto';

        const body = el('div', 'explore-card-body');
        body.append(title, author);
        if (typeof book.availability === 'string' && book.availability !== '') {
            const stockStatus = STOCK_STATUSES.includes(book.stock_status) ? book.stock_status : 'in';
            body.appendChild(el('p', 'stock-badge stock-' + stockStatus, book.availability));
        }

        link.append(cover, body, el('span', 'explore-card-price', String(book.price_display ?? '')));
        item.appendChild(link);
        return item;
    };

    // Links that switch the listing: data-genre (required) and optional data-q.
    const createSwitchLink = (label, genre, q) => {
        const link = el('a', 'btn', label);
        link.href = pageUrl(genre, q, 1);
        link.dataset.genre = genre;
        link.dataset.q = q;
        return link;
    };

    const createEmptyState = (genre, q) => {
        const box = el('div', 'empty-state explore-empty');
        if (q !== '') {
            box.append(
                el('p', 'explore-empty-title', text('no-match-title')),
                el('p', '', text('no-match-text')),
                createSwitchLink(text('clear-search'), genre, '')
            );
        } else if (genre === 'Favorites') {
            box.append(
                el('p', 'explore-empty-title', text('no-favorites-title')),
                el('p', '', text('no-favorites-text')),
                createSwitchLink(text('browse-all'), 'all', '')
            );
        } else {
            box.append(
                el('p', 'explore-empty-title', text('empty-title')),
                el('p', '', text('empty-text'))
            );
        }
        return box;
    };

    // Same page numbers as pager_items() in src/books.php: first, last and two on each side.
    const pagerItems = (page, pages) => {
        const items = [];
        let previous = 0;
        for (let i = 1; i <= pages; i += 1) {
            if (i === 1 || i === pages || Math.abs(i - page) <= 2) {
                if (i - previous > 1) {
                    items.push(null);
                }
                items.push(i);
                previous = i;
            }
        }
        return items;
    };

    const renderPager = (genre, q, page, pages) => {
        if (pages <= 1) {
            pager.replaceChildren();
            return;
        }
        const nav = el('nav', 'pager');
        nav.setAttribute('aria-label', text('pager-label'));
        const list = el('ul', 'pager-list');

        const edge = (className, label, target, rel) => {
            const item = el('li');
            if (target === null) {
                const span = el('span', 'pager-link ' + className + ' is-disabled', label);
                span.setAttribute('aria-disabled', 'true');
                item.appendChild(span);
            } else {
                const link = el('a', 'pager-link ' + className, label);
                link.href = pageUrl(genre, q, target);
                link.rel = rel;
                link.dataset.page = String(target);
                item.appendChild(link);
            }
            return item;
        };

        list.appendChild(edge('pager-prev', text('previous'), page > 1 ? page - 1 : null, 'prev'));
        pagerItems(page, pages).forEach((number) => {
            const item = el('li');
            if (number === null) {
                const gap = el('span', 'pager-gap', '…');
                gap.setAttribute('aria-hidden', 'true');
                item.appendChild(gap);
            } else {
                const link = el('a', 'pager-link pager-number', String(number));
                link.href = pageUrl(genre, q, number);
                link.dataset.page = String(number);
                link.setAttribute('aria-label', text('page').replace(':page', String(number)));
                if (number === page) {
                    link.setAttribute('aria-current', 'page');
                }
                item.appendChild(link);
            }
            list.appendChild(item);
        });
        list.appendChild(edge('pager-next', text('next'), page < pages ? page + 1 : null, 'next'));
        nav.appendChild(list);
        pager.replaceChildren(nav);
    };

    const renderBooks = (data, genre, q) => {
        if (data.books.length === 0) {
            results.replaceChildren(createEmptyState(genre, q));
        } else {
            const grid = el('ul', 'explore-grid');
            grid.setAttribute('role', 'list');
            data.books.forEach((book) => grid.appendChild(createCard(book)));
            results.replaceChildren(grid);
        }
        status.textContent = typeof data.summary === 'string' ? data.summary : '';
        renderPager(genre, q, toPage(data.page), toPage(data.pages));
    };

    const renderError = () => {
        const box = el('div', 'explore-error');
        box.setAttribute('role', 'alert');
        const retry = el('button', 'btn', text('retry'));
        retry.type = 'button';
        retry.addEventListener('click', () => {
            // The button is replaced by the reload, so keep focus on the live status
            // message instead of letting it fall back to the top of the page.
            focusStatus();
            load(state.genre, state.q, state.page);
        });
        box.append(
            el('p', 'explore-error-title', text('error-title')),
            el('p', '', text('error-text')),
            retry
        );
        results.replaceChildren(box);
        pager.replaceChildren();
        status.textContent = text('error-status');
    };

    const focusStatus = () => {
        if (!status.hasAttribute('tabindex')) {
            status.setAttribute('tabindex', '-1');
        }
        status.focus();
    };

    const syncControls = (genre, q, page) => {
        genreInput.value = genre;
        tabs.forEach((tab) => {
            const active = tab.dataset.genre === genre;
            tab.classList.toggle('is-active', active);
            if (active) {
                tab.setAttribute('aria-current', 'page');
            } else {
                tab.removeAttribute('aria-current');
            }
            tab.href = pageUrl(tab.dataset.genre, q, 1);
        });
        try {
            window.history.replaceState(null, '', pageUrl(genre, q, page));
        } catch (error) {
            // URL sync is a convenience only.
        }
    };

    const setLoading = (loading) => {
        results.setAttribute('aria-busy', loading ? 'true' : 'false');
        results.classList.toggle('is-loading', loading);
        status.classList.toggle('is-loading', loading);
        if (loading) {
            status.textContent = text('loading');
        }
    };

    async function load(genre, q, page) {
        window.clearTimeout(typingTimer);
        if (controller) {
            controller.abort();
        }
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        const seq = ++requestSeq;

        state = { genre, q, page };
        syncControls(genre, q, page);
        setLoading(true);

        try {
            const response = await fetch(buildUrl('fetch_books.php', genre, q, page), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller ? controller.signal : undefined,
            });
            if (response.status === 401) {
                // Favorites of a guest (or of a visitor signed out meanwhile): the
                // full page explains that they are kept in the account and links to the login form.
                window.location.assign(pageUrl(genre, q, page));
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
            // The server resolves unknown genres to "all" and clamps the page number.
            const resolvedGenre = typeof data.genre === 'string' ? data.genre : genre;
            const resolvedPage = toPage(data.page);
            if (resolvedGenre !== genre || resolvedPage !== page) {
                state.genre = resolvedGenre;
                state.page = resolvedPage;
                syncControls(resolvedGenre, q, resolvedPage);
            }
            renderBooks(data, resolvedGenre, q);
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
        load(state.genre, q, 1);
    });

    input.addEventListener('input', () => {
        window.clearTimeout(typingTimer);
        typingTimer = window.setTimeout(() => {
            const q = cleanQuery(input.value);
            if (q !== state.q) {
                load(state.genre, q, 1);
            }
        }, TYPING_DELAY_MS);
    });

    const isPlainClick = (event) => !(event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey);

    // Tabs, "Clear search" / "Browse all books" links and pager links (including ones rendered later).
    root.addEventListener('click', (event) => {
        if (!(event.target instanceof Element) || !isPlainClick(event)) {
            return;
        }
        const pageLink = event.target.closest('#explore-pager a[data-page]');
        if (pageLink && root.contains(pageLink)) {
            event.preventDefault();
            load(state.genre, state.q, toPage(pageLink.dataset.page));
            results.scrollIntoView({ block: 'start' });
            focusStatus();
            return;
        }
        const link = event.target.closest('a[data-genre]');
        if (!link || !root.contains(link)) {
            return;
        }
        event.preventDefault();
        const q = link.dataset.q !== undefined ? cleanQuery(link.dataset.q) : cleanQuery(input.value);
        input.value = q;
        load(link.dataset.genre || 'all', q, 1);
        if (link.dataset.q !== undefined) {
            input.focus();
        }
    });
})();
