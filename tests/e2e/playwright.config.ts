import { defineConfig, devices } from '@playwright/test';
import { ownerStorageState } from './tests/support/session';

// The stack under test is started outside Playwright (make e2e / CI compose up),
// so no webServer is declared here. Point the run at it with E2E_BASE_URL.
const baseURL = process.env.E2E_BASE_URL ?? 'https://localhost:8443';

/** Specs that compare pixels also run on the phone viewport (the audit spec drives its own widths). */
const mobileSpecs = /(?:visual|a11y)\.spec\.ts/;

export default defineConfig({
  testDir: './tests',
  // `make e2e-update-snapshots` runs as the host user, who cannot create test-results in the image.
  outputDir: process.env.E2E_OUTPUT_DIR ?? 'test-results',
  // Baselines are generated in the e2e image (Linux fonts); the platform suffix is dropped so a
  // developer's host never produces a second, divergent set.
  snapshotPathTemplate: '{testDir}/__screenshots__/{testFilePath}/{projectName}/{arg}{ext}',
  // The journeys mutate one shared demo workspace (closing a month, adding transactions): running
  // them side by side would make their assertions depend on each other.
  fullyParallel: false,
  workers: 1,
  forbidOnly: Boolean(process.env.CI),
  // One retry in CI absorbs a cold-stack race without hiding a genuinely flaky gate.
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['list']] : 'list',
  expect: {
    toHaveScreenshot: { maxDiffPixelRatio: 0.01, animations: 'disabled', caret: 'hide' },
  },
  use: {
    baseURL,
    // The local runtime terminates TLS with a Caddy-issued local CA that is not
    // in the system trust store; the smoke run only needs transport, not trust.
    ignoreHTTPSErrors: true,
    // ignoreHTTPSErrors does not reach the service worker script fetch, which Chrome rejects over
    // the untrusted local CA and would leave the PWA spec waiting for a worker that never installs.
    launchOptions: { args: ['--ignore-certificate-errors'] },
    trace: 'on-first-retry',
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    {
      name: 'Desktop Chrome',
      testIgnore: [/auth\.setup\.ts/, /smoke\.spec\.ts/],
      use: { ...devices['Desktop Chrome'], storageState: ownerStorageState },
      dependencies: ['setup'],
    },
    {
      name: 'Mobile 360',
      testMatch: mobileSpecs,
      use: {
        ...devices['Pixel 5'],
        viewport: { width: 360, height: 740 },
        storageState: ownerStorageState,
      },
      dependencies: ['setup'],
    },
    { name: 'smoke', testMatch: /smoke\.spec\.ts/, use: { ...devices['Desktop Chrome'] } },
  ],
});
