# Audit and remediation record

This document records the October 2026 audit of the original BookWise project, the changes made,
and how they were verified. It lets reviewers trace why the code looks the way it does.

## 1. Original state

The original project was a set of procedural PHP pages using `mysqli`, plus a folder of raw MariaDB data files instead of an SQL export.
Every page and handler was reviewed by tracing each feature from the form, through the PHP handler, to the database.

### Critical and high-severity problems found

| Area | Problem |
| --- | --- |
| Secrets | A Stripe secret key was hard-coded in three files, and a SendGrid API key in two. **Both must be revoked**, as they were distributed with the source. |
| SQL injection | Login, sign-up, password reset and token lookup concatenated raw input into SQL. Login could be bypassed with a crafted email. |
| Passwords | Unsalted MD5, plus a stored MD5 of the "confirm password" field. |
| Payments | `success_pay.php` recorded sales and emptied the cart for anyone who opened the URL; no payment was ever verified. |
| Payments | `unit_amount` was sent to Stripe as a float (`19.99 * 100`), which Stripe rejects for many prices. |
| File upload | The profile picture upload trusted the client's MIME type and kept the original filename in a web directory, so a `.php` upload could execute (remote code execution) or overwrite other files. |
| Access control | `delete_from_cart.php` deleted any cart row by id (IDOR). The admin "delete book" action was a GET link with no CSRF protection. |
| CSRF | No form had CSRF protection. |
| Privacy | Reviews publicly displayed reviewers' email addresses. The payment-method field invited users to type card numbers in plain text. |
| Data integrity | No unique keys on cart (user, book), favorites or emails, so `ON DUPLICATE KEY UPDATE` never fired and duplicate cart rows accumulated. There were no orders table and no transactions. |
| Repository | The raw database files contained 22 real user accounts (emails and MD5 hashes). The repo also held committed `vendor/` folders, user-uploaded photos (including a copyrighted still and third-party logos), IDE files and a 4 MB background image. |
| Portability | Six links differed in case from the actual file names (`selected.php`, `ProfilePage.css`, …), which works on Windows/XAMPP and breaks on Linux. |
| Missing dependencies | The SendGrid `vendor/` lived in a separate folder, so `Register.php` and `reset_password.php` fatally errored on a fresh clone. |

There were also many medium and low issues: reset tokens stored in plaintext, timezone mismatches in token expiry, unvalidated ratings (0 or any integer), an Explore page that showed only 15 books, inline scripts incompatible with a strict CSP, broken mobile navigation (fixed 90px height) and others.

## 2. Database recovery

The `.frm` header identified the server as **MariaDB 10.4.32**.
In an isolated MariaDB 10.4 sandbox, the table definitions were recovered:
the `.frm` files were rewritten as MyISAM and `REPAIR TABLE … USE_FRM` was run.
Row data was recovered with `ALTER TABLE … IMPORT TABLESPACE`.

- `database/schema.sql` keeps the original table and column names and adds the constraints the code relies on.
- `database/seed.sql` contains **only** the 55-book catalog. No accounts, reviews, tokens or sales are published.
- The original foreign-key definitions could not be recovered (they were stored in the missing `ibdata1`) and were re-specified.
- The `comments` table's primary B-tree was corrupt in the export. It was not needed for the seed.

## 3. Changes

### Architecture

The project keeps its plain-PHP page structure, with two changes:

- **`public/` is the web root.** Configuration, dependencies, logs and source code sit outside it.
- **Shared code moved to `src/`**, autoloaded by Composer:
  - configuration, PDO and transactions, CSRF and authentication, validation, mail;
  - the catalog, cart, order and payment domain logic;
  - the shared views.

### Security

- PDO prepared statements everywhere, and output escaping with `e()`.
- A strict Content-Security-Policy. Every inline script was moved into `public/js`.
- CSRF tokens on every state-changing POST, and POST-only endpoints.
- `password_hash()` with transparent MD5 upgrade, and timing-equalised login.
- Login throttling per email+IP, per IP, and a global per-email ceiling.
- Session protection:
  - session ID regenerated at login;
  - 2-hour idle limit and 24-hour absolute limit;
  - a password reset signs out other sessions (`session_version`).
- Password reset:
  - 256-bit tokens stored only as SHA-256 hashes;
  - single use, 60-minute expiry and a request cooldown;
  - no account enumeration.
- Avatar upload:
  - type detected from content, with size and dimension limits;
  - random filename, and only GD-re-encoded pixels are stored;
  - PHP execution blocked in `uploads/`.
