// Real-browser end-to-end and accessibility tests (e2e/), see docs/TESTING.md.
//
//     npx playwright test                                 local demo store, started for the run
//     BASE_URL=http://localhost:8080 npx playwright test  a running demo, e.g. docker compose up
//
// The store under test must run in demo mode (APP_DEMO=true) with the payment
// simulator and the demo inbox (MAIL_DRIVER=database), as docker-compose.yml
// and e2e/local-server.js set it up. Without BASE_URL, Playwright starts
// e2e/local-server.js, which reinstalls the scratch database named by DB_NAME
// (it must end in "_test"; default bookwise_e2e_test).
import { defineConfig, devices } from '@playwright/test';

const localUrl = 'http://127.0.0.1:8181';
// The tests open pages by relative paths ('index.php'), which Playwright
// resolves like a link on the page at baseURL. With exactly one trailing
// slash they stay inside a store in a subfolder (http://localhost/bookwise/).
const baseURL = `${(process.env.BASE_URL || localUrl).replace(/\/+$/, '')}/`;
const ci = Boolean(process.env.CI);

export default defineConfig({
    testDir: './e2e',
    testMatch: '*.spec.js',
    // Each test creates its own demo customer, so tests are independent; the
    // payment simulator's "slow payment" alone takes about 15 seconds.
    fullyParallel: true,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    forbidOnly: ci,
    // One retry on CI only: every retry of a customer journey creates another
    // demo customer, and the demo allows 10 per visitor address and hour.
    retries: ci ? 1 : 0,
    workers: ci ? 2 : undefined,
    reporter: ci ? [['list'], ['html', { open: 'never' }]] : [['list'], ['html', { open: 'on-failure' }]],
    use: {
        baseURL,
        locale: 'en-US',
        timezoneId: 'Asia/Riyadh',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: process.env.BASE_URL
        ? undefined
        : {
            command: 'node e2e/local-server.js',
            url: `${localUrl}/health.php`,
            reuseExistingServer: !ci,
            timeout: 120_000,
            stdout: 'ignore',
            stderr: 'pipe',
        },
});
