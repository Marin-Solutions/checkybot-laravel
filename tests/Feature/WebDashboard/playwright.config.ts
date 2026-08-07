import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  testMatch: 'ApiAssertionBuilderRuntime.spec.ts',
  timeout: 90_000,
  fullyParallel: false,
  workers: 1,
  use: {
    browserName: 'chromium',
    headless: true,
    trace: 'retain-on-failure',
  },
  reporter: [['line']],
});