- Deny-by-default `.htaccess` at the project root for Apache setups that serve the whole folder.
  This was verified against XAMPP's Apache with stock `htdocs` settings.
- Text that is not valid UTF-8 is rejected as empty input.
- Live or restricted Stripe keys are refused outside `APP_ENV=production`.

### Payments

- Orders and order items snapshot the cart, using prices from the database and integer minor units.
- An order is marked paid only after the Checkout Session is **retrieved from Stripe**
  and its order reference, amount, currency and payment status all match.
- Fulfilment is idempotent under a row lock.
- Checkout is serialised per customer (`GET_LOCK`). Earlier pending sessions are expired, fulfilled or marked expired before a new one opens.
- Webhooks are signature-verified and then re-fetch the session from the API.
- Mismatched captured payments are flagged for manual review instead of being fulfilled.
- A minimum order total is enforced, and only two-decimal currencies are accepted.

### Features and user experience

The visual identity is unchanged: the colours, the Quicksand font, the card layouts and the book background.

- Shared navigation shows a cart badge.
- Responsive layouts work down to 375px.
- Flash messages replace raw `echo` output and JS alerts.
- The home page uses real data: catalog size, genre chips, recommended books and best sellers.
- Explore has data-driven genre tabs, search by title or author, all books listed, and a no-JS fallback.
- The book page has a quantity selector, accessible star ratings, and reviews that show names.
- The cart has quantity editing and an order summary.
- Payment results are clear for paid, processing, unpaid, under review and error states.
- The profile has an order history and a payment *preference* (no card data).
- The admin dashboard adds totals, recent orders and validated book forms.
- Accessibility:
  - labels and error associations;
  - a two-tone focus ring and keyboard-operable controls;
  - `dir="auto"` for Arabic text, and contrast fixes.

### Tooling and repository

- One Composer project with Stripe, SendGrid, PHPUnit 10 and PHPStan 2. `composer audit` is clean.
- `.gitignore` excludes secrets, dumps, engine files, logs, uploads and caches.
- A reproducible schema and seed, plus `bin/upgrade-legacy-db.php`. The upgrade script is idempotent and was tested on a copy of the recovered legacy database.
- `bin/create-admin.php` replaces hand-inserted admin rows.
- GitHub Actions CI: validate, audit, lint, PHPStan and all test suites on PHP 8.1–8.4 with MySQL 8.0.

## 4. Verification

Commands run on the final tree, against isolated sandbox databases only.

| Check | Command | Result |
| --- | --- | --- |
| Composer metadata | `composer validate --strict` | valid |
| Dependency advisories | `composer audit` | no advisories |
| PHP syntax | `php bin/lint.php` | 95 files, 0 errors |
| JavaScript syntax | `node --check public/js/*.js` | all OK |
| Static analysis | `vendor/bin/phpstan analyse` (level 6: src, bin, public) | no errors |
| Tests: PHP 8.2.12 + MariaDB 10.4.32 | `DB_PORT=3399 BOOKWISE_REQUIRE_DB=1 vendor/bin/phpunit` | **OK, 579 tests** (unit, integration and HTTP end-to-end) |
| Tests: PHP 8.4.24 + MySQL 8.4.11 | same suite against a MySQL 8.4 sandbox | **OK, 579 tests** |
| Schema portability | `schema.sql` + `seed.sql` imported on MariaDB 10.4 and MySQL 8.4 | OK (55 books) |
| Legacy upgrade | `bin/upgrade-legacy-db.php` on a copy of the recovered legacy database, run twice | OK, idempotent |
| Apache exposure | XAMPP Apache 2.4.58 with stock `htdocs` directives, project mounted as a sub-folder | `.env`, logs, `vendor/`, `src/`, `bin/` and `tests/` return 403; `public/` is served; PHP in `uploads/` is not executed |
| Browser walkthrough | Local app, real browser, 375px and 1280px widths | Sign-up, browse, cart, mock Stripe payment, verified success page, review and admin dashboard all worked. No page overflow and no console errors. |

The suite was additionally exercised by an independent five-lens adversarial review (security, payments, functional, frontend, release).
It reported 40 findings: separate verifiers confirmed 39 (about 33 distinct after removing duplicates across lenses) and refuted 1.
All confirmed findings were fixed. The exception is sign-up account enumeration, which needs email verification and is documented as a known limitation in the README.
An end-to-end test suite added during the review found one further bug class: invalid UTF-8 input caused HTTP 500 errors. It was fixed.

