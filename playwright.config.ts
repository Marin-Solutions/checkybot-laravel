import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: true,
  retries: 0,
  timeout: 30_000,
  expect: { timeout: 15_000 },
  outputDir: process.env.HARNESS_PLAYWRIGHT_OUTPUT || 'build/playwright-results',
  reporter: [['line']],
  use: {
    baseURL: process.env.HARNESS_FRONTEND_URL || 'http://127.0.0.1:8797',
    screenshot: 'only-on-failure',
    trace: 'on',
    ...devices['Desktop Chrome'],
  },
});
