import { execFileSync, spawn } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '../../..');
const runsRoot = path.join(root, 'build/ai-annotation-runtime');
const currentPath = path.join(runsRoot, 'current.json');
const latestEvidencePath = path.join(runsRoot, 'evidence.json');
const action = process.argv[2];

function current() {
  if (!fs.existsSync(currentPath)) throw new Error('No prepared AI annotation runtime exists.');
  return JSON.parse(fs.readFileSync(currentPath, 'utf8'));
}

async function freePort() {
  return await new Promise((resolve, reject) => {
    const server = net.createServer();
    server.on('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      if (!address || typeof address === 'string') return reject(new Error('Unable to allocate a loopback port.'));
      server.close(() => resolve(address.port));
    });
  });
}

function ownsProcess(pid, runtimeId) {
  if (!Number.isInteger(pid) || pid <= 1) return false;
  try {
    const environment = fs.readFileSync(`/proc/${pid}/environ`, 'utf8').split('\0');
    return environment.includes(`CHECKYBOT_AI_RUNTIME_ID=${runtimeId}`);
  } catch {
    return false;
  }
}

async function stop() {
  if (!fs.existsSync(currentPath)) return;
  const state = current();
  for (const role of ['frontend', 'worker', 'backend']) {
    const pid = state.pids?.[role];
    if (!ownsProcess(pid, state.runtime_id)) continue;
    try { process.kill(-pid, 'SIGTERM'); } catch { try { process.kill(pid, 'SIGTERM'); } catch {} }
  }
  await new Promise((resolve) => setTimeout(resolve, 500));
  for (const role of ['frontend', 'worker', 'backend']) {
    const pid = state.pids?.[role];
    if (!ownsProcess(pid, state.runtime_id)) continue;
    try { process.kill(-pid, 'SIGKILL'); } catch { try { process.kill(pid, 'SIGKILL'); } catch {} }
  }
  fs.rmSync(currentPath, { force: true });
  process.stdout.write(`[stage=stop] runtime_id=${state.runtime_id}\n`);
}

function environment(state) {
  return {
    ...process.env,
    CI: '1',
    APP_ENV: 'harness',
    APP_DEBUG: 'false',
    APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    APP_URL: state.backend_url,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: state.database,
    QUEUE_CONNECTION: 'database',
    CACHE_STORE: 'array',
    SESSION_DRIVER: 'file',
    LOG_CHANNEL: 'single',
    HARNESS_RUN_ID: state.runtime_id,
    HARNESS_RUN_DIR: state.run_dir,
    HARNESS_BACKEND_PORT: String(state.backend_port),
    CHECKYBOT_AI_RUNTIME_ID: state.runtime_id,
    CHECKYBOT_WEB_FRONTEND_ORIGIN: state.frontend_url,
    EXPO_NO_TELEMETRY: '1',
    AI_ANNOTATIONS_PROVIDER_URL: 'https://provider.example.test/v1/annotations',
    AI_ANNOTATIONS_PROVIDER_CREDENTIAL: 'provider-secret-runtime',
    AI_ANNOTATIONS_PROVIDER_MODEL: 'bounded-runtime-model',
    AI_ANNOTATIONS_HARNESS_FAKE: 'true',
    AI_ANNOTATIONS_HARNESS_ROOT_CAUSE: 'Repeated upstream timeouts for operator@example.test at 192.0.2.19 saturated the PHP-FPM worker pool, exhausting available workers and causing the confirmed outage.',
    AI_ANNOTATIONS_HARNESS_BILLED_MICROUSD: '12',
    AI_ANNOTATIONS_GLOBAL_MONTHLY_LIMIT_MICROUSD: '1000',
    AI_ANNOTATIONS_PROJECT_MONTHLY_LIMIT_MICROUSD: '1000',
    AI_ANNOTATIONS_MAX_REQUEST_MICROUSD: '100',
    AI_ANNOTATIONS_INPUT_TOKEN_MICROUSD: '1',
    AI_ANNOTATIONS_OUTPUT_TOKEN_MICROUSD: '1',
  };
}

function run(state, command, args, { capture = false } = {}) {
  return execFileSync(command, args, {
    cwd: root,
    env: environment(state),
    encoding: 'utf8',
    stdio: capture ? 'pipe' : 'inherit',
  });
}

function launch(state, role, command, args) {
  const output = fs.openSync(path.join(state.run_dir, `${role}.log`), 'a');
  const child = spawn(command, args, {
    cwd: root,
    env: environment(state),
    detached: true,
    stdio: ['ignore', output, output],
  });
  child.unref();
  return child.pid;
}

async function waitFor(url, timeoutMs = 60_000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    const ready = await new Promise((resolve) => {
      const request = http.get(url, (response) => {
        response.resume();
        resolve((response.statusCode ?? 500) < 500);
      });
      request.setTimeout(1000, () => request.destroy());
      request.on('error', () => resolve(false));
    });
    if (ready) return;
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
  throw new Error(`Timed out waiting for ${url}`);
}

