import { defineConfig, devices } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const state = JSON.parse(readFileSync(resolve(__dirname, '../build/runtime.json'), 'utf8'));

export default defineConfig({
  testDir: '.',
  testMatch: 'status-runtime.spec.ts',
  fullyParallel: false,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  reporter: [['line']],
  outputDir: resolve(__dirname, '../build/playwright'),
  use: { baseURL: state.frontendUrl, trace: 'on', screenshot: 'only-on-failure', ...devices['Desktop Chrome'] },
});
