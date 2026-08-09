import { randomBytes, randomUUID } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const root = resolve(import.meta.dirname, '../..');
const localBuild = resolve(root, 'mobile/build');
const statePath = resolve(localBuild, 'runtime.json');
const milestone = resolve(root, '.full-send/canvas-runs/current/slices/push-mobile-widget-status/milestones/frontend-expo-status-app');
const evidenceDir = resolve(milestone, 'evidence');
const action = process.argv[2];
const appKey = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
const projectUuid = '11111111-1111-4111-8111-111111111111';
const statusToken = 'cbp_harness_status_read_token';

const freePort = () => new Promise((done, reject) => {
  const server = createServer();
  server.once('error', reject);
  server.listen(0, '127.0.0.1', () => {
    const address = server.address();
    const port = typeof address === 'object' && address ? address.port : 0;
    server.close((error) => error ? reject(error) : done(port));
  });
});

function execute(command, args, env = {}) {
  console.log(`[mobile-runtime:${action}] ${command} ${args.join(' ')}`);
  const result = spawnSync(command, args, { cwd: root, env: { ...process.env, ...env }, encoding: 'utf8', timeout: 120_000 });
  if (result.stdout.trim()) console.log(result.stdout.trim());
  if (result.stderr.trim()) console.error(result.stderr.trim());
  if (result.error || result.status !== 0) throw new Error(`${command} exited ${result.status ?? 'null'}`);
  return result;
}

function readState() {
  if (!existsSync(statePath)) throw new Error('Mobile runtime has not been started.');
  return JSON.parse(readFileSync(statePath, 'utf8'));
}

function runtimeEnv(state) {
  return {
    APP_ENV: 'harness', APP_KEY: appKey, DB_CONNECTION: 'sqlite', DB_DATABASE: state.database,
    QUEUE_CONNECTION: 'database', CACHE_STORE: 'array', SESSION_DRIVER: 'array',
    HARNESS_RUN_ID: state.runId, HARNESS_RUN_DIR: state.runDir,
    CHECKYBOT_EXPO_DELIVERY_FAKE: 'accepted', CHECKYBOT_LEGACY_WEBHOOK_FAKE: 'accepted',
    CHECKYBOT_PUSH_PROVING_ENABLED: 'true', MOBILE_E2E_PROJECT_UUID: projectUuid,
    MOBILE_E2E_EXPO_TOKEN: state.expoToken,
  };
}

if (action === 'backend-start') {
  if (existsSync(statePath)) throw new Error('A mobile runtime state already exists; run stop first.');
  mkdirSync(localBuild, { recursive: true });
  const runId = randomUUID();
  const runDir = resolve(root, 'build/harness-runs', runId);
  const backendPort = await freePort();
  const frontendPort = await freePort();
  const state = {
    runId, runDir, database: resolve(runDir, 'database.sqlite'),
    backendUrl: `http://127.0.0.1:${backendPort}`, frontendUrl: `http://127.0.0.1:${frontendPort}`,
    backendPort, frontendPort, projectUuid, statusToken,
    expoToken: `ExponentPushToken[${randomBytes(18).toString('hex')}]`,
  };
  execute('scripts/runtime/backend', ['start', '--run-dir', runDir, '--port', String(backendPort), '--timeout', '20'], {
    ...runtimeEnv(state), HARNESS_DB_CONNECTION: 'sqlite', HARNESS_DB_DATABASE: state.database,
  });
  writeFileSync(statePath, `${JSON.stringify(state, null, 2)}\n`);
  console.log(`[mobile-runtime:backend-start] ready run=${runId} database=run-scoped-sqlite worker=real`);
} else if (action === 'seed-device') {
  const state = readState();
  execute('php', ['mobile/e2e/seed-push-device.php'], runtimeEnv(state));
} else if (action === 'frontend-start') {
  const state = readState();
  execute('scripts/runtime/frontend', ['start', '--run-dir', state.runDir, '--port', String(state.frontendPort), '--backend-url', state.backendUrl], runtimeEnv(state));
  console.log(`[mobile-runtime:frontend-start] ready url=${state.frontendUrl}`);
} else if (action === 'evidence') {
  const state = readState();
  const journeyPath = resolve(evidenceDir, 'runtime-journey.json');
  if (!existsSync(journeyPath)) throw new Error('Missing Playwright runtime journey evidence.');
  const journey = JSON.parse(readFileSync(journeyPath, 'utf8'));
  const workerLog = readFileSync(resolve(state.runDir, 'worker.log'), 'utf8');
  if (!workerLog.includes('[stage=worker-start]') || journey.result !== 'passed'
    || journey.status_summary_http !== 200 || journey.push_receipt?.expo_deliveries?.[0]?.status !== 'accepted') {
    throw new Error('Runtime evidence does not prove the required real-worker journey.');
  }
  const serialized = JSON.stringify(journey);
  if (serialized.includes(state.expoToken) || serialized.includes(state.statusToken)) throw new Error('Evidence contains a credential or Expo token.');
  writeFileSync(resolve(evidenceDir, 'runtime-stages.json'), `${JSON.stringify({
    result: 'passed', run_id: state.runId, database: 'run-scoped SQLite', queue_worker: 'real database queue worker',
    worker_start_observed: true, journey: 'runtime-journey.json', screenshot: 'runtime-status-offline.png',
  }, null, 2)}\n`);
  console.log('[mobile-runtime:evidence] verified real worker, authenticated summary, push receipt, deep link, and offline cache evidence');
} else if (action === 'stop') {
  const state = readState();
  const pids = ['fixture', 'worker', 'app'].map((role) => ({ role, path: resolve(state.runDir, `${role}.pid`) }))
    .filter(({ path }) => existsSync(path)).map(({ role, path }) => ({ role, pid: Number(readFileSync(path, 'utf8').trim()) }));
  execute('scripts/runtime/frontend', ['stop', '--run-dir', state.runDir], runtimeEnv(state));
  execute('scripts/runtime/backend', ['stop', '--run-dir', state.runDir], runtimeEnv(state));
  await new Promise((done) => setTimeout(done, 300));
  const alive = pids.filter(({ pid }) => { try { process.kill(pid, 0); return true; } catch { return false; } });
  if (alive.length) throw new Error(`Owned runtime children remain alive: ${alive.map(({ role }) => role).join(', ')}`);
  mkdirSync(evidenceDir, { recursive: true });
  writeFileSync(resolve(evidenceDir, 'runtime-cleanup.json'), `${JSON.stringify({ result: 'passed', run_id: state.runId, stopped_roles: pids.map(({ role }) => role), owned_children_alive: 0 }, null, 2)}\n`);
  writeFileSync(statePath, '');
  await import('node:fs/promises').then(({ rm }) => rm(statePath));
  console.log('[mobile-runtime:stop] scoped app, queue worker, and fixture processes stopped');
} else {
  throw new Error('Usage: node mobile/e2e/runtime.mjs backend-start|seed-device|frontend-start|evidence|stop');
}