if (action === 'prepare') {
  await stop();
  fs.mkdirSync(runsRoot, { recursive: true });
  const runtimeId = crypto.randomUUID();
  const runDir = path.join(runsRoot, runtimeId);
  for (const directory of [
    'bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions',
    'storage/framework/views', 'storage/logs', 'playwright-results',
  ]) fs.mkdirSync(path.join(runDir, directory), { recursive: true });
  const [backendPort, frontendPort] = await Promise.all([freePort(), freePort()]);
  const state = {
    runtime_id: runtimeId,
    run_dir: runDir,
    database: path.join(runDir, 'database.sqlite'),
    backend_port: backendPort,
    frontend_port: frontendPort,
    backend_url: `http://127.0.0.1:${backendPort}`,
    frontend_url: `http://127.0.0.1:${frontendPort}`,
    project_uuid: crypto.randomUUID(),
    opted_out_server_uuid: crypto.randomUUID(),
    enabled_server_uuid: crypto.randomUUID(),
    pids: {},
  };
  fs.writeFileSync(path.join(runDir, 'run.id'), `${runtimeId}\n`);
  fs.writeFileSync(currentPath, `${JSON.stringify(state, null, 2)}\n`);
  fs.rmSync(latestEvidencePath, { force: true });
  process.stdout.write(`[stage=prepare] runtime_id=${runtimeId} database=${state.database}\n`);
} else if (action === 'start') {
  const state = current();
  fs.closeSync(fs.openSync(state.database, 'a'));
  run(state, 'php', [path.join(root, 'scripts/harness/initialize.php'), state.database]);
  process.stdout.write('[stage=database-config] effective configuration before migration\n');
  const databaseConfig = run(state, 'php', [path.join(root, 'scripts/harness/artisan'), 'config:show', 'database'], { capture: true });
  process.stdout.write(databaseConfig);
  if (!databaseConfig.includes('sqlite') || !databaseConfig.includes(state.database)) {
    throw new Error('Effective database config is not the run-scoped SQLite database.');
  }
  if (!path.resolve(state.database).startsWith(`${path.resolve(root)}${path.sep}`)) {
    throw new Error('Runtime SQLite database is outside the workspace.');
  }
  run(state, 'php', [path.join(root, 'scripts/harness/artisan'), 'migrate', '--force', '--no-interaction']);
  run(state, 'php', [
    path.join(root, 'tests/Component/AiAnnotations/Runtime/seed.php'),
    state.project_uuid, state.opted_out_server_uuid, state.enabled_server_uuid, currentPath,
  ]);
  Object.assign(state, current());

  state.pids.backend = launch(state, 'backend', 'php', [
    '-S', `127.0.0.1:${state.backend_port}`,
    '-t', path.join(root, 'tests/Feature/WebDashboard/Runtime'),
    path.join(root, 'tests/Feature/WebDashboard/Runtime/router.php'),
  ]);
  state.pids.worker = launch(state, 'worker', path.join(root, 'scripts/runtime/queue-worker'), []);
  state.pids.frontend = launch(state, 'frontend', path.join(root, 'node_modules/.bin/expo'), [
    'start', path.join(root, 'tests/Feature/WebDashboard/RuntimeApp'), '--web', '--port', String(state.frontend_port),
  ]);
  fs.writeFileSync(currentPath, `${JSON.stringify(state, null, 2)}\n`);
  await Promise.all([
    waitFor(`${state.backend_url}/__harness/ready`),
    waitFor(state.frontend_url),
  ]);
  process.stdout.write(`[stage=start] backend=${state.backend_url} frontend=${state.frontend_url} worker_pid=${state.pids.worker}\n`);
} else if (action === 'relay') {
  const state = current();
  const startedAt = new Date().toISOString();
  const output = run(state, 'php', [
    path.join(root, 'scripts/harness/artisan'), 'checkybot:foundation-relay', '--no-interaction',
  ], { capture: true });
  fs.appendFileSync(path.join(state.run_dir, 'relay.log'), `[${startedAt}] ${output}`);
  process.stdout.write(`[stage=relay] ${output.trim()}\n`);
} else if (action === 'verify-evidence') {
  const state = current();
  const evidencePath = path.join(state.run_dir, 'evidence.json');
  if (!fs.existsSync(evidencePath)) throw new Error('Playwright did not emit AI annotation evidence.');
  const evidence = JSON.parse(fs.readFileSync(evidencePath, 'utf8'));
  for (const file of [
    evidence.screenshot, evidence.trace,
    path.join(state.run_dir, 'backend.log'), path.join(state.run_dir, 'worker.log'),
    path.join(state.run_dir, 'frontend.log'), path.join(state.run_dir, 'playwright.log'),
  ]) {
    if (!file || !fs.existsSync(file)) throw new Error(`Missing runtime evidence artifact: ${file}`);
  }
  if (evidence.opted_out?.root_cause !== null
    || evidence.completed?.status !== 'completed'
    || evidence.completed?.notification_side_effects_equal !== true
    || typeof evidence.completed?.root_cause !== 'string') {
    throw new Error('Runtime evidence does not prove both annotation states and no AI notification increase.');
  }
  fs.copyFileSync(evidencePath, latestEvidencePath);
  process.stdout.write(`[stage=evidence] PASS evidence=${path.relative(root, latestEvidencePath)} screenshot=${path.relative(root, evidence.screenshot)} trace=${path.relative(root, evidence.trace)}\n`);
} else if (action === 'stop') {
  await stop();
} else {
  throw new Error('Usage: runtime-stage.mjs prepare|start|relay|verify-evidence|stop');
}
