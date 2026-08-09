import { expect, test } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const root = resolve(__dirname, '../..');
const state = JSON.parse(readFileSync(resolve(__dirname, '../build/runtime.json'), 'utf8'));
const evidenceDir = resolve(root, '.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/frontend-expo-status-app/evidence');
const evidencePath = resolve(evidenceDir, 'runtime-journey.json');
const screenshotPath = resolve(evidenceDir, 'runtime-status-offline.png');
const monitorUuid = '99999999-9999-4999-8999-999999999999';

function runtimeEnv() {
  return {
    ...process.env,
    APP_ENV: 'harness', APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    DB_CONNECTION: 'sqlite', DB_DATABASE: state.database, QUEUE_CONNECTION: 'database',
    CACHE_STORE: 'array', SESSION_DRIVER: 'array', HARNESS_RUN_ID: state.runId, HARNESS_RUN_DIR: state.runDir,
    CHECKYBOT_EXPO_DELIVERY_FAKE: 'accepted', CHECKYBOT_LEGACY_WEBHOOK_FAKE: 'accepted', CHECKYBOT_PUSH_PROVING_ENABLED: 'true',
  };
}

function readIntent(): null | { operation_id: string; project_uuid: string; group_id: string; phase: 'incident' | 'recovery'; severity: 'warn' | 'critical'; monitor_uuids: string[] } {
  const result = spawnSync('php', ['mobile/e2e/read-intent.php'], { cwd: root, env: runtimeEnv(), encoding: 'utf8' });
  if (result.status !== 0) throw new Error(result.stderr || 'Unable to read grouped intent evidence.');
  return JSON.parse(result.stdout);
}

test('real alerting, push, authenticated status, deep-link and offline loop', async ({ page, request }) => {
  const browserMessages: string[] = [];
  page.on('console', (message) => browserMessages.push(message.text()));

  const observedAt = Date.now() - 65_000;
  const ingestion: Array<{ operation_id: string; status: number }> = [];
  for (let index = 0; index < 3; index++) {
    const operationId = randomUUID();
    const response = await request.post(`${state.backendUrl}/__harness/alerting/results`, {
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      data: {
        operation_id: operationId,
        identity: { project_uuid: state.projectUuid, monitor_uuid: monitorUuid, type: 'server' },
        source: 'push', observed_at: new Date(observedAt + index * 1000).toISOString(), signal: 'critical',
        reason_code: 'runtime-threshold', value: 95,
        thresholds: { warn: 50, critical: 80, recovery_delta: 5 },
      },
    });
    expect(response.status()).toBe(202);
    ingestion.push({ operation_id: operationId, status: response.status() });
  }

  let statusBody: any;
  await expect.poll(async () => {
    const response = await request.get(`${state.backendUrl}/api/status-summary`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${state.statusToken}` },
    });
    statusBody = await response.json();
    return response.status() === 200 ? statusBody.data?.counts?.servers?.down : -1;
  }).toBe(1);

  let intent: ReturnType<typeof readIntent> = null;
  await expect.poll(() => {
    intent = readIntent();
    return intent?.operation_id ?? null;
  }).not.toBeNull();
  expect(intent!.monitor_uuids).toEqual([monitorUuid]);

  const relay = spawnSync('scripts/harness/artisan', ['checkybot:foundation-relay', '--no-interaction'], {
    cwd: root, env: runtimeEnv(), encoding: 'utf8', timeout: 30_000,
  });
  expect(relay.status, `${relay.stdout}\n${relay.stderr}`).toBe(0);

  let receipt: any;
  await expect.poll(async () => {
    const response = await request.get(`${state.backendUrl}/__harness/push/receipts/${intent!.operation_id}`, { headers: { Accept: 'application/json' } });
    receipt = await response.json();
    return receipt.expo_deliveries?.[0]?.status;
  }).toBe('accepted');
  expect(receipt.listener_status).toBe('processed');
  expect(receipt.legacy_webhook).toBe('accepted');
  expect(receipt.reliability_recorded).toBe(true);
  expect(receipt.expo_deliveries[0].refresh_widget).toBe(true);

  await page.addInitScript(({ statusToken, projectUuid, notification }) => {
    globalThis.__CHECKYBOT_HARNESS_VAULT__ = { statusApiToken: statusToken };
    globalThis.__CHECKYBOT_HARNESS_PROJECT_UUID__ = projectUuid;
    globalThis.__CHECKYBOT_HARNESS_NOTIFICATION__ = notification;
  }, {
    statusToken: state.statusToken,
    projectUuid: state.projectUuid,
    notification: {
      route: 'problems', projectUuid: intent!.project_uuid, groupId: intent!.group_id,
      monitorUuids: intent!.monitor_uuids, phase: intent!.phase, severity: intent!.severity, refreshWidget: true,
    },
  });

  let summaryHttp = 0;
  let firstSummary = true;
  await page.route('**/api/status-summary', async (route) => {
    if (firstSummary) {
      firstSummary = false;
      expect(route.request().headers().authorization).toBe(`Bearer ${state.statusToken}`);
      await new Promise((done) => setTimeout(done, 400));
      const response = await route.fetch();
      summaryHttp = response.status();
      await route.fulfill({ response });
      return;
    }
    await route.continue();
  });

  await page.goto(state.frontendUrl);
  await expect(page.getByTestId('status-loading')).toBeVisible();
  await expect(page.getByText('Problems need attention')).toBeVisible();
  await expect(page.getByTestId('problem-servers-down')).toContainText('1');
  expect(summaryHttp).toBe(200);

  await page.getByRole('button', { name: 'Open latest notification' }).click();
  await expect(page).toHaveURL(/\/problems\?/);
  await expect(page.getByTestId('filter-project')).toContainText(state.projectUuid);
  await expect(page.getByTestId('filter-group')).toContainText(intent!.group_id);
  await expect(page.getByTestId('filter-monitors')).toContainText(monitorUuid);

  await page.route('**/api/status-summary', async (route) => {
    await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Forced offline proof.' }) });
  });
  await page.goBack();
  await expect(page.getByTestId('offline-banner')).toContainText('Showing your last synced status.');
  await expect(page.getByTestId('last-synced')).toContainText(/Last synced \d+m ago/);
  await expect(page.getByTestId('problem-servers-down')).toContainText('1');
  await page.screenshot({ path: screenshotPath, fullPage: true });

  const browserOutput = browserMessages.join('\n');
  expect(browserOutput).not.toContain(state.statusToken);
  expect(browserOutput).not.toContain(state.expoToken);
  await mkdir(evidenceDir, { recursive: true });
  await writeFile(evidencePath, `${JSON.stringify({
    result: 'passed', run_id: state.runId, real_queue_worker: true,
    ingestion, grouped_intent: intent, relay_exit: relay.status,
    push_receipt: receipt, status_summary_http: summaryHttp, status_summary: statusBody,
    shell: { loading_observed: true, problem_observed: true, deep_link_filter_observed: true, offline_cache_observed: true },
    screenshot: 'runtime-status-offline.png', browser_console_secret_free: true,
  }, null, 2)}\n`);
});
