import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end configuration for the real stack.
 *
 * The journeys share one seeded database, so they run in a single worker and
 * never in parallel: two tests creating a customer at the same time would race on
 * the same tenant. `retries: 1` in CI absorbs a flaky network hiccup; locally a
 * failure should be reproducible on the first run.
 *
 * No test sleeps on a timer. Everything waits on a real condition (a locator, a
 * URL, a Mailpit message), which is what makes the retries meaningful.
 */
const baseURL = process.env.E2E_BASE_URL ?? 'http://localhost:5173'

export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : [['list']],
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    actionTimeout: 15_000,
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  webServer: [
    {
      // The SPA proxies /api to this origin, so the session cookie stays
      // first-party and the server's Origin check sees the SPA's own origin.
      command: 'php -S 127.0.0.1:8000 -t public public/index.php',
      cwd: '../backend',
      url: 'http://127.0.0.1:8000/healthz',
      reuseExistingServer: !process.env.CI,
      timeout: 120_000,
    },
    {
      // Binds both loopback families so `localhost` works whichever way the
      // browser resolves it.
      command: 'pnpm dev --host localhost',
      cwd: '../frontend',
      url: baseURL,
      reuseExistingServer: !process.env.CI,
      timeout: 120_000,
    },
  ],
})
