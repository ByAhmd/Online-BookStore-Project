# Deploying BookWise

This guide covers three setups, all built from the same Docker image:

- **(a) Local store with Docker:** `docker compose up --build`, a MySQL container and simulated payments.
- **(b) Free public demo:** a free [Render](https://render.com) web service with a free [Aiven for MySQL](https://aiven.io/mysql) database. Payments are simulated and no email is sent.
- **(c) Real store:** the same image with real Stripe payments and real email.

It ends with the [limits of the free demo](#limits-of-the-free-demo), [security notes](#security-notes) and [troubleshooting](#troubleshooting).

## Contents

- [The deployment files](#the-deployment-files)
- [How the container works](#how-the-container-works)
- [(a) Run it locally with Docker](#a-run-it-locally-with-docker)
- [(b) Free public demo on Render and Aiven](#b-free-public-demo-on-render-and-aiven)
- [(c) A real store: Stripe payments and email](#c-a-real-store-stripe-payments-and-email)
- [Limits of the free demo](#limits-of-the-free-demo)
- [Security notes](#security-notes)
- [Container settings reference](#container-settings-reference)
- [Checks and CI](#checks-and-ci)
- [Troubleshooting](#troubleshooting)

---

## The deployment files

| File | Purpose |
| --- | --- |
| `Dockerfile` | Two-stage build. Composer installs the production dependencies; the runtime image is `php:8.3-apache` with `pdo_mysql`, `gd` (JPEG, PNG, WebP) and `opcache`. |
| `.dockerignore` | Allowlist for the build context. `.env`, `.git`, `vendor/`, logs, uploads and the test suite never reach the build; the only file taken from `tests/` is the payment simulator. |
| `docker/entrypoint.sh` | Start-up script, described [below](#start-up). |
| `docker/apache/` | `ports.conf` (listen on `$PORT`), `site.conf` (virtual host), `server.conf` (server-wide hardening, proxy addresses, worker limits). |
| `docker/php/bookwise.ini` | PHP overrides on top of `php.ini-production`. |
| `docker/db-ready.php` | Database check that the entrypoint repeats while it waits. |
| `docker/healthcheck.php` | Command used by the image's `HEALTHCHECK`. |
| `public/health.php` | Health endpoint for Docker, Render and uptime monitors. |
| `docker-compose.yml` | Local store: MySQL 8.0 plus the app in demo mode. |
| `render.yaml` | Render Blueprint for the free demo. |
| `.gitattributes` | Keeps shell scripts and the files under `docker/` on LF line endings, including in Windows checkouts. |
| `tests/Docker/` | `smoke.sh` (end-to-end check of a running container), `entrypoint_test.sh` and PHPUnit tests for these files. |

## How the container works

### The image

- The image is based on `php:8.3-apache` and uses the production `php.ini`. Errors are logged, never displayed, and `expose_php` is off.
  Uploads are allowed up to 5 MB at the PHP level; the application itself limits profile pictures to 2 MB.
- The container runs as **`www-data`**. The code is owned by root and is read-only for the web server. Only `storage/logs/` and `public/uploads/` are writable.
- Apache listens on **`PORT`**, 8080 by default. Hosting platforms set their own value; Render, for example, sets `PORT` itself.
  Because the server does not run as root, `PORT` must be above 1023.
- The document root is **`public/`**. The rest of the project (`src/`, `vendor/`, `storage/`, `bin/`, `database/`, `docker/`) is denied in the Apache configuration.
- The rules of `public/.htaccess` and `public/uploads/.htaccess` apply (`AllowOverride All`). The virtual host repeats their essential rules: dotfiles are denied, PHP never runs under `/uploads/`, and directory listings are off.
- Apache sends `Server: Apache` without a version (`ServerTokens Prod`). Static files also get `X-Content-Type-Options: nosniff`.
- The `HEALTHCHECK` runs `docker/healthcheck.php` every 30 seconds, after a 120-second start period. That script calls `/health.php` inside the container.

### Start-up

`docker/entrypoint.sh` runs before Apache starts:

1. **`APP_URL`**: if it is not set, it is taken from `RENDER_EXTERNAL_URL` (set by Render). Otherwise it defaults to `http://localhost:$PORT`. A trailing slash is removed.
2. **Payments**: in demo mode (`APP_DEMO=true`), it configures the built-in payment simulator (see [Demo mode and real mode](#demo-mode-and-real-mode)).
3. **Database**: it waits until the database answers, retrying every 3 seconds for up to `DB_WAIT_SECONDS` (default 60).
   Each failed attempt is logged with the driver's reason, for example `Connection refused` or `Access denied`. Passwords are never logged.
   If the database is still unreachable after that time, the container exits with an error.
4. **Schema**: it runs `php bin/install.php --seed`:
   - **Empty database:** creates the latest schema and loads the 55-book sample catalog.
   - **Existing BookWise database:** applies pending migrations, and loads the sample catalog only if the `books` table is empty.
   - **Administrator:** if both `BOOKWISE_ADMIN_EMAIL` and `BOOKWISE_ADMIN_PASSWORD` are set, it creates that administrator, or resets the administrator's password when it differs from the stored one.
5. **Logs**: it copies new lines of `storage/logs/app.log` and `php-error.log` to the container output, so they appear in `docker compose logs` and in the Render dashboard.
   `mail.log` is deliberately not copied: with `MAIL_DRIVER=log` it contains password-reset links.
6. **Apache**: it starts Apache in the foreground. In demo mode it adds `-D BOOKWISE_STRIPE_SIM`.

Any other command runs without these steps, for example `docker compose exec app php bin/create-admin.php …` or `docker run … bash`.

The entrypoint never prints passwords, keys or other secrets.

### Demo mode and real mode

| | `APP_DEMO=true` (demo) | `APP_DEMO=false` (real store) |
| --- | --- | --- |
| Banner | "Demo store …" on every page | none |
| Payments | the built-in simulator at `APP_URL/stripe-sim` | Stripe Checkout, with your own keys |
| `/stripe-sim` | served by `tests/Support/stripe_mock_router.php` | not served (404) |
| `STRIPE_SECRET_KEY` | generated at every start (`sk_test_` + 48 random hex digits) if unset; a live key (`sk_live_`/`rk_live_`) stops the container | used as configured |
| `STRIPE_WEBHOOK_SECRET` | generated (`whsec_…`) if unset | used as configured |

In demo mode the entrypoint sets these variables for the application and the simulator:

| Variable | Value |
| --- | --- |
| `STRIPE_API_BASE` | `http://127.0.0.1:$PORT/stripe-sim`: the application calls the simulator inside the container |
| `STRIPE_SIM_PUBLIC_URL` | `APP_URL/stripe-sim`: the simulated payment page that browsers are sent to |
| `STRIPE_SIM_BASE_PATH` | `/stripe-sim` |
| `STRIPE_SIM_KEY` | the same value as `STRIPE_SECRET_KEY`: the simulator API accepts only this key |
| `STRIPE_MOCK_STORE` | `/tmp/bookwise-stripe-sim.json`, the simulator's state |
| `STRIPE_SIM_TEST_API` | always removed: the simulator's test controls (`/stripe-sim/__test/...`) answer 404 |

If `STRIPE_SECRET_KEY` is set to a value that does not start with `sk_test_` or `rk_test_` (for example a random value from a hosting platform), the entrypoint keeps its letters and digits and prefixes them with `sk_test_`.

The simulator shows a "Pay (simulated)" / "Cancel" page instead of Stripe's payment page. It never asks for card details and takes no money. It does not send webhooks; the payment is confirmed when the customer returns to `success_pay.php`, which checks the session with the simulator exactly as it would with Stripe.

### Health endpoint

`GET /health.php` (or `HEAD`) runs `SELECT 1` on the database:

- `200 {"status":"ok"}` when the database answers.
- `503 {"status":"unavailable"}` when it does not. The reason is written to `app.log` as a warning and is never sent to the caller.

Other methods get `405`. The endpoint starts no session and sends `Cache-Control: no-store`.

### Logs and proxies

- The container output contains Apache's access log, Apache and PHP start-up errors, and the mirrored `app.log` and `php-error.log`.
- The access log records the path **without the query string**, because password-reset and other tokens travel in URLs. Requests to `/health.php` are not logged.
- Render and similar platforms terminate HTTPS at a proxy on a private network and pass the visitor's address in `X-Forwarded-For`.
  Apache (`mod_remoteip`) takes the visitor's address from that header only when the connection comes from a private address (10.0.0.0/8, 172.16.0.0/12 or 192.168.0.0/16).
  This keeps login throttling per visitor instead of per proxy. The header is ignored on connections from public addresses.

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
   - Sign up as a customer (no email confirmation is needed), add books to the cart and check out. The simulated payment page appears; press **Pay (simulated)** and you return to the order confirmation.
   - Or log in as the local administrator: `admin@bookwise.test` / `local-admin-password`.
     These values come from `docker-compose.yml` and are **for local use only**. Change them there before the first start if you like, and never reuse them anywhere else.

4. Useful commands:

   | Command | What it does |
   | --- | --- |
   | `docker compose up -d --build` | Start in the background |
   | `docker compose logs -f app` | Follow the app's output (start-up, access log, `app.log`) |
   | `docker compose exec app cat storage/logs/mail.log` | Read the emails, e.g. password-reset links (`MAIL_DRIVER=log`) |
   | `docker compose exec app php bin/create-admin.php you@example.com "Your Name"` | Create another administrator (asks for a password) |
   | `docker compose down` | Stop; the database and uploads are kept in named volumes |
   | `docker compose down -v` | Stop and **delete** the database and uploads |
   | `BASE_URL=http://localhost:8080 bash tests/Docker/smoke.sh` | Run the end-to-end smoke test against the running store |

Notes:

- **Port in use:** if port 8080 is taken, change the left side of `"8080:8080"` and `APP_URL` in `docker-compose.yml` together, for example `"8090:8080"` and `http://localhost:8090`.
  The simulator sends the browser back to `APP_URL`.
- **Volumes:** the database lives in the `db-data` volume and uploaded pictures in the `uploads` volume, mounted at `public/uploads`.
  The volume keeps the `public/uploads/.htaccess` file it was created with. The virtual host enforces the same rules either way.
- **Windows:** `.gitattributes` keeps the scripts on LF line endings. The image also strips carriage returns from them, so a checkout made before `.gitattributes` existed still works.

## (b) Free public demo on Render and Aiven

The demo uses `render.yaml`: one free Render web service built from the `Dockerfile`, with a free Aiven for MySQL database. The Blueprint fixes these values:

| Variable | Value | Meaning |
| --- | --- | --- |
| `APP_ENV` | `production` | Production behaviour |
| `APP_DEBUG` | `false` | No error details in the browser |
| `APP_DEMO` | `true` | Demo banner and simulated payments |
| `REQUIRE_EMAIL_VERIFICATION` | `false` | New customers can sign in straight away (the demo sends no email) |
| `MAIL_DRIVER` | `null` | Emails are discarded |
| `STRIPE_CURRENCY` | `sar` | Prices are shown and charged in SAR |

### Step 1: create the database on Aiven

1. Sign up at <https://console.aiven.io/signup> and create a project if you are asked to.
2. Click **Create service** and choose **MySQL**.
3. Choose the **Free** plan. Pick a cloud region close to the Render region you will use; `render.yaml` uses Frankfurt.
4. Give the service a name, then create it. Wait until its status is **Running**.
5. On the service's **Overview** page, under **Connection information**, note:

   | Aiven field | Render variable |
   | --- | --- |
   | Host | `DB_HOST` |
   | Port | `DB_PORT` |
   | User (usually `avnadmin`) | `DB_USER` |
   | Password | `DB_PASS` |
   | Database name (usually `defaultdb`) | `DB_NAME` |
   | CA certificate (**Show** or **Download**) | `DB_SSL_CA_PEM` |

   Aiven requires TLS. BookWise uses the CA certificate to encrypt the connection **and** to verify the server.
   Copy the whole certificate, including the `-----BEGIN CERTIFICATE-----` and `-----END CERTIFICATE-----` lines.
   The value may span several lines, or be written on one line with literal `\n` between lines.

You do not need to create tables: the container does that on its first start.

### Step 2: deploy the Blueprint on Render

1. Sign in at <https://dashboard.render.com> with the GitHub account that can see the repository.
   To deploy your own copy, fork <https://github.com/ByAhmd/Online-BookStore-Project> first.
2. Optional: to use a region other than Frankfurt, change `region:` in `render.yaml` and push. A service's region cannot be changed after it is created.
3. Click **New > Blueprint**, connect the repository and select the `main` branch. Render reads `render.yaml` and proposes the `bookwise-demo` web service on the free plan.
4. Fill in the variables marked `sync: false`:
   - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` and `DB_SSL_CA_PEM`, from step 1.
   - Optionally `BOOKWISE_ADMIN_EMAIL` and `BOOKWISE_ADMIN_PASSWORD`, for an administrator account. Use a strong password of at least 8 characters.
     Leave both empty if you do not want an administrator; one is created only when both are set.
     While they are set, the administrator's password is set back to this value at every start. Remove them in **Environment** once the account exists if you prefer.

   Do **not** add `STRIPE_SECRET_KEY` or `STRIPE_WEBHOOK_SECRET`: the demo generates them at every start.
5. Click **Apply**. Render builds the image (several minutes the first time) and starts it. In the service's **Logs** you should see:

   ```
   [bookwise] Payments: demo mode, simulated at https://<your-service>.onrender.com/stripe-sim (no real payments are taken).
   [bookwise] Database is reachable.
   Created schema (… statements).
   Loaded the sample catalog (55 books).
   [bookwise] Starting Apache on port 10000.
   ```

   Render then checks `/health.php` (`healthCheckPath`) and puts the service live.
6. Open `https://<your-service>.onrender.com`. The address is shown at the top of the service page.
   `https://<your-service>.onrender.com/health.php` should answer `{"status":"ok"}`.
   Optionally, run the smoke test against it: `BASE_URL=https://<your-service>.onrender.com bash tests/Docker/smoke.sh`. It registers one throwaway customer and buys one book with the simulator.

### After the first deploy

- **Updates:** every push to `main` redeploys automatically (`autoDeployTrigger: commit`). Migrations run at start-up.
- **Changing a secret:** Render asks for `sync: false` values only when the Blueprint is created. To change one later, edit it in the service's **Environment** page.
- **Custom domain:** add the domain in Render and set `APP_URL` to `https://your.domain` in **Environment**. Without it, `APP_URL` follows `RENDER_EXTERNAL_URL`.

## (c) A real store: Stripe payments and email

Starting from the demo setup (or from `docker-compose.yml` for a self-hosted server), change the following.

1. **Leave demo mode:** set `APP_DEMO=false`. The banner disappears and `/stripe-sim` is no longer served.
2. **Stripe:**
   - Set `STRIPE_SECRET_KEY` to your secret key. Live keys (`sk_live_…`) are accepted only with `APP_ENV=production`; use a test key (`sk_test_…`) for a staging copy.
   - In the Stripe Dashboard, add a webhook endpoint at `APP_URL/stripe_webhook.php` for these events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed` and `checkout.session.expired`.
     Set `STRIPE_WEBHOOK_SECRET` to its signing secret (`whsec_…`).
   - Keep `STRIPE_CURRENCY` (default `sar`) or set another two-decimal currency.
   - Make sure `STRIPE_API_BASE` is **not** set.
3. **Email:**
   - Set `MAIL_DRIVER=sendgrid`, `SENDGRID_API_KEY`, `MAIL_FROM_ADDRESS` (a sender verified in SendGrid) and `MAIL_FROM_NAME`.
     SendGrid is reached over HTTPS, so Render's block on outgoing SMTP does not affect it.
   - Turn email verification back on with `REQUIRE_EMAIL_VERIFICATION=true`, or remove the variable (true is the default).
4. **Address:** set `APP_URL` to the public HTTPS address. It is used in Stripe return URLs and email links.
5. **Persistent uploads:** profile pictures are stored in `public/uploads/`. Render's free filesystem is wiped on every restart, so a real store needs persistent storage there.
   On Render that means a paid instance with a disk mounted at `/var/www/bookwise/public/uploads`; with Docker, the `uploads` volume does this.
6. **Database:**
   - Use a database plan with backups.
   - The entrypoint creates and migrates the schema at start-up. The configured user therefore needs schema privileges (`CREATE`, `ALTER`, `INDEX`, `REFERENCES`) as well as `SELECT, INSERT, UPDATE, DELETE`.
7. **Administrator:** create it once with `BOOKWISE_ADMIN_EMAIL` and `BOOKWISE_ADMIN_PASSWORD`, then remove both variables so the password is not reset at every start.
   Alternatively, use `php bin/create-admin.php` in a shell on the server.

For a Render Blueprint, put these settings in `render.yaml` of your own copy.
Change the `APP_DEMO` value, and add the new secrets as `sync: false` entries, never with their values. Use a paid `plan:` if you need a disk.

## Limits of the free demo

**Render (free web service):**

- The service sleeps after 15 minutes without traffic. The next visit wakes it, which takes about a minute (the "cold start").
- Free instances share 750 free instance-hours per month.
- The filesystem is ephemeral. On every restart, redeploy or wake-up, the following are lost:
  - **uploaded profile pictures**, so customers fall back to the default picture;
  - **log files**;
  - **sessions**, so everyone is signed out;
  - **the simulator's state**. A checkout started before a restart can no longer be paid: its payment page answers "Unknown checkout session".
    Starting a new checkout from the cart works normally; the old order is then marked as failed.
- Outbound SMTP is blocked. The demo sends no email (`MAIL_DRIVER=null`).

**Aiven (free MySQL):**

- 1 GB of storage.
- The free service may be powered off when it is idle. While it is off, `/health.php` answers 503 and a starting container gives up after `DB_WAIT_SECONDS`.
  Power the service on again in the Aiven console. If the web service does not recover by itself, restart or redeploy it from the Render dashboard.

## Security notes

- **The demo takes no real payments.** With `APP_DEMO=true` all payments go to the simulator inside the container. **Never put live Stripe keys into a demo:** the entrypoint refuses to start when `APP_DEMO=true` is combined with an `sk_live_`/`rk_live_` key.
- **The simulator is closed to visitors.** Its API accepts only the key generated for this start. Its test controls (`/stripe-sim/__test/...`) are disabled, and it does not keep customer email addresses.
- **Demo data is public and temporary.** Anyone can register on a public demo. Do not enter real personal data, and expect the data to be reset.
- **Secrets live only in environment variables.** For the demo they are the Render `sync: false` values and the generated Stripe values. They are never in `render.yaml` or in the image, and the entrypoint never prints them.
- **Use TLS for hosted databases.** Set `DB_SSL_CA_PEM` (or `DB_SSL_CA`, a file path); the server certificate is then verified.
- **The container is locked down.** It runs as `www-data` with read-only code and serves only `public/`. PHP never runs under `/uploads/`, and dotfiles are denied.
  Errors are not displayed, and neither Apache nor PHP reveal their versions.
- **Logs stay clean.** URLs are logged without query strings, so reset tokens stay out of the access log. `mail.log` is never copied to the container output.
- **The local Compose setup is not for the internet.** `docker-compose.yml` contains placeholder passwords and an administrator with a published password. Do not expose it publicly.
- **Check the proxy rule for other platforms.** The visitor's address is read from `X-Forwarded-For` only on connections from private addresses.
  If you deploy behind a different proxy setup, check that this rule fits it.
  For example, when Docker's own port forwarding presents connections as coming from its private gateway address, visitors could set that header themselves.
  In that case, put a reverse proxy that sets `X-Forwarded-For` in front of the container, or remove the `mod_remoteip` block from `docker/apache/server.conf`.

## Container settings reference

These variables are specific to the container. The application settings (`DB_*`, `STRIPE_*`, `MAIL_*` and so on) are listed in the README's configuration reference.

| Variable | Default | Description |
| --- | --- | --- |
| `PORT` | `8080` | Port Apache listens on (above 1023). Render sets it. |
| `APP_URL` | from `RENDER_EXTERNAL_URL`, else `http://localhost:$PORT` | Public address of the store, without a trailing slash |
| `RENDER_EXTERNAL_URL` | set by Render | Used for `APP_URL` when that is not set |
| `APP_DEMO` | `false` | `true`: demo banner and the payment simulator at `/stripe-sim` |
| `DB_WAIT_SECONDS` | `60` | How long the entrypoint waits for the database |
| `DB_SSL_CA_PEM` / `DB_SSL_CA` | — | CA certificate text / file for TLS database connections (Aiven) |
| `BOOKWISE_ADMIN_EMAIL` / `BOOKWISE_ADMIN_PASSWORD` | — | Administrator created (or its password reset) at every start when both are set |
| `LOG_DIR` | `storage/logs` | Where `app.log`, `php-error.log` and `mail.log` are written; the first two are copied to the container output |
| `MAIL_DRIVER` | `log` | `null` in the Render demo, `log` locally, `sendgrid` for a real store |
| `APP_ENV` | `production` | Must be `production` for live Stripe keys (the local Compose file uses `local`) |
| `DB_NAME`, `DB_HOST`, `DB_PORT` | — | Shown in the start-up log (never `DB_PASS`) |
| `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_API_BASE`, `STRIPE_SIM_PUBLIC_URL`, `STRIPE_SIM_TEST_API` | — | Set or removed by the entrypoint in demo mode, see [Demo mode and real mode](#demo-mode-and-real-mode) |

## Checks and CI

The **Docker image** job in `.github/workflows/ci.yml` runs next to the PHP 8.1–8.4 test matrix:

1. It checks the shell scripts with `bash -n` and ShellCheck, and runs `tests/Docker/entrypoint_test.sh` (the entrypoint's configuration logic).
2. It builds the image with `docker compose build`. The build fails if a PHP extension or a gd image format is missing, or if the Apache configuration is invalid with or without the simulator.
3. It starts `docker compose up` and waits until `/health.php` answers. Then it checks:
   - that the app runs as `www-data`;
   - `tests/Docker/smoke.sh`: the home page with the 55 books, sign-up, add to cart, checkout to `/stripe-sim/pay/...`, the simulated payment, and the paid confirmation on `success_pay.php`.
     It also checks that `/stripe-sim/__test/...` answers 404, that the simulator API rejects callers without the key, that `/.env`, `/composer.json`, `/vendor/autoload.php`, `/storage/logs/app.log` and other private paths answer 403/404, and the security headers;
   - that the order is stored as `paid` in MySQL;
   - that a second container with `APP_DEMO=false` and `PORT=9090` serves the store without `/stripe-sim`;
   - that `/health.php` answers 503 after the database is stopped.
4. On failure it prints the container logs.

Locally, without Docker:

- `bash tests/Docker/entrypoint_test.sh` tests the entrypoint's configuration logic.
- With `ENTRYPOINT_TEST_DB_NAME=<a database whose name ends in _test>` and `DB_HOST`/`DB_PORT`/`DB_USER`/`DB_PASS`, the same script also runs the whole entrypoint against that database, with a stand-in for Apache. **It drops every table in that database.**
- `vendor/bin/phpunit tests/Docker` runs the PHPUnit tests for `/health.php`, `docker/db-ready.php`, `docker/healthcheck.php` and the deployment files.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| `Database not ready yet … Access denied for user …` | Wrong `DB_USER`/`DB_PASS`/`DB_NAME`. Copy them again from Aiven's connection information. |
| `… SSL …` or certificate errors, or Aiven rejecting the connection | `DB_SSL_CA_PEM` is missing or incomplete. Paste the whole CA certificate, including the BEGIN/END lines. |
| `DB_SSL_CA_PEM does not contain a PEM certificate` | The value is not the certificate text (for example a file name). Paste the certificate itself, or use `DB_SSL_CA` for a file path. |
| `Giving up.` after 60 seconds, repeatedly | The database is unreachable; on Aiven it may be powered off. Power it on, or raise `DB_WAIT_SECONDS`. |
| `APP_DEMO=true must never be combined with a live Stripe key` | Remove the live key from the demo, or set `APP_DEMO=false` for a real store. |
| `Administrator not created: …` | `BOOKWISE_ADMIN_EMAIL` is not a valid address, or `BOOKWISE_ADMIN_PASSWORD` is shorter than 8 characters. Fix or remove both. |
| The first page takes about a minute | A Render free cold start after 15 idle minutes. |
| "Unknown checkout session" on the simulated payment page, or "Payment status unavailable" after it | The service restarted between checkout and payment, so the simulator forgot the session. Go back to the cart and check out again. |
| `PORT must be a TCP port number` / Apache cannot bind | `PORT` must be a number from 1024 to 65535 (the container does not run as root). |
