import { expect, test } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';

const evidencePath = resolve(process.env.ALERTING_GROUPING_EVIDENCE || 'build/incident-grouping-playwright/evidence.json');

type Receipt = {
  operation_id: string;
  status: string;
  current_state: string | null;
  incident_groups: Array<{ group_id: string; closed_at: string | null; notification_thread_key: string }>;
  notification_intents: Array<Record<string, unknown>>;
  consumer_receipts: Array<{ consumer: string; effect: string }>;
};

test('real HTTP queue relay and grouping jobs produce only the grouped incident and final recovery', async ({ request }) => {
  const project = randomUUID();
  const shortMonitor = randomUUID();
  const firstMonitor = randomUUID();
  const secondMonitor = randomUUID();
  const attempts: Array<{ operation_id: string; monitor_uuid: string; signal: string; observed_at: string; status: number }> = [];
  const now = Date.now();

  const postPull = async (monitor: string, signal: 'failure' | 'success', observedAt: Date) => {
    const operationId = randomUUID();
    const response = await request.post('/__harness/alerting/results', {
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      data: {
        operation_id: operationId,
        identity: { project_uuid: project, monitor_uuid: monitor, type: 'website' },
        source: 'pull',
        observed_at: observedAt.toISOString(),
        signal,
        reason_code: signal === 'failure' ? 'runtime-timeout' : null,
        value: null,
        thresholds: null,
      },
    });
    expect(response.status()).toBe(202);
    attempts.push({ operation_id: operationId, monitor_uuid: monitor, signal, observed_at: observedAt.toISOString(), status: response.status() });
    return operationId;
  };

  const receipt = async (operationId: string): Promise<Receipt> => {
    const response = await request.get(`/__harness/alerting/receipts/${operationId}`, {
      headers: { Accept: 'application/json' },
    });
    expect(response.status()).toBe(200);
    return await response.json() as Receipt;
  };

  const waitProcessed = async (operationId: string) => {
    let latest: Receipt | undefined;
    await expect.poll(async () => {
      latest = await receipt(operationId);
      return latest.status;
    }).toBe('processed');
    return latest as Receipt;
  };

  // This confirmed outage recovers twenty seconds later. Its collection deadline is
  // deliberately in the future, proving grouping happens through queued jobs rather
  // than a direct processor invocation or a test-only clock shortcut.
  const shortDownAt = new Date(now + 100_000);
  for (const offset of [-30_000, -20_000, 0]) {
    await waitProcessed(await postPull(shortMonitor, 'failure', new Date(shortDownAt.getTime() + offset)));
  }
  const shortRecoveryOperation = await postPull(shortMonitor, 'success', new Date(shortDownAt.getTime() + 20_000));
  await waitProcessed(shortRecoveryOperation);
  let shortReceipt: Receipt | undefined;
  await expect.poll(async () => {
    shortReceipt = await receipt(shortRecoveryOperation);
    return {
      state: shortReceipt.current_state,
      groups: shortReceipt.incident_groups.length,
      intents: shortReceipt.notification_intents.length,
      closed: shortReceipt.incident_groups[0]?.closed_at !== null,
    };
  }).toEqual({ state: 'healthy', groups: 1, intents: 0, closed: true });

  // Queue all actual pull attempts before the collection deadline. The database queue
  // worker processes the registered result and grouping jobs; no action/processor is called here.
  const confirmedAt = new Date(Date.now() - 27_000);
  const multiOperations: string[] = [];
  for (const offset of [-30_000, -20_000]) {
    multiOperations.push(await postPull(firstMonitor, 'failure', new Date(confirmedAt.getTime() + offset)));
    multiOperations.push(await postPull(secondMonitor, 'failure', new Date(confirmedAt.getTime() + offset)));
  }
  const firstDownOperation = await postPull(firstMonitor, 'failure', confirmedAt);
  const secondDownOperation = await postPull(secondMonitor, 'failure', new Date(confirmedAt.getTime() + 100));
  multiOperations.push(firstDownOperation, secondDownOperation);
  await waitProcessed(firstDownOperation);
  await waitProcessed(secondDownOperation);

  let incidentReceipt: Receipt | undefined;
  await expect.poll(async () => {
    incidentReceipt = await receipt(secondDownOperation);
    return incidentReceipt.notification_intents.filter(({ phase }) => phase === 'incident').length;
  }).toBe(1);
  const incident = incidentReceipt!.notification_intents.find(({ phase }) => phase === 'incident')!;
  expect((incident.affected_monitors as unknown[]).length).toBe(2);
  expect((incident.problem_filter as { monitor_uuids: string[] }).monitor_uuids).toEqual([firstMonitor, secondMonitor]);

  const firstHealthyOperation = await postPull(firstMonitor, 'success', new Date());
  await waitProcessed(firstHealthyOperation);
  await expect.poll(async () => (await receipt(firstHealthyOperation)).notification_intents.filter(({ phase }) => phase === 'recovery').length).toBe(0);

  const finalHealthyOperation = await postPull(secondMonitor, 'success', new Date(Date.now() + 1_000));
  await waitProcessed(finalHealthyOperation);
  let finalReceipt: Receipt | undefined;
  await expect.poll(async () => {
    finalReceipt = await receipt(finalHealthyOperation);
    return finalReceipt.notification_intents.filter(({ phase }) => phase === 'recovery').length;
  }).toBe(1);
  const recovery = finalReceipt!.notification_intents.find(({ phase }) => phase === 'recovery')!;
  expect(recovery.notification_thread_key).toBe(incident.notification_thread_key);
  expect((recovery.affected_monitors as unknown[]).length).toBe(2);
  expect(Number(recovery.downtime_seconds)).toBeGreaterThanOrEqual(0);

  const relayRuns = [];
  for (let round = 0; round < 2; round += 1) {
    const relay = spawnSync('scripts/harness/artisan', ['checkybot:foundation-relay', '--no-interaction'], {
      cwd: process.cwd(),
      env: process.env,
      encoding: 'utf8',
    });
    expect(relay.status, `${relay.stdout}\n${relay.stderr}`).toBe(0);
    relayRuns.push({ exit: relay.status, stdout: relay.stdout.trim(), stderr: relay.stderr.trim() });
    await new Promise((resolvePromise) => setTimeout(resolvePromise, 1_100));
  }

  await expect.poll(async () => {
    finalReceipt = await receipt(finalHealthyOperation);
    return finalReceipt.consumer_receipts.some(({ effect }) => effect === 'transition_persisted');
  }).toBe(true);

  await mkdir(dirname(evidencePath), { recursive: true });
  await writeFile(evidencePath, `${JSON.stringify({
    project_uuid: project,
    attempts,
    sub_30_second_recovery: shortReceipt,
    confirmed_failure: incidentReceipt,
    final_recovery: finalReceipt,
    relay_runs: relayRuns,
    execution: {
      transport: 'real HTTP testing routes',
      queue: 'database worker',
      grouping: 'registered ProcessIncidentTransition and EmitIncidentIntent jobs',
      direct_processor_calls: 0,
    },
  }, null, 2)}\n`);
});
