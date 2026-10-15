# Testing BookWise

BookWise has two kinds of automated tests:

- **PHPUnit suites** (`tests/`): unit tests, integration tests against a real database, end-to-end HTTP tests of the real pages, and checks of the deployment files, the workflows and the documentation.
- **Browser tests** (`e2e/`), which drive the store in Chromium with [Playwright](https://playwright.dev) and check its accessibility with [axe-core](https://github.com/dequelabs/axe-core). The same tools take the **README screenshots**.
  Neither is needed to run the store: Node.js is only used for these tools (`package.json`, `e2e/`).

GitHub Actions runs both, with static analysis and a test of the Docker image, on every push to `main` and every pull request ([Continuous integration](#continuous-integration)).

## Contents

- [Quality checks](#quality-checks)
- [PHPUnit suites](#phpunit-suites)
- [Browser tests](#browser-tests)
  - [What the browser tests cover](#what-the-browser-tests-cover)
  - [Setting up](#setting-up)
  - [Running them against a local store](#running-them-against-a-local-store)
  - [Running them against Docker](#running-them-against-docker)
  - [Useful options](#useful-options)
  - [Accessibility checks](#accessibility-checks)
- [Continuous integration](#continuous-integration)
- [README screenshots](#readme-screenshots)

## Quality checks

```bash
composer lint          # php -l on every PHP file
composer analyse       # PHPStan level 8 (src/, bin/, public/)
composer test:unit     # unit tests, no database needed
composer test          # all suites
composer check         # lint, analyse and test, one after the other
```

The checks need PHP 8.3 or newer and the development dependencies (`composer install`).

## PHPUnit suites

The **integration** and **http** suites need a MySQL/MariaDB database whose name ends in `_test`.
They drop and recreate every table in it, and refuse to run against any other database name.
Defaults are in `phpunit.xml.dist`; override them with environment variables:

```bash
mysql -u root -p -e "CREATE DATABASE bookwise_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=root DB_PASS=secret composer test
```

If no database is reachable, the database-backed tests are skipped and say so.
Set `BOOKWISE_REQUIRE_DB=1` to make that a failure instead; CI does this.
The tests never read the project's `.env` file: their settings come from `phpunit.xml.dist` and the environment.
One suite runs alone with `vendor/bin/phpunit --testsuite <name>`, and one file with `vendor/bin/phpunit tests/Http/CheckoutFlowTest.php`.

The suites are:

- **unit** (`tests/Unit`): pure functions, the translations (the same keys and placeholders in English and Arabic, and every key the code uses), the image tools (WebP copies, `bin/optimize-images.php`) on scratch files, and rules for the scripts in `bin/` (they read their arguments through `$GLOBALS['argv']` and answer 404 through a web server).
- **integration** (`tests/Integration`): domain code against a real database, including the migrations, the demo's showcase (every rule of the store it must keep) and its daily reset.
- **http** (`tests/Http`): end-to-end tests that start the application with `php -S` and drive the real pages, covering sign-up, email verification and login, CSRF, access control, guest browsing, cart and stock, the checkout page and delivery addresses, checkout (with Stripe and with every ending of the payment simulator), the order page, deliveries and order emails, refunds, webhooks, password reset and change, account deletion, uploads, cover images, admin pages, read-only administrators (every admin form is posted as a viewer, and nothing may change; no checkout session id is shown to them), the one-click demo accounts, the demo inbox, the demo's showcase on the home, book and admin pages, and the Arabic interface.
  Every page check also loads the page's CSS, scripts and images, including each WebP copy in a `srcset`, and checks that all of the page is inside landmarks that can be told apart.
- **deploy** (`tests/Docker`): checks of the Docker files, the deployment guide and `bin/setup.php` (line endings, configuration, the entrypoint's database check, the settings the setup script writes, the demo's scheduled tasks) and of the Apache cache rules; checks of the documentation (links and anchors between the Markdown files, the screenshots the README shows, the settings and defaults in [CONFIGURATION.md](CONFIGURATION.md) and `.env.example`, the format of `CHANGELOG.md`); and checks of the workflows (every action pinned to a commit, the release workflow, Dependabot) and of `bin/release-notes.php`. The image itself is built and smoke-tested by the CI's Docker job.

Stripe is never contacted by the tests. Most of them talk to `tests/Support/stripe_mock_router.php`, a thin HTTP wrapper
around the payment simulator that the suites start automatically, so they can record, fail and rewrite Stripe requests.
The payment simulator's own tests run it in-process, as a demo does. You can also run the wrapper by hand
(`php -S 127.0.0.1:12111 tests/Support/stripe_mock_router.php`, then `PAYMENT_SIMULATOR=false`, `STRIPE_SECRET_KEY=sk_test_anything`
and `STRIPE_API_BASE=http://127.0.0.1:12111` in `.env`), but `PAYMENT_SIMULATOR=true` is simpler for clicking through checkout.

No email leaves the machine either: the SMTP tests talk to a small local server, `tests/Support/fake_smtp_server.php`, which only records the conversation.

The Docker entrypoint has a test of its own in Bash, `tests/Docker/entrypoint_test.sh`; [DEPLOYMENT.md](DEPLOYMENT.md#checks-and-ci) explains it and the smoke test `tests/Docker/smoke.sh`.

## Browser tests

In short (the sections below explain each step):

```bash
npm ci && npx playwright install chromium
DB_PORT=3306 DB_USER=root DB_PASS=secret npx playwright test   # starts a local demo store on a scratch database (bookwise_e2e_test)
BASE_URL=http://localhost:8080 npx playwright test             # or tests the Docker store
```

### What the browser tests cover

The tests use a store in demo mode (`APP_DEMO=true`) with the payment simulator and the demo inbox (`MAIL_DRIVER=database`), as `docker-compose.yml` and the public demo set it up.
Each test is independent: it creates its own customer, either a one-click demo customer (where that is what is tested) or a customer who signs up through the sign-up form.

| File | What it checks |
| --- | --- |
| `e2e/browsing.spec.js` | A guest, in English and Arabic: the home page; the Explore page's live search, genre tabs and pages, which `explore.js` updates without reloading the page, with covers as `<picture>` elements whose WebP copy the browser uses; a book page whose **Log in to buy** link leads to the sign-in page and remembers the book. |
| `e2e/language.spec.js` | The language switch: Arabic pages are right-to-left, stay Arabic from page to page and keep the Explore page's search; the empty search field shows its Arabic placeholder right to left; Arabic pages never scroll sideways at 375 and 1280 pixels. |
| `e2e/purchase.spec.js` | **Try as a customer**, a book in the cart, the checkout page with the demo customer's saved address chosen, **Pay** on the simulated payment page, the confirmation, the order page and the confirmation email in the demo inbox, whose line for the book (a title in Latin letters) shows the price after the title in both directions (English in a desktop window, Arabic in a 375-pixel one). **Decline** keeps the payment page open for another try, **Cancel** charges nothing and keeps the cart, and **Slow payment** is confirmed after about 15 seconds. |
| `e2e/signup.spec.js` | Signing up through the form shows the welcome email (or, with `REQUIRE_EMAIL_VERIFICATION=true`, the confirmation email) in the demo inbox, in English and Arabic. |
| `e2e/admin-viewer.spec.js` | **Try as an administrator (read-only)**: the dashboard, the orders page (its search still works) and an order's page, in English and Arabic, with every control of every form that would change something switched off, and customers' details shortened. In Arabic, the empty order search shows its placeholder right to left, and the cover hint draws `https://` and its paths left to right. |
| `e2e/keyboard.spec.js` | The profile page with the keyboard alone: the skip link, the section menu in tab order, Enter opens a section and moves the focus to its heading, Tab reaches the main controls of every section, the focus is always visible, and a form is sent with Enter. An address sent back with errors keeps the focus on the first field the server rejected. The demo banner's link keeps a dark focus ring on its yellow inside the dark header. |
| `e2e/accessibility.spec.js` | axe-core on the main pages in English and Arabic (see [below](#accessibility-checks)). |

Shared steps (the demo buttons, signing up, buying a book, the axe check) are in `e2e/support/store.js`.

### Setting up

You need Node.js 20 or newer (CI uses Node.js 24). In the project folder:

```bash
npm ci                                  # Playwright and axe-core, as locked in package-lock.json
npx playwright install chromium         # on Linux: npx playwright install --with-deps chromium
```

### Running them against a local store

You need PHP with the project's Composer dependencies (`composer install`) and a MySQL or MariaDB database that the tests may wipe. Its name must end in `_test`:

```sql
CREATE DATABASE bookwise_e2e_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then run the tests with the database's connection settings:

```bash
DB_PORT=3306 DB_USER=root DB_PASS=secret npx playwright test
```

Without `BASE_URL`, Playwright first starts `e2e/local-server.js` (also available as `npm run serve:e2e`) and stops it after the run. That script:

1. stops if something already answers on port 8181 (it never resets the database of a store that is still running), then drops every table of `DB_NAME` (default `bookwise_e2e_test`; it refuses any name that does not end in `_test`) and runs `php bin/install.php --seed`, so every run starts from the sample catalog and the demo's [showcase](ARCHITECTURE.md#the-showcase-and-the-daily-reset) (which `bin/install.php` adds in demo mode), and nothing else;
2. serves `public/` with PHP's built-in server, `php -S 127.0.0.1:8181 -t public`, with the settings of a demo:

   | Setting | Value |
   | --- | --- |
   | `APP_ENV` / `APP_DEMO` / `APP_URL` | `local` / `true` / `http://127.0.0.1:8181` |
   | `APP_NAME` / `APP_DEBUG` / `APP_TIMEZONE` / `APP_DEFAULT_LOCALE` | `BookWise` / `false` / `Asia/Riyadh` / `en` |
   | `PAYMENT_SIMULATOR` / `STRIPE_CURRENCY` | `true` / `sar` |
   | `MAIL_DRIVER` / `REQUIRE_EMAIL_VERIFICATION` | `database` (the demo inbox) / `false` (`true` in your shell shows the confirmation emails, but the tests that sign customers up need `false`) |
   | `PAYMENT_SIMULATOR_STORE`, `LOG_DIR`, sessions | the folder `bookwise-e2e-8181` in the system's temporary folder, emptied when the store starts and deleted when it stops (on Windows, Playwright ends the store without letting it clean up, so the folder stays until the next start) |
   | `DB_NAME` | from your shell (never from `.env`); default `bookwise_e2e_test` |
   | `DB_HOST` / `DB_PORT` / `DB_USER` / `DB_PASS` | from your shell, or else from the project's `.env` file; defaults `127.0.0.1` / `3306` / `root` / empty |

   A `.env` file in the project cannot change the other settings of this table. Settings that are not in it come from your shell or else from `.env`, as for any store: with `BOOKWISE_ADMIN_EMAIL` and `BOOKWISE_ADMIN_PASSWORD`, for example, `bin/install.php` also creates that administrator in the test database (as for the [README screenshots](#readme-screenshots)).
   `PHP_BINARY` chooses another PHP than the `php` on your `PATH`.

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

### Running them against Docker

```bash
docker compose up -d --build
BASE_URL=http://localhost:8080 npx playwright test
```

`BASE_URL` must be the store's `APP_URL`, because the payment page sends the browser back there. Any store set up like `docker-compose.yml` works (demo mode, the demo inbox and `REQUIRE_EMAIL_VERIFICATION=false`), also one in a subfolder such as `BASE_URL=http://localhost/bookwise`.
The Compose store keeps its database between runs; `docker compose down -v` deletes it if the demo-customer limit is reached.
The tests create customers and orders, so never point them at a public demo.

### Useful options

| Command | What it does |
| --- | --- |
| `npx playwright test e2e/purchase.spec.js` | One file |
| `npx playwright test -g "slow payment"` | Tests whose title matches |
| `npx playwright test --headed` / `--ui` | Watch the browser / Playwright's interactive mode |
| `npx playwright show-report` | The HTML report of the last run (it also opens by itself after a local run with failures) |
| `npx playwright show-trace test-results/<test>/trace.zip` | Step through a failed test: every action, the page before and after it, the network and the console |

### Accessibility checks

`expectAccessible()` in `e2e/support/store.js` runs axe-core on the page with its default rules (WCAG 2 A and AA, and best practices) and the rule `label-content-name-mismatch` (WCAG 2.5.3, Label in Name), which axe marks experimental and leaves off. It fails on **serious** or **critical** violations, naming each rule and element.
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
- In Arabic, the accessible name of the header's cart link left out the number that its icon shows for 0, 1 and 2 items ("السلة، عنصر واحد"), against Label in Name. Every Arabic form now includes the number ("السلة (1)، عنصر واحد"), which `tests/Unit/CommerceTranslationsTest.php` also checks.

The HTTP suite checks both rules on every page it opens (`HtmlDocument::contentOutsideLandmarks()` and `duplicateLandmarks()` in `tests/Support`, used by `assertHtmlPage()`), and `tests/Http/AccessibilityMarkupTest.php` checks the two fixes.

## Continuous integration

`.github/workflows/ci.yml` runs on every push to `main` and every pull request, in three jobs:

- **Tests** runs `composer validate`, `composer audit`, lint, PHPStan and all PHPUnit suites (with `BOOKWISE_REQUIRE_DB=1`).
  It runs on PHP 8.3, 8.4 and 8.5 against a MySQL 8.4 service, and once more on PHP 8.4 against MariaDB 11.4 (MySQL 8.0 reached its end of life in April 2026).
  PHP 8.3 is the oldest version BookWise supports: PHP 8.1 is past its end of life, and PHP 8.2 receives security fixes only until December 2026.
- **Docker image** builds the image and smoke-tests the running container ([DEPLOYMENT.md](DEPLOYMENT.md#checks-and-ci) lists its checks).
- **Browser tests** builds the image, starts the Compose store as the Docker job does, installs Chromium and runs `npx playwright test` against `http://localhost:8080`.
  On CI, a failed test is tried once more and two tests run at a time. When a test fails, the job uploads the HTML report (with screenshots and traces) as the `playwright-report` artifact of the workflow run, and prints the containers' logs.

The workflows' actions are pinned to commit SHAs, with the release in a comment, and a deploy test checks that every action of every workflow stays pinned.
Dependabot (`.github/dependabot.yml`) proposes updates every week for the Composer packages, the npm packages of the browser tests, the actions, the Dockerfile's base images and `docker-compose.yml`: minor and patch updates of each come as one grouped pull request, major updates one by one.
It does not propose PHP 8.5 for the image or MySQL versions after the 8.4 LTS line: those moves are made by hand (the file's comments say why).
`package.json` pins `playwright-core` (which `@axe-core/playwright` also asks for) to the version of `@playwright/test` with an `overrides` entry that refers to it (`"$@playwright/test"`), so an update of `@playwright/test` moves both.

`.github/workflows/release.yml` publishes a release; [CONTRIBUTING.md](../CONTRIBUTING.md#releasing-a-version) describes it.

## README screenshots

`npm run screenshots` takes a new set of screenshots for the README, as JPEG files (quality 78) in `docs/screenshots/`:

| File | Shows |
| --- | --- |
| `home.jpg`, `explore.jpg`, `home-ar.jpg` | The home page, the Explore page and the Arabic home page, as a guest |
| `book.jpg`, `cart.jpg`, `checkout.jpg`, `payment-simulator.jpg`, `order.jpg`, `inbox.jpg` | A demo customer buying the first book the home page recommends: its book page, the cart, the checkout page, the simulated payment page, the order page (with its timeline) and the demo inbox |
| `order-ar.jpg` | The same order page in Arabic |
| `admin-dashboard.jpg`, `admin-orders.jpg` | The admin dashboard and the orders page (the demo's [showcase](ARCHITECTURE.md#the-showcase-and-the-daily-reset) orders at every stage of their delivery) |
| `mobile.jpg`, `mobile-order-ar.jpg` | On a phone (375 pixels wide): the home page, and the customer's order page in Arabic |

Desktop pages are taken at 1280 × 800 pixels, the phone at 375 × 812 with a device pixel ratio of 2.
Like the tests, the script uses the store at `BASE_URL`, or starts the local store described above:

```bash
npm run screenshots
BASE_URL=http://localhost:8080 SCREENSHOT_ADMIN_EMAIL=admin@bookwise.test SCREENSHOT_ADMIN_PASSWORD=local-admin-password npm run screenshots
```

With `SCREENSHOT_ADMIN_EMAIL` and `SCREENSHOT_ADMIN_PASSWORD` (an owner administrator of that store, such as the local one of `docker-compose.yml` above), the order is marked shipped first, so its timeline shows the carrier and the tracking number, and the admin pages are the owner's. Without them, they are the read-only demo administrator's, with customers' names and addresses shortened.
The local store has no owner administrator of its own, but `bin/install.php` creates one when `BOOKWISE_ADMIN_EMAIL` and `BOOKWISE_ADMIN_PASSWORD` are set, and `e2e/local-server.js` passes your shell's variables on to it (Bash):

```bash
export BOOKWISE_ADMIN_EMAIL=owner@bookwise.test BOOKWISE_ADMIN_PASSWORD='<a password of your own>'
SCREENSHOT_ADMIN_EMAIL="$BOOKWISE_ADMIN_EMAIL" SCREENSHOT_ADMIN_PASSWORD="$BOOKWISE_ADMIN_PASSWORD" \
    DB_PORT=3306 DB_USER=root DB_PASS=secret npm run screenshots
```

The script overwrites the files of the same name; look at every image before you commit it. The README's [Screenshots](../README.md#screenshots) section shows all of them: `tests/Docker/DocumentationTest.php` fails when an image in `docs/screenshots/` is not shown there, or when the README shows one that does not exist.
