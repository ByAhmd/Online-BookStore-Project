# Contributing to BookWise

Thank you for helping with BookWise. Bug reports and pull requests are welcome on [GitHub](https://github.com/ByAhmd/Online-BookStore-Project).
This guide explains how to set up a development copy, run the tests, follow the project's conventions and, for maintainers, publish a release.
Security problems are reported privately: see [Reporting security issues](#reporting-security-issues).

## Contents

- [Development setup](#development-setup)
- [Running the tests](#running-the-tests)
- [Coding conventions](#coding-conventions)
- [Commit messages](#commit-messages)
- [Reporting security issues](#reporting-security-issues)
- [Releasing a version](#releasing-a-version)

## Development setup

You need:

- PHP 8.3 or newer with `pdo_mysql`, `mbstring`, `curl`, `json` and `fileinfo`, and preferably `gd` (with WebP support, which the image tests use) and `openssl`. `php -v` must show 8.3 or newer: XAMPP 8.2's PHP cannot run BookWise.
- [Composer](https://getcomposer.org/) 2.
- MySQL 8.0.16+ or MariaDB 10.4+.
- For the browser tests only: Node.js 20 or newer.
- Optional: Docker with the Compose plugin, for the container and the CI's Docker checks.

Then:

1. Clone the repository and run `composer install`, which also installs the development tools (PHPUnit 12 and PHPStan 2).
2. Set up a local store as [DEPLOYMENT.md](docs/DEPLOYMENT.md#local-setup-without-docker) describes: a database, a `.env` file from `.env.example`, `php bin/install.php --seed`, an administrator and `php -S localhost:8000 -t public`.
   With `PAYMENT_SIMULATOR=true` (or `APP_DEMO=true`) checkout works without a Stripe account. [CONFIGURATION.md](docs/CONFIGURATION.md) lists every setting.
3. Create a database for the tests. Its name must end in `_test`, because the integration and HTTP suites drop and recreate every table in it:

   ```sql
   CREATE DATABASE bookwise_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

   The tests never read `.env`: they take their settings from `phpunit.xml.dist` and the environment (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`).

[ARCHITECTURE.md](docs/ARCHITECTURE.md) explains how the code is organised.

## Running the tests

```bash
composer lint                                   # php -l on every PHP file
composer analyse                                # PHPStan level 8 (src/, bin/, public/)
composer test:unit                              # unit tests, no database needed
DB_PORT=3306 DB_USER=root DB_PASS=secret BOOKWISE_REQUIRE_DB=1 composer test   # all four suites
composer check                                  # lint, analyse and test, one after the other
```

- One suite: `vendor/bin/phpunit --testsuite unit` (or `integration`, `http`, `deploy`); one file: `vendor/bin/phpunit tests/Http/CheckoutFlowTest.php`.
- Without `BOOKWISE_REQUIRE_DB=1`, the database-backed tests are skipped when no database answers; with it they fail, as on CI.
- The browser tests start a local demo store on their own scratch database (default `bookwise_e2e_test`, which must also end in `_test`):

  ```bash
  npm ci && npx playwright install chromium
  DB_PORT=3306 DB_USER=root DB_PASS=secret npx playwright test
  ```

  `PHP_BINARY` chooses another PHP than the `php` on your `PATH`, and `BASE_URL=http://localhost:8080` tests a running Docker store instead.
- `bash tests/Docker/entrypoint_test.sh` tests the container's entrypoint (Bash; Git Bash works on Windows).

[TESTING.md](docs/TESTING.md) describes each suite, the browser tests, the accessibility checks and the CI jobs.

Before you open a pull request, run lint, PHPStan and all PHPUnit suites with `BOOKWISE_REQUIRE_DB=1`, and the browser tests too when you changed a page, CSS, JavaScript or `e2e/`.
CI runs the same checks on PHP 8.3, 8.4 and 8.5 with MySQL 8.4 and MariaDB 11.4, builds the Docker image and runs the browser tests against it.

## Coding conventions

Match the code around your change. In particular:

- **Procedural PHP, no framework.** Shared code is plain functions in the modules of `src/`, with `declare(strict_types=1)`. Every module is listed under `autoload.files` in `composer.json`, so a new one must be added there. PHPStan stays at level 8 with no errors.
- **Pages in `public/`.** A page starts with `require __DIR__ . '/../src/bootstrap.php';`, checks access with `require_user()`, `customer_or_guest()`, `require_admin()` or `require_admin_write()`, and renders with `page_header()` and `page_footer()`. Only `public/` is served.
- **Forms.** Anything that changes data is a POST: the handler calls `require_post()` and `verify_csrf()`, the form contains `csrf_field()`, and the answer is `flash()` plus `redirect()` (303). Input is read with `input_string()`, which treats text that is not valid UTF-8 as empty.
- **Database.** Only prepared statements, through `db_run()`, `db_one()`, `db_all()` and `db_value()`, with `db_transaction()` for changes that belong together.
- **Output.** Every value printed into HTML goes through `e()`. CSS, scripts and images are linked with `asset()`, which versions their URLs.
- **Content-Security-Policy.** Scripts come only from the store itself: no inline `<script>` and no event-handler attributes (`onclick=` and so on). Put JavaScript in `public/js/` and load it with `page_footer()`.
- **Two languages.** Every text a visitor sees (pages, form messages, emails, error pages) is a key in both `lang/en/<area>.php` and `lang/ar/<area>.php`, with the same placeholders, used through `t()` or `tn()` for plurals (Arabic has six plural forms). Use logical CSS properties (`margin-inline-start` and so on), so the right-to-left layout mirrors by itself. The unit tests fail on a key that is missing in one language or in both.
- **Migrations.** A schema change is a new file in `database/migrations/` (the next number, for example `007_….php`) that returns `static function (PDO $pdo): void` and checks the current schema before it changes it (`db_table_exists()`, `db_column_exists()` and so on), so it can run again. Make the same change in `database/schema.sql`, which fresh installs use: the integration tests compare a migrated database with a fresh one. Never change a migration that has been published.
- **Command-line scripts** in `bin/` answer 404 when a web server opens them (`PHP_SAPI` check) and read their arguments through `$GLOBALS['argv']`.
- **Images** added to `public/imges/` need their WebP copies: run `php bin/optimize-images.php` and commit the `.webp` files.
- **Tests for every behaviour:** unit tests for pure functions, integration tests for code that uses the database, HTTP tests for pages, and deploy tests for the deployment files, the workflows and the documentation.
- **Documentation.** Update the guide that describes what you changed, in plain English that matches the code. The deploy tests check the links and headings between the Markdown files and the settings in [CONFIGURATION.md](docs/CONFIGURATION.md). Add a line under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md) for a change that users or operators notice.
- **Line endings.** Shell scripts and the files used inside the container stay on LF (`.gitattributes`).

## Commit messages

- A subject line that says what the commit does, in plain words and the imperative, without a trailing period, for example `Keep the chosen delivery address when a checkout does not go ahead`.
- A body, after a blank line and wrapped at about 72 characters, that explains why the change was made and what it changes, including its tests.
- One logical change per commit, with its tests and documentation.
- No attribution lines: no `Co-Authored-By:` or "Generated with" trailers, in commits, pull requests or files.

## Reporting security issues

Do not open a public issue for a security problem. [SECURITY.md](SECURITY.md) explains how to report it privately and what is in scope.

## Releasing a version

For maintainers. Versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html): for this application, a major version for changes that need an operator's action beyond `php bin/install.php` (a setting that is removed or renamed, a script or option that goes away), a minor version for new features, and a patch version for fixes.

1. In [CHANGELOG.md](CHANGELOG.md), move the entries under `## [Unreleased]` into a new section `## [X.Y.Z] - YYYY-MM-DD` below it, grouped under Added, Changed, Deprecated, Removed, Fixed and Security, and update the link definitions at the end of the file.
   The section becomes the notes of the GitHub release, so links inside it must be absolute (`https://…`).
2. Check the notes: `php bin/release-notes.php X.Y.Z` prints them, or says what is missing.
3. Commit the changelog on `main` (for example `Release X.Y.Z`), push it and wait until CI passes.
4. Tag that commit and push the tag:

   ```bash
   git tag -a vX.Y.Z -m "BookWise X.Y.Z"
   git push origin vX.Y.Z
   ```

5. The **Release** workflow (`.github/workflows/release.yml`) then:
   - takes the notes from `CHANGELOG.md` and stops, before anything is published, if the version has no section;
   - builds the Docker image and pushes it to GitHub Container Registry as `ghcr.io/byahmd/online-bookstore-project`, tagged `X.Y.Z`, `X.Y`, `X` and `latest`, with provenance and SBOM attestations;
   - creates the GitHub release of the tag with those notes.

   It uses the workflow's own `GITHUB_TOKEN` with `contents: write` and `packages: write`; no other secret is needed.
6. If the workflow stopped, fix the cause, delete the tag (`git push --delete origin vX.Y.Z` and `git tag -d vX.Y.Z`) and tag again.
7. After the first release, open the package on GitHub (the repository's **Packages**) and check its visibility: pulling the image without signing in needs a public package.

[DEPLOYMENT.md](docs/DEPLOYMENT.md#use-a-published-image) explains how to run a published image.
