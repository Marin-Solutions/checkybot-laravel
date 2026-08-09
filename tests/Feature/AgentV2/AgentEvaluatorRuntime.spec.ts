import { expect, test } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const root = process.cwd();
const runtimePath = resolve(root, 'build/agent-evaluator-playwright/runtime.json');
const projectId = '11111111-1111-4111-8111-111111111111';
const serverUuid = '22222222-2222-4222-8222-222222222222';
const agentToken = 'cbp_harness_agent_report_token';

type Runtime = { run_id: string; run_dir: string; backend_url: string; database: string };

function artisan(runtime: Runtime, ...args: string[]) {
  return spawnSync('scripts/harness/artisan', [...args, '--no-interaction'], {
    cwd: root,
    env: {
      ...process.env,
      APP_ENV: 'harness',
      APP_DEBUG: 'false',
      APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
      APP_URL: runtime.backend_url,
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: runtime.database,
      QUEUE_CONNECTION: 'database',
      CACHE_STORE: 'array',
      SESSION_DRIVER: 'array',
      HARNESS_RUN_ID: runtime.run_id,
      HARNESS_RUN_DIR: runtime.run_dir,
    },
    encoding: 'utf8',
    timeout: 30_000,
  });
}

test.afterAll(async () => {
  const runtime = JSON.parse(await readFile(runtimePath, 'utf8')) as Runtime;
  const stopped = spawnSync('scripts/runtime/backend', ['stop', '--run-dir', runtime.run_dir], {
    cwd: root,
    encoding: 'utf8',
    timeout: 20_000,
  });
  if (stopped.status !== 0) console.error(stopped.stderr || stopped.stdout);
});

test('authenticated reports traverse agent evaluation, alerting confirmation, outbox relay, and receipt HTTP seams', async ({ request }) => {
  const runtime = JSON.parse(await readFile(runtimePath, 'utf8')) as Runtime;
  const evaluationOperationIds: string[] = [];
  const reportReceipts: unknown[] = [];
  const baseTime = Date.now() - 4_000;

  for (let index = 0; index < 3; index += 1) {
    const response = await request.post('/api/v2/agent-reports', {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${agentToken}`,
      },
      data: {
        schema_version: 'agent-report.v2',
        operation_id: randomUUID(),
        agent_version: '2.5.0',
        server_uuid: serverUuid,
        observed_at: new Date(baseTime + index * 2_000).toISOString(),
        reporting_interval_seconds: 60,
        cpu: { five_min_percent: 20 },
        memory: { used_percent: 30 },
        disks: [{ mount: '/', used_percent: 40, predicted_days_to_full: 60 }],
        network_interfaces: [{
          name: 'eth0',
          rx_bytes_total: 8_000_000_000 + index * 10_000,
          tx_bytes_total: 8_000_000_000 + index * 10_000,
          rx_delta_bytes: 7_125_000_000,
          tx_delta_bytes: 1_000,
          elapsed_seconds: 60,
          sample_status: 'ready',
        }],
        php_fpm_pools: [{ pool: 'www', active_workers: 2, max_children: 10, max_children_reached_5m: 0 }],
        nginx_window: { window_seconds: 300, total_requests: 100, five_xx_count: 0, upstream_timeout_count: 0 },
        prerequisites: [
          { kind: 'php_fpm_status', path_hint: '[REDACTED]', status: 'readable' },
          { kind: 'nginx_access_log', path_hint: '[REDACTED]', status: 'readable' },
        ],
      },
    });
    expect(response.status()).toBe(202);
    const body = await response.json() as {
      data: { status: string; evaluation_operation_ids: string[] };
    };
    expect(body.data.status).toBe('queued');
    expect(body.data.evaluation_operation_ids).toHaveLength(1);
    evaluationOperationIds.push(body.data.evaluation_operation_ids[0]);
    reportReceipts.push(body);
  }

  const evaluator = artisan(runtime, 'checkybot:agent-evaluate-due');
  expect(evaluator.status, `${evaluator.stdout}\n${evaluator.stderr}`).toBe(0);

  let confirmedReceipt: Record<string, unknown> = {};
  await expect.poll(async () => {
    const response = await request.get(`/__harness/alerting/receipts/${evaluationOperationIds[2]}`, {
      headers: { Accept: 'application/json' },
    });
    if (response.status() !== 200) return `http-${response.status()}`;
    confirmedReceipt = await response.json() as Record<string, unknown>;
    return `${confirmedReceipt.status}:${confirmedReceipt.current_state}`;
  }).toBe('processed:down');

  const relay = artisan(runtime, 'checkybot:foundation-relay');
  expect(relay.status, `${relay.stdout}\n${relay.stderr}`).toBe(0);

  await expect.poll(async () => {
    const response = await request.get(`/__harness/alerting/receipts/${evaluationOperationIds[2]}`, {
      headers: { Accept: 'application/json' },
    });
    confirmedReceipt = await response.json() as Record<string, unknown>;
    const receipts = confirmedReceipt.consumer_receipts as Array<{ effect: string }>;
    return receipts.some(({ effect }) => effect === 'transition_persisted');
  }).toBe(true);

  expect(confirmedReceipt).toMatchObject({
    status: 'processed',
    current_state: 'down',
  });
  expect((confirmedReceipt.transitions as Array<{ to: string }>).map(({ to }) => to)).toEqual(['warn', 'down']);

  await writeFile(resolve(root, 'build/agent-evaluator-playwright/evidence.json'), `${JSON.stringify({
    acceptance_criterion: 'AC-agent-v2-expanded-monitors-9',
    project_id: projectId,
    server_uuid: serverUuid,
    report_receipts: reportReceipts,
    evaluation_operation_ids: evaluationOperationIds,
    evaluator_command: { exit: evaluator.status, stdout: evaluator.stdout.trim() },
    relay_command: { exit: relay.status, stdout: relay.stdout.trim() },
    confirmed_receipt: confirmedReceipt,
    real_queue_worker_pid: JSON.parse(await readFile(resolve(runtime.run_dir, 'runtime.json'), 'utf8')).worker_pid,
  }, null, 2)}\n`);
});
