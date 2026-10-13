# Security Policy

## Reporting a vulnerability

Please **do not open a public issue** for security problems.

Report them privately through GitHub's **"Report a vulnerability"** button (Security → Advisories) on this repository, or contact the maintainers directly. Include:

- the affected page or file,
- steps to reproduce (requests, inputs, expected vs. actual behaviour),
- the impact you believe it has.

We will acknowledge the report, investigate, and credit you in the fix unless you prefer to stay anonymous.

## Scope notes

- Use Stripe **test mode** keys and a local database when investigating. Never test against real payment methods or other people's accounts.
- `tests/Support/stripe_mock_router.php` is a local test tool and is not part of the deployed application.
- See the *Security notes* and *Deployment checklist* sections of the README for the protections the application relies on.
