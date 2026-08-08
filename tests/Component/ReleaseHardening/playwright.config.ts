import fs from 'node:fs';
import path from 'node:path';
import { defineConfig } from '@playwright/test';

const root = path.resolve(__dirname, '../../..');
const state = JSON.parse(fs.readFileSync(path.join(root, 'build/release-hardening-runtime/current.json'), 'utf8'));

export default defineConfig({
  testDir: '.',
  testMatch: 'ReleaseHandoverRuntime.spec.ts',
  outputDir: path.join(state.run_dir, 'playwright-results'),
  timeout: 120_000,
  expect: { timeout: 20_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  use: { browserName: 'chromium', headless: true },
  reporter: [['line']],
});
