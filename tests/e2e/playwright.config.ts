import { defineConfig, devices } from '@playwright/test';

// The stack under test is started outside Playwright (make e2e / CI compose up),
// so no webServer is declared here. Point the run at it with E2E_BASE_URL.
const baseURL = process.env.E2E_BASE_URL ?? 'https://localhost:8443';

export default defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: Boolean(process.env.CI),
  // One retry in CI absorbs a cold-stack race without hiding a genuinely flaky gate.
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: process.env.CI ? [['github'], ['list']] : 'list',
  use: {
    baseURL,
    // The local runtime terminates TLS with a Caddy-issued local CA that is not
    // in the system trust store; the smoke run only needs transport, not trust.
    ignoreHTTPSErrors: true,
    trace: 'on-first-retry',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
