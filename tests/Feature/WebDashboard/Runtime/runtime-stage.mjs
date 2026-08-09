import { execFileSync, spawn } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '../../../..');
const runsRoot = path.join(root, 'build/web-dashboard-api-runtime');
const currentPath = path.join(runsRoot, 'current.json');
const action = process.argv[2];

function current() {
  if (!fs.existsSync(currentPath)) throw new Error('No prepared web-dashboard runtime exists.');
  return JSON.parse(fs.readFileSync(currentPath, 'utf8'));
}

async function freePort() {
  return await new Promise((resolve, reject) => {
    const server = net.createServer();
    server.on('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      const port = typeof address === 'object' && address ? address.port : 0;
      server.close(() => resolve(port));
    });
  });
}

function ownsProcess(pid, runtimeId) {
  if (!Number.isInteger(pid) || pid <= 1) return false;
  try {
    const environment = fs.readFileSync(`/proc/${pid}/environ`, 'utf8').split('\0');
    return environment.includes(`CHECKYBOT_WEB_RUNTIME_ID=${runtimeId}`);
  } catch {
    return false;
  }
}

async function stop() {
  if (!fs.existsSync(currentPath)) return;
  const state = current();
  for (const role of ['metro', 'worker', 'backend', 'upstream']) {
    const pid = state.pids?.[role];
    if (!ownsProcess(pid, state.runtime_id)) continue;
    try { process.kill(-pid, 'SIGTERM'); } catch { try { process.kill(pid, 'SIGTERM'); } catch {} }
  }
  await new Promise((resolve) => setTimeout(resolve, 500));
  for (const role of ['metro', 'worker', 'backend', 'upstream']) {
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
    CHECKYBOT_WEB_RUNTIME_ID: state.runtime_id,
    CHECKYBOT_WEB_FRONTEND_ORIGIN: state.frontend_url,
    CHECKYBOT_WEB_UPSTREAM_PORT: String(state.upstream_port),
    CHECKYBOT_WEB_UPSTREAM_EVIDENCE: path.join(state.run_dir, 'upstream.ndjson'),
    CHECKYBOT_WEB_SAMPLE_URL: state.sample_url,
    CHECKYBOT_WEB_AUTH_URL: state.auth_url,
    EXPO_NO_TELEMETRY: '1',
  };
}

function runPhp(state, args, options = {}) {
  return execFileSync('php', args, {
    cwd: root,
    env: environment(state),
    encoding: 'utf8',
    stdio: options.capture ? 'pipe' : 'inherit',
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

async function waitFor(url, timeoutMs = 30000) {
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
  fs.mkdirSync(path.join(runDir, 'bootstrap/cache'), { recursive: true });
  for (const directory of ['cache/data', 'sessions', 'views']) {
    fs.mkdirSync(path.join(runDir, 'storage/framework', directory), { recursive: true });
  }
  fs.mkdirSync(path.join(runDir, 'storage/logs'), { recursive: true });
  const [backendPort, frontendPort, upstreamPort] = await Promise.all([freePort(), freePort(), freePort()]);
  const state = {
    runtime_id: runtimeId,
    run_dir: runDir,
    database: path.join(runDir, 'database.sqlite'),
    backend_port: backendPort,
    frontend_port: frontendPort,
    upstream_port: upstreamPort,
    backend_url: `http://127.0.0.1:${backendPort}`,
    frontend_url: `http://127.0.0.1:${frontendPort}`,
    sample_url: `http://127.0.0.1:${upstreamPort}/sample`,
    auth_url: `http://127.0.0.1:${upstreamPort}/auth`,
    project_uuid: crypto.randomUUID(),
    server_monitor_uuid: crypto.randomUUID(),
    api_monitor_uuid: crypto.randomUUID(),
    pids: {},
  };
  fs.writeFileSync(path.join(runDir, 'run.id'), `${runtimeId}\n`);
  fs.writeFileSync(currentPath, JSON.stringify(state, null, 2));
  process.stdout.write(`[stage=prepare] runtime_id=${runtimeId} database=${state.database}\n`);
} else if (action === 'start') {
  const state = current();
  fs.closeSync(fs.openSync(state.database, 'a'));
  runPhp(state, [path.join(root, 'scripts/harness/initialize.php'), state.database]);
  process.stdout.write('[stage=database-config] printing effective database configuration before migration\n');
  runPhp(state, [path.join(root, 'scripts/harness/artisan'), 'config:show', 'database']);
  if (!path.resolve(state.database).startsWith(`${path.resolve(root)}${path.sep}`)) throw new Error('Runtime SQLite database is outside the workspace.');
  runPhp(state, [path.join(root, 'scripts/harness/artisan'), 'migrate', '--force', '--no-interaction']);
  runPhp(state, [path.join(root, 'tests/Feature/WebDashboard/Runtime/seed.php'), 'initial', state.project_uuid, state.api_monitor_uuid, state.sample_url]);

  state.pids.upstream = launch(state, 'upstream', 'node', [path.join(root, 'tests/Feature/WebDashboard/Runtime/upstream.mjs')]);
  state.pids.backend = launch(state, 'backend', 'php', ['-S', `127.0.0.1:${state.backend_port}`, '-t', path.join(root, 'tests/Feature/WebDashboard/Runtime'), path.join(root, 'tests/Feature/WebDashboard/Runtime/router.php')]);
  state.pids.worker = launch(state, 'worker', path.join(root, 'scripts/runtime/queue-worker'), []);
  state.pids.metro = launch(state, 'metro', path.join(root, 'node_modules/.bin/expo'), ['start', path.join(root, 'tests/Feature/WebDashboard/RuntimeApp'), '--web', '--port', String(state.frontend_port)]);
  fs.writeFileSync(currentPath, JSON.stringify(state, null, 2));
  await Promise.all([
    waitFor(`${state.backend_url}/__harness/ready`),
    waitFor(state.sample_url),
    waitFor(state.frontend_url, 60000),
  ]);
  process.stdout.write(`[stage=start] backend=${state.backend_url} frontend=${state.frontend_url} worker_pid=${state.pids.worker}\n`);
} else if (action === 'relay-maintenance') {
  const state = current();
  process.stdout.write('[stage=relay] executing registered foundation relay\n');
  runPhp(state, [path.join(root, 'scripts/harness/artisan'), 'checkybot:foundation-relay']);
  runPhp(state, [path.join(root, 'tests/Feature/WebDashboard/Runtime/seed.php'), 'maintenance', state.project_uuid]);
  process.stdout.write('[stage=maintenance] active project window seeded after down confirmation\n');
} else if (action === 'stop') {
  await stop();
} else {
  throw new Error('Usage: runtime-stage.mjs prepare|start|relay-maintenance|stop');
}