### Not verified

- **PHP 8.1:** no runtime was available locally. CI covers it.
- **Real Stripe test mode:** no keys were available, so all payment tests use the local API mock in `tests/Support/stripe_mock_router.php`.
- **Real SendGrid delivery:** the mail driver was tested with the `log` and `null` drivers only.
- **nginx deployment:** not tested; the README documents the required `uploads/` rule.

## 5. Follow-up: new features and their review

After the first release, these features were added:

- email verification, password change and account deletion;
- stock tracking, catalog pagination, admin cover uploads, an admin orders page and full refunds through Stripe (including refunds made in the Stripe Dashboard);
- an Arabic interface with a right-to-left layout;
- schema migrations;
- a Docker image and a free public demo setup (Render + Aiven) with a built-in payment simulator.

They were then reviewed adversarially in four areas (accounts, commerce, deployment and migrations, translations).
Each reviewer's findings went to a separate verifier who tried to refute them, by reading the code paths and, where possible, reproducing them against a scratch database.
21 findings were confirmed (4 medium, 17 low) and 3 refuted. All confirmed findings were fixed and covered by tests:

| Area | Finding | Fix |
| --- | --- | --- |
| Accounts | Opening the confirmation link signed in without a password. A person who registered someone else's address kept access after the owner confirmed it, and mail scanners used up links. | The link only shows a form; the account's password confirms the address. An owner who never chose that password uses the password reset, which confirms the address and signs out other sessions. |
| Accounts | Signing up and then logging in with the chosen password told new addresses from registered ones. | A sign-in on an unconfirmed account gets the same answer as a wrong password; a fresh link is sent quietly. |
| Accounts | With verification off, reset emails greeted a name typed by whoever registered the address. | Emails use the name only for confirmed accounts. |
| Accounts | Unhandled errors logged the full URL, including single-use tokens. | Only the path is logged. |
| Commerce | Refunding an oversold order restocked copies that never left the shelf. | Each order line records the copies it took (`order_items.stock_taken`, migration 003); refunds put back exactly those. |
| Commerce | Demo visitors could sell the shared catalog out for good. | Demo mode tops the stock up at every start and never takes a book below 3 copies. |
| Commerce | Demo refunds failed after a restart (the simulator forgets its payments). | In demo mode such a refund is recorded locally. |
| Commerce | Saving the edit-book form overwrote stock changes made meanwhile by sales or refunds. | The stock is changed by the difference the admin made. |
| Commerce | In demo mode, many parallel requests could tie up every Apache worker waiting on the simulator, which runs on the same server. | Short timeouts and no retries for simulator calls. |
| Commerce | A book whose uploaded cover was lost (free hosting) could no longer be edited. | The book's existing cover never blocks saving. |
| Deployment | The legacy upgrade script failed on databases upgraded by its earlier version. | It makes `orders.user_id` nullable before adding the `ON DELETE SET NULL` key. |
| Deployment | An interrupted first installation was later taken for a complete database. | Migrations and the installer refuse a partly installed database with a clear message. |
| Deployment | The container always loaded the sample catalog, also for a real store. | Only in demo mode or with `BOOKWISE_SEED=true`. |
| Translations | Language preference could be changed by a session that had been signed out. Language links broke on the site root, on error pages of form actions, and after in-page navigation. | The preference is saved only for valid sessions, and the links are built from the page actually shown. |
| Translations | The "Unknown" favorite-genre placeholder was shown and saved as text; an Arabic total was forced left-to-right; the review counter used the browser's number format; Apache showed its English error page. | Fixed; the favorite genre is optional, and top-level missing URLs get the site's translated error page. |

The translation review also crawled every page in Arabic as a guest, a customer and an administrator.
Apart from the findings above it found no untranslated interface text. Catalog genre names are now translated too.

Verification after the fixes:

| Check | Result |
| --- | --- |
| `php bin/lint.php`, `node --check public/js/*.js` | no errors |
| PHPStan level 6 | no errors |
| PHPUnit (unit, integration, http, deploy) on PHP 8.2 + MariaDB 10.4 | **791 tests OK** (1 skipped: a Windows-only limitation of one deploy test) |
| `tests/Docker/entrypoint_test.sh` against a scratch database | 71 checks OK |
| Legacy upgrade of a first-release database, run twice | all 14 foreign keys, migrations 002 and 003 applied, idempotent |
| Docker image build and container smoke test | run by the CI's Docker job (Docker was not available locally) |
