# Deploying BookWise

This guide covers four setups:

- **(a) Local store with Docker:** `docker compose up --build`, a MySQL container and simulated payments.
- **(b) Free public demo on alwaysdata:** classic PHP hosting with MariaDB on [alwaysdata](https://www.alwaysdata.com)'s free plan, set up over SSH with `php bin/setup.php`. No Docker. Payments are simulated, and emails are shown in the site's demo inbox instead of being sent.
- **(c) Presenting from your own PC:** the Docker store from (a), published for a while through [Tailscale Funnel](https://tailscale.com/kb/1223/funnel).
- **(d) Real store:** real Stripe payments and real email.

Payments in (a), (b) and (c) go to the store's **built-in payment simulator**, which is described first.
The guide ends with the [limits of the free demo](#limits-of-the-free-demo), [security notes](#security-notes), [troubleshooting](#troubleshooting) and the [facts that could not be confirmed](#facts-that-could-not-be-confirmed).

## Contents

- [The deployment files](#the-deployment-files)
- [The payment simulator](#the-payment-simulator)
- [How the container works](#how-the-container-works)
- [(a) Run it locally with Docker](#a-run-it-locally-with-docker)
- [(b) Free public demo on alwaysdata](#b-free-public-demo-on-alwaysdata)
- [(c) Present it from your own PC with Tailscale Funnel](#c-present-it-from-your-own-pc-with-tailscale-funnel)
- [(d) A real store: Stripe payments and email](#d-a-real-store-stripe-payments-and-email)
- [Limits of the free demo](#limits-of-the-free-demo)
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
| `bin/demo-cleanup.php` | Deletes the demo's one-click customers after 24 hours; run it daily as a scheduled task ([below](#schedule-the-demo-cleanup)). |
| `src/payment_simulator.php`, `public/pay.php` | The built-in payment simulator and its payment page. |
| `Dockerfile` | Two-stage build. Composer installs the production dependencies; the runtime image is `php:8.3-apache` with `pdo_mysql`, `gd` (JPEG, PNG, WebP) and `opcache`. |
| `.dockerignore` | Allowlist for the build context. `.env`, `.git`, `vendor/`, logs, uploads and the test suite never reach the build. |
| `docker/entrypoint.sh` | Start-up script of the container, described [below](#start-up). |
| `docker/apache/` | `ports.conf` (listen on `$PORT`), `site.conf` (virtual host), `server.conf` (server-wide hardening, proxy addresses, worker limits). |
| `docker/php/bookwise.ini` | PHP overrides on top of `php.ini-production`. |
| `docker/db-ready.php` | Database check that the entrypoint repeats while it waits. |
| `docker/healthcheck.php` | Command used by the image's `HEALTHCHECK`. |
| `public/health.php` | Health endpoint for Docker and uptime monitors. |
| `docker-compose.yml` | Local store: MySQL 8.0 plus the app in demo mode. |
| `.gitattributes` | Keeps shell scripts and the files under `docker/` on LF line endings, including in Windows checkouts. |
| `tests/Docker/` | `smoke.sh` (end-to-end check of a running store), `entrypoint_test.sh` and PHPUnit tests for these files and for `bin/setup.php`. |

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

## How the container works

### The image

- The image is based on `php:8.3-apache` and uses the production `php.ini`. Errors are logged, never displayed, and `expose_php` is off.
  Uploads are allowed up to 5 MB at the PHP level; the application itself limits profile pictures to 2 MB.
- The container runs as **`www-data`**. The code is owned by root and is read-only for the web server. Only `storage/` (logs and the payment simulator's state) and `public/uploads/` are writable.
- Apache listens on **`PORT`**, 8080 by default. Because the server does not run as root, `PORT` must be above 1023.
- The document root is **`public/`**. The rest of the project (`src/`, `vendor/`, `storage/`, `bin/`, `database/`, `docker/`) is denied in the Apache configuration. The test suite is not in the image.
- The rules of `public/.htaccess` and `public/uploads/.htaccess` apply (`AllowOverride All`). The virtual host repeats their essential rules: dotfiles are denied, PHP never runs under `/uploads/`, and directory listings are off.
- Apache sends `Server: Apache` without a version (`ServerTokens Prod`). Static files also get `X-Content-Type-Options: nosniff`.
- Static files get browser-cache headers: a year for the versioned URLs pages link (`?v=…`), an hour for uploads and a day for the rest. PHP pages are never cached. See [Images and browser caching](../README.md#images-and-browser-caching).
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

   Wait for `[bookwise] Starting Apache on port 8080.` On the first start you will also see `Created schema …` and `Loaded the sample catalog (55 books).`

3. Open <http://localhost:8080>.
   - Sign up as a customer (no email confirmation is needed), or press **Try as a customer** on the sign-in page, add books to the cart and check out. The simulated payment page appears; press **Pay** and you return to the order confirmation.
     Try **Decline**, **Slow payment** and **Cancel** too.
   - **Try as an administrator (read-only)** shows the admin pages with every form switched off.
   - Or log in as the local administrator: `admin@bookwise.test` / `local-admin-password`.
     These values come from `docker-compose.yml` and are **for local use only**. Never reuse them anywhere else; the container refuses that password when `APP_URL` is not a local address.

4. Useful commands:

   | Command | What it does |
   | --- | --- |
   | `docker compose up -d --build` | Start in the background |
   | `docker compose logs -f app` | Follow the app's output (start-up, access log, `app.log`) |
   | `docker compose exec app cat storage/logs/mail.log` | Read the emails, e.g. password-reset links (`MAIL_DRIVER=log`) |
   | `docker compose exec app php bin/create-admin.php you@example.com "Your Name"` | Create another administrator (asks for a password); add `--viewer` for a read-only one |
   | `docker compose exec app php bin/demo-cleanup.php` | Delete the one-click demo customers that are more than 24 hours old |
   | `docker compose down` | Stop; the database and uploads are kept in named volumes |
   | `docker compose down -v` | Stop and **delete** the database and uploads |
   | `BASE_URL=http://localhost:8080 bash tests/Docker/smoke.sh` | Run the end-to-end smoke test against the running store |

Notes:

- **Port in use:** if port 8080 is taken, change the left side of `"8080:8080"` in `docker-compose.yml`, for example `"8090:8080"`, and start the store with `BOOKWISE_APP_URL=http://localhost:8090 docker compose up -d`.
  The payment page sends the browser back to `APP_URL`, so it must be the address you browse to.
- **Volumes:** the database lives in the `db-data` volume and uploaded pictures in the `uploads` volume, mounted at `public/uploads`.
  The volume keeps the `public/uploads/.htaccess` file it was created with. The virtual host enforces the same rules either way.
  The payment simulator's state lives in the container's `storage/`; it is lost when the container is recreated, which only matters for refunds (see [the payment simulator](#the-payment-simulator)).
- **Windows:** `.gitattributes` keeps the scripts on LF line endings. The image also strips carriage returns from them, so a checkout made before `.gitattributes` existed still works.

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
2. Set the default version to **8.3** (any version from 8.1 to 8.4 works; the CI tests 8.1–8.4) and save.

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

`php -v` must print PHP 8.1 or newer; otherwise go back to step 2. Composer is installed on alwaysdata (`composer` is Composer 2).

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
- creates the tables and loads the 55-book sample catalog (`php bin/install.php --seed`);
- creates the administrator;
- names the command for the daily cleanup of the one-click demo customers ([Schedule the demo cleanup](#schedule-the-demo-cleanup)).

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
2. Open `https://myaccount.alwaysdata.net`, sign up as a customer, add a book to the cart and check out. The simulated payment page appears; press **Pay**.
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
The same holds for the one-click demo customers: 10 per hour for everyone together instead of per visitor.
That is acceptable for a demo; note it in the limits you present.

### Schedule the demo cleanup

Every press of **Try as a customer** creates a throwaway customer account. `php bin/demo-cleanup.php` deletes the ones that are more than 24 hours old, with their carts, favorites, reviews and abandoned checkouts (paid orders are kept, anonymised).
It is safe to run at any time and prints one line. Run it once a day:

1. Open **Advanced > Scheduled tasks** and add a task that runs a command.
2. Command: `cd $HOME/bookwise && php bin/demo-cleanup.php`
3. Schedule: `0 4 * * *` (04:00 every day, in crontab syntax; shortcuts such as `@daily` are not accepted). Save.

Its output goes to `/home/myaccount/admin/logs/jobs/`. Without the task, demo customers are only deleted when 200 of them exist (the oldest make room for new ones).

### After the first deploy

- **Updates:** over SSH, run:

  ```bash
  cd ~/bookwise
  git pull
  composer install --no-dev --optimize-autoloader
  php bin/install.php
  ```

  `bin/install.php` applies pending migrations and, in demo mode, restocks the catalog.
- **Logs:** BookWise writes `~/bookwise/storage/logs/app.log` and `php-error.log`. Read them with `tail -n 50 ~/bookwise/storage/logs/app.log`.
- **Another administrator:** `php bin/create-admin.php you@example.com "Your Name"` (asks for a password).
- **Scheduled tasks:** **Advanced > Scheduled tasks** runs commands on a schedule written in crontab syntax, for example `0 4 * * *` for 04:00 every day (shortcuts such as `@daily` are not accepted).
  Their output goes to `/home/myaccount/admin/logs/jobs/`. The demo cleanup runs this way ([above](#schedule-the-demo-cleanup)); a daily `cd $HOME/bookwise && php bin/install.php` also restocks the catalog.
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
6. **Only then** go back to the local store:

   ```bash
   docker compose up -d
   ```

   Without the two variables, this puts the store back on `http://localhost:8080` with the local administrator password, which is published in `docker-compose.yml`.
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
- Do not publish the store any other way (for example by forwarding port 8080 on your router): the container trusts `X-Forwarded-For` from private addresses, see [Logs and proxies](#logs-and-proxies).

## (d) A real store: Stripe payments and email

Starting from the Docker setup, or from a server prepared with `bin/setup.php`, change the following (in the container's environment or in `.env`).

1. **Leave demo mode:** set `APP_DEMO=false` **and** `PAYMENT_SIMULATOR=false`. The banner disappears and `pay.php` answers 404.
   `bin/setup.php` writes `PAYMENT_SIMULATOR=true` into `.env`, so on a server set up with it both lines must change (the Docker setup does not set `PAYMENT_SIMULATOR`, but `false` does no harm there).
   If `PAYMENT_SIMULATOR=true` is left in place, the store takes no payments at all: checkout says "Online payments are not available in this environment.", `app.log` says `Refusing the payment simulator on a real store …`, and the container does not start.
2. **Stripe:**
   - Set `STRIPE_SECRET_KEY` to your secret key. Live keys (`sk_live_…`) are accepted only with `APP_ENV=production` and outside demo mode; use a test key (`sk_test_…`) for a staging copy.
   - In the Stripe Dashboard, add a webhook endpoint at `APP_URL/stripe_webhook.php` for these events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired` and `charge.refunded`.
     Set `STRIPE_WEBHOOK_SECRET` to its signing secret (`whsec_…`).
   - Keep `STRIPE_CURRENCY` (default `sar`) or set another two-decimal currency.
   - Make sure `STRIPE_API_BASE` is **not** set.
3. **Email:**
   - Set `MAIL_DRIVER=smtp` and the `SMTP_*` settings of any email provider (for example Brevo or Mailjet, which have free plans): `SMTP_HOST`, `SMTP_PORT` (587, or 2525 where the host blocks 587), `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION=tls` (`ssl` for port 465) and optionally `SMTP_TIMEOUT`.
     Set `MAIL_FROM_ADDRESS` to an address the provider lets you send from, and `MAIL_FROM_NAME`.
     The host must allow outgoing connections to the SMTP port; see the README's [Email](../README.md#email) section.
   - Turn email verification back on with `REQUIRE_EMAIL_VERIFICATION=true`, or remove the variable (true is the default).
4. **Address:** set `APP_URL` to the public HTTPS address. It is used in Stripe return URLs and email links.
5. **Persistent uploads:** profile pictures and uploaded book covers are stored in `public/uploads/`. With Docker the `uploads` volume keeps them; on a classic host they stay in the folder.
6. **Database:**
   - Use a database plan with backups.
   - The container creates and migrates the schema at start-up. The configured user therefore needs schema privileges (`CREATE`, `ALTER`, `INDEX`, `REFERENCES`) as well as `SELECT, INSERT, UPDATE, DELETE`.
7. **Administrator:** create it once with `BOOKWISE_ADMIN_EMAIL` and `BOOKWISE_ADMIN_PASSWORD`, then remove both variables so the password is not reset at every start.
   Alternatively, use `php bin/create-admin.php` in a shell on the server.
8. **Catalog:** outside demo mode the sample books are not loaded into a new database. If you started from the demo database, delete the sample books in the admin dashboard (or start with an empty database).

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

- Anyone can register. Do not enter real personal data (delivery addresses included: any made-up Saudi address in the right format works), and expect the data to be reset.
- One-click demo customers are limited to 10 per visitor address and hour and 200 at once (the oldest are deleted first), and are deleted after 24 hours by `php bin/demo-cleanup.php`.
  Everybody who tries the read-only administrator shares one account, which shows every order of the demo with shortened customer names and addresses.
- Visitors' purchases never take a book below 3 copies, and `php bin/install.php` (or a container start) restocks the catalog.
- Nothing is ever shipped: a demo order stays *processing* until an administrator marks it shipped and delivered on its order page, which only updates the order and sends its emails to the demo inbox.

## Security notes

- **The demo takes no real payments.** With the payment simulator on, the Stripe key is not even read and nothing leaves the server.
  **Never put live Stripe keys into a demo:** in demo mode the application never uses a live key, even with the simulator off, and the container refuses to start with one.
- **The payment page is closed outside the simulator.** `pay.php` answers 404 when `PAYMENT_SIMULATOR` is off. Its buttons need the visitor's CSRF token, and it only ever redirects to the store's own `APP_URL`.
  The simulator keeps no card data and no email addresses.
- **A real store never uses the simulator.** With `APP_ENV=production` and `APP_DEMO=false`, `PAYMENT_SIMULATOR=true` is refused (no payments, an error in `app.log`, and the container does not start), so a forgotten setting cannot hand out free orders.
- **Stripe's webhook is closed in the demo.** While the simulator is on, `stripe_webhook.php` answers 404 and logs nothing, so a demo needs no webhook secret and visitors cannot fill the error log through it.
- **Demo data is public and temporary.** Anyone can register on a public demo. Do not enter real personal data, and expect the data to be reset.
- **Secrets stay out of the repository.** On alwaysdata they are in `~/bookwise/.env`, which `bin/setup.php` makes readable by its owner only; it never prints passwords.
  With Docker they are environment variables, and the entrypoint never prints them.
- **Serve only `public/`.** On alwaysdata the site's root directory must be `/bookwise/public/`. With Docker, the virtual host serves only `public/`, PHP never runs under `/uploads/`, and dotfiles are denied.
  Errors are not displayed, and neither Apache nor PHP reveal their versions in the container.
- **Use TLS for hosted databases that require it.** Set `DB_SSL_CA_PEM` (or `DB_SSL_CA`, a file path); the server certificate is then verified. alwaysdata's own databases are reached inside its network and do not need it.
- **Logs stay clean.** URLs are logged without query strings, so reset tokens stay out of the access log. `mail.log` is never copied to the container output.
- **The local Compose setup is not for the internet.** `docker-compose.yml` contains placeholder passwords and an administrator with a published password; the container refuses that password on a non-local `APP_URL`.
  Publish it only through Tailscale Funnel as in (c), with a password of your own, and stop Funnel before you start the store with the local password again.
- **Check the proxy rule for other setups.** In the container, the visitor's address is read from `X-Forwarded-For` only on connections from private addresses.
  If you deploy behind a different proxy setup, check that this rule fits it.
  For example, when Docker's own port forwarding presents connections as coming from its private gateway address, visitors could set that header themselves.
  In that case, put a reverse proxy that sets `X-Forwarded-For` in front of the container, or remove the `mod_remoteip` block from `docker/apache/server.conf`.
  For Tailscale Funnel, [(c)](#check-the-visitors-addresses-through-funnel) shows how to check.

## Container settings reference

These variables are specific to the container and `docker-compose.yml`. The application settings (`DB_*`, `STRIPE_*`, `PAYMENT_SIMULATOR`, `MAIL_*` and so on) are listed in the README's configuration reference.

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
| `MAIL_DRIVER` | `log` | `log` locally, `database` (demo inbox) for a public demo, `smtp` for a real store |
| `APP_ENV` | `production` | Must be `production` for live Stripe keys (the local Compose file uses `local`). With `production`, the payment simulator needs `APP_DEMO=true` |
| `DB_NAME`, `DB_HOST`, `DB_PORT` | — | Shown in the start-up log (never `DB_PASS`) |
| `STRIPE_SECRET_KEY`, `STRIPE_API_BASE` | — | Checked by the entrypoint: a live key stops a demo, and a set `STRIPE_API_BASE` is reported. Neither is used while the simulator is on |
| `STRIPE_WEBHOOK_SECRET` | — | Only for a real store; the demo's webhook endpoint answers 404 and needs none |

## Checks and CI

The **Docker image** job in `.github/workflows/ci.yml` runs next to the test matrix (PHP 8.1–8.5 on MySQL 8.4, and PHP 8.4 on MariaDB 11.4):

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

Locally, without Docker:

- `bash tests/Docker/entrypoint_test.sh` tests the entrypoint's configuration logic.
- With `ENTRYPOINT_TEST_DB_NAME=<a database whose name ends in _test>` and `DB_HOST`/`DB_PORT`/`DB_USER`/`DB_PASS`, the same script also runs the whole entrypoint against that database, with a stand-in for Apache. **It drops every table in that database.**
- `vendor/bin/phpunit tests/Docker` runs the PHPUnit tests for `/health.php`, `docker/db-ready.php`, `docker/healthcheck.php`, `bin/setup.php` and the deployment files.
- `BASE_URL=https://myaccount.alwaysdata.net bash tests/Docker/smoke.sh` checks a deployed demo the same way.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| `Error: Cannot connect to the database: … Access denied for user …` (setup) or `Database not ready yet … Access denied …` (container) | Wrong database user, password or name, or the user has no rights on that database. On alwaysdata, check **Databases > MySQL**. |
| `Error: … already exists` from `bin/setup.php` | `.env` is already there. Edit it, or run `php bin/setup.php --force` to write it again. |
| `BookWise needs PHP 8.1 or newer` | Choose a newer default version in **Environment > PHP** (alwaysdata), then reconnect over SSH. |
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
| `PORT must be a TCP port number` / Apache cannot bind | `PORT` must be a number from 1024 to 65535 (the container does not run as root). |

## Facts that could not be confirmed

These points come from alwaysdata's and Tailscale's documentation as it could be read in October 2026, or could not be found there. They were not tried on a live account.

- **alwaysdata sign-up and panel labels:** the exact button and field labels (for example where the PHP type, root directory and "Force HTTPS" are on the site form, or how password login for the SSH user is switched on) may differ slightly from this guide.
  The documentation confirms the menus **Web > Sites**, **Environment > PHP**, **Databases > MySQL**, **Remote access > SSH** and **Advanced > Scheduled tasks**, the site's **Root directory** field (relative to the home folder), and that SSH access is off by default.
- **The default site:** whether a new account already has a site for `myaccount.alwaysdata.net` (and which root directory it uses) is not documented; step 7 covers both cases.
- **The visitors' addresses:** alwaysdata documents an `X-Real-IP` header from its proxy but not what `REMOTE_ADDR` contains. Step 9 shows how to check.
- **`.htaccess` rules:** alwaysdata serves PHP sites with Apache and supports `.htaccess` files, but whether every rule in `public/.htaccess` (`Options -Indexes`, `Require`) is allowed was not confirmed.
- **Free-plan details:** SSH, scheduled tasks and unlimited sites and databases are listed for the Public Cloud offers, which include the free plan; the free plan's own page lists only disk, memory, CPU, backups and the restrictions above.
  alwaysdata's PHP documentation shows `memory_limit`, `post_max_size` and `upload_max_filesize` of 256M in an example `php.ini`; BookWise's own limits (2 MB for pictures, 5 MB for covers) are far lower either way.
- **git on alwaysdata:** alwaysdata's own guides use git on their servers, but the documentation has no current version list. Check with `git --version`; without git, upload the project with SFTP instead.
- **Environment variables on alwaysdata:** sites have an environment-variables setting (`FOO=bar` pairs), but whether PHP sites receive them was not confirmed. BookWise does not need it: `bin/setup.php` writes `.env`.
- **Tailscale Funnel:** which forwarding headers Funnel adds is not documented. Tailscale's source code sets `X-Forwarded-For` to the visitor's address and replaces any value the visitor sent; [(c)](#check-the-visitors-addresses-through-funnel) shows how to confirm it on your own setup.
  Tailscale's documentation and its announcement of version 1.52 confirm that the current commands need Tailscale 1.52 or newer, that a foreground `tailscale funnel` ends with Ctrl+C, and that `--bg` survives reboots until `tailscale funnel reset`; they do not show the exact output of `tailscale funnel status`. Funnel on Windows was not tried.
