// The README screenshots (npm run screenshots), see docs/TESTING.md. Writes
// JPEG files into docs/screenshots/ from a running demo store (BASE_URL) or,
// without BASE_URL, from the local store that e2e/local-server.js starts.
import { fileURLToPath } from 'node:url';
import { defineConfig } from '@playwright/test';
import baseConfig from '../playwright.config.js';

export default defineConfig({
    ...baseConfig,
    testDir: './screenshots',
    testMatch: '*.shots.js',
    outputDir: '../test-results',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 120_000,
    reporter: [['list']],
    use: {
        ...baseConfig.use,
        trace: 'off',
        screenshot: 'off',
    },
    projects: [{ name: 'screenshots' }],
    webServer: baseConfig.webServer
        ? { ...baseConfig.webServer, command: `node "${fileURLToPath(new URL('./local-server.js', import.meta.url))}"` }
        : undefined,
});
