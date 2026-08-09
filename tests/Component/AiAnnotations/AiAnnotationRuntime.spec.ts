import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { expect, request as playwrightRequest, test } from '@playwright/test';

const root = path.resolve(__dirname, '../../..');
const currentPath = path.join(root, 'build/ai-annotation-runtime/current.json');

type Runtime = {
  runtime_id: string;
  run_dir: string;
  backend_url: string;
  frontend_url: string;
  project_uuid: string;
  opted_out_server_uuid: string;
  enabled_server_uuid: string;
  agent_token: string;
};

type AlertReceipt = {
  status: string;
  current_state: string | null;
  transitions: Array<{ to: string }>;
  notification_intents: unknown[];
};

type AnnotationReceipt = {
  operation_id: string;
  status: string;
  annotation: { root_cause: string | null; generated_at: string | null };
  notification_side_effects: { before: Record<string, number>; after: Record<string, number> };
};

function uuidV5Url(name: string): string {
  const namespace = Buffer.from('6ba7b8119dad11d180b400c04fd430c8', 'hex');
  const digest = crypto.createHash('sha1').update(namespace).update(name).digest().subarray(0, 16);
  digest[6] = (digest[6] & 0x0f) | 0x50;
  digest[8] = (digest[8] & 0x3f) | 0x80;
  const hex = digest.toString('hex');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function relay(): string {
  return execFileSync('node', ['tests/Component/AiAnnotations/runtime-stage.mjs', 'relay'], {
    cwd: root,
    encoding: 'utf8',
  });
}

function reportPayload(serverUuid: string, observedAt: string) {
  return {
    schema_version: 'agent-report.v2',
    operation_id: crypto.randomUUID(),
    agent_version: '2.5.0',
    server_uuid: serverUuid,
    observed_at: observedAt,
    reporting_interval_seconds: 60,
    cpu: { five_min_percent: 99 },
    memory: { used_percent: 99 },
    disks: [{ mount: '/', used_percent: 95, predicted_days_to_full: 2 }],
    network_interfaces: [{
      name: 'eth0', rx_bytes_total: 2_000, tx_bytes_total: 3_000,
      rx_delta_bytes: 1_000, tx_delta_bytes: 1_500, elapsed_seconds: 60, sample_status: 'ready',
    }],
    php_fpm_pools: [{ pool: 'www', active_workers: 20, max_children: 20, max_children_reached_5m: 1 }],
    nginx_window: { window_seconds: 300, total_requests: 100, five_xx_count: 20, upstream_timeout_count: 5 },
    prerequisites: [
      { kind: 'nginx_access_log', path_hint: '/var/log/nginx/access.log', status: 'readable' },
      { kind: 'php_fpm_status', path_hint: '/run/php/status', status: 'readable' },
    ],
    relevant_log_lines: [{
      source: 'nginx', observed_at: observedAt,
      line: 'upstream timed out user=[REDACTED] client=[REDACTED] token=[REDACTED]',
    }],
  };
}

async function produceConfirmedIncident(
  api: Awaited<ReturnType<typeof playwrightRequest.newContext>>,
  runtime: Runtime,
  serverUuid: string,
): Promise<{ evaluationOperationId: string; downOperationId: string; receipt: AlertReceipt }> {
  const evaluationOperationIds: string[] = [];
  const base = Date.now() - 15_000;
  for (let index = 0; index < 3; index += 1) {
    const response = await api.post('/api/v2/agent-reports', {
      headers: { Accept: 'application/json', Authorization: `Bearer ${runtime.agent_token}` },
      data: reportPayload(serverUuid, new Date(base + index * 2_000).toISOString()),
    });
    expect(response.status()).toBe(202);
    const body = await response.json() as { data: { status: string; evaluation_operation_ids: string[] } };
    expect(body.data.status).toBe('queued');
    expect(body.data.evaluation_operation_ids).toHaveLength(1);
    evaluationOperationIds.push(body.data.evaluation_operation_ids[0]);
  }

  const evaluationOperationId = evaluationOperationIds[2];
  let receipt: AlertReceipt = { status: 'queued', current_state: null, transitions: [], notification_intents: [] };
  await expect.poll(async () => {
    const response = await api.get(`/__harness/alerting/receipts/${evaluationOperationId}`, {
      headers: { Accept: 'application/json' },
    });
    if (response.status() !== 200) return `http-${response.status()}`;
    receipt = await response.json() as AlertReceipt;
    return `${receipt.status}:${receipt.current_state}`;
  }, { timeout: 30_000 }).toBe('processed:down');
  expect(receipt.transitions.map(({ to }) => to)).toEqual(['warn', 'down']);

  return {
    evaluationOperationId,
    downOperationId: uuidV5Url(`${evaluationOperationId}#transition-1`),
    receipt,
  };
}

async function timelineFromBrowser(page: import('@playwright/test').Page, runtime: Runtime, serverUuid: string) {
  return await page.evaluate(async ({ backend, monitor }) => {
    const response = await fetch(`${backend}/checkybot/monitors/server/${monitor}`, {
      credentials: 'include',
      headers: { Accept: 'application/json', 'X-Inertia': 'true' },
    });
    return { status: response.status, body: await response.json() };
  }, { backend: runtime.backend_url, monitor: serverUuid });
}

test('full runtime proves opted-out unavailable and enabled redacted probable-cause UI states', async ({ page, context }) => {
  const runtime = JSON.parse(fs.readFileSync(currentPath, 'utf8')) as Runtime;
  const screenshotPath = path.join(runtime.run_dir, 'ai-annotation-monitor-detail.png');
  const tracePath = path.join(runtime.run_dir, 'ai-annotation-trace.zip');
  const logPath = path.join(runtime.run_dir, 'playwright.log');
  const stages: string[] = [];
  const api = await playwrightRequest.newContext({ baseURL: runtime.backend_url });

  await context.tracing.start({ screenshots: true, snapshots: true, sources: true });
  try {
    await page.goto(`${runtime.frontend_url}?${new URLSearchParams({
      backend: runtime.backend_url,
      project: runtime.project_uuid,
      api_monitor: runtime.enabled_server_uuid,
    })}`);
    await expect(page.getByRole('heading', { name: 'Monitor overview' })).toBeVisible({ timeout: 30_000 });
    stages.push('authenticated through harness web session');

    const optedOut = await produceConfirmedIncident(api, runtime, runtime.opted_out_server_uuid);
    stages.push(`opted-out static incident confirmed ${optedOut.downOperationId}`);
    stages.push(relay().trim());

    await page.getByRole('button', { name: 'Dashboard' }).click();
    await expect(page.getByRole('link', { name: new RegExp(runtime.opted_out_server_uuid) })).toBeVisible();
    await page.getByRole('link', { name: new RegExp(runtime.opted_out_server_uuid) }).click();
    await expect(page.getByRole('heading', { name: runtime.opted_out_server_uuid })).toBeVisible();
    await expect(page.getByLabel('Root Cause unavailable')).toHaveText('Unavailable');
    const optedOutTimeline = await timelineFromBrowser(page, runtime, runtime.opted_out_server_uuid);
    const optedOutSlots = optedOutTimeline.body.props.timeline.annotation_slots as Array<{ key: string; value: string | null }>;
    expect(optedOutTimeline.status).toBe(200);
    expect(optedOutSlots.find(({ key }) => key === 'root_cause')?.value).toBeNull();
    stages.push('opted-out monitor detail rendered explicit root-cause unavailable state');

    const settings = await page.evaluate(async (backend) => {
      const shown = await fetch(`${backend}/checkybot/ai-annotations/settings`, {
        credentials: 'include', headers: { Accept: 'application/json' },
      });
      const before = await shown.json();
      const tokenCookie = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='));
      const csrf = tokenCookie ? decodeURIComponent(tokenCookie.slice('XSRF-TOKEN='.length)) : '';
      const requestBody = { enabled: true, version: before.data.version };
      const updated = await fetch(`${backend}/checkybot/ai-annotations/settings`, {
        method: 'PUT',
        credentials: 'include',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf },
        body: JSON.stringify(requestBody),
      });
      return {
        get_status: shown.status,
        put_status: updated.status,
        request_body: requestBody,
        before: before.data,
        after: await updated.json(),
      };
    }, runtime.backend_url);
    expect(settings.get_status).toBe(200);
    expect(settings.put_status).toBe(200);
    expect(settings.request_body).toEqual({ enabled: true, version: 0 });
    expect(settings.request_body).not.toHaveProperty('project_id');
    expect(settings.after.data.enabled).toBe(true);
    expect(settings.after.data.version).toBe(1);
    expect(settings.after.data.provider_configured).toBe(true);
    expect(settings.after.data).not.toHaveProperty('credential');
    stages.push('project enabled through authenticated settings GET/PUT contract without project selector');

    const enabled = await produceConfirmedIncident(api, runtime, runtime.enabled_server_uuid);
    stages.push(`enabled static incident confirmed ${enabled.downOperationId}`);
    stages.push(relay().trim());

    let annotationReceipt!: AnnotationReceipt;
    await expect.poll(async () => {
      const response = await api.get(`/__harness/ai-annotations/receipts/${enabled.downOperationId}`, {
        headers: { Accept: 'application/json' },
      });
      if (response.status() !== 200) return `http-${response.status()}`;
      annotationReceipt = await response.json() as AnnotationReceipt;
      return annotationReceipt.status;
    }, { timeout: 30_000 }).toBe('completed');
    expect(annotationReceipt.annotation.root_cause).toContain('[REDACTED]');
    expect(annotationReceipt.annotation.root_cause).not.toContain('operator@example.test');
    expect(annotationReceipt.annotation.root_cause).not.toContain('192.0.2.19');
    expect(annotationReceipt.notification_side_effects.after)
      .toEqual(annotationReceipt.notification_side_effects.before);
    stages.push('real queue worker completed AI receipt without notification count changes');

    await page.getByRole('button', { name: 'Dashboard' }).click();
    await expect(page.getByRole('link', { name: new RegExp(runtime.enabled_server_uuid) })).toBeVisible();
    await page.getByRole('link', { name: new RegExp(runtime.enabled_server_uuid) }).click();
    await expect(page.getByRole('heading', { name: runtime.enabled_server_uuid })).toBeVisible();
    await expect(page.getByText(annotationReceipt.annotation.root_cause as string, { exact: true })).toBeVisible();
    await expect(page.locator('section[aria-labelledby="annotations-heading"] dd').filter({ hasText: '[REDACTED]' }))
      .toHaveClass(/whitespace-pre-wrap/);

    const enabledTimeline = await timelineFromBrowser(page, runtime, runtime.enabled_server_uuid);
    const enabledSlots = enabledTimeline.body.props.timeline.annotation_slots as Array<{ key: string; value: string | null }>;
    expect(enabledSlots.filter(({ key }) => key !== 'root_cause'))
      .toEqual(optedOutSlots.filter(({ key }) => key !== 'root_cause'));
    const dom = await page.locator('body').innerText();
    for (const forbidden of [
      'provider.example.test', 'bounded-runtime-model', 'provider-secret-runtime',
      'operator@example.test', '192.0.2.19', 'raw log snippet', 'prompt_tokens',
      'billed_microusd', 'failure_diagnostic',
    ]) expect(dom).not.toContain(forbidden);

    await page.screenshot({ path: screenshotPath, fullPage: true });
    stages.push('shared MonitorDetail UI rendered the redacted probable-cause paragraph');

    const evidence = {
      acceptance_criterion: 'AC-ai-incident-annotations-13',
      runtime_id: runtime.runtime_id,
      authenticated_project: runtime.project_uuid,
      queue_worker: 'real database queue worker',
      settings_contract: settings,
      opted_out: {
        server_uuid: runtime.opted_out_server_uuid,
        evaluation_operation_id: optedOut.evaluationOperationId,
        transition_operation_id: optedOut.downOperationId,
        root_cause: optedOutSlots.find(({ key }) => key === 'root_cause')?.value ?? null,
      },
      completed: {
        server_uuid: runtime.enabled_server_uuid,
        evaluation_operation_id: enabled.evaluationOperationId,
        transition_operation_id: enabled.downOperationId,
        status: annotationReceipt.status,
        root_cause: annotationReceipt.annotation.root_cause,
        notification_side_effects: annotationReceipt.notification_side_effects,
        notification_side_effects_equal: JSON.stringify(annotationReceipt.notification_side_effects.before)
          === JSON.stringify(annotationReceipt.notification_side_effects.after),
      },
      unrelated_annotation_slots_preserved: true,
      stages,
      screenshot: screenshotPath,
      trace: tracePath,
      logs: {
        backend: path.join(runtime.run_dir, 'backend.log'),
        worker: path.join(runtime.run_dir, 'worker.log'),
        frontend: path.join(runtime.run_dir, 'frontend.log'),
        relay: path.join(runtime.run_dir, 'relay.log'),
        playwright: logPath,
      },
    };
    fs.writeFileSync(path.join(runtime.run_dir, 'evidence.json'), `${JSON.stringify(evidence, null, 2)}\n`);
    fs.writeFileSync(logPath, `${stages.map((stage, index) => `[${index + 1}] ${stage}`).join('\n')}\n`);
  } finally {
    await context.tracing.stop({ path: tracePath });
    await api.dispose();
  }
});
