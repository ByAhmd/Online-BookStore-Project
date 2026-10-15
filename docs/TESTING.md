# Testing BookWise in a real browser

The PHPUnit suites (unit, integration, http and deploy) are described in the README under [Testing and quality checks](../README.md#testing-and-quality-checks).
This page covers the **browser tests**, which drive the store in Chromium with [Playwright](https://playwright.dev) and check its accessibility with [axe-core](https://github.com/dequelabs/axe-core), and the script that takes the **README screenshots**.
Neither is needed to run the store: Node.js is only used for these tools (`package.json`, `e2e/`).

## Contents

- [What the browser tests cover](#what-the-browser-tests-cover)
- [Setting up](#setting-up)
- [Running them against a local store](#running-them-against-a-local-store)
- [Running them against Docker](#running-them-against-docker)
- [Useful options](#useful-options)
- [Accessibility checks](#accessibility-checks)
- [On CI](#on-ci)
- [README screenshots](#readme-screenshots)

## What the browser tests cover

The tests use a store in demo mode (`APP_DEMO=true`) with the payment simulator and the demo inbox (`MAIL_DRIVER=database`), as `docker-compose.yml` and the public demo set it up.
Each test is independent: it creates its own customer, either a one-click demo customer (where that is what is tested) or a customer who signs up through the sign-up form.

| File | What it checks |
| --- | --- |
| `e2e/browsing.spec.js` | A guest, in English and Arabic: the home page; the Explore page's live search, genre tabs and pages, which `explore.js` updates without reloading the page, with covers as `<picture>` elements whose WebP copy the browser uses; a book page whose **Log in to buy** link leads to the sign-in page and remembers the book. |
| `e2e/language.spec.js` | The language switch: Arabic pages are right-to-left, stay Arabic from page to page and keep the Explore page's search; Arabic pages never scroll sideways at 375 and 1280 pixels. |
| `e2e/purchase.spec.js` | **Try as a customer**, a book in the cart, the checkout page with the demo customer's saved address chosen, **Pay** on the simulated payment page, the confirmation, the order page and the confirmation email in the demo inbox (English in a desktop window, Arabic in a 375-pixel one). **Decline** keeps the payment page open for another try, **Cancel** charges nothing and keeps the cart, and **Slow payment** is confirmed after about 15 seconds. |
| `e2e/signup.spec.js` | Signing up through the form shows the welcome email (or, with `REQUIRE_EMAIL_VERIFICATION=true`, the confirmation email) in the demo inbox, in English and Arabic. |
| `e2e/admin-viewer.spec.js` | **Try as an administrator (read-only)**: the dashboard, the orders page (its search still works) and an order's page, in English and Arabic, with every control of every form that would change something switched off, and customers' details shortened. |
| `e2e/keyboard.spec.js` | The profile page with the keyboard alone: the skip link, the section menu in tab order, Enter opens a section and moves the focus to its heading, Tab reaches the main controls of every section, the focus is always visible, and a form is sent with Enter. The demo banner's link keeps a dark focus ring on its yellow inside the dark header. |
| `e2e/accessibility.spec.js` | axe-core on the main pages in English and Arabic (see [below](#accessibility-checks)). |

Shared steps (the demo buttons, signing up, buying a book, the axe check) are in `e2e/support/store.js`.

## Setting up

You need Node.js 20 or newer (CI uses Node.js 24). In the project folder:

```bash
npm ci                                  # Playwright and axe-core, as locked in package-lock.json
npx playwright install chromium         # on Linux: npx playwright install --with-deps chromium
```

## Running them against a local store

You need PHP with the project's Composer dependencies (`composer install`) and a MySQL or MariaDB database that the tests may wipe. Its name must end in `_test`:

```sql
CREATE DATABASE bookwise_e2e_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then run the tests with the database's connection settings:

```bash
DB_PORT=3306 DB_USER=root DB_PASS=secret npx playwright test
```

Without `BASE_URL`, Playwright first starts `e2e/local-server.js` (also available as `npm run serve:e2e`) and stops it after the run. That script:

1. stops if something already answers on port 8181 (it never resets the database of a store that is still running), then drops every table of `DB_NAME` (default `bookwise_e2e_test`; it refuses any name that does not end in `_test`) and runs `php bin/install.php --seed`, so every run starts from the sample catalog and nothing else;
2. serves `public/` with PHP's built-in server, `php -S 127.0.0.1:8181 -t public`, with the settings of a demo:

   | Setting | Value |
   | --- | --- |
   | `APP_ENV` / `APP_DEMO` / `APP_URL` | `local` / `true` / `http://127.0.0.1:8181` |
   | `MAIL_DRIVER` / `REQUIRE_EMAIL_VERIFICATION` | `database` (the demo inbox) / `false` (`true` in your shell shows the confirmation emails, but the tests that sign customers up need `false`) |
   | `PAYMENT_SIMULATOR_STORE`, `LOG_DIR`, sessions | the folder `bookwise-e2e-8181` in the system's temporary folder, emptied when the store starts and deleted when it stops (on Windows, Playwright ends the store without letting it clean up, so the folder stays until the next start) |
   | `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | from your shell; defaults `127.0.0.1` / `3306` / `bookwise_e2e_test` / `root` / empty |

   `PHP_BINARY` chooses another PHP than the `php` on your `PATH`. A `.env` file in the project only fills settings that are empty here, such as `DB_PASS`.

You can also start the store by hand, without the script (Bash, on an empty database):

```bash
export APP_ENV=local APP_DEMO=true MAIL_DRIVER=database REQUIRE_EMAIL_VERIFICATION=false \
    APP_URL=http://127.0.0.1:8181 DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=bookwise_e2e_test DB_USER=root DB_PASS= \
    LOG_DIR="$(mktemp -d)" PAYMENT_SIMULATOR_STORE="$(mktemp -d)/payment-simulator.json"
php bin/install.php --seed
php -S 127.0.0.1:8181 -t public
```

While a store answers on port 8181, `npx playwright test` uses it instead of starting its own (except on CI).
The demo allows 10 **Try as a customer** per visitor address and hour, and a run of the tests uses 3, all from `127.0.0.1`. A store you keep running therefore takes three runs an hour; after that the tests stop with the store's own message. Restart it on a fresh database (the script does that by itself).

## Running them against Docker

```bash
docker compose up -d --build
BASE_URL=http://localhost:8080 npx playwright test
```

`BASE_URL` must be the store's `APP_URL`, because the payment page sends the browser back there. Any store set up like `docker-compose.yml` works (demo mode, the demo inbox and `REQUIRE_EMAIL_VERIFICATION=false`), also one in a subfolder such as `BASE_URL=http://localhost/bookwise`.
The Compose store keeps its database between runs; `docker compose down -v` deletes it if the demo-customer limit is reached.
The tests create customers and orders, so never point them at a public demo.

## Useful options

| Command | What it does |
| --- | --- |
| `npx playwright test e2e/purchase.spec.js` | One file |
| `npx playwright test -g "slow payment"` | Tests whose title matches |
| `npx playwright test --headed` / `--ui` | Watch the browser / Playwright's interactive mode |
| `npx playwright show-report` | The HTML report of the last run (it also opens by itself after a local run with failures) |
| `npx playwright show-trace test-results/<test>/trace.zip` | Step through a failed test: every action, the page before and after it, the network and the console |

## Accessibility checks

`expectAccessible()` in `e2e/support/store.js` runs axe-core on the page with its default rules (WCAG 2 A and AA, and best practices) and fails on **serious** or **critical** violations, naming each rule and element.
Moderate and minor violations do not fail the test; they are listed as annotations of the test in the HTML report.

`e2e/accessibility.spec.js` checks, in English and in Arabic:

- as a guest: the home page, the Explore page (also after a genre tab, with the cards `explore.js` renders), a book page, the sign-in, sign-up and password-reset forms (and the sign-up form with errors), the demo inbox and the error 404 page;
- as a customer: the home page, the cart, the checkout page, the payment page (also after **Decline**), the confirmation, the order page, all six profile sections, the demo inbox and an email in it;
- as the read-only administrator: the dashboard, the orders page and an order's page.

Two things to know:

- **Arabic text:** axe skips the contrast check of text drawn with an icon font's ligatures, and it recognises them by comparing a word with its letters drawn one by one. Arabic letters join, so axe takes every Arabic word for an icon and would check the contrast of no Arabic text at all. `e2e/support/store.js` removes that heuristic for Arabic text before axe runs (it fails loudly if a new axe-core version changes the code it patches).
- **Text on photos:** axe cannot measure text on a background image, such as the home page's heading on its bookshelf photo. That contrast was measured on screenshots of the darkened photo: at least 4.45:1 behind the heading (large text, which needs 3:1) and 4.8:1 behind the line below it (which needs 4.5:1).

What the checks found, and how it was fixed:

- The demo banner was outside every landmark, so screen-reader users who move between landmarks could miss it. It is now part of the page header (`src/views/header.php`).
- The scrolling tables of the admin pages had the same name as the section around them, so the two regions could not be told apart. They are now named "Table: …" (`admin.table_region`).

The HTTP suite checks both rules on every page it opens (`HtmlDocument::contentOutsideLandmarks()` and `duplicateLandmarks()` in `tests/Support`, used by `assertHtmlPage()`), and `tests/Http/AccessibilityMarkupTest.php` checks the two fixes.

## On CI

The **Browser tests** job of `.github/workflows/ci.yml` builds the image, starts the Compose store as the Docker job does, installs Chromium and runs `npx playwright test` against `http://localhost:8080`.
On CI, a failed test is tried once more and two tests run at a time. When a test fails, the job uploads the HTML report (with screenshots and traces) as the `playwright-report` artifact of the workflow run, and prints the containers' logs.

## README screenshots

`npm run screenshots` takes the README's screenshots, as JPEG files (quality 78) in `docs/screenshots/`:

| File | Shows |
| --- | --- |
| `home.jpg`, `explore.jpg`, `home-ar.jpg` | The home page, the Explore page and the Arabic home page, as a guest |
| `book.jpg`, `checkout.jpg`, `payment-simulator.jpg`, `order.jpg`, `inbox.jpg` | A demo customer's book page, checkout page, simulated payment page, order page (with its timeline) and demo inbox |
| `admin-dashboard.jpg` | The admin dashboard |
| `mobile.jpg` | The home page on a phone (375 pixels wide) |

Desktop pages are taken at 1280 × 800 pixels, the phone at 375 × 812 with a device pixel ratio of 2.
Like the tests, the script uses the store at `BASE_URL`, or starts the local store described above:

```bash
npm run screenshots
BASE_URL=http://localhost:8080 SCREENSHOT_ADMIN_EMAIL=admin@bookwise.test SCREENSHOT_ADMIN_PASSWORD=local-admin-password npm run screenshots
```

With `SCREENSHOT_ADMIN_EMAIL` and `SCREENSHOT_ADMIN_PASSWORD` (an owner administrator of that store, such as the local one of `docker-compose.yml` above), the order is marked shipped first, so its timeline shows the carrier and the tracking number, and the dashboard is the owner's. Without them, the dashboard is the read-only demo administrator's.
The script overwrites the files of the same name; look at them before you commit them.
