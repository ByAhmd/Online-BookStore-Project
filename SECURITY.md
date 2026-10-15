# Security Policy

## Reporting a vulnerability

Please **do not describe security problems in public issues**.

Report them privately through GitHub's private vulnerability reporting: the **Report a vulnerability** button under **Security** → **Advisories** on this repository.
If the button is not there (private reporting is turned off), open an issue that only asks the maintainers for a private way to send a report, without any details of the problem, and send the report once they have answered.

A report should include:

- the affected page or file,
- steps to reproduce (requests, inputs, expected vs. actual behaviour),
- the impact you believe it has.

We will acknowledge the report, investigate, and credit you in the fix unless you prefer to stay anonymous.

## Scope notes

- Use Stripe **test mode** keys, or the built-in payment simulator, and a local database when investigating. Never test against real payment methods or other people's accounts.
- The payment simulator (`src/payment_simulator.php` and its page `public/pay.php`) is part of the deployed application: it takes the payments of demo stores (`APP_DEMO=true` or `PAYMENT_SIMULATOR=true`).
  It must never move real money or accept a live Stripe key, and `pay.php` must answer 404 while it is off. A real store (`APP_ENV=production` with `APP_DEMO=false`) must never use it. Reports about it are in scope.
- `tests/Support/stripe_mock_router.php` is a test tool that serves the simulator over HTTP for the test suites; it is not deployed (the Docker image contains no tests).
- The protections the application relies on are described in the [security model](docs/ARCHITECTURE.md#security-model), and those of the hosting side in the [deployment checklist](docs/DEPLOYMENT.md#deployment-checklist) and the [security notes](docs/DEPLOYMENT.md#security-notes) of the deployment guide.

## Credential rotation

The original project's source files contained a hard-coded Stripe test secret key and a SendGrid API key.
Neither key is in this repository or its history, and the owner can no longer revoke them.
Stripe and SendGrid both receive GitHub's secret-scanning reports and usually disable keys that leak that way.
Keep the original project files private. BookWise itself uses neither key: Stripe keys come from the environment, and email goes through SMTP.
