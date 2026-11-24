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
/**
 * Ports are configurable so the suite can run beside a development stack.
 *
 * `E2E_API_PORT` / `E2E_WEB_PORT` default to the documented 8000 and 5173; on a
 * machine where those are taken (a stray `php -S`, a running `pnpm dev`) set both
 * and the proxy, the readiness URL and the base URL follow. `KEELSON_BACKEND_ORIGIN`
 * is what the SPA's Vite proxy targets, so it has to move with the API port.
 */
const apiPort = process.env.E2E_API_PORT ?? '8000'
const webPort = process.env.E2E_WEB_PORT ?? '5173'
const apiOrigin = process.env.E2E_API_ORIGIN ?? `http://127.0.0.1:${apiPort}`
const baseURL = process.env.E2E_BASE_URL ?? `http://localhost:${webPort}`

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
      command: `php -S 127.0.0.1:${apiPort} -t public public/index.php`,
      cwd: '../backend',
      url: `${apiOrigin}/healthz`,
      reuseExistingServer: !process.env.CI,
      timeout: 120_000,
    },
    {
      // Binds both loopback families so `localhost` works whichever way the
      // browser resolves it.
      command: `pnpm dev --host localhost --port ${webPort}`,
      cwd: '../frontend',
      url: baseURL,
      reuseExistingServer: !process.env.CI,
      timeout: 120_000,
      env: { KEELSON_BACKEND_ORIGIN: apiOrigin },
    },
  ],
})
