# Changelog

All notable changes to BookWise are recorded in this file.

The format is based on [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and versions follow [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).
The release workflow publishes each version's section below as the notes of its GitHub release ([CONTRIBUTING.md](CONTRIBUTING.md#releasing-a-version)), so links inside a section are absolute.

## [Unreleased]

## [1.0.0] - 2026-10-15

The first release of BookWise as an audited and extended version of the original student project, the course project of *Fundamentals of Web Programming* at King Faisal University.
`docs/AUDIT.md` records the audit, the reviews and how each part was checked.

### Added

- English and Arabic interface with a right-to-left layout, Arabic plural forms, dates and amounts, and a language that is remembered on the account.
- Guest browsing of the home page, the Explore page (search, genres, pages) and the book pages.
- Accounts: email verification with the account's password, password reset by email, password change and account deletion.
- Stock per book, a catalog of 24 books per page, cover uploads with WebP copies, and refunds in full from the admin order page or the Stripe Dashboard.
- Saudi National Address delivery addresses (up to five per customer), a checkout page with the delivery address and the VAT, delivery stages (processing, shipped with carrier and tracking number, delivered), an order page with a printable receipt and **Buy again**, and order emails in the customer's language.
- Administrator roles: owners, and read-only viewers who open every admin page but change nothing and see customers' details and payment references only shortened.
- Email through any SMTP server (PHPMailer), and a demo inbox (`MAIL_DRIVER=database`) that shows a public demo's emails instead of sending them.
- A payment simulator that runs inside the application (`src/payment_simulator.php`, `public/pay.php`) with four endings (pay, decline, slow payment, cancel), so a demo takes no real payments and needs no Stripe account.
- Demo mode (`APP_DEMO=true`): a banner, one-click demo customers and a shared read-only demo administrator, a showcase of fictional customers, orders and reviews, and a nightly reset (`bin/demo-reset.php`) with a cleanup of demo customers (`bin/demo-cleanup.php`).
- Schema migrations (`bin/migrate.php`, migrations 002 to 006), `bin/install.php`, `bin/create-admin.php` and `bin/upgrade-legacy-db.php` for databases of the original project.
- A Docker image (PHP 8.4 with Apache), a Compose setup with MySQL 8.4, a health endpoint (`/health.php`), and a setup script for PHP hosting without Docker (`bin/setup.php`) with a guide for a free public demo on alwaysdata.
- Versioned asset URLs with long browser caching, and WebP copies of the catalog covers (`bin/optimize-images.php`).
- Tests: PHPUnit suites (unit, integration, http, deploy), browser tests with Playwright and axe-core, and a script that takes the README screenshots.
- GitHub Actions: tests on PHP 8.3, 8.4 and 8.5 with MySQL 8.4 and MariaDB 11.4, PHPStan level 8, a Docker image job, a browser-test job, and a release workflow that publishes the image to GitHub Container Registry. Dependabot proposes weekly updates.
- Documentation: the README, `docs/CONFIGURATION.md`, `docs/ARCHITECTURE.md`, `docs/DEPLOYMENT.md`, `docs/TESTING.md`, `docs/AUDIT.md`, `CONTRIBUTING.md` and `SECURITY.md`.

### Changed

- `public/` is the web root, and the shared code lives in `src/` (procedural modules loaded by Composer); configuration comes from environment variables or `.env`.
- The database is used through PDO with prepared statements instead of `mysqli`, with the constraints, foreign keys and transactions the code relies on.
- Payments: an order is paid only after the Checkout Session has been retrieved from Stripe (or the simulator) and matches the order; prices are charged in integer minor units, and checkouts are serialised per customer.
- The pages were reworked on the original visual identity: real data on the home page, a no-JavaScript Explore page, a cart with an order summary, flash messages instead of alerts, and layouts that work down to 375 pixels.
- PHP 8.3 or newer is required. The project uses PHPUnit 12 and `stripe/stripe-php` 22 (Stripe API version `2026-09-30.endive`, with the client's telemetry turned off).

### Security

- Prepared statements everywhere, output escaping, a Content-Security-Policy without inline scripts, and CSRF tokens on every state-changing request.
- Passwords hashed with `password_hash()` (old MD5 hashes are upgraded at the next sign-in), throttled sign-in, session ID regeneration and session time limits.
- Reset and verification tokens stored only as SHA-256 hashes, single use and expiring; no account enumeration through sign-up, reset or sign-in.
- Uploads checked by their contents, re-encoded with GD and stored under random names, with PHP execution blocked in `uploads/`.
- Access control on the server for every page and action; read-only administrators get 403 for every change.
- Live Stripe keys are refused outside production and in demo mode, and a real store refuses the payment simulator.

### Removed

- SendGrid: the original project's SendGrid library and API key. Email goes through SMTP instead.
- Render: the Render Blueprint (`render.yaml`) and the Render + Aiven demo setup, replaced by the alwaysdata guide before this release.
- The original project's hard-coded Stripe and SendGrid keys, its raw database files with real user accounts, its committed `vendor/` folders, user uploads and IDE files.
- Storing card numbers: the profile keeps only a payment-method preference.

[unreleased]: https://github.com/ByAhmd/Online-BookStore-Project/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/ByAhmd/Online-BookStore-Project/releases/tag/v1.0.0
