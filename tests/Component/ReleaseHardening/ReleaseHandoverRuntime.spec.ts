import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { expect, request as playwrightRequest, test } from '@playwright/test';

const root = path.resolve(__dirname, '../../..');
const currentPath = path.join(root, 'build/release-hardening-runtime/current.json');

type Runtime = {
  run_id: string;
  run_dir: string;
  backend_url: string;
  frontend_url: string;
  project_uuid: string;
};

type AlertingReceipt = {
  operation_id: string;
  status: string;
  current_state: string | null;
  incident_groups: Array<Record<string, unknown>>;
  notification_intents: Array<{
    operation_id: string;
    phase: string;
    notification_thread_key: string;
    affected_monitors: Array<Record<string, unknown>>;
  }>;
};

type PushReceipt = {
  operation_id: string;
  listener_status: string;
  expo_deliveries: Array<{ status: string }>;
  legacy_webhook: string;
  reliability_recorded: boolean;
};

function relay() {
  return execFileSync('node', ['tests/Component/ReleaseHardening/runtime-stage.mjs', 'relay'], {
    cwd: root,
    encoding: 'utf8',
  }).trim();
}

async function json(response: import('@playwright/test').APIResponse) {
  return await response.json() as Record<string, any>;
}

test('canonical release handover journey proves alerting, push, status refresh, and cached failure states', async ({ page, context }) => {
  const runtime = JSON.parse(fs.readFileSync(currentPath, 'utf8')) as Runtime;
  const api = await playwrightRequest.newContext({ baseURL: runtime.backend_url, extraHTTPHeaders: { Accept: 'application/json' } });
  const tracePath = path.join(runtime.run_dir, 'playwright-trace.zip');
  const statusScreenshot = path.join(runtime.run_dir, 'status-updated.png');
  const staleScreenshot = path.join(runtime.run_dir, 'status-api-failure.png');
  const stages: string[] = [];
  const snapshots: Record<string, unknown> = {};

  await context.tracing.start({ screenshots: true, snapshots: true, sources: true });
  try {
    await page.goto(runtime.frontend_url);
    await expect(page.getByTestId('all-healthy')).toContainText('No warnings or outages right now.');
    await expect(page.getByTestId('last-synced')).toContainText('Last synced unknown');
    stages.push('built production StatusScreen loaded explicit healthy/empty state from authenticated real HTTP');

    const blipMonitor = crypto.randomUUID();
    const blipStarted = new Date(Date.now() - 25_000);
    const failedOperation = crypto.randomUUID();
    const recoveredOperation = crypto.randomUUID();
    const failedPayload = {
      operation_id: failedOperation,
      identity: { project_uuid: runtime.project_uuid, monitor_uuid: blipMonitor, type: 'website' },
      source: 'pull', observed_at: blipStarted.toISOString(), signal: 'failure', reason_code: 'deploy-timeout',
    };
    const recoveredPayload = {
      operation_id: recoveredOperation,
      identity: { project_uuid: runtime.project_uuid, monitor_uuid: blipMonitor, type: 'website' },
      source: 'pull', observed_at: new Date(blipStarted.getTime() + 20_000).toISOString(), signal: 'success', reason_code: null,
    };
    const failedResponse = await api.post('/__harness/alerting/results', { data: failedPayload });
    const recoveredResponse = await api.post('/__harness/alerting/results', { data: recoveredPayload });
    expect(failedResponse.status()).toBe(202);
    expect(recoveredResponse.status()).toBe(202);

    let recoveredReceipt!: AlertingReceipt;
    await expect.poll(async () => {
      const response = await api.get(`/__harness/alerting/receipts/${recoveredOperation}`);
      if (response.status() !== 200) return `http-${response.status()}`;
      recoveredReceipt = await response.json() as AlertingReceipt;
      return recoveredReceipt.status;
    }).toBe('processed');
    const blipRelay = relay();
    const failedReceiptResponse = await api.get(`/__harness/alerting/receipts/${failedOperation}`);
    const failedReceipt = await failedReceiptResponse.json() as AlertingReceipt;
    const failedPush = await api.get(`/__harness/push/receipts/${failedOperation}`);
    const recoveredPush = await api.get(`/__harness/push/receipts/${recoveredOperation}`);
    expect(failedReceipt.notification_intents).toEqual([]);
    expect(recoveredReceipt.notification_intents).toEqual([]);
    expect(failedPush.status()).toBe(404);
    expect(recoveredPush.status()).toBe(404);
    snapshots.deploy_blip = {
      elapsed_seconds: 20,
      post_statuses: [failedResponse.status(), recoveredResponse.status()],
      alerting: { failed: failedReceipt, recovered: recoveredReceipt },
      push_statuses: { failed: failedPush.status(), recovered: recoveredPush.status() },
      relay: blipRelay,
    };
    stages.push('sub-30-second deploy blip produced zero intents and no push receipt');

    const monitors = [crypto.randomUUID(), crypto.randomUUID()];
    const criticalPayloads: Array<Record<string, unknown>> = [];
    const base = Date.now() - 15_000;
    for (const [monitorIndex, monitor] of monitors.entries()) {
      for (let sample = 0; sample < 3; sample += 1) {
        const payload = {
          operation_id: crypto.randomUUID(),
          identity: { project_uuid: runtime.project_uuid, monitor_uuid: monitor, type: 'server' },
          source: 'push',
          observed_at: new Date(base + (monitorIndex * 3 + sample) * 1_000).toISOString(),
          signal: 'critical', reason_code: 'threshold-exceeded', value: 95,
          thresholds: { warn: 50, critical: 80, recovery_delta: 5 },
        };
        criticalPayloads.push(payload);
        const response = await api.post('/__harness/alerting/results', { data: payload });
        expect(response.status()).toBe(202);
      }
    }

    const finalOperation = String(criticalPayloads.at(-1)?.operation_id);
    let criticalReceipt!: AlertingReceipt;
    await expect.poll(async () => {
      const response = await api.get(`/__harness/alerting/receipts/${finalOperation}`);
      if (response.status() !== 200) return `http-${response.status()}`;
      criticalReceipt = await response.json() as AlertingReceipt;
      return `${criticalReceipt.status}:${criticalReceipt.notification_intents.length}`;
    }, { timeout: 30_000 }).toBe('processed:1');
    const criticalRelay = relay();
    const incidentIntent = criticalReceipt.notification_intents[0];
    expect(incidentIntent.phase).toBe('incident');
    expect(incidentIntent.affected_monitors).toHaveLength(2);

    let pushReceipt!: PushReceipt;
    await expect.poll(async () => {
      const response = await api.get(`/__harness/push/receipts/${incidentIntent.operation_id}`);
      if (response.status() !== 200) return `http-${response.status()}`;
      pushReceipt = await response.json() as PushReceipt;
      return pushReceipt.listener_status;
    }, { timeout: 30_000 }).toBe('processed');
    expect(pushReceipt.expo_deliveries).toHaveLength(1);
    expect(pushReceipt.expo_deliveries[0].status).toBe('accepted');
    expect(pushReceipt.legacy_webhook).toBe('accepted');
    expect(pushReceipt.reliability_recorded).toBe(true);

    const replayStatuses = [];
    for (const payload of criticalPayloads) {
      replayStatuses.push((await api.post('/__harness/alerting/results', { data: payload })).status());
    }
    expect(replayStatuses).toEqual(Array(6).fill(200));
    const replayRelay = relay();
    const deduplicatedPush = await json(await api.get(`/__harness/push/receipts/${incidentIntent.operation_id}`)) as PushReceipt;
    expect(deduplicatedPush.expo_deliveries).toHaveLength(1);
    expect(deduplicatedPush.legacy_webhook).toBe('accepted');
    snapshots.confirmed_critical_group = {
      alerting: criticalReceipt,
      push: pushReceipt,
      replay_statuses: replayStatuses,
      push_after_replay: deduplicatedPush,
      relays: [criticalRelay, replayRelay],
    };
    stages.push('confirmed two-monitor critical group produced one deduplicated Expo and legacy-webhook receipt');

    const summaryResponse = await api.get('/api/status-summary', {
      headers: { Accept: 'application/json', Authorization: 'Bearer cbp_harness_status_read_token' },
    });
    expect(summaryResponse.status()).toBe(200);
    const summary = await json(summaryResponse);
    expect(summary.data.counts.servers.down).toBe(2);
    expect(summary.data.stale).toBe(false);
    snapshots.status_summary = { http_status: summaryResponse.status(), body: summary };

    await page.getByRole('button', { name: 'Refresh status' }).click();
    await expect(page.getByTestId('problem-servers-down')).toContainText('2');
    await expect(page.getByText('Problems need attention')).toBeVisible();
    await expect(page.getByTestId('last-synced')).not.toContainText('unknown');
    await page.screenshot({ path: statusScreenshot, fullPage: true });
    stages.push('authenticated status-summary counts refreshed into the production status surface');

    await page.route('**/api/status-summary', (route) => route.abort('failed'));
    await page.getByRole('button', { name: 'Refresh status' }).click();
    await expect(page.getByTestId('offline-banner')).toContainText('Showing your last synced status.');
    await expect(page.getByTestId('problem-servers-down')).toContainText('2');
    await expect(page.getByTestId('last-synced')).not.toContainText('unknown');
    await page.screenshot({ path: staleScreenshot, fullPage: true });
    snapshots.forced_api_failure = {
      mechanism: 'Playwright aborted the surfaced GET /api/status-summary request',
      offline_alert_visible: true,
      cached_server_down: 2,
      last_synced_age_visible: true,
    };
    stages.push('forced surfaced API transport failure retained cached counts and visible last-sync age in offline treatment');

    fs.writeFileSync(path.join(runtime.run_dir, 'browser-evidence.json'), `${JSON.stringify({
      acceptance_criteria: ['AC-release-hardening-8', 'AC-release-hardening-9'],
      run_id: runtime.run_id,
      stages,
      response_snapshots: snapshots,
      screenshots: { status: statusScreenshot, stale_error: staleScreenshot },
      trace: tracePath,
    }, null, 2)}\n`);
  } finally {
    await context.tracing.stop({ path: tracePath });
    await api.dispose();
  }
});
