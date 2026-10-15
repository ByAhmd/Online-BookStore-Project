# Configuring BookWise

Every setting of BookWise is an environment variable. This page lists all of them, with the value the application uses when a variable is not set (`src/env.php`), and explains the payment and email settings in more detail.
`.env.example` is the template for your own `.env` file.

## Contents

- [How settings are read](#how-settings-are-read)
- [Settings reference](#settings-reference)
- [Common combinations](#common-combinations)
- [Payments: the simulator or Stripe](#payments-the-simulator-or-stripe)
- [Email](#email)
- [Settings outside the application](#settings-outside-the-application)

## How settings are read

- `src/bootstrap.php` loads the `.env` file in the project root (`load_env()` in `src/env.php`) at the start of every page and of the scripts in `bin/` (`bin/setup.php` reads the environment before it writes `.env`, and `lint.php` and `release-notes.php` need no settings). A missing file is ignored.
- Variables that are already set in the real environment (by the web server, the container or your shell) take precedence over `.env`, even when they are empty. An empty value counts as not set, so the default below applies.
- The file has one `KEY=VALUE` per line. Blank lines and lines starting with `#` are ignored, as is a comment after a value (`value # note`), and an `export ` prefix is allowed.
  Values can be quoted: `'single'` quotes keep the text as it is, `"double"` quotes understand `\n`, `\"` and `\\`.
- Yes/no settings are true for `1`, `true`, `yes` or `on` (in any case) and false for anything else.
- `.env` is git-ignored and must never be committed; `.env.example` contains placeholders only. The PHPUnit suites never read `.env`, and the local store of the browser tests takes only its database login from it (see [TESTING.md](TESTING.md)).

To start, copy the template and edit it:

```bash
cp .env.example .env
```

`.env.example` is set up for local development, so a few of its values differ from the defaults below: `APP_ENV=local` (default `production`), `DB_USER=bookwise` and `DB_PASS=change-me` (default `root` with no password), and placeholder Stripe keys (`sk_test_replace_me`, `whsec_replace_me`).
On PHP hosting, `php bin/setup.php` writes a `.env` with the values of a public demo instead ([DEPLOYMENT.md](DEPLOYMENT.md#step-6-run-the-setup-script-over-ssh)).

## Settings reference

### Application

| Variable | Default | Description |
| --- | --- | --- |
| `APP_NAME` | `BookWise` | Name shown in page titles, the navigation bar and the footer |
| `APP_ENV` | `production` | `local`, `testing` or `production`. Live Stripe keys are refused unless this is `production`, and with `production` the payment simulator needs `APP_DEMO=true`. |
| `APP_DEBUG` | `false` | Show exception details in the browser. **Never enable in production.** |
| `APP_URL` | `http://localhost:8000` | Public URL of `public/` without a trailing slash (trailing slashes are removed). Used for Stripe return URLs, the payment simulator's return addresses and the links in emails (password reset, email confirmation, order pages). With `https://`, cookies are marked `Secure` and HSTS is sent. |
| `APP_TIMEZONE` | `Asia/Riyadh` | PHP and database-session timezone |
| `APP_DEFAULT_LOCALE` | `en` | Interface language for visitors who have not chosen one: `en` or `ar` |
| `APP_DEMO` | `false` | Public demo mode: shows a banner, offers the one-click demo accounts on the sign-in page (`demo_login.php` answers 404 without it), turns the payment simulator on (unless `PAYMENT_SIMULATOR=false`), keeps the sample catalog in stock and lets `bin/install.php` add the [showcase](ARCHITECTURE.md#the-showcase-and-the-daily-reset) ([DEPLOYMENT.md](DEPLOYMENT.md)). Required for the demo inbox (`MAIL_DRIVER=database`). |
| `REQUIRE_EMAIL_VERIFICATION` | `true` | New customers must confirm their email address before signing in. Needs working email in production. |
| `LOG_DIR` | `storage/logs` | Directory for `app.log`, `php-error.log` and `mail.log` (relative paths start at the project root) |

### Database

| Variable | Default | Description |
| --- | --- | --- |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | Database server (MySQL 8.0.16+ or MariaDB 10.4+) |
| `DB_NAME` | `bookwise` | Database name |
| `DB_USER` / `DB_PASS` | `root` / *(empty)* | Database credentials |
| `DB_SSL_CA` / `DB_SSL_CA_PEM` | — | TLS for hosted databases: path to the CA certificate, or the certificate text itself. The server certificate is then verified. Leave both empty for a local server. |

### Payments

| Variable | Default | Description |
| --- | --- | --- |
| `PAYMENT_SIMULATOR` | follows `APP_DEMO` | `true`: payments go to the built-in [payment simulator](#payments-the-simulator-or-stripe) instead of Stripe (refused on a real store: `APP_ENV=production` with `APP_DEMO=false`); `false`: always Stripe. Empty or unset: on in demo mode, off otherwise. |
| `PAYMENT_SIMULATOR_STORE` | `storage/payment-simulator.json` | The simulator's state file (relative paths start at the project root); its folder must be writable |
| `STRIPE_SECRET_KEY` | — | Stripe secret key (`sk_test_…` for development). Not used while the simulator is on. Outside `APP_ENV=production`, and in demo mode, only test-mode keys (`sk_test_`, `rk_test_`) are accepted. |
| `STRIPE_WEBHOOK_SECRET` | — | Signing secret of the webhook endpoint (`whsec_…`). Not needed while the simulator is on: the endpoint then answers 404. |
| `STRIPE_CURRENCY` | `sar` | ISO code of a **two-decimal** currency that book prices are charged in, also by the simulator (zero- and three-decimal currencies such as JPY or KWD are refused) |
| `STRIPE_API_BASE` | — | **Tests only:** points the Stripe SDK at the local mock in `tests/Support` (ignored while the simulator is on) |

### Email

| Variable | Default | Description |
| --- | --- | --- |
| `MAIL_DRIVER` | `log` | `log` (write to `mail.log`), `smtp` (send through `SMTP_*`), `database` (demo inbox; only with `APP_DEMO=true`) or `null` (discard). See [Email](#email). |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | `no-reply@example.com` / `BookWise` | Sender of outgoing email; your provider must allow this address |
| `SMTP_HOST` / `SMTP_PORT` | — / `587` | SMTP server for `MAIL_DRIVER=smtp`. Use port `2525` where a host blocks 587 and the provider offers it. |
| `SMTP_USERNAME` / `SMTP_PASSWORD` | — | SMTP sign-in; leave `SMTP_USERNAME` empty for a server without authentication. The password is never logged. |
| `SMTP_ENCRYPTION` | `tls` | `tls` (STARTTLS, required before signing in), `ssl` (TLS from the start, usually port 465) or `none` (only for a test server on your own machine; signs in only to `localhost`) |
| `SMTP_TIMEOUT` | `10` | Seconds to wait for the SMTP server before the email counts as failed |

### Command-line scripts

These are read by the scripts in `bin/`, not by the pages, and are not in `.env.example`.

| Variable | Default | Description |
| --- | --- | --- |
| `BOOKWISE_ADMIN_PASSWORD` | — | Password of the administrator that `bin/create-admin.php` creates or resets (otherwise it asks), and that `bin/setup.php` creates (otherwise it asks). With `BOOKWISE_ADMIN_EMAIL`, `bin/install.php` creates that administrator, or resets its password when it differs. |
| `BOOKWISE_ADMIN_EMAIL` | — | Used by `bin/install.php` (with `BOOKWISE_ADMIN_PASSWORD`, see above), and by `bin/setup.php` when `--admin-email` is not given: it then creates that administrator without asking |

`bin/setup.php` also takes `APP_URL`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASS` from the environment before it asks for them; `php bin/setup.php --help` lists its options.

## Common combinations

| | Local development | Docker Compose (local) | Public demo | Real store |
| --- | --- | --- | --- | --- |
| `APP_ENV` | `local` | `local` | `production` | `production` |
| `APP_DEBUG` | `false` (`true` while debugging) | `false` | `false` | `false` |
| `APP_DEMO` | `false` or `true` | `true` | `true` | `false` |
| `PAYMENT_SIMULATOR` | `true` (or Stripe test keys) | not set (demo mode turns it on) | `true` (set by `bin/setup.php`; demo mode turns it on anyway) | `false` |
| `MAIL_DRIVER` | `log` | `database` | `database` | `smtp` |
| `REQUIRE_EMAIL_VERIFICATION` | `false`, or open the links in `mail.log` | `false` | `true` (the links appear in the demo inbox) | `true` |

The Compose column is `docker-compose.yml`. [DEPLOYMENT.md](DEPLOYMENT.md) walks through the [local setup](DEPLOYMENT.md#local-setup-without-docker), the public demo and the switch to a [real store](DEPLOYMENT.md#d-a-real-store-stripe-payments-and-email).

## Payments: the simulator or Stripe

BookWise takes payments with Stripe Checkout. For demos and local development it has a **built-in payment simulator** instead, so no Stripe account is needed and no money can ever move.
How payments work inside the store (checkout, verification, refunds, webhooks) is described in [ARCHITECTURE.md](ARCHITECTURE.md#checkout-payments-and-refunds).

### The payment simulator

- Turn it on with `PAYMENT_SIMULATOR=true`. It is on by default in demo mode (`APP_DEMO=true`); `PAYMENT_SIMULATOR=false` turns it off there.
  A real store (`APP_ENV=production` with `APP_DEMO=false`) refuses it and takes no payments until `PAYMENT_SIMULATOR=false` is set, so a forgotten setting cannot give books away.
- It runs inside the application, so `php -S localhost:8000 -t public` is enough to buy a book, and the same holds on any PHP host. Its page is `pay.php`.
- Its state is a JSON file, `PAYMENT_SIMULATOR_STORE` (default `storage/payment-simulator.json`); the web server must be able to write to its folder.
- While it is on, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` and `STRIPE_API_BASE` are not used; `STRIPE_CURRENCY` still sets the store's currency. A live Stripe key is never used in demo mode.

[DEPLOYMENT.md](DEPLOYMENT.md#the-payment-simulator) describes the simulator's page and its four endings in detail.

### Stripe (test mode)

1. In the [Stripe Dashboard](https://dashboard.stripe.com/test/apikeys) (test mode), copy the **Secret key** (`sk_test_…`) into `STRIPE_SECRET_KEY`, and keep the simulator off.
2. Make sure `APP_URL` is the address you browse to; Stripe redirects back to `APP_URL/success_pay.php`.
3. Pay with a [Stripe test card](https://docs.stripe.com/testing#cards) such as `4242 4242 4242 4242`, with any future expiry date and any CVC.

**Webhooks (recommended).** These confirm payments even if the customer closes the browser before returning.
Register `APP_URL/stripe_webhook.php` for these events:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `checkout.session.expired`
- `charge.refunded` (records full refunds made in the Stripe Dashboard)

Put the endpoint's signing secret in `STRIPE_WEBHOOK_SECRET`. For local development, use the [Stripe CLI](https://docs.stripe.com/stripe-cli):

```bash
stripe listen --forward-to localhost:8000/stripe_webhook.php
```

`stripe listen` prints a `whsec_…` secret to use.
For a real store, [DEPLOYMENT.md](DEPLOYMENT.md#d-a-real-store-stripe-payments-and-email) lists the remaining steps.

## Email

The store sends, in the customer's language, the email-verification (or welcome) email, a notice when someone tries to sign up with an existing address, the password-reset email, a password-changed notice, an account-deleted confirmation and the [order emails](ARCHITECTURE.md#emails).
Every email has a plain-text and an HTML part. `MAIL_DRIVER` decides where it goes:

- **`log`** (default) writes every email, including password-reset and verification links, to `storage/logs/mail.log`. Nothing is sent. For local development only: never use it in production, because the log then holds working reset links.
- **`smtp`** sends through any SMTP server, using [PHPMailer](https://github.com/PHPMailer/PHPMailer). Most email providers offer SMTP; copy the server, port and sign-in from their settings into `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME` and `SMTP_PASSWORD`, and set `MAIL_FROM_ADDRESS` to an address the provider lets you send from.
  - `SMTP_ENCRYPTION=tls` (the default) requires STARTTLS: if the server does not offer it, the email fails before the password is sent.
    `ssl` uses TLS from the start (usually port 465). `none` is only for a test server on your own machine, such as [Mailpit](https://mailpit.axllent.org/).
    With `none`, BookWise signs in only to `localhost` or a loopback address; for any other `SMTP_HOST` with an `SMTP_USERNAME`, the email fails and an error is logged, because the password would cross the network unencrypted.
  - Port 587 is the standard. Some hosts block it; many providers also accept port 2525.
  - Messages are encoded as UTF-8, so Arabic subjects, names and text arrive intact.
  - A server that does not answer makes the email fail after `SMTP_TIMEOUT` seconds (10 by default), so a page never hangs on it.
    Failures are logged in `app.log` as `Email delivery failed` (never with the password); visitors see the usual answer.
  - The host must allow outgoing connections to the SMTP port.
- **`database`** is the **demo inbox**, for a public demo without an email provider. It only works with `APP_DEMO=true`; otherwise every email fails and an error is logged.
  Emails are stored in the `demo_mail` table instead of being sent, and shown on `inbox.php` (the **Inbox** link in the header). Who may read which email is described in [ARCHITECTURE.md](ARCHITECTURE.md#the-demo-inbox).
- **`null`** discards every email. Use it with `REQUIRE_EMAIL_VERIFICATION=false`, or new customers can never confirm their address.

The one-click demo customers have made-up addresses at the reserved `demo.invalid` domain: their emails appear in the demo inbox (or `mail.log`), and are never sent over SMTP.

## Settings outside the application

- **The Docker image** adds a few settings of its own (`PORT`, `DB_WAIT_SECONDS`, `BOOKWISE_SEED`, `BOOKWISE_APP_URL` for Compose and others): see [DEPLOYMENT.md](DEPLOYMENT.md#container-settings-reference).
- **The tests** take their database from `DB_*` and use `BOOKWISE_REQUIRE_DB`, `BASE_URL`, `PHP_BINARY` and a few more: see [TESTING.md](TESTING.md).
