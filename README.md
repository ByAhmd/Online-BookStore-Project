# BookWise — Online Book Store

BookWise is an online bookstore written in plain PHP with MySQL/MariaDB.
Customers browse a catalog, search and filter by genre, keep favorites, buy books
through **Stripe Checkout** and review the books they bought. Administrators manage the
catalog and see recent orders.

It started as a team project for the *Fundamentals of Web Programming* course at
King Faisal University (see the in-app **About** page). It has since been audited
and partly rewritten for security, correctness and maintainability. The plain-PHP
page architecture is unchanged; there is no framework.

---

## Contents

- [Features](#features)
- [Technology stack](#technology-stack)
- [Project structure](#project-structure)
- [Getting started](#getting-started)
- [Configuration reference](#configuration-reference)
- [Payments with Stripe (test mode)](#payments-with-stripe-test-mode)
- [Email](#email)
- [Upgrading a database from the original project](#upgrading-a-database-from-the-original-project)
- [Testing and quality checks](#testing-and-quality-checks)
- [Security notes](#security-notes)
- [Deployment checklist](#deployment-checklist)
- [Known limitations](#known-limitations)
- [Screenshots](#screenshots)
- [License](#license)

---

## Features

**Customers**
- Sign up, log in and log out. Passwords are hashed with `password_hash()`, failed logins are throttled, and the session ID is regenerated on login.
- Password reset by email, using single-use links that expire after 60 minutes.
- Home page with the real catalog size, genre shortcuts, recommended books (by rating) and best sellers (by sales).
- Explore page:
  - Search by title or author.
  - Genre tabs generated from the catalog, plus **Favorites**.
  - Works without JavaScript; with JavaScript, results update in place.
- Book pages:
  - Description, price, average rating and copies sold.
  - Add to cart with a quantity, and add to or remove from favorites.
  - Reviews with a 1–5 star rating, only for customers who bought the book and only once per book. Other readers can like or dislike a review.
- Cart: change quantities (1–10 per title), remove items, and see totals computed from catalog prices on the server.
- Checkout on Stripe's hosted payment page. An order is confirmed only after BookWise has verified the payment with Stripe.
- Profile:
  - Name, favorite genre, date of birth and profile picture (JPEG/PNG/GIF/WebP, max 2 MB, re-encoded on upload).
  - A payment-method *preference* (no card data is ever stored).
  - Order history with status.

**Administrators**
- Add, edit and delete books, with server-side validation.
- Dashboard with catalog and sales totals and the 20 most recent orders.
- Accounts are created from the command line (`bin/create-admin.php`); there is no public admin sign-up.

## Technology stack

| Layer | Technology |
| --- | --- |
| Language | PHP ≥ 8.1 (tested on 8.2 and 8.4; CI covers 8.1–8.4) |
| Database | MySQL ≥ 8.0.16 or MariaDB ≥ 10.4 (InnoDB, utf8mb4), accessed with PDO prepared statements |
| Payments | [Stripe Checkout](https://stripe.com/docs/payments/checkout) via `stripe/stripe-php` 16.x |
| Email | SendGrid (`sendgrid/sendgrid` 8.x), or a local log file for development |
| Frontend | Server-rendered HTML, plain CSS and vanilla JavaScript (no build step), Google Fonts (Quicksand) |
| Tooling | Composer, PHPUnit 10, PHPStan 2 (level 6), GitHub Actions |

Required PHP extensions: `pdo_mysql`, `mbstring`, `curl`, `json`, `fileinfo`.
`gd` is recommended (it re-encodes uploaded avatars, which strips metadata and embedded payloads).

## Project structure

```
.
├── public/                 Web root: point your server's document root here
│   ├── *.php               Pages and form handlers (index.php = login/sign-up, Home_Page.php, Explore_Page.php, …)
│   ├── css/  js/           Stylesheets and scripts (common.css/common.js are shared)
│   ├── imges/              Catalog covers and UI images (folder name kept: database rows reference it)
│   └── uploads/avatars/    Uploaded profile pictures (git-ignored, must be writable)
├── src/                    Shared PHP code (autoloaded by Composer)
│   ├── bootstrap.php       Loaded by every page: config, error handling, session, security headers
│   ├── auth.php users.php password_reset.php csrf.php validation.php
│   ├── books.php cart.php orders.php   Catalog, cart and Stripe order logic
│   ├── db.php env.php helpers.php mailer.php uploads.php
│   └── views/              Shared header (navigation), footer and error page
├── database/
│   ├── schema.sql          Database schema (fresh installs)
│   └── seed.sql            Sample catalog: 55 books, no user data
├── bin/
│   ├── create-admin.php    Create an administrator or reset an administrator's password
│   ├── upgrade-legacy-db.php  Upgrade a database created by the original project
│   └── lint.php            `php -l` over the whole project
├── docs/                   AUDIT.md (audit and remediation record), screenshots/
├── storage/logs/           app.log, php-error.log and mail.log (git-ignored)
├── tests/                  PHPUnit suites (unit, integration, http) and a local Stripe API mock
├── .env.example            Configuration template
└── .github/workflows/ci.yml
```

Only `public/` should be served to browsers; `.env`, `vendor/`, `src/` and the logs live outside it.
This holds when the web server's document root (or alias) is `public/`, as described below.
As a safety net for Apache setups that serve the whole folder, the root `.htaccess` denies everything except `public/`.

## Getting started

### 1. Prerequisites

- PHP 8.1+ with the extensions listed above, and [Composer](https://getcomposer.org/)
- MySQL 8.0.16+ or MariaDB 10.4+ (XAMPP, Laragon, DBngin, Docker, a system service and so on)
- A free [Stripe](https://dashboard.stripe.com/register) account in **test mode** (only needed for checkout)

### 2. Install dependencies

```bash
git clone <your-fork-url> bookwise
cd bookwise
composer install
```

### 3. Create the database

```sql
CREATE DATABASE bookwise CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bookwise'@'localhost' IDENTIFIED BY 'choose-a-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON bookwise.* TO 'bookwise'@'localhost';
```

Import the schema and, optionally, the sample catalog. Use an account that can create tables, for example `root`:

```bash
mysql -u root -p bookwise < database/schema.sql
mysql -u root -p bookwise < database/seed.sql
```

On XAMPP you can instead use phpMyAdmin → *Import* with the same two files, in that order.

### 4. Configure the environment

```bash
cp .env.example .env
```

Edit `.env`: set the `DB_*` values, `APP_URL`, and your Stripe **test** keys. See the [configuration reference](#configuration-reference).

### 5. Create an administrator

```bash
php bin/create-admin.php admin@example.com "Store Admin"
```

You will be prompted for a password (minimum 8 characters). To script it, set
`BOOKWISE_ADMIN_PASSWORD` in the environment instead.

Running the command again for the same email resets that administrator's password.
It also signs out the administrator's other sessions and clears any sign-in lockout.

### 6. Run it

**PHP built-in server (simplest):**

```bash
php -S localhost:8000 -t public
```

Open <http://localhost:8000>. With this option, set `APP_URL=http://localhost:8000`.

**XAMPP / Apache:** keep the project *outside* `htdocs` and expose only `public/` with an alias.
Add this to `C:\xampp\apache\conf\extra\httpd-xampp.conf`, or to the end of `httpd.conf`, then restart Apache:

```apache
Alias /bookwise "C:/path/to/bookwise/public"
<Directory "C:/path/to/bookwise/public">
    AllowOverride All
    Require all granted
</Directory>
```

Open `http://localhost/bookwise/`. With this option, set `APP_URL=http://localhost/bookwise`.
In production, point the virtual host's `DocumentRoot` at `public/`.

> If you do copy the whole project into `htdocs`, Apache still serves only `public/`.
> The project-root `.htaccess` denies everything else (`.env`, `storage/logs`, `vendor/`, `src/` and so on), and `public/.htaccess` re-allows the web root.
> This relies on `AllowOverride All`, which is XAMPP's default.
> Servers that ignore `.htaccess` files (nginx, `php -S`) must use `public/` as the document root.

Customers register through the **Sign Up** button. Administrators log in on the same form.

## Configuration reference

All settings come from environment variables. `src/env.php` loads `.env` for
convenience, and variables already set in the real environment take precedence.

| Variable | Default | Description |
| --- | --- | --- |
| `APP_NAME` | `BookWise` | Name shown in page titles, the navigation bar and the footer |
| `APP_ENV` | `production` | `local`, `testing` or `production`. Live Stripe keys are refused unless this is `production`. |
| `APP_DEBUG` | `false` | Show exception details in the browser. **Never enable in production.** |
| `APP_URL` | `http://localhost:8000` | Public URL of `public/` without a trailing slash. Used for Stripe return URLs and password-reset links. |
| `APP_TIMEZONE` | `Asia/Riyadh` | PHP and database-session timezone |
| `LOG_DIR` | `storage/logs` | Directory for `app.log`, `php-error.log` and `mail.log` (relative paths start at the project root) |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | Database server |
| `DB_NAME` | `bookwise` | Database name |
| `DB_USER` / `DB_PASS` | `root` / *(empty)* | Database credentials |
| `STRIPE_SECRET_KEY` | — | Stripe secret key (`sk_test_…` for development) |
| `STRIPE_WEBHOOK_SECRET` | — | Signing secret of the webhook endpoint (`whsec_…`) |
| `STRIPE_CURRENCY` | `sar` | ISO code of a **two-decimal** currency that book prices are charged in (zero- and three-decimal currencies such as JPY or KWD are refused) |
| `STRIPE_API_BASE` | — | **Tests only:** points the Stripe SDK at the local mock in `tests/Support` |
| `MAIL_DRIVER` | `log` | `log` (write to `mail.log`), `sendgrid`, or `null` (discard) |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | `no-reply@example.com` / `BookWise` | Sender of outgoing email |
| `SENDGRID_API_KEY` | — | Needed when `MAIL_DRIVER=sendgrid` |
| `BOOKWISE_ADMIN_PASSWORD` | — | Optional; used only by `bin/create-admin.php` |

## Payments with Stripe (test mode)

1. In the [Stripe Dashboard](https://dashboard.stripe.com/test/apikeys) (test mode), copy the **Secret key** (`sk_test_…`) into `STRIPE_SECRET_KEY`.
2. Make sure `APP_URL` is the address you browse to; Stripe redirects back to `APP_URL/success_pay.php`.
3. Pay with a [Stripe test card](https://docs.stripe.com/testing#cards) such as `4242 4242 4242 4242`, with any future expiry date and any CVC.

How a payment becomes an order:

1. `checkout.php` copies the cart into a **pending** order, using prices from the database and integer minor units.
   It then creates a Checkout Session for exactly that amount.
   Checkouts are serialised per customer, so double clicks, two tabs or two devices cannot open two payable sessions.
   Orders below the minimum charge (2.00 in the store currency) are refused before Stripe is called.
2. After payment, `success_pay.php` **retrieves the session from Stripe** and checks that it belongs to the signed-in customer's order.
   It also checks that the amount, currency and payment status all match.
   Only then is the order marked **paid**, the sales recorded and the purchased items removed from the cart.
   Opening the success URL without a real payment changes nothing.
3. Starting a new checkout first resolves older pending sessions: it expires open ones, fulfils paid ones and marks expired ones.
   A customer therefore can never have two payable sessions for one cart.

**Webhooks (recommended).** These confirm payments even if the customer closes the browser before returning.
Register `APP_URL/stripe_webhook.php` for these events:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `checkout.session.expired`

Put the endpoint's signing secret in `STRIPE_WEBHOOK_SECRET`. For local development, use the [Stripe CLI](https://docs.stripe.com/stripe-cli):

```bash
stripe listen --forward-to localhost:8000/stripe_webhook.php
```

`stripe listen` prints a `whsec_…` secret to use. Every webhook request's signature is verified, and unsigned requests are rejected.
A webhook is treated only as a notification: BookWise re-fetches the Checkout Session from the Stripe API and acts on that state.
A leaked webhook secret alone therefore cannot mark an unpaid order as paid, and out-of-order deliveries are harmless.

## Email

- `MAIL_DRIVER=log` (default) writes every email, including password-reset links, to `storage/logs/mail.log`. Nothing is sent.
- `MAIL_DRIVER=sendgrid` sends through SendGrid. It needs `SENDGRID_API_KEY` and a verified sender (`MAIL_FROM_ADDRESS`).

The app sends two emails: a welcome email after sign-up and the password-reset email.

## Upgrading a database from the original project

The original project used a database named `signup` with MD5 passwords and fewer constraints.
To keep its accounts, books, carts, favorites, reviews and sales:

```bash
mysqldump -u root -p signup > ../signup-backup.sql   # back up first, outside the project folder
# point DB_NAME at that database in .env, then:
php bin/upgrade-legacy-db.php --i-have-a-backup
```

Keep database dumps out of the repository; `*.sql` files other than `database/schema.sql` and `database/seed.sql` are git-ignored as a safety net.

The script is idempotent: each step is skipped if it has already been applied. It:

- Widens the password columns and drops the stored "confirm password" column.
- Adds the `session_version` columns used to sign out other sessions after a password change.
- Adds unique keys, merging duplicate cart lines, favorites, reviews and review reactions first.
- Clamps legacy ratings to the range 1–5.
- Deletes old plaintext reset tokens.
- Clears free-text payment-method entries, which could contain card numbers.
- Creates the `orders`, `order_items` and `login_attempts` tables.
- Recreates all foreign keys with the documented `ON DELETE` rules.

Existing MD5 passwords keep working and are re-hashed with bcrypt the next time each user logs in.

## Testing and quality checks

```bash
composer lint          # php -l on every PHP file
composer analyse       # PHPStan level 6 (src/, bin/, public/)
composer test:unit     # unit tests, no database needed
composer test          # all suites
```

The **integration** and **http** suites need a MySQL/MariaDB database whose name ends in `_test`.
They drop and recreate every table in it, and refuse to run against any other database name.
Defaults are in `phpunit.xml.dist`; override them with environment variables:

```bash
mysql -u root -p -e "CREATE DATABASE bookwise_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=root DB_PASS=secret composer test
```

If no database is reachable, the database-backed tests are skipped and say so.
Set `BOOKWISE_REQUIRE_DB=1` to make that a failure instead; CI does this.

The suites are:

- **unit:** pure functions.
- **integration:** domain code against a real database.
- **http:** end-to-end tests that start the application with `php -S` and drive the real pages, covering sign-up and login, CSRF, access control, cart, checkout, webhooks, password reset, profile uploads and admin actions.

Stripe is never contacted by the tests. `tests/Support/stripe_mock_router.php` is a small local stand-in
for the Checkout Session API, started automatically by the suites. You can also run it by hand to click
through checkout without Stripe keys:

```bash
php -S 127.0.0.1:12111 tests/Support/stripe_mock_router.php
# then, in .env: STRIPE_SECRET_KEY=sk_test_anything  STRIPE_API_BASE=http://127.0.0.1:12111
```

The mock shows a "Pay (simulate success)" / "Cancel" page in place of Stripe's hosted checkout. Use it for local testing only.

GitHub Actions (`.github/workflows/ci.yml`) runs `composer validate`, `composer audit`, lint, PHPStan and all test suites.
It runs on PHP 8.1, 8.2, 8.3 and 8.4 against a MySQL 8.0 service.

## Security notes

- **SQL injection:** all queries use PDO prepared statements with native binding.
- **XSS:** all output is escaped with `e()`. A Content-Security-Policy allows only same-origin scripts; there are no inline scripts or event-handler attributes.
- **CSRF:** every state-changing request is a POST with a per-session CSRF token. The Stripe webhook is authenticated by its signature instead.
- **Sessions:** HttpOnly, `SameSite=Lax` cookies (plus `Secure` when served over HTTPS), strict mode and ID regeneration on login.
  Sessions expire after 2 hours of inactivity or 24 hours after sign-in, and a password change signs out every other session.
- **Access control:** customer and admin checks are enforced on the server for every page and action.
  Cart lines, orders and payment returns are always scoped to the signed-in user.
- **Passwords:** bcrypt via `password_hash()`, with a transparent upgrade of legacy MD5 hashes.
  Sign-in performs one hash verification per attempt, so response times don't reveal which accounts exist.
  Login is throttled after 5 failures for one email from one IP address, 20 failures from one IP, or 100 failures for one email from anywhere, within 15 minutes.
  Attackers elsewhere therefore cannot lock the real owner out.
  `bin/create-admin.php` clears an administrator's lockout.
- **Password-reset tokens:** 256-bit random, stored only as SHA-256 hashes, single use and expiring after 60 minutes.
  Reset requests do not reveal whether an account exists.
- **Uploads:**
  - The type is detected from the file contents, and only images up to 2 MB and 4000×4000 pixels are accepted.
  - Files get random names and are re-encoded with GD, so only decoded pixels are stored.
  - `public/uploads/.htaccess` prevents script execution on Apache.
- **Input encoding:** text that is not valid UTF-8 is rejected as empty input rather than reaching the database.
- **Secrets:** read only from the environment. `.env` is git-ignored, and `.env.example` contains placeholders only.
- **Headers:** `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy` and HSTS when served over HTTPS.

> **Credential rotation:** earlier, unpublished versions of this project contained a hard-coded Stripe test secret key and a SendGrid API key in their source files.
> Those keys must be considered compromised and revoked in the Stripe and SendGrid dashboards.
> They are not present anywhere in this repository.

## Deployment checklist

- Serve **only** `public/` as the document root, over HTTPS.
- Set `APP_ENV=production` and `APP_DEBUG=false`, and set `APP_URL` to the public HTTPS URL.
- Use a dedicated database user with only `SELECT, INSERT, UPDATE, DELETE` on the application database.
- Make `storage/logs/` and `public/uploads/avatars/` writable by the web server, and keep them out of version control.
- On **nginx** or any server that ignores `.htaccess`, deny PHP execution under `/uploads/` in the server config, for example:
  `location ^~ /uploads/ { location ~ \.php$ { deny all; } }`
- Configure the Stripe webhook endpoint and secret. Use live keys only in production.
- Configure SendGrid (or switch `src/mailer.php` to your provider) and set `MAIL_FROM_ADDRESS` to a verified sender.
- Rotate logs. `storage/logs/mail.log` contains reset links when `MAIL_DRIVER=log`, so never use that driver in production.

## Known limitations

- **No stock tracking:** the original schema has no inventory, so any catalog book can always be bought (quantity is capped at 10 per title per order).
- **Single currency:** prices are stored without a currency and charged in `STRIPE_CURRENCY` (SAR by default).
- **No account self-service:** customers cannot change their email, delete their account or export data. Administrators cannot manage customers from the UI.
- **Refunds and disputes** are handled in the Stripe Dashboard and are not reflected back into orders automatically.
  A payment that does not match its order is marked `failed` and logged as `MANUAL REVIEW REQUIRED` in `app.log`.
- **Admin cover images** are referenced by path (`imges/...`) or `https://` URL; there is no image upload for book covers.
- **Login throttling** counts attempts per IP address. Behind a reverse proxy, every client shares the proxy's address unless `REMOTE_ADDR` is set correctly by the server.
- **No email verification or sign-up rate limit:** sign-up reports when an email is already registered, which reveals that the account exists.
  Login and password reset do not reveal this.
  The welcome email contains no user-supplied text, so it cannot be abused to send crafted messages.
  Adding email verification would close the enumeration gap.
- **The original database could not be fully recovered:** the original repository shipped raw MariaDB data files instead of an SQL export.
  The table definitions and the 55-book catalog were recovered from them and form `schema.sql`/`seed.sql`.
  The original foreign-key definitions could not be recovered (they lived in the missing InnoDB system tablespace).
  They were re-specified from how the application uses the data.

## Screenshots

These were captured from the application running locally with the sample catalog, a demo customer and the local Stripe mock.

| | |
| --- | --- |
| ![Home page with real catalog data](docs/screenshots/home.jpg) | ![Explore page filtered by genre](docs/screenshots/explore.jpg) |
| **Home**: catalog size, genres, recommended books | **Explore**: search and genre tabs |
| ![Book detail page](docs/screenshots/book.jpg) | ![Cart with server-side totals](docs/screenshots/cart.jpg) |
| **Book page**: price, quantity, favorites | **Cart**: quantities and totals computed on the server |
| ![Payment confirmed after verification](docs/screenshots/payment-success.jpg) | ![Reviews unlocked after purchase](docs/screenshots/reviews.jpg) |
| **Payment confirmed** after server-side verification | **Reviews**: only buyers can review; names shown, never emails |
| ![Sign-up form](docs/screenshots/sign-up.jpg) | ![Admin dashboard](docs/screenshots/admin-dashboard.jpg) |
| **Sign up / log in** | **Admin dashboard**: catalog and sales totals, book management |

## License

No license has been chosen yet, so all rights are reserved by the authors (see `"license": "proprietary"` in `composer.json`).
To let others reuse the code, add a `LICENSE` file. Common choices are [MIT](https://choosealicense.com/licenses/mit/) (permissive)
or [GPL-3.0](https://choosealicense.com/licenses/gpl-3.0/) (copyleft), and https://choosealicense.com helps compare them.
Update `composer.json` to match. The book cover images in `public/imges/` may have their own copyright owners;
confirm you have the right to redistribute them before choosing a license.
