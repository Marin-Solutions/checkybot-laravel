import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  testMatch: 'IncidentGroupingRuntime.spec.ts',
  fullyParallel: false,
  forbidOnly: true,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 20_000 },
  reporter: [['line']],
  outputDir: process.env.HARNESS_PLAYWRIGHT_OUTPUT || 'build/incident-grouping-playwright',
  use: {
    baseURL: process.env.HARNESS_BACKEND_URL || 'http://127.0.0.1:8787',
    trace: 'on',
  },
});
