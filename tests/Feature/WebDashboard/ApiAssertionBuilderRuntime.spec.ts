import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { expect, request as playwrightRequest, test } from '@playwright/test';

const root = path.resolve(__dirname, '../../..');
const currentPath = path.join(root, 'build/web-dashboard-api-runtime/current.json');

test('canonical alerting, Inertia, maintenance, sample, save, and fallback journey', async ({ page }) => {
  const runtime = JSON.parse(fs.readFileSync(currentPath, 'utf8')) as {
    run_dir: string;
    backend_url: string;
    frontend_url: string;
    auth_url: string;
    project_uuid: string;
    server_monitor_uuid: string;
    api_monitor_uuid: string;
  };
  const api = await playwrightRequest.newContext({ baseURL: runtime.backend_url });
  const operationIds: string[] = [];

  for (let index = 0; index < 3; index += 1) {
    const operationId = crypto.randomUUID();
    operationIds.push(operationId);
    const response = await api.post('/__harness/alerting/results', {
      data: {
        operation_id: operationId,
        identity: {
          project_uuid: runtime.project_uuid,
          monitor_uuid: runtime.server_monitor_uuid,
          type: 'server',
        },
        source: 'push',
        observed_at: new Date(Date.now() - (3 - index) * 1000).toISOString(),
        signal: 'critical',
        reason_code: 'runtime-critical',
        value: 95,
        thresholds: { warn: 50, critical: 80, recovery_delta: 5 },
      },
    });
    expect(response.status()).toBe(202);
  }

  await expect.poll(async () => {
    const response = await api.get(`/__harness/alerting/receipts/${operationIds[2]}`);
    const body = await response.json();
    return { status: body.status, current_state: body.current_state };
  }, { timeout: 20_000 }).toEqual({ status: 'processed', current_state: 'down' });

  execFileSync('node', ['tests/Feature/WebDashboard/Runtime/runtime-stage.mjs', 'relay-maintenance'], {
    cwd: root,
    stdio: 'inherit',
  });
  await expect.poll(async () => {
    const body = await (await api.get(`/__harness/alerting/receipts/${operationIds[2]}`)).json();
    return body.consumer_receipts.some((receipt: { effect?: string }) => receipt.effect === 'transition_persisted');
  }, { timeout: 20_000 }).toBe(true);

  await page.goto(`${runtime.frontend_url}?${new URLSearchParams({
    backend: runtime.backend_url,
    project: runtime.project_uuid,
    api_monitor: runtime.api_monitor_uuid,
  })}`);
  await expect(page.getByRole('heading', { name: 'Monitor overview' })).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('maintenance-banner')).toContainText('Project maintenance active');
  await expect(page.getByTestId('maintenance-banner')).toContainText('Notifications silenced until');
  await expect(page.getByRole('link', { name: new RegExp(runtime.server_monitor_uuid) })).toBeVisible();
  await expect(page.getByTestId('problems-present')).toContainText('Down');

  await page.getByRole('link', { name: new RegExp(runtime.server_monitor_uuid) }).click();
  await expect(page.getByRole('heading', { name: runtime.server_monitor_uuid })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Incident timeline' })).toBeVisible();
  await expect(page.locator('main')).toContainText('Down');
  await expect(page.locator('main')).toContainText('Incident groups');

  await page.getByRole('button', { name: 'API assertion builder' }).click();
  await expect(page.getByRole('heading', { name: 'Assertion builder' })).toBeVisible();
  await expect(page.getByTestId('header-mask-0')).toHaveText('[REDACTED]');
  await expect(page.getByTestId('header-action-0')).toContainText('preserve');

  const sampleResponse = page.waitForResponse((response) => response.url().endsWith('/sample') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Fetch live sample' }).click();
  expect((await sampleResponse).status()).toBe(200);
  await expect(page.getByTestId('sample-status')).toHaveText('200');
  await expect(page.getByTestId('sample-latency')).toContainText('ms');
  await expect(page.getByTestId('sample-json')).toContainText('"state": "ready"');

  const statePath = page.getByRole('treeitem', { name: /\$\.data\[0\]\.state/ });
  await statePath.focus();
  await statePath.press('ArrowUp');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Enter');
  await expect(page.getByLabel('Assertion 2 manual JSON path')).toHaveValue('$.data[0].state');

  const saveResponse = page.waitForResponse((response) => response.url().endsWith('/assertions') && response.request().method() === 'PUT');
  await page.getByRole('button', { name: 'Save configuration' }).click();
  const saved = await saveResponse;
  expect(saved.status()).toBe(200);
  const savePayload = saved.request().postDataJSON();
  expect(savePayload.headers).toEqual([{ name: 'Authorization', action: 'preserve' }]);
  expect(savePayload.headers[0]).not.toHaveProperty('value');
  expect(savePayload.assertions[1].path).toBe('$.data[0].state');
  await expect(page.locator('header')).toContainText('configuration version 2');
  await expect(page.getByTestId('header-mask-0')).toHaveText('[REDACTED]');

  await page.getByLabel('Endpoint URL').fill(runtime.auth_url);
  const fallbackResponse = page.waitForResponse((response) => response.url().endsWith('/sample') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Fetch live sample' }).click();
  expect((await fallbackResponse).status()).toBe(502);
  await expect(page.getByTestId('sample-error')).toContainText('upstream_auth');
  await expect(page.getByTestId('sample-error')).toContainText('Manual JSON-path entry remains available');
  await expect(page.getByLabel('Assertion 2 manual JSON path')).toBeEditable();
  await expect(page.getByRole('button', { name: 'Save configuration' })).toBeEnabled();

  const evidence = {
    runtime_id: path.basename(runtime.run_dir),
    queue_worker: 'database queue worker',
    alerting_operations: operationIds,
    project_uuid: runtime.project_uuid,
    down_monitor_uuid: runtime.server_monitor_uuid,
    api_monitor_uuid: runtime.api_monitor_uuid,
    checked: ['overview', 'problem', 'detail', 'maintenance', 'sample', 'masked-preserve-save', 'upstream-auth-fallback'],
  };
  fs.writeFileSync(path.join(runtime.run_dir, 'evidence.json'), JSON.stringify(evidence, null, 2));
  await api.dispose();
}/* AC-web-dashboard-api-builder-16 */);
