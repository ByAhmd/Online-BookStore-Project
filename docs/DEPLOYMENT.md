# Deploying BookWise

This guide covers four setups:

- **(a) Local store with Docker:** `docker compose up --build`, a MySQL container and simulated payments.
- **(b) Free public demo on alwaysdata:** classic PHP hosting with MariaDB on [alwaysdata](https://www.alwaysdata.com)'s free plan, set up over SSH with `php bin/setup.php`. No Docker. Payments are simulated, and emails are shown in the site's demo inbox instead of being sent.
- **(c) Presenting from your own PC:** the Docker store from (a), published for a while through [Tailscale Funnel](https://tailscale.com/kb/1223/funnel).
- **(d) Real store:** real Stripe payments and real email.

Payments in (a), (b) and (c) go to the store's **built-in payment simulator**, which is described first.
Before them, [Local setup without Docker](#local-setup-without-docker) runs the store on your own PHP and database for development.
The guide ends with the [limits of the free demo](#limits-of-the-free-demo), the [deployment checklist](#deployment-checklist), [security notes](#security-notes), [troubleshooting](#troubleshooting) and the [facts that could not be confirmed](#facts-that-could-not-be-confirmed).

## Contents

- [The deployment files](#the-deployment-files)
- [The payment simulator](#the-payment-simulator)
- [The showcase and the daily reset](#the-showcase-and-the-daily-reset)
- [How the container works](#how-the-container-works)
- [Local setup without Docker](#local-setup-without-docker)
- [(a) Run it locally with Docker](#a-run-it-locally-with-docker)
- [(b) Free public demo on alwaysdata](#b-free-public-demo-on-alwaysdata)
- [(c) Present it from your own PC with Tailscale Funnel](#c-present-it-from-your-own-pc-with-tailscale-funnel)
- [(d) A real store: Stripe payments and email](#d-a-real-store-stripe-payments-and-email)
- [Limits of the free demo](#limits-of-the-free-demo)
- [Deployment checklist](#deployment-checklist)
- [Security notes](#security-notes)
- [Container settings reference](#container-settings-reference)
- [Checks and CI](#checks-and-ci)
- [Troubleshooting](#troubleshooting)
- [Facts that could not be confirmed](#facts-that-could-not-be-confirmed)

---

## The deployment files

| File | Purpose |
| --- | --- |
| `bin/setup.php` | Setup script for hosts without Docker, such as alwaysdata: checks PHP, writes `.env`, installs the database and creates an administrator. |
| `bin/demo-reset.php` | Returns the demo to its starting state (visitors' data and orders deleted, sample catalog put back, showcase added again); run it daily as a scheduled task ([below](#schedule-the-daily-reset-and-the-cleanup)). |
| `bin/demo-seed.php`, `src/demo.php` | The demo's [showcase](#the-showcase-and-the-daily-reset): fictional customers, orders and reviews, added once (also by `bin/install.php` in demo mode). |
| `bin/demo-cleanup.php` | Deletes the demo's one-click customers after 24 hours; run it daily as a scheduled task ([below](#schedule-the-daily-reset-and-the-cleanup)). |
| `src/payment_simulator.php`, `public/pay.php` | The built-in payment simulator and its payment page. |
| `Dockerfile` | Two-stage build. Composer installs the production dependencies; the runtime image is `php:8.4-apache` with `pdo_mysql`, `gd` (JPEG, PNG, WebP) and `opcache`. |
| `.dockerignore` | Allowlist for the build context. `.env`, `.git`, `vendor/`, logs, uploads and the test suite never reach the build. |
| `docker/entrypoint.sh` | Start-up script of the container, described [below](#start-up). |
| `docker/apache/` | `ports.conf` (listen on `$PORT`), `site.conf` (virtual host), `server.conf` (server-wide hardening, proxy addresses, worker limits). |
| `docker/php/bookwise.ini` | PHP overrides on top of `php.ini-production`. |
| `docker/db-ready.php` | Database check that the entrypoint repeats while it waits. |
| `docker/healthcheck.php` | Command used by the image's `HEALTHCHECK`. |
| `public/health.php` | Health endpoint for Docker and uptime monitors. |
| `docker-compose.yml` | Local store: MySQL 8.4 LTS plus the app in demo mode. |
| `.gitattributes` | Keeps shell scripts and the files under `docker/` on LF line endings, including in Windows checkouts. |
| `tests/Docker/` | `smoke.sh` (end-to-end check of a running store), `entrypoint_test.sh` and PHPUnit tests for these files and for `bin/setup.php`. |
| `.github/workflows/release.yml` | Publishes the image of each release to GitHub Container Registry ([below](#use-a-published-image)). |

## The payment simulator

The store never needs to take real payments for a demo. With the payment simulator on, checkout works without a Stripe account and no money can ever move:

- **Setting:** `PAYMENT_SIMULATOR`. When it is empty or unset, it is on in demo mode (`APP_DEMO=true`) and off otherwise; `true` or `false` overrides that.
  A real store (`APP_ENV=production` with `APP_DEMO=false`) refuses `PAYMENT_SIMULATOR=true`, because anyone could "pay" for its orders: `pay.php` answers 404, checkout says "Online payments are not available in this environment.", `app.log` explains why, and the container does not start.
- **In-process:** the application still uses the Stripe library, but every request goes to `src/payment_simulator.php` inside the same PHP request.
  There is no network call, no second server and no special web-server configuration. `php -S`, Apache and alwaysdata all work the same way.
- **The payment page:** checkout sends the customer to `APP_URL/pay.php?session=cs_test_…`, a normal page of the store in English and Arabic.
  It shows the store name, the books, the quantities and the total, and never asks for card details. Its buttons decide how the payment ends:

  | Button | What happens | The customer sees |
  | --- | --- | --- |
  | **Pay** | The payment succeeds. | The order confirmation (`success_pay.php`); the order is paid. |
  | **Decline** | The card is declined. Nothing is charged, and the payment page stays open for another try. | An error message on the payment page; the order stays pending. |
  | **Slow payment** | The payment is accepted but the "bank" confirms it only after about 15 seconds, as with some real payment methods. | "We're confirming your payment…"; **Refresh status** shows the confirmation once it has gone through. A new checkout is refused until then. |
  | **Cancel** | Nothing is charged. | The cancel page (`cancel_pay.php`); the cart is kept. |

  Like Stripe's own page, it needs no store sign-in: the random session id in the link is the key. Its buttons are a normal form with a CSRF token.
  Without the simulator, `pay.php` answers 404.
- **Everything else works as with Stripe:** the store confirms each payment by retrieving the session, expires abandoned sessions at the next checkout, and refunds paid orders from the admin order page.
  Open sessions expire on their own after one hour.
- **State:** the simulator keeps its sessions and refunds in one JSON file, `PAYMENT_SIMULATOR_STORE` (default `storage/payment-simulator.json`). The web server must be able to write to its folder.
  The file is replaced in one step (written to `….tmp` first, under a lock on `….lock`), so a full disk makes the payment fail with an error instead of damaging the file. A file that cannot be read is moved aside to `….unreadable-<date>-<time>` and the simulator starts afresh.
  It holds no card data and no email addresses. Records older than 30 days are dropped.
  If the file is lost, the store records refunds of the forgotten payments by itself (no money moved in the first place).
- **Stripe settings:** while the simulator is on, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` and `STRIPE_API_BASE` are not used. `STRIPE_CURRENCY` still sets the store's currency.
  In demo mode a live key (`sk_live_…`/`rk_live_…`) is refused even with the simulator off.
- **Webhooks:** the simulator sends none. The store does not need them: it checks the payment when the customer returns. While the simulator is on, `stripe_webhook.php` answers every request with 404 and logs nothing.

## The showcase and the daily reset

A demo that starts empty has no best sellers, no ratings and an empty admin dashboard. In demo mode (`APP_DEMO=true`) BookWise therefore adds a **showcase** of clearly fictional activity, once (`src/demo.php`):

- **12 customers** with made-up names, six in English and six in Arabic. Their addresses are at the reserved `demo.invalid` domain (`showcase-maya@demo.invalid`, …) and their passwords are random and thrown away: nobody can sign in to them, sign up with their addresses or ask for their links, and no email ever leaves the store for them.
- **A made-up Saudi delivery address** each: real city names, invented streets and building numbers, and phone numbers of the form `+966 50 000 01NN`.
- **31 orders over the last 60 days:** 29 paid ones at every stage of their delivery (processing, shipped with a carrier and tracking number, delivered), one refunded before it was shipped and one abandoned checkout.
  They follow the store's own bookkeeping: a copy of the delivery address, catalog prices with VAT in minor units, a sales row for each line of a paid order, and the copies taken out of stock (no book goes below 3 copies). Every date is counted back from the moment the showcase is added, so the orders always look recent.
- **32 reviews** (3 to 5 stars, in the customer's language), only of books their author bought and received, with likes and dislikes by the other customers, and a few favorites per customer.

The home page's best sellers and recommendations, the book pages and the admin dashboard then look like a store in use. The read-only demo administrator sees the showcase's orders like any others (names and addresses shortened).
An owner can ship, deliver and refund them like any order; a refund is recorded in the store only, as the simulator never took those payments. The emails such changes send never reach anybody: they are never sent over SMTP to `demo.invalid`, and the demo inbox keeps them for a customer nobody can sign in to.

| Command | Adds the showcase |
| --- | --- |
| `php bin/install.php` (so also `php bin/setup.php` and every container start) | In demo mode, when the store has none yet. `--no-showcase` leaves it out. |
| `php bin/demo-seed.php` | When the store has none yet; otherwise it says `Demo showcase: already there, nothing added.` |
| `php bin/demo-reset.php --yes` | After every reset. `--no-showcase` leaves it out, for a demo that is about to become a real store ([(d)](#d-a-real-store-stripe-payments-and-email)). |

All three add nothing outside demo mode; `bin/demo-seed.php` and `bin/demo-reset.php` refuse to run there. The showcase is written in one transaction: a failure adds nothing.

**The daily reset.** A public demo collects visitors' sign-ups, orders, reviews and pictures, and administrators may add or change books. `php bin/demo-reset.php --yes` returns it to its starting state:

- it deletes every customer (sign-ups, one-click demo customers and the showcase's customers) with their carts, favorites, addresses, reviews and orders, every other order, the demo inbox, the one-click demo customers' rate-limit records, password-reset links and failed sign-ins;
- it deletes the books that administrators added and puts the 55 sample books back as `database/seed.sql` has them (text, prices, covers and stock), also those that were changed or deleted;
- it deletes the uploaded profile pictures and book covers of the deleted rows, and empties the payment simulator's state, so a payment page left open can no longer be paid;
- it adds the showcase again, unless `--no-showcase` is given: the demo then has no customers and no orders at all.

Administrators are kept, the read-only demo administrator too. The database part is one transaction: visitors never see a half-reset store, and a failure changes nothing. Customers who are signed in at that moment are signed out, as their account is gone.
Without `--yes` the command explains what it would do and changes nothing; outside demo mode it refuses. It prints two lines and writes the same counts to `storage/logs/app.log` (`Demo reset`).
Its exit status is 0 after a reset and 1 when nothing was reset. It is 2 when the reset was done but the payment simulator's state could not be emptied (a full disk, for example): payment pages opened before the reset can then still be paid, so the command prints a warning, and a scheduled task reports the run as failed.

```
[2026-10-16 04:00:01] Demo reset: deleted 27 customer(s), 45 order(s), 39 review(s), 18 demo inbox email(s) and 2 uploaded picture(s); removed 1 book(s) added by administrators and put back the 55 sample books.
[2026-10-16 04:00:01] Demo showcase: added 12 customer(s), 31 order(s), 32 review(s), 98 like(s) and dislike(s) and 36 favorite(s).
```

On alwaysdata it runs every night as a scheduled task ([below](#schedule-the-daily-reset-and-the-cleanup)). With Docker, run it by hand when you like: `docker compose exec app php bin/demo-reset.php --yes`.

## How the container works

### The image

- The image is based on `php:8.4-apache`, the PHP version of the alwaysdata demo, and uses the production `php.ini`. Errors are logged, never displayed, and `expose_php` is off.
  Uploads are allowed up to 5 MB at the PHP level; the application itself limits profile pictures to 2 MB.
- The container runs as **`www-data`**. The code is owned by root and is read-only for the web server. Only `storage/` (logs and the payment simulator's state) and `public/uploads/` are writable.
- Apache listens on **`PORT`**, 8080 by default. Because the server does not run as root, `PORT` must be above 1023.
- The document root is **`public/`**. The rest of the project (`src/`, `vendor/`, `storage/`, `bin/`, `database/`, `docker/`) is denied in the Apache configuration. The test suite is not in the image.
- The rules of `public/.htaccess` and `public/uploads/.htaccess` apply (`AllowOverride All`). The virtual host repeats their essential rules: dotfiles are denied, PHP never runs under `/uploads/`, and directory listings are off.
- Apache sends `Server: Apache` without a version (`ServerTokens Prod`). Static files also get `X-Content-Type-Options: nosniff`.
- Static files get browser-cache headers: a year for the versioned URLs pages link (`?v=…`), an hour for uploads and a day for the rest. PHP pages are never cached. See [Images and browser caching](ARCHITECTURE.md#images-and-browser-caching).
- The `HEALTHCHECK` runs `docker/healthcheck.php` every 30 seconds, after a 120-second start period. That script calls `/health.php` inside the container.

### Start-up

`docker/entrypoint.sh` runs before Apache starts:

1. **`APP_URL`**: if it is not set, it defaults to `http://localhost:$PORT`. A trailing slash is removed.
   If `BOOKWISE_ADMIN_PASSWORD` is still the local-only placeholder from `docker-compose.yml` (`local-admin-password`) and `APP_URL` is not `localhost`, `127.0.0.1` or `[::1]`, the container stops: anyone could sign in with that password.
2. **Payments**: it reports whether the payment simulator or Stripe takes the payments. Nothing has to be configured for the simulator.
   In demo mode a live Stripe key stops the container, and so does `PAYMENT_SIMULATOR=true` on a real store (`APP_ENV=production`, the default, with `APP_DEMO=false`).
3. **Database**: it waits until the database answers, retrying every 3 seconds for up to `DB_WAIT_SECONDS` (default 60).
   Each failed attempt is logged with the driver's reason, for example `Connection refused` or `Access denied`. Passwords are never logged.
   If the database is still unreachable after that time, the container exits with an error.
4. **Schema**: it runs `php bin/install.php` (with `--seed` in demo mode or when `BOOKWISE_SEED=true`):
   - **Empty database:** creates the latest schema. With `--seed` it also loads the 55-book sample catalog; a real store starts with an empty catalog.
   - **Existing BookWise database:** applies pending migrations. If the first installation was interrupted, it stops with a message instead of guessing; drop the half-created tables and start again.
   - **Demo mode:** puts the stock of every book back to at least its sample level, so visitors' simulated purchases never leave the catalog sold out.
   - **Administrator:** if both `BOOKWISE_ADMIN_EMAIL` and `BOOKWISE_ADMIN_PASSWORD` are set, it creates that administrator, or resets the administrator's password when it differs from the stored one.
5. **Logs**: it copies new lines of `storage/logs/app.log` and `php-error.log` to the container output, so they appear in `docker compose logs`.
   `mail.log` is deliberately not copied: with `MAIL_DRIVER=log` it contains password-reset links.
6. **Apache**: it starts Apache in the foreground.

Any other command runs without these steps, for example `docker compose exec app php bin/create-admin.php …` or `docker run … bash`.

The entrypoint never prints passwords, keys or other secrets.

### Demo mode and real mode

| | `APP_DEMO=true` (demo) | `APP_DEMO=false` (real store) |
| --- | --- | --- |
| Banner | "Demo store …" on every page | none |
| Payments | the [payment simulator](#the-payment-simulator) (unless `PAYMENT_SIMULATOR=false`) | Stripe Checkout with your own keys. `PAYMENT_SIMULATOR=true` is refused with `APP_ENV=production` and works only outside it, for example with `APP_ENV=local` for development |
| `pay.php` | the simulated payment page | 404 |
| `stripe_webhook.php` | 404 while the simulator is on | Stripe's webhooks, checked with `STRIPE_WEBHOOK_SECRET` |
| `STRIPE_SECRET_KEY` | not needed; a live key stops the container | used as configured |
| Sample catalog | loaded into an empty database, restocked at every start | only with `BOOKWISE_SEED=true` |

### Health endpoint

`GET /health.php` (or `HEAD`) runs `SELECT 1` on the database:

- `200 {"status":"ok"}` when the database answers.
- `503 {"status":"unavailable"}` when it does not. The reason is written to `app.log` as a warning and is never sent to the caller.

Other methods get `405`. The endpoint starts no session and sends `Cache-Control: no-store`.

### Logs and proxies

- The container output contains Apache's access log, Apache and PHP start-up errors, and the mirrored `app.log` and `php-error.log`.
- The access log records the path **without the query string**, because password-reset and other tokens travel in URLs. Requests to `/health.php` are not logged.
- A proxy in front of the container (a hosting platform's HTTPS proxy, or a tunnel such as Tailscale Funnel reaching it through Docker's port mapping) connects from a private address.
  Apache (`mod_remoteip`) takes the visitor's address from `X-Forwarded-For` only when the connection comes from a private address (10.0.0.0/8, 172.16.0.0/12 or 192.168.0.0/16), and ignores the header on connections from public addresses.
  This keeps login throttling per visitor instead of per proxy, **provided the proxy sets that header itself** and replaces any value the visitor sent.
  Tailscale's source code does that for Funnel, but its documentation does not promise it; [(c)](#check-the-visitors-addresses-through-funnel) shows how to check.

---

## Local setup without Docker

For development, BookWise also runs on your own PHP and database, with PHP's built-in server or with Apache (for example XAMPP or Laragon).

### 1. Prerequisites

- PHP 8.3+ with the extensions listed in [ARCHITECTURE.md](ARCHITECTURE.md#technology-stack), and [Composer](https://getcomposer.org/). `php -v` shows the version of the `php` on your PATH.
  XAMPP 8.2 bundles PHP 8.2, which cannot run BookWise; XAMPP can still provide the database.
- MySQL 8.0.16+ or MariaDB 10.4+ (XAMPP, Laragon, DBngin, Docker, a system service and so on)
- Optional: a free [Stripe](https://dashboard.stripe.com/register) account in **test mode**. Without one, checkout uses the built-in [payment simulator](#the-payment-simulator).

### 2. Install dependencies

```bash
git clone https://github.com/ByAhmd/Online-BookStore-Project.git bookwise
cd bookwise
composer install
```

### 3. Create the database

```sql
CREATE DATABASE bookwise CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bookwise'@'localhost' IDENTIFIED BY 'choose-a-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON bookwise.* TO 'bookwise'@'localhost';
```

### 4. Configure the environment

```bash
cp .env.example .env
```

Edit `.env`: set the `DB_*` values and `APP_URL`. [CONFIGURATION.md](CONFIGURATION.md) lists every setting.
For payments, either set `PAYMENT_SIMULATOR=true` (or `APP_DEMO=true`, which also turns it on) to use the built-in payment simulator,
or set your Stripe **test** keys ([CONFIGURATION.md](CONFIGURATION.md#stripe-test-mode)).
For local development you can set `REQUIRE_EMAIL_VERIFICATION=false`, or open the verification links from `storage/logs/mail.log`.

Then create the tables and, with `--seed`, the sample catalog:

```bash
php bin/install.php --seed
```

This needs a database account that may create tables (`CREATE, ALTER, INDEX, REFERENCES, DROP` on the database).
Either run it once with an administrative account in the environment, for example `DB_USER=root DB_PASS=... php bin/install.php --seed`, or grant those rights to the application user.
On an existing BookWise database the same command applies pending [migrations](ARCHITECTURE.md#database-and-migrations) instead.

You can also import the SQL files by hand: `mysql -u root -p bookwise < database/schema.sql`, then `database/seed.sql`
(on Windows PowerShell run them through `cmd /c "..."`, or use phpMyAdmin → *Import*, in that order).

### 5. Create an administrator

```bash
php bin/create-admin.php admin@example.com "Store Admin"
```

You will be prompted for a password (minimum 8 characters). To script it, set
`BOOKWISE_ADMIN_PASSWORD` in the environment instead.

Running the command again for the same email resets that administrator's password.
It also signs out the administrator's other sessions and clears any sign-in lockout.

Add `--viewer` to create a read-only administrator instead, for example for a reviewer:
`php bin/create-admin.php reviewer@example.com "Reviewer" --viewer`.
Running the command again keeps the administrator's role, so resetting a viewer's password never gives it write access.
Add `--owner` or `--viewer` to change the role; the command says when it changed.

### 6. Run it

**PHP built-in server (simplest):**

```bash
php -S localhost:8000 -t public
```

Open <http://localhost:8000>. With this option, set `APP_URL=http://localhost:8000`.
With the payment simulator on, this one server is all you need to buy a book: checkout opens the simulated payment page `pay.php` on the same server.
The `php` that starts the server must be PHP 8.3 or newer. With an older one, for example XAMPP 8.2's `C:\xampp\php\php.exe` first on the PATH, every page fails with Composer's "Composer detected issues in your platform" error.

**XAMPP / Apache:** this needs a XAMPP whose bundled PHP is 8.3 or newer, because Apache runs BookWise with XAMPP's own PHP.
With XAMPP 8.2, use XAMPP only for the database and start the store with `php -S` from a separate PHP 8.3+ installation, or use Laragon or [Docker](#a-run-it-locally-with-docker).
Keep the project *outside* `htdocs` and expose only `public/` with an alias.
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

## (a) Run it locally with Docker

You need [Docker Desktop](https://www.docker.com/products/docker-desktop/) or Docker Engine with the Compose plugin (`docker compose`).

1. Get the code:

   ```bash
   git clone https://github.com/ByAhmd/Online-BookStore-Project.git bookwise
   cd bookwise
   ```

2. Build and start the store. The first build takes a few minutes.

   ```bash
   docker compose up --build
   ```

   Wait for `[bookwise] Starting Apache on port 8080.` On the first start you will also see `Created schema …`, `Loaded the sample catalog (55 books).` and `Demo showcase: added 12 customer(s), 31 order(s), …` (the [showcase](#the-showcase-and-the-daily-reset)).

3. Open <http://localhost:8080>.
   - Sign up as a customer (no email confirmation is needed), or press **Try as a customer** on the sign-in page, add books to the cart and check out. The simulated payment page appears; press **Pay** and you return to the order confirmation.
     Try **Decline**, **Slow payment** and **Cancel** too.
   - The store's emails (the welcome email, the order confirmation and so on) are not sent: they appear in the demo inbox, the **Inbox** link in the header (`MAIL_DRIVER=database`, as on the public demo). A browser sees the emails of the account it is logged in to and of the accounts it created.
   - **Try as an administrator (read-only)** shows the admin pages with every form switched off.
   - Or log in as the local administrator: `admin@bookwise.test` / `local-admin-password`.
     These values come from `docker-compose.yml` and are **for local use only**. Never reuse them anywhere else; the container refuses that password when `APP_URL` is not a local address.

4. Useful commands:

   | Command | What it does |
   | --- | --- |
   | `docker compose up -d --build` | Start in the background |
   | `docker compose logs -f app` | Follow the app's output (start-up, access log, `app.log`) |
   | `docker compose exec app php bin/create-admin.php you@example.com "Your Name"` | Create another administrator (asks for a password); add `--viewer` for a read-only one |
   | `docker compose exec app php bin/demo-cleanup.php` | Delete the one-click demo customers that are more than 24 hours old |
   | `docker compose exec app php bin/demo-reset.php --yes` | [Reset the demo](#the-showcase-and-the-daily-reset): delete every customer and order, put the sample catalog back and add the showcase again |
   | `docker compose down` | Stop; the database and uploads are kept in named volumes |
   | `docker compose down -v` | Stop and **delete** the database and uploads |
   | `BASE_URL=http://localhost:8080 bash tests/Docker/smoke.sh` | Run the end-to-end smoke test against the running store |

Notes:

- **Only this computer:** the store listens on `127.0.0.1:8080`, not on every network interface, so other devices on your network (a campus or café Wi-Fi) cannot open it: the local administrator password is published.
  To show the store to others, use [(c)](#c-present-it-from-your-own-pc-with-tailscale-funnel).
- **Port in use:** if port 8080 is taken, change the middle number of `"127.0.0.1:8080:8080"` in `docker-compose.yml`, for example to `"127.0.0.1:8090:8080"`, and start the store with its new address: `BOOKWISE_APP_URL=http://localhost:8090 docker compose up -d` (Bash), or in PowerShell `$env:BOOKWISE_APP_URL = 'http://localhost:8090'; docker compose up -d`.
  The payment page sends the browser back to `APP_URL`, so it must be the address you browse to. In PowerShell the variable stays set until you close the window or run `Remove-Item Env:BOOKWISE_APP_URL`.
- **Volumes:** the database lives in the `db-data` volume and uploaded pictures in the `uploads` volume, mounted at `public/uploads`.
  The `uploads` volume keeps the `public/uploads/.htaccess` file it was created with. The virtual host enforces the same rules either way.
  The payment simulator's state lives in the container's `storage/`; it is lost when the container is recreated, which only matters for refunds (see [the payment simulator](#the-payment-simulator)).
- **MySQL 8.4:** the database runs on MySQL 8.4 LTS. A `db-data` volume created by the earlier `mysql:8.0` image is upgraded by MySQL when 8.4 first starts it; `docker compose down -v` starts afresh instead.
  The upgrade is one-way: MySQL 8.0 cannot open the volume afterwards, so an older checkout of BookWise no longer starts on it.
  To keep a way back, export the database with the old checkout before you update: `docker compose exec -T db sh -c 'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" bookwise' > bookwise.sql`.
- **Windows:** `.gitattributes` keeps the scripts on LF line endings. The image also strips carriage returns from them, so a checkout made before `.gitattributes` existed still works.

### Use a published image

Every release also publishes the image to GitHub Container Registry once its tag has been pushed, so it does not have to be built: `ghcr.io/byahmd/online-bookstore-project`, tagged with the version (`1.0.0`), its minor and major lines (`1.0`, `1`) and `latest`.
Versions are released in increasing order, so `latest` is the most recent release and a major tag such as `1` the most recent release of that major version.
The images are built for `linux/amd64` by `.github/workflows/release.yml` from the release's tag ([CONTRIBUTING.md](../CONTRIBUTING.md#releasing-a-version)), from the same Dockerfile as above.

```bash
docker pull ghcr.io/byahmd/online-bookstore-project:1.0.0
```

Pulling without signing in works only once the package is public; the maintainer checks its visibility after the first release ([CONTRIBUTING.md](../CONTRIBUTING.md#releasing-a-version), step 7).
While it is private, only GitHub accounts that have access to it can pull it, after signing in with `docker login ghcr.io` (the GitHub user name, and a personal access token (classic) with the `read:packages` scope as the password).

To run it with the Compose setup of (a), give it the name the Compose file uses for its own build, then start Compose **without** `--build` (Compose builds `bookwise:local` only when it is missing):

```bash
docker tag ghcr.io/byahmd/online-bookstore-project:1.0.0 bookwise:local
docker compose up -d
```

`docker compose up --build` replaces it with a local build again. The image needs the same database and settings as a local build ([Container settings reference](#container-settings-reference)); it contains no database of its own.
Each image carries a provenance and an SBOM attestation from its build. `docker buildx imagetools inspect ghcr.io/byahmd/online-bookstore-project:1.0.0 --format '{{ json .Provenance }}'` shows the first, and `--format '{{ json .SBOM }}'` the second.

## (b) Free public demo on alwaysdata

[alwaysdata](https://www.alwaysdata.com) offers a free plan with classic PHP hosting, MariaDB and SSH. BookWise runs there without Docker:
the code lives in your home folder, the site's root directory is the project's `public/` folder, and `php bin/setup.php` writes the settings and prepares the database.

What the free plan gives you (alwaysdata's pricing pages, October 2026): 1 GB of disk space (files, databases and mail together), 256 MB of RAM, a quarter of a CPU and 3 days of backups, for free.
The free plan may not be used for profit, and its sites can only use your account's address, `https://<account>.alwaysdata.net`.
BookWise needs roughly 50 to 100 MB of disk space for the code, its dependencies and the sample covers.

In this section, `myaccount` stands for your alwaysdata account name. Replace it everywhere.
The panel is at <https://admin.alwaysdata.com>; menu names are written as **Menu > Item**.

### Step 1: create the account

1. Sign up at <https://www.alwaysdata.com> and create an account on the **Free** plan (Public Cloud).
2. The account name you choose becomes your address: `https://myaccount.alwaysdata.net`. Choose it with care.

### Step 2: choose the PHP version

1. Open **Environment > PHP**.
2. Set the default version to **8.4** and save. BookWise needs PHP 8.3 or newer; the CI tests 8.3, 8.4 and 8.5, and the Docker image uses 8.4 too.

The default version is used both by your site and by the `php` command over SSH.
alwaysdata advises against choosing a PHP version per site, because each extra version uses more of the account's memory.

### Step 3: create the database

1. Open **Databases > MySQL** (the menu is called MySQL; the server is MariaDB).
2. Add a database user, for example `myaccount_bookwise`, with a strong password. Generate one and keep it in your password manager; you will type it once in step 6.
3. Add a database named `myaccount_bookwise` and give that user **all privileges** on it ("GRANT ALL PRIVILEGES"). The setup script creates the tables, so the user needs more than read and write.
4. Note the connection details. The server is `mysql-myaccount.alwaysdata.net` on port 3306.

alwaysdata creates databases and database users only in its panel (not in phpMyAdmin), and their names must start with your account name and an underscore.
You can also use the database and user created with the account (`myaccount_base` and `myaccount`) if you know that user's password.

### Step 4: turn on SSH

SSH access is off by default.

1. Open **Remote access > SSH**.
2. Edit the user named `myaccount`: set a password and allow logging in with it, then save.
3. On your computer, open a terminal (on Windows: PowerShell or Windows Terminal) and connect:

   ```bash
   ssh myaccount@ssh-myaccount.alwaysdata.net
   ```

   The first time, type `yes` to accept the server's key (the panel shows its fingerprint). Then type the SSH password.

If you cannot use SSH on your computer, the panel's web terminal at `https://ssh-myaccount.alwaysdata.net` works too, but alwaysdata calls it slow and unreliable.

### Step 5: get the code (over SSH)

Type these commands in the SSH session:

```bash
cd ~
git clone https://github.com/ByAhmd/Online-BookStore-Project.git bookwise
cd bookwise
php -v
composer install --no-dev --optimize-autoloader
```

`php -v` must print PHP 8.3 or newer (8.4 if you followed step 2); otherwise go back to step 2. Composer is installed on alwaysdata (`composer` is Composer 2).

### Step 6: run the setup script (over SSH)

Still in `~/bookwise`:

```bash
php bin/setup.php
```

It checks PHP, its extensions and the folders it writes to, then asks:

| Question | Answer |
| --- | --- |
| Address of the site | `https://myaccount.alwaysdata.net` |
| Database server | press Enter for the suggested `mysql-myaccount.alwaysdata.net` |
| Database port | press Enter for `3306` |
| Database name | `myaccount_bookwise` |
| Database user | `myaccount_bookwise` (the suggestion is `myaccount`) |
| Database password | the password from step 3. Nothing appears while you type it. |
| Create an administrator account? | `y`, then your email address and a password of at least 8 characters, twice |

Nothing changes until every answer is valid and the database answers. Then the script:

- writes `~/bookwise/.env`, readable only by you, with the values of a public demo: `APP_ENV=production`, `APP_DEBUG=false`, `APP_DEMO=true`, `PAYMENT_SIMULATOR=true`, `MAIL_DRIVER=database` (emails are kept for 48 hours and shown on the demo inbox page, `inbox.php`) and `REQUIRE_EMAIL_VERIFICATION=true` (new customers confirm their address with the link in the inbox);
- creates the tables and loads the 55-book sample catalog (`php bin/install.php --seed`), then adds the [showcase](#the-showcase-and-the-daily-reset) of fictional customers, orders and reviews;
- creates the administrator;
- names the commands for the two daily scheduled tasks, the reset and the cleanup of the one-click demo customers ([below](#schedule-the-daily-reset-and-the-cleanup)).

Settings come from the `.env` file; nothing has to be entered in the panel's environment variables.
If you ever turn the demo into a real store, change both `APP_DEMO` and `PAYMENT_SIMULATOR` to `false`, as described in [(d)](#d-a-real-store-stripe-payments-and-email).
To run the script again later, for example with a new database password, add `--force`; it then replaces `.env`.
All its options are listed by `php bin/setup.php --help`.

### Step 7: point the site at `public/`

1. Open **Web > Sites** and edit the site whose address is `myaccount.alwaysdata.net` (the gear icon). If there is none, click **Add a site** and enter that address.
2. **Type:** PHP.
3. **Root directory:** `/bookwise/public/` (the path is relative to your home folder, `/home/myaccount`). Only this folder may be served: `.env`, `vendor/`, `storage/` and the rest of the project stay out of reach.
4. **PHP version:** leave the default (step 2).
5. In the **SSL** section, tick **Force HTTPS**. `*.alwaysdata.net` addresses are already covered by alwaysdata's certificate.
6. Save.

### Step 8: check it

1. Open `https://myaccount.alwaysdata.net/health.php`. It should answer `{"status":"ok"}`.
2. Open `https://myaccount.alwaysdata.net`: the home page shows best sellers with their sales and recommended books with their ratings, from the showcase. Sign up as a customer, add a book to the cart and check out. The simulated payment page appears; press **Pay**.
   The sign-in page also offers **Try as a customer** and **Try as an administrator (read-only)**, which sign visitors in with one click.
3. Log in with the administrator account from step 6 and open **Orders**: the order is paid, and you can refund it.
4. Optional, from your own computer (Git Bash on Windows): `BASE_URL=https://myaccount.alwaysdata.net bash tests/Docker/smoke.sh`.
   It registers one throwaway customer and buys one book.

### Step 9: check the visitors' addresses

Login throttling counts failed sign-ins per visitor address (`REMOTE_ADDR`). alwaysdata runs a reverse proxy in front of every site; its documentation says that the proxy passes the visitor's address in an `X-Real-IP` header, but not whether PHP's `REMOTE_ADDR` holds the visitor's address or the proxy's. To find out:

```bash
cd ~/bookwise
printf '%s\n' '<?php echo htmlspecialchars((string) ($_SERVER["REMOTE_ADDR"] ?? ""));' > public/ip-check.php
```

Open `https://myaccount.alwaysdata.net/ip-check.php` and compare the address with your own (search the web for "what is my IP"). Then delete the file:

```bash
rm public/ip-check.php
```

If the page showed your address, throttling works per visitor. If it showed another address (the proxy's), every visitor shares that address: after 20 failed sign-ins within 15 minutes, sign-in is refused for everyone until the 15 minutes have passed.
The same holds for the one-click demo customers: 10 per hour for everyone together instead of 10 per visitor (and 60 for everyone).
That is acceptable for a demo; note it in the limits you present.

### Schedule the daily reset and the cleanup

Two commands keep a public demo tidy. Run both as scheduled tasks:

- `php bin/demo-reset.php --yes` returns the demo to its starting state every night: visitors' accounts, orders, reviews and pictures go, the sample catalog comes back and the showcase is added again ([what it does](#the-showcase-and-the-daily-reset)).
- `php bin/demo-cleanup.php` deletes the one-click demo customers that are more than 24 hours old, with their carts, favorites, reviews and abandoned checkouts (paid orders are kept, anonymised).
  After a nightly reset it rarely finds any: it is the safety net for a night without a reset, and the only cleanup if you remove the reset task to keep visitors' data. Without either task, demo customers are only deleted when 200 of them exist (the oldest make room for new ones).

Both are safe to run at any time. In **Advanced > Scheduled tasks**, add one task per line of this table (type: command), with the schedule in crontab syntax (`minute hour * * *`; shortcuts such as `@daily` are not accepted), then save:

| Task | Command | Schedule | Riyadh time, if the scheduler keeps UTC |
| --- | --- | --- | --- |
| Daily reset | `cd $HOME/bookwise && php bin/demo-reset.php --yes` | `0 1 * * *` | 04:00 |
| Cleanup | `cd $HOME/bookwise && php bin/demo-cleanup.php` | `0 13 * * *` | 16:00 |

- **The PHP version:** `php` in a scheduled task is the account's default version, the one chosen in **Environment > PHP** in [step 2](#step-2-choose-the-php-version): alwaysdata runs command tasks with the default versions of the **Environment** menu, like the `php` command over SSH. If you change the default version later, the tasks follow it.
- **The time zone:** the schedules above are for a scheduler that keeps UTC (Riyadh is UTC+3 all year), which is not confirmed: alwaysdata does not document the time zone of scheduled tasks. Check it over SSH: `date` shows the server's time and `TZ=Asia/Riyadh date` the time in Riyadh. Schedule the reset at 4 minus their difference in hours.
  On a server whose time zone has summer time, the reset then moves by an hour twice a year, which does no harm: on Paris time, for example, `0 2 * * *` runs it at 04:00 in Riyadh in winter and at 03:00 while Paris has summer time.
- **Output:** each run's output goes to `/home/myaccount/admin/logs/jobs/`; the reset also writes its counts to `~/bookwise/storage/logs/app.log`. Put your address in the task's email field to be told when a run fails (an exit status other than 0: 1 when nothing was reset, for example when the database cannot be reached; 2 when the reset was done but the payment simulator's state could not be emptied).
- **Overlaps:** alwaysdata skips a run while the previous one of the same task is still running. A reset takes a few seconds; a second reset or showcase command started meanwhile waits for it, and gives up with `Another showcase seed or demo reset is running` after 30 seconds.

### After the first deploy

- **Updates:** over SSH, run:

  ```bash
  cd ~/bookwise
  git pull
  composer install --no-dev --optimize-autoloader
  php bin/install.php
  ```

  `bin/install.php` applies pending migrations and, in demo mode, restocks the catalog and adds the showcase if it is missing.
- **Logs:** BookWise writes `~/bookwise/storage/logs/app.log` and `php-error.log`. Read them with `tail -n 50 ~/bookwise/storage/logs/app.log`.
- **Another administrator:** `php bin/create-admin.php you@example.com "Your Name"` (asks for a password).
- **Scheduled tasks:** **Advanced > Scheduled tasks** runs commands on a schedule written in crontab syntax, for example `0 4 * * *` for 04:00 every day (shortcuts such as `@daily` are not accepted).
  Their output goes to `/home/myaccount/admin/logs/jobs/`. The daily reset and the demo cleanup run this way ([above](#schedule-the-daily-reset-and-the-cleanup)).
- **Idle site:** alwaysdata stops a site's PHP processes after a period without visits (30 minutes by default, changeable under the site's **Advanced** settings) and starts them again on the next visit.
  Unlike a container platform, files are kept: uploads, logs, sessions and the payment simulator's state survive.

## (c) Present it from your own PC with Tailscale Funnel

For a presentation you can publish the Docker store from (a) on your own computer for a while.
[Tailscale Funnel](https://tailscale.com/kb/1223/funnel) gives it a public HTTPS address of the form `https://<machine>.<tailnet>.ts.net`, without opening ports on your router.

You need Docker as in (a), and [Tailscale](https://tailscale.com/download) **1.52 or newer**, installed and signed in on the same computer.
The `tailscale funnel` commands below changed in version 1.52; `tailscale version` shows yours.

1. **Allow Funnel:** in the [Tailscale admin console](https://login.tailscale.com/admin), turn on **MagicDNS** and **HTTPS certificates** (on the DNS page).
   Funnel also needs the `funnel` attribute in the tailnet policy; the first `tailscale funnel` command offers to add it for you.
2. **Find the address:** run `tailscale status`, or look up the machine in the admin console. The address is `https://<machine>.<tailnet>.ts.net`.
3. **Start the store with that address and an administrator password of your own.** Bash (macOS, Linux, Git Bash):

   ```bash
   BOOKWISE_APP_URL=https://<machine>.<tailnet>.ts.net BOOKWISE_ADMIN_PASSWORD='<your own password>' docker compose up -d --build
   ```

   PowerShell:

   ```powershell
   $env:BOOKWISE_APP_URL = 'https://<machine>.<tailnet>.ts.net'
   $env:BOOKWISE_ADMIN_PASSWORD = '<your own password>'
   docker compose up -d --build
   ```

   `docker-compose.yml` passes these two values to the container as `APP_URL` and `BOOKWISE_ADMIN_PASSWORD`. The container refuses to start with the published placeholder password on a public address.
4. **Publish it:** open a second terminal and run

   ```bash
   tailscale funnel 8080
   ```

   The store is public for as long as this command runs, so keep that window open during the presentation.
   Funnel answers on port 443 of the public address and forwards to `http://127.0.0.1:8080`. A new address can take up to 10 minutes to appear in DNS.
   Check it from a phone on mobile data: `https://<machine>.<tailnet>.ts.net/health.php` should answer `{"status":"ok"}`.

   Do not add `--bg`. With it, Funnel keeps running after the terminal is closed, and even after a reboot, until you run `tailscale funnel reset`.
5. **Stop publishing** after the presentation: press **Ctrl+C** in the Funnel window, or close it. Then run:

   ```bash
   tailscale funnel status
   ```

   It must no longer list your `ts.net` address. If it still does (for example after `--bg`), run `tailscale funnel reset` and check again.
6. **Only then** go back to the local store, without the two variables. In Bash (a new terminal, or the same one: the one-line form of step 3 set them for that command only):

   ```bash
   docker compose up -d
   ```

   PowerShell keeps `$env:` variables until the window is closed, so remove them first (or open a new window):

   ```powershell
   Remove-Item Env:BOOKWISE_APP_URL, Env:BOOKWISE_ADMIN_PASSWORD
   docker compose up -d
   ```

   This puts the store back on `http://localhost:8080` with the local administrator password, which is published in `docker-compose.yml`.
   The container accepts that password because `APP_URL` is local again; it cannot tell whether Funnel is still running. So never run this while Funnel is still on.

### Check the visitors' addresses through Funnel

Login throttling counts failed sign-ins per visitor address. Through Funnel, every request reaches the container from a private address (Docker's own gateway), so Apache takes the visitor's address from `X-Forwarded-For` (see [Logs and proxies](#logs-and-proxies)).
Tailscale's source code (`ipn/ipnlocal/serve.go`, October 2026) sets that header to the visitor's address and replaces any value the visitor sent, but Tailscale's documentation does not say so. Check it once while Funnel is on:

1. On a device that is **not** signed in to your tailnet (for example a phone on mobile data with the Tailscale app off), open `https://<machine>.<tailnet>.ts.net/Home_Page.php`.
   If you have a computer outside your tailnet with `curl`, also send a forged header from it:

   ```bash
   curl -s -o /dev/null -H 'X-Forwarded-For: 203.0.113.7' https://<machine>.<tailnet>.ts.net/Home_Page.php
   ```

2. On your PC, show the last requests for that page:

   ```bash
   docker compose logs app | grep 'GET /Home_Page.php' | tail -n 2
   ```

   After the `app-1 |` prefix, each access-log line starts with the address the store saw:

   - **the device's public address** (search the web for "what is my IP" on it): Funnel sets the header, and throttling works per visitor. This is the expected result.
   - **`203.0.113.7`**: the store believed the forged header, so visitors could pick their own address and avoid throttling. Stop publishing, remove the `<IfModule remoteip_module>` block from `docker/apache/server.conf` and rebuild with `docker compose up -d --build` before you publish again.
   - **a private address** such as `172.18.0.1`: Funnel sent no header. Every visitor then shares that address: after 20 failed sign-ins within 15 minutes, sign-in is refused for everyone until the 15 minutes have passed. That is acceptable for a presentation.

Notes:

- The store is public while Funnel runs: anyone with the address can register and "buy". Your computer must stay on and awake, and Funnel traffic has bandwidth limits.
- `APP_URL` must be exactly the Funnel address, or the payment page sends customers back to the wrong place.
- Do not publish the store any other way (for example by removing `127.0.0.1:` from its port in `docker-compose.yml` and forwarding port 8080 on your router): the container trusts `X-Forwarded-For` from private addresses, see [Logs and proxies](#logs-and-proxies).

## (d) A real store: Stripe payments and email

Starting from the Docker setup, or from a server prepared with `bin/setup.php`, change the following (in the container's environment or in `.env`).

1. **Leave demo mode:** if you keep the demo's database, empty it first as step 8 describes; that only works in demo mode. Then set `APP_DEMO=false` **and** `PAYMENT_SIMULATOR=false`. The banner disappears and `pay.php` answers 404.
   `bin/setup.php` writes `PAYMENT_SIMULATOR=true` into `.env`, so on a server set up with it both lines must change (the Docker setup does not set `PAYMENT_SIMULATOR`, but `false` does no harm there).
   If `PAYMENT_SIMULATOR=true` is left in place, the store takes no payments at all: checkout says "Online payments are not available in this environment.", `app.log` says `Refusing the payment simulator on a real store …`, and the container does not start.
2. **Stripe:**
   - Set `STRIPE_SECRET_KEY` to your secret key. Live keys (`sk_live_…`) are accepted only with `APP_ENV=production` and outside demo mode; use a test key (`sk_test_…`) for a staging copy.
   - In the Stripe Dashboard, add a webhook endpoint at `APP_URL/stripe_webhook.php` for these events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired` and `charge.refunded`.
     Set `STRIPE_WEBHOOK_SECRET` to its signing secret (`whsec_…`).
     The store's requests use API version `2026-09-30.endive` (that of `stripe/stripe-php` 22). From an event it reads only the type, ids and amounts, and it checks each event through the API before acting on it.
   - Keep `STRIPE_CURRENCY` (default `sar`) or set another two-decimal currency.
   - Make sure `STRIPE_API_BASE` is **not** set.
3. **Email:**
   - Set `MAIL_DRIVER=smtp` and the `SMTP_*` settings of any email provider (for example Brevo or Mailjet, which have free plans): `SMTP_HOST`, `SMTP_PORT` (587, or 2525 where the host blocks 587), `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION=tls` (`ssl` for port 465) and optionally `SMTP_TIMEOUT`.
     Set `MAIL_FROM_ADDRESS` to an address the provider lets you send from, and `MAIL_FROM_NAME`.
     The host must allow outgoing connections to the SMTP port; see [CONFIGURATION.md](CONFIGURATION.md#email).
   - Turn email verification back on with `REQUIRE_EMAIL_VERIFICATION=true`, or remove the variable (true is the default).
4. **Address:** set `APP_URL` to the public HTTPS address. It is used in Stripe return URLs and email links.
5. **Persistent uploads:** profile pictures and uploaded book covers are stored in `public/uploads/`. With Docker the `uploads` volume keeps them; on a classic host they stay in the folder.
6. **Database:**
   - Use a database plan with backups.
   - The container creates and migrates the schema at start-up. The configured user therefore needs schema privileges (`CREATE`, `ALTER`, `INDEX`, `REFERENCES`) as well as `SELECT, INSERT, UPDATE, DELETE`.
7. **Administrator:** create it once with `BOOKWISE_ADMIN_EMAIL` and `BOOKWISE_ADMIN_PASSWORD`, then remove both variables so the password is not reset at every start.
   Alternatively, use `php bin/create-admin.php` in a shell on the server.
8. **Catalog and demo data:** outside demo mode neither the sample books nor the [showcase](#the-showcase-and-the-daily-reset) are added to a new database.
   A database that served as a demo holds the showcase's fictional customers with their paid orders (some still waiting to be shipped, all counted in the dashboard's revenue) and whatever visitors did. Start the real store with a new, empty database, or empty the demo's database before step 1, while it is still a demo:
   - On alwaysdata, first delete both [scheduled tasks](#schedule-the-daily-reset-and-the-cleanup): a reset during the switch would add the showcase again, and outside demo mode the reset refuses to run and would report a failure every night.
   - Run `php bin/demo-reset.php --yes --no-showcase` (with Docker: `docker compose exec app php bin/demo-reset.php --yes --no-showcase`). It deletes every customer and every order, the showcase's too, and keeps the administrators and the 55 sample books.
   - Delete the sample books in the admin dashboard if you do not sell them.

The free alwaysdata plan cannot be used for a store that makes money.

## Limits of the free demo

**alwaysdata (free plan):**

- 1 GB of disk space for files, databases and mail together; 256 MB of RAM and a quarter of a CPU. A demo with a few hundred orders stays far below that.
- Not for profit, and only at `https://<account>.alwaysdata.net`.
- After 30 minutes without visits (the default) the site's PHP processes stop; the next visit starts them again and is a little slower.
- Backups are kept for 3 days.
- No email leaves the site (`MAIL_DRIVER=database`). Confirmation, password-reset and other emails appear in the demo inbox, and only in the browser that created the account (or for the signed-in customer), so visitors cannot read each other's links.
- The visitors' addresses may all look the same to the store, see [step 9](#step-9-check-the-visitors-addresses).

**The demo itself:**

- Anyone can register. Do not enter real personal data (delivery addresses included: any made-up Saudi address in the right format works). Everything visitors do is deleted by the nightly reset (`php bin/demo-reset.php --yes`, meant for 04:00 in Riyadh once [the scheduler's time zone](#schedule-the-daily-reset-and-the-cleanup) has been checked), and until then the administrators can see it.
- One-click demo customers are limited to 10 per visitor address (for IPv6, per /64 network) and hour, 60 per hour for all visitors together, and 200 at once (the oldest are deleted first). The nightly reset deletes them, and `php bin/demo-cleanup.php` deletes any that are more than 24 hours old.
  Everybody who tries the read-only administrator shares one account, which shows every order of the demo with shortened customer names, addresses and payment references (a full checkout session id would open that customer's payment page).
- The showcase's customers, orders and reviews are fictional and the same every day; only their dates move with the reset. Its orders never reached anybody, and its customers cannot sign in.
- Visitors' purchases never take a book below 3 copies, and `php bin/install.php` (or a container start) restocks the catalog.
- Nothing is ever shipped: a visitor's order stays *processing* until an administrator marks it shipped and delivered on its order page, which only updates the order and sends its emails to the demo inbox.

## Deployment checklist

For any server, with Docker or without:

- Serve **only** `public/` as the document root, over HTTPS.
- Set `APP_ENV=production` and `APP_DEBUG=false`, and set `APP_URL` to the public HTTPS URL.
- Use a dedicated database user with only `SELECT, INSERT, UPDATE, DELETE` on the application database.
  Run `bin/install.php` / `bin/migrate.php` with an account that may also `CREATE, ALTER, INDEX, REFERENCES, DROP`.
  (The Docker image runs `bin/install.php` at every start, so there the configured user needs those rights too.)
- Make `storage/logs/`, `public/uploads/avatars/` and `public/uploads/covers/` writable by the web server, and keep them out of version control.
  With the payment simulator on, `storage/` (or the folder of `PAYMENT_SIMULATOR_STORE`) must be writable too.
- For a public demo on PHP hosting, `php bin/setup.php` does most of this list: it checks the folders, writes `.env` with safe demo values (readable by its owner only) and installs the database ([(b)](#b-free-public-demo-on-alwaysdata)).
- Set `REQUIRE_EMAIL_VERIFICATION=true` only together with a working mail driver, or new customers cannot sign in.
- On **nginx** or any server that ignores `.htaccess`, deny PHP execution under `/uploads/` in the server config, for example:
  `location ^~ /uploads/ { location ~ \.php$ { deny all; } }`
- Configure the Stripe webhook endpoint and secret. Use live keys only in production.
- Configure email with `MAIL_DRIVER=smtp` and the `SMTP_*` settings of your provider, and set `MAIL_FROM_ADDRESS` to an address it lets you send from.
- Rotate logs. `storage/logs/mail.log` contains reset links when `MAIL_DRIVER=log`, so never use that driver in production.

## Security notes

- **The demo takes no real payments.** With the payment simulator on, the Stripe key is not even read and nothing leaves the server.
  The store also turns off stripe-php's client telemetry, so no Stripe ID file is written to the account's home folder (`~/.config/stripe`).
  **Never put live Stripe keys into a demo:** in demo mode the application never uses a live key, even with the simulator off, and the container refuses to start with one.
- **The payment page is closed outside the simulator.** `pay.php` answers 404 when `PAYMENT_SIMULATOR` is off. Its buttons need the visitor's CSRF token, and it only ever redirects to the store's own `APP_URL`.
  The simulator keeps no card data and no email addresses.
- **A real store never uses the simulator.** With `APP_ENV=production` and `APP_DEMO=false`, `PAYMENT_SIMULATOR=true` is refused (no payments, an error in `app.log`, and the container does not start), so a forgotten setting cannot hand out free orders.
- **Stripe's webhook is closed in the demo.** While the simulator is on, `stripe_webhook.php` answers 404 and logs nothing, so a demo needs no webhook secret and visitors cannot fill the error log through it.
- **Demo data is public and temporary.** Anyone can register on a public demo. Do not enter real personal data; the nightly reset deletes it with every customer, order and upload. Both the reset and the showcase refuse to run without `APP_DEMO=true`, and the reset needs `--yes`, so they never touch a real store's customers.
- **Secrets stay out of the repository.** On alwaysdata they are in `~/bookwise/.env`, which `bin/setup.php` makes readable by its owner only; it never prints passwords.
  With Docker they are environment variables, and the entrypoint never prints them.
- **Serve only `public/`.** On alwaysdata the site's root directory must be `/bookwise/public/`. With Docker, the virtual host serves only `public/`, PHP never runs under `/uploads/`, and dotfiles are denied.
  Errors are not displayed, and neither Apache nor PHP reveal their versions in the container.
- **Use TLS for hosted databases that require it.** Set `DB_SSL_CA_PEM` (or `DB_SSL_CA`, a file path); the server certificate is then verified. alwaysdata's own databases are reached inside its network and do not need it.
- **Logs stay clean.** URLs are logged without query strings, so reset tokens stay out of the access log. `mail.log` is never copied to the container output.
- **The local Compose setup is not for the internet.** `docker-compose.yml` contains placeholder passwords and an administrator with a published password; the container refuses that password on a non-local `APP_URL`, and the store's port is published on `127.0.0.1` only, so other devices on the same network cannot reach it.
  Publish it only through Tailscale Funnel as in (c), with a password of your own, and stop Funnel before you start the store with the local password again.
- **Check the proxy rule for other setups.** In the container, the visitor's address is read from `X-Forwarded-For` only on connections from private addresses.
  If you deploy behind a different proxy setup, check that this rule fits it.
  For example, when Docker's own port forwarding presents connections as coming from its private gateway address, visitors could set that header themselves.
  In that case, put a reverse proxy that sets `X-Forwarded-For` in front of the container, or remove the `mod_remoteip` block from `docker/apache/server.conf`.
  For Tailscale Funnel, [(c)](#check-the-visitors-addresses-through-funnel) shows how to check.

## Container settings reference

These variables are specific to the container and `docker-compose.yml`. The application settings (`DB_*`, `STRIPE_*`, `PAYMENT_SIMULATOR`, `MAIL_*` and so on) are listed in [CONFIGURATION.md](CONFIGURATION.md).

| Variable | Default | Description |
| --- | --- | --- |
| `PORT` | `8080` | Port Apache listens on (above 1023) |
| `APP_URL` | `http://localhost:$PORT` | Public address of the store, without a trailing slash |
| `BOOKWISE_APP_URL` | `http://localhost:8080` | Read by `docker-compose.yml` from your shell: the `APP_URL` it gives the container |
| `APP_DEMO` | `false` | `true`: demo banner, sample catalog and the payment simulator |
| `PAYMENT_SIMULATOR` | follows `APP_DEMO` | `true`/`false`: payment simulator on or off, whatever `APP_DEMO` says. `true` stops the container on a real store (`APP_ENV=production`, `APP_DEMO=false`) |
| `PAYMENT_SIMULATOR_STORE` | `storage/payment-simulator.json` | The simulator's state file |
| `DB_WAIT_SECONDS` | `60` | How long the entrypoint waits for the database |
| `DB_SSL_CA_PEM` / `DB_SSL_CA` | — | CA certificate text / file for TLS database connections |
| `BOOKWISE_ADMIN_EMAIL` / `BOOKWISE_ADMIN_PASSWORD` | — (Compose: `admin@bookwise.test` / `local-admin-password`) | Administrator created (or its password reset) at every start when both are set. `docker-compose.yml` reads `BOOKWISE_ADMIN_PASSWORD` from your shell. |
| `BOOKWISE_SEED` | `false` | `true`: load the sample catalog into a new, empty database also outside demo mode |
| `LOG_DIR` | `storage/logs` | Where `app.log`, `php-error.log` and `mail.log` are written; the first two are copied to the container output |
| `MAIL_DRIVER` | `log` (Compose: `database`) | `database` (demo inbox) for a demo, as in `docker-compose.yml`; `log` writes the emails to `mail.log` for development; `smtp` for a real store |
| `APP_ENV` | `production` | Must be `production` for live Stripe keys (the local Compose file uses `local`). With `production`, the payment simulator needs `APP_DEMO=true` |
| `DB_NAME`, `DB_HOST`, `DB_PORT` | — | Shown in the start-up log (never `DB_PASS`) |
| `STRIPE_SECRET_KEY`, `STRIPE_API_BASE` | — | Checked by the entrypoint: a live key stops a demo, and a set `STRIPE_API_BASE` is reported. Neither is used while the simulator is on |
| `STRIPE_WEBHOOK_SECRET` | — | Only for a real store; the demo's webhook endpoint answers 404 and needs none |

## Checks and CI

The **Docker image** job in `.github/workflows/ci.yml` runs next to the test matrix (PHP 8.3–8.5 on MySQL 8.4, and PHP 8.4 on MariaDB 11.4):

1. It checks the shell scripts with `bash -n` and ShellCheck, and runs `tests/Docker/entrypoint_test.sh` (the entrypoint's configuration logic).
2. It builds the image with `docker compose build`. The build fails if a PHP extension or a gd image format is missing, or if the Apache configuration is invalid.
3. It starts `docker compose up` and waits until `/health.php` answers. Then it checks:
   - that the app runs as `www-data`;
   - `tests/Docker/smoke.sh`: the home page with the 55 books, sign-up, add to cart, the checkout page with a new delivery address, checkout to `pay.php`, the refused form without a CSRF token, the simulated payment, the paid confirmation on `success_pay.php`, the simulator's own 404 for an unknown payment session, and 404 from `stripe_webhook.php`.
     It also checks that `/.env`, `/composer.json`, `/vendor/autoload.php`, `/storage/logs/app.log`, `/storage/payment-simulator.json` and other private paths answer 403/404, and the security headers;
   - that the order is stored as `paid` in MySQL, and that the simulator's state file in `storage/` belongs to `www-data` and records the payment;
   - that the container refuses the local-only administrator password with a public `APP_URL`, and `PAYMENT_SIMULATOR=true` on a real store (`APP_ENV=production`, `APP_DEMO=false`);
   - that a second container with `APP_DEMO=false` and `PORT=9090` serves the store without the simulator: its `pay.php` answers 404 without the simulator's message (which the demo store shows for the same link), the application reports the simulator off, and `stripe_webhook.php` checks signatures again (400 for an unsigned request);
   - that `/health.php` answers 503 after the database is stopped.
4. On failure it prints the container logs.

The **Browser tests** job starts the same Compose store and drives it in Chromium with the Playwright tests of `e2e/` (journeys in English and Arabic, the demo inbox, the read-only administrator, keyboard use and axe-core accessibility checks).
When a test fails, it keeps Playwright's HTML report as the `playwright-report` artifact and prints the container logs. [TESTING.md](TESTING.md) explains how to run these tests yourself, also against `docker compose up`.

The **Release** workflow (`.github/workflows/release.yml`) runs only when a version tag such as `v1.0.0` is pushed. It builds the image from that tag and pushes it to GitHub Container Registry ([Use a published image](#use-a-published-image)), then creates the GitHub release with the version's notes from `CHANGELOG.md`.

Locally, without Docker:

- `bash tests/Docker/entrypoint_test.sh` tests the entrypoint's configuration logic.
- With `ENTRYPOINT_TEST_DB_NAME=<a database whose name ends in _test>` and `DB_HOST`/`DB_PORT`/`DB_USER`/`DB_PASS`, the same script also runs the whole entrypoint against that database, with a stand-in for Apache. **It drops every table in that database.**
- `vendor/bin/phpunit tests/Docker` runs the PHPUnit tests for `/health.php`, `docker/db-ready.php`, `docker/healthcheck.php`, `bin/setup.php` and the deployment files, including the demo's scheduled tasks in this guide.
  The showcase and the daily reset are tested by the integration suite (`tests/Integration/DemoShowcaseTest.php`, `DemoResetTest.php`), and the pages that show the showcase by `tests/Http/DemoShowcasePagesTest.php`.
- `BASE_URL=https://myaccount.alwaysdata.net bash tests/Docker/smoke.sh` checks a deployed demo the same way.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| `Error: Cannot connect to the database: … Access denied for user …` (setup) or `Database not ready yet … Access denied …` (container) | Wrong database user, password or name, or the user has no rights on that database. On alwaysdata, check **Databases > MySQL**. |
| `Error: … already exists` from `bin/setup.php` | `.env` is already there. Edit it, or run `php bin/setup.php --force` to write it again. |
| `BookWise needs PHP 8.3 or newer` | Choose a newer default version in **Environment > PHP** (alwaysdata; 8.4 is recommended), then reconnect over SSH. |
| `The dependencies are not installed` or "BookWise is not installed yet" in the browser | Run `composer install --no-dev --optimize-autoloader` in the project folder. |
| The site shows a listing of the project, or `.env` can be opened | The site's root directory is not `public/`. Set it to `/bookwise/public/` (alwaysdata) or point the server's document root at `public/`. |
| 403 or 500 on every page on a classic host | The server does not allow the rules in `public/.htaccess` (`Require`, `Options`). Ask the host to allow them, or remove the lines it rejects. |
| Payments return to the wrong address | `APP_URL` is not the address you browse to. Fix it in `.env` (classic host) or with `BOOKWISE_APP_URL` (Compose). |
| "This payment link is not valid." on the payment page | The session does not exist (any more): an old link, or the simulator's state file was deleted. Go back to the cart and check out again. |
| "We could not verify your previous checkout" after a slow payment | The slow payment is still being confirmed. Open the order's status page (**Refresh status**) after about 15 seconds, then check out again. |
| `BOOKWISE_ADMIN_PASSWORD is the local-only password …` | You published the Docker store with the placeholder password. Set `BOOKWISE_ADMIN_PASSWORD` to a password of your own, as in (c). |
| `DB_SSL_CA_PEM does not contain a PEM certificate` | The value is not the certificate text (for example a file name). Paste the certificate itself, or use `DB_SSL_CA` for a file path. |
| `Giving up.` after 60 seconds, repeatedly | The database is unreachable. Check `DB_HOST`/`DB_PORT`, or raise `DB_WAIT_SECONDS`. |
| `APP_DEMO=true must never be combined with a live Stripe key` | Remove the live key from the demo, or set `APP_DEMO=false` for a real store. |
| `PAYMENT_SIMULATOR is on, but this is a real store` (container), or "Online payments are not available in this environment." with `Refusing the payment simulator on a real store` in `app.log` | `APP_DEMO=false` with `APP_ENV=production` but `PAYMENT_SIMULATOR=true` (written by `bin/setup.php`). Set `PAYMENT_SIMULATOR=false` for Stripe, or `APP_DEMO=true` for a demo. |
| `The payment simulator cannot save …` in `app.log`, and checkout or the payment page fails | The folder of `PAYMENT_SIMULATOR_STORE` is not writable, or the disk is full (on alwaysdata: the 1 GB quota). The previous state is kept; make the folder writable or free some space, then try again. |
| `Administrator not created: …` | `BOOKWISE_ADMIN_EMAIL` is not a valid address, or `BOOKWISE_ADMIN_PASSWORD` is shorter than 8 characters. Fix or remove both. |
| The first page after a quiet period is slow | alwaysdata restarts the site's PHP processes after the idle time (30 minutes by default). |
| `Refusing to reset: only a public demo (APP_DEMO=true) is reset` or `Refusing to add the showcase` | The store is not in demo mode. Both commands are only for a public demo; check `APP_DEMO` in `.env`. |
| `Warning: the payment simulator's state could not be cleared` (the reset task reports exit status 2) | The reset was done, but the folder of `PAYMENT_SIMULATOR_STORE` is not writable or the disk is full, so payment pages opened before the reset can still be paid. Make the folder writable or free some space, then run `php bin/demo-reset.php --yes` again. |
| `Another showcase seed or demo reset is running` | A reset (or `bin/demo-seed.php`) was still running after 30 seconds. Run the command again later; the scheduled reset tries again the next night. |
| `The database "…" is not up to date. Run: php bin/install.php` (reset or showcase) | The code was updated without its migrations. Run `php bin/install.php`, then the command again. |
| `PORT must be a TCP port number` / Apache cannot bind | `PORT` must be a number from 1024 to 65535 (the container does not run as root). |

## Facts that could not be confirmed

These points come from alwaysdata's, Tailscale's and MySQL's documentation as it could be read in October 2026, or could not be found there. They were not tried on a live account.

- **alwaysdata sign-up and panel labels:** the exact button and field labels (for example where the PHP type, root directory and "Force HTTPS" are on the site form, or how password login for the SSH user is switched on) may differ slightly from this guide.
  The documentation confirms the menus **Web > Sites**, **Environment > PHP**, **Databases > MySQL**, **Remote access > SSH** and **Advanced > Scheduled tasks**, the site's **Root directory** field (relative to the home folder), and that SSH access is off by default.
- **The default site:** whether a new account already has a site for `myaccount.alwaysdata.net` (and which root directory it uses) is not documented; step 7 covers both cases.
- **The visitors' addresses:** alwaysdata documents an `X-Real-IP` header from its proxy but not what `REMOTE_ADDR` contains. Step 9 shows how to check.
- **`.htaccess` rules:** alwaysdata serves PHP sites with Apache and supports `.htaccess` files, but whether every rule in `public/.htaccess` (`Options -Indexes`, `Require`) is allowed was not confirmed.
- **Free-plan details:** SSH, scheduled tasks and unlimited sites and databases are listed for the Public Cloud offers, which include the free plan; the free plan's own page lists only disk, memory, CPU, backups and the restrictions above.
  alwaysdata's PHP documentation shows `memory_limit`, `post_max_size` and `upload_max_filesize` of 256M in an example `php.ini`; BookWise's own limits (2 MB for pictures, 5 MB for covers) are far lower either way.
- **git on alwaysdata:** alwaysdata's own guides use git on their servers, but the documentation has no current version list. Check with `git --version`; without git, upload the project with SFTP instead.
- **Scheduled tasks on alwaysdata:** the documentation says that command tasks use the default language versions of the **Environment** menu and that a run is skipped while the previous one is still running, but not which time zone the schedule uses, nor which shell or working directory a command gets (it warns that Bash-only commands do not work). The tasks above therefore change to the project folder themselves, and [the time zone](#schedule-the-daily-reset-and-the-cleanup) has to be checked with `date`.
- **An old `db-data` volume:** MySQL's manual supports upgrading a MySQL 8.0 data directory in place to 8.4, and going back from 8.4 to 8.0 only by a dump and reload. Starting this Compose file on a volume made by `mysql:8.0` was not tried.
- **Environment variables on alwaysdata:** sites have an environment-variables setting (`FOO=bar` pairs), but whether PHP sites receive them was not confirmed. BookWise does not need it: `bin/setup.php` writes `.env`.
- **Tailscale Funnel:** which forwarding headers Funnel adds is not documented. Tailscale's source code sets `X-Forwarded-For` to the visitor's address and replaces any value the visitor sent; [(c)](#check-the-visitors-addresses-through-funnel) shows how to confirm it on your own setup.
  Tailscale's documentation and its announcement of version 1.52 confirm that the current commands need Tailscale 1.52 or newer, that a foreground `tailscale funnel` ends with Ctrl+C, and that `--bg` survives reboots until `tailscale funnel reset`; they do not show the exact output of `tailscale funnel status`. Funnel on Windows was not tried.
