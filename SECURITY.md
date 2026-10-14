# Security Policy

## Reporting a vulnerability

Please **do not open a public issue** for security problems.

Report them privately through GitHub's **"Report a vulnerability"** button (Security → Advisories) on this repository, or contact the maintainers directly. Include:

- the affected page or file,
- steps to reproduce (requests, inputs, expected vs. actual behaviour),
- the impact you believe it has.

We will acknowledge the report, investigate, and credit you in the fix unless you prefer to stay anonymous.

## Scope notes

- Use Stripe **test mode** keys, or the built-in payment simulator, and a local database when investigating. Never test against real payment methods or other people's accounts.
- The payment simulator (`src/payment_simulator.php` and its page `public/pay.php`) is part of the deployed application: it takes the payments of demo stores (`APP_DEMO=true` or `PAYMENT_SIMULATOR=true`).
  It must never move real money or accept a live Stripe key, and `pay.php` must answer 404 while it is off. Reports about it are in scope.
- `tests/Support/stripe_mock_router.php` is a test tool that serves the simulator over HTTP for the test suites; it is not deployed (the Docker image contains no tests).
- See the *Security notes* and *Deployment checklist* sections of the README, and the security notes in `docs/DEPLOYMENT.md`, for the protections the application relies on.
