import { defineConfig } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const runtime = JSON.parse(readFileSync(resolve(process.cwd(), 'build/agent-evaluator-playwright/runtime.json'), 'utf8')) as {
  backend_url: string;
};

export default defineConfig({
  testDir: '.',
  testMatch: 'AgentEvaluatorRuntime.spec.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 20_000 },
  reporter: [['line']],
  outputDir: resolve(process.cwd(), 'build/agent-evaluator-playwright/results'),
  use: { baseURL: runtime.backend_url, trace: 'on' },
});
