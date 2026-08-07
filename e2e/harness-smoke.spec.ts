import { expect, test } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';

const evidencePath = resolve(process.env.HARNESS_PROBE_EVIDENCE || 'build/playwright-results/probe-evidence.json');
const screenshotPath = resolve(process.env.HARNESS_SCREENSHOT || 'build/playwright-results/processed.png');
const statusEvidencePath = resolve(process.env.HARNESS_STATUS_EVIDENCE || 'build/playwright-results/status-summary-evidence.json');
const statusScreenshotPath = resolve(process.env.HARNESS_STATUS_SCREENSHOT || 'build/playwright-results/status-summary.png');
const projectId = '11111111-1111-4111-8111-111111111111';
const statusToken = 'cbp_harness_status_read_token';

test('Expo fixture proves readiness and real queue processing', async ({ page }) => {
  const apiResponses: Array<{ method: string; url: string; status: number; body: unknown }> = [];

  page.on('response', async (response) => {
    if (!response.url().includes('/__harness/')) return;
    try {
      apiResponses.push({
        method: response.request().method(),
        url: response.url(),
        status: response.status(),
        body: await response.json(),
      });
    } catch {
      // A failed/non-JSON response is still surfaced by the fixture and test assertions.
    }
  });

  await page.goto('/');
  await expect(page.getByTestId('backend-ready')).toContainText('Backend ready');
  const runId = (await page.getByText(/^Run ID:/).textContent())?.replace('Run ID: ', '') || '';

  const acceptedResponsePromise = page.waitForResponse((response) =>
    response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/__harness/queue-probes',
  );
  await page.getByRole('button', { name: 'Create queue probe' }).click();
  const acceptedResponse = await acceptedResponsePromise;
  expect(acceptedResponse.status()).toBe(202);
  const accepted = await acceptedResponse.json() as {
    status: string;
    probe_id: string;
    accepted_at: string;
  };

  await expect(page.getByTestId('queue-queued')).toContainText('Queue probe queued');
  await expect(page.getByTestId('queue-processed')).toContainText('Queue probe processed');
  await expect(page.getByTestId('queue-processed')).toContainText(accepted.probe_id);
  await page.screenshot({ path: screenshotPath, fullPage: true });

  await expect.poll(() => apiResponses.some(({ method, body }) =>
    method === 'GET'
      && typeof body === 'object'
      && body !== null
      && (body as { probe_id?: string }).probe_id === accepted.probe_id
      && (body as { status?: string }).status === 'processed',
  )).toBe(true);
  const processedResponse = [...apiResponses].reverse().find(({ method, body }) =>
    method === 'GET'
      && typeof body === 'object'
      && body !== null
      && (body as { probe_id?: string }).probe_id === accepted.probe_id
      && (body as { status?: string }).status === 'processed',
  );

  await mkdir(dirname(evidencePath), { recursive: true });
  await writeFile(evidencePath, `${JSON.stringify({
    run_id: runId,
    frontend_url: page.url(),
    probe_id: accepted.probe_id,
    accepted_at: accepted.accepted_at,
    processed_at: (processedResponse?.body as { processed_at: string }).processed_at,
    post_status: acceptedResponse.status(),
    get_status: processedResponse?.status,
    api_responses: apiResponses,
    screenshot: screenshotPath,
  }, null, 2)}\n`);
});

test('generated web client displays the relayed canonical status summary', async ({ page, request }) => {
  const newest = new Date(Date.now() - 30_000);
  const transitions = [
    { type: 'server', state: 'down', severity: 'critical', occurredAt: new Date(newest.getTime() - 20_000) },
    { type: 'website', state: 'recovering', severity: 'warn', occurredAt: new Date(newest.getTime() - 10_000) },
    { type: 'api', state: 'healthy', severity: 'warn', occurredAt: newest },
  ] as const;
  const operationIds: string[] = [];

  for (const transition of transitions) {
    const operationId = randomUUID();
    operationIds.push(operationId);
    const response = await request.post('/__harness/monitor-foundation/events', {
      data: {
        operation_id: operationId,
        event_type: 'monitor.transitioned',
        payload: {
          contract_version: 'monitor-foundation.v1',
          identity: { project_id: projectId, monitor_id: randomUUID(), type: transition.type },
          from_state: 'healthy',
          to_state: transition.state,
          severity: transition.severity,
          filter: { types: [transition.type], states: [transition.state], severities: [transition.severity] },
          occurred_at: transition.occurredAt.toISOString(),
        },
      },
      headers: { Accept: 'application/json' },
    });
    expect(response.status()).toBe(202);
  }

  const { spawnSync } = await import('node:child_process');
  const relay = spawnSync('scripts/harness/artisan', ['checkybot:foundation-relay', '--no-interaction'], {
    cwd: process.cwd(),
    env: process.env,
    encoding: 'utf8',
  });
  expect(relay.status, `${relay.stdout}\n${relay.stderr}`).toBe(0);

  const receiptResponses: unknown[] = [];
  for (const operationId of operationIds) {
    let receipt: Record<string, unknown> | undefined;
    await expect.poll(async () => {
      const response = await request.get(`/__harness/monitor-foundation/receipts/${operationId}`, {
        headers: { Accept: 'application/json' },
      });
      receipt = await response.json() as Record<string, unknown>;
      return receipt.status;
    }).toBe('delivered');
    receiptResponses.push(receipt);
  }

  const summaryResponse = await request.get('/api/status-summary', {
    headers: { Accept: 'application/json', Authorization: `Bearer ${statusToken}` },
  });
  expect(summaryResponse.status()).toBe(200);
  const summaryBody = await summaryResponse.json() as {
    data: {
      counts: Record<'servers' | 'websites' | 'apis', Record<'healthy' | 'warn' | 'down', number>>;
      updated_at: string;
      stale: boolean;
    };
  };
  const webReceipt = (receiptResponses.at(-1) as { receipts: Array<Record<string, unknown>> }).receipts
    .find(({ consumer }) => consumer === 'web');
  expect(webReceipt?.status_summary).toEqual(summaryBody.data);

  await page.goto('/');
  await expect(page.getByTestId('backend-ready')).toContainText('Backend ready');
  for (const type of ['servers', 'websites', 'apis'] as const) {
    for (const state of ['healthy', 'warn', 'down'] as const) {
      await expect(page.getByTestId(`status-${type}-${state}`))
        .toContainText(`${state}: ${summaryBody.data.counts[type][state]}`);
    }
  }
  await expect(page.getByTestId('status-updated-at')).toContainText(summaryBody.data.updated_at);
  await expect(page.getByTestId('status-stale')).toContainText(`Stale: ${String(summaryBody.data.stale)}`);
  await page.screenshot({ path: statusScreenshotPath, fullPage: true });

  await mkdir(dirname(statusEvidencePath), { recursive: true });
  await writeFile(statusEvidencePath, `${JSON.stringify({
    project_id: projectId,
    operation_ids: operationIds,
    relay: { exit: relay.status, stdout: relay.stdout.trim() },
    receipt_responses: receiptResponses,
    status_summary_response: summaryBody,
    displayed: summaryBody.data,
    screenshot: statusScreenshotPath,
  }, null, 2)}\n`);
});
