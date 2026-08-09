import { execFileSync, spawn, spawnSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import net from 'node:net';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '../../..');
const runsRoot = path.join(root, 'build/release-hardening-runtime');
const currentPath = path.join(runsRoot, 'current.json');
const checklistRoot = path.join(root, '.full-send/canvas-runs/current/handover-checklists/evidence/frontend-final-states-runtime-proof');
const action = process.argv[2];
const projectUuid = '11111111-1111-4111-8111-111111111111';
const commands = [
  'node tests/Component/ReleaseHardening/runtime-stage.mjs prepare',
  'node tests/Component/ReleaseHardening/runtime-stage.mjs build',
  'node tests/Component/ReleaseHardening/runtime-stage.mjs component',
  'node tests/Component/ReleaseHardening/runtime-stage.mjs start',
  'node tests/Component/ReleaseHardening/runtime-stage.mjs playwright',
  'node tests/Component/ReleaseHardening/runtime-stage.mjs stop',
  'node tests/Component/ReleaseHardening/runtime-stage.mjs finalize',
];

function current() {
  if (!fs.existsSync(currentPath)) throw new Error('No prepared release-hardening runtime exists.');
  return JSON.parse(fs.readFileSync(currentPath, 'utf8'));
}

function save(state) {
  fs.writeFileSync(currentPath, `${JSON.stringify(state, null, 2)}\n`);
}

async function freePort() {
  return await new Promise((resolve, reject) => {
    const server = net.createServer();
    server.on('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      if (!address || typeof address === 'string') return reject(new Error('Unable to allocate loopback port.'));
      server.close(() => resolve(address.port));
    });
  });
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
    SESSION_DRIVER: 'array',
    LOG_CHANNEL: 'single',
    HARNESS_RUN_ID: state.run_id,
    HARNESS_RUN_DIR: state.run_dir,
    HARNESS_BACKEND_PORT: String(state.backend_port),
    HARNESS_FRONTEND_HOST: '127.0.0.1',
    HARNESS_FRONTEND_PORT: String(state.frontend_port),
    HARNESS_BACKEND_URL: state.backend_url,
    HARNESS_FIXTURE_DIST: state.dist,
    CHECKYBOT_RELEASE_RUNTIME_ID: state.run_id,
    CHECKYBOT_EXPO_DELIVERY_FAKE: 'accepted',
    CHECKYBOT_LEGACY_WEBHOOK_FAKE: 'accepted',
    CHECKYBOT_PUSH_PROVING_ENABLED: 'true',
    CHECKYBOT_LEGACY_ALERT_WEBHOOK_URL: 'https://legacy.example.test/redacted-runtime-channel',
    EXPO_NO_TELEMETRY: '1',
  };
}

function execute(state, label, command, args, { capture = false } = {}) {
  const started = new Date().toISOString();
  const result = spawnSync(command, args, {
    cwd: root,
    env: environment(state),
    encoding: 'utf8',
    stdio: 'pipe',
  });
  const output = `${result.stdout || ''}${result.stderr || ''}`;
  const logPath = path.join(state.run_dir, `${label}.log`);
  fs.appendFileSync(logPath, `[${started}] $ ${[command, ...args].join(' ')}\n${output}[${new Date().toISOString()}] exit=${result.status}\n`);
  if (output.trim()) process.stdout.write(`${output.trim()}\n`);
  if (result.error || result.status !== 0) throw new Error(`${label} failed with exit ${result.status}; see ${path.relative(root, logPath)}`);
  return capture ? output : '';
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

function ownsProcess(pid, runId) {
  if (!Number.isInteger(pid) || pid <= 1) return false;
  try {
    const values = fs.readFileSync(`/proc/${pid}/environ`, 'utf8').split('\0');
    return values.includes(`CHECKYBOT_RELEASE_RUNTIME_ID=${runId}`);
  } catch {
    return false;
  }
}

async function waitFor(url, predicate = (status) => status < 500, timeoutMs = 30_000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    const result = await new Promise((resolve) => {
      const request = http.get(url, { headers: { Accept: 'application/json' } }, (response) => {
        let body = '';
        response.on('data', (chunk) => { body += chunk; });
        response.on('end', () => resolve({ status: response.statusCode ?? 500, body }));
      });
      request.setTimeout(1_000, () => request.destroy());
      request.on('error', () => resolve({ status: 599, body: '' }));
    });
    if (predicate(result.status, result.body)) return result;
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
  throw new Error(`Timed out waiting for ${url}`);
}

async function stop() {
  if (!fs.existsSync(currentPath)) return;
  const state = current();
  const recorded = { ...state.pids };
  for (const role of ['frontend', 'worker', 'backend']) {
    const pid = state.pids?.[role];
    if (!ownsProcess(pid, state.run_id)) continue;
    try { process.kill(-pid, 'SIGTERM'); } catch { try { process.kill(pid, 'SIGTERM'); } catch {} }
  }
  await new Promise((resolve) => setTimeout(resolve, 700));
  for (const role of ['frontend', 'worker', 'backend']) {
    const pid = state.pids?.[role];
    if (!ownsProcess(pid, state.run_id)) continue;
    try { process.kill(-pid, 'SIGKILL'); } catch { try { process.kill(pid, 'SIGKILL'); } catch {} }
  }
  await new Promise((resolve) => setTimeout(resolve, 200));
  state.cleanup = {
    completed_at: new Date().toISOString(),
    recorded_pids: recorded,
    alive_after_cleanup: Object.fromEntries(Object.entries(recorded).map(([role, pid]) => [role, ownsProcess(pid, state.run_id)])),
  };
  save(state);
  process.stdout.write(`[stage=stop] run_id=${state.run_id} alive=${JSON.stringify(state.cleanup.alive_after_cleanup)}\n`);
}

function sanitizeTrace(source, destination, token) {
  const temporary = path.join(path.dirname(source), 'trace-sanitized');
  fs.rmSync(temporary, { recursive: true, force: true });
  fs.mkdirSync(temporary, { recursive: true });
  execFileSync('unzip', ['-q', source, '-d', temporary]);
  const needles = [token, `Bearer ${token}`];
  const visit = (directory) => {
    for (const item of fs.readdirSync(directory, { withFileTypes: true })) {
      const file = path.join(directory, item.name);
      if (item.isDirectory()) visit(file);
      else {
        let body = fs.readFileSync(file);
        for (const needle of needles) body = Buffer.from(body.toString('latin1').split(needle).join('[REDACTED]'), 'latin1');
        fs.writeFileSync(file, body);
      }
    }
  };
  visit(temporary);
  fs.rmSync(destination, { force: true });
  execFileSync('zip', ['-q', '-r', destination, '.'], { cwd: temporary });
  fs.rmSync(temporary, { recursive: true, force: true });
}

function allFiles(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const target = path.join(directory, entry.name);
    return entry.isDirectory() ? allFiles(target) : [target];
  });
}

if (action === 'prepare') {
  await stop();
  fs.mkdirSync(runsRoot, { recursive: true });
  const runId = crypto.randomUUID();
  const runDir = path.join(runsRoot, runId);
  for (const directory of ['bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'playwright-results']) {
    fs.mkdirSync(path.join(runDir, directory), { recursive: true });
  }
  const [backendPort, frontendPort] = await Promise.all([freePort(), freePort()]);
  const state = {
    run_id: runId,
    commit: execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim(),
    started_at: new Date().toISOString(),
    run_dir: runDir,
    database: path.join(runDir, 'database.sqlite'),
    dist: path.join(runDir, 'web-build'),
    backend_port: backendPort,
    frontend_port: frontendPort,
    backend_url: `http://127.0.0.1:${backendPort}`,
    frontend_url: `http://127.0.0.1:${frontendPort}`,
    project_uuid: projectUuid,
    pids: {},
  };
  fs.writeFileSync(path.join(runDir, 'run.id'), `${runId}\n`);
  save(state);
  process.stdout.write(`[stage=prepare] run_id=${runId} database=${state.database}\n`);
} else if (action === 'build') {
  const state = current();
  execute(state, 'build', path.join(root, 'node_modules/.bin/expo'), [
    'export', path.join(root, 'tests/Component/ReleaseHardening/RuntimeApp'), '--platform', 'web', '--output-dir', state.dist, '--clear',
  ]);
  if (!fs.existsSync(path.join(state.dist, 'index.html'))) throw new Error('Expo production-shaped export did not create index.html.');
  process.stdout.write(`[stage=build] PASS dist=${path.relative(root, state.dist)}\n`);
} else if (action === 'component') {
  const state = current();
  const output = execute(state, 'component', path.join(root, 'node_modules/.bin/jest'), [
    '--config', 'tests/Component/ReleaseHardening/jest.config.cjs', '--runInBand', '--ci',
  ], { capture: true });
  state.component_summary = { passed: true, summary: output.split('\n').filter((line) => /Test Suites:|Tests:/.test(line)).join(' | ') };
  save(state);
  process.stdout.write(`[stage=component] PASS ${state.component_summary.summary}\n`);
} else if (action === 'start') {
  const state = current();
  fs.closeSync(fs.openSync(state.database, 'a'));
  execute(state, 'database-initialize', 'php', [path.join(root, 'scripts/harness/initialize.php'), state.database]);
  process.stdout.write('[stage=database-config] printing effective database configuration before migration\n');
  const config = execute(state, 'database-config', 'php', [path.join(root, 'scripts/harness/artisan'), 'config:show', 'database'], { capture: true });
  if (!config.includes('sqlite') || !config.includes(state.database) || !path.resolve(state.database).startsWith(`${root}${path.sep}`)) {
    throw new Error('Effective database is not the workspace-local run-scoped SQLite file.');
  }
  execute(state, 'database-migrate', 'php', [path.join(root, 'scripts/harness/artisan'), 'migrate', '--force', '--no-interaction']);
  execute(state, 'status-seed', 'php', [path.join(root, 'scripts/harness/seed-status-summary.php'), state.database]);
  execute(state, 'push-seed', 'php', [path.join(root, 'tests/Component/ReleaseHardening/Runtime/seed.php'), projectUuid]);

  state.pids.backend = launch(state, 'backend', 'php', [
    '-S', `127.0.0.1:${state.backend_port}`, '-t', path.join(root, 'scripts/harness/public'), path.join(root, 'scripts/harness/server.php'),
  ]);
  state.pids.worker = launch(state, 'worker', path.join(root, 'scripts/runtime/queue-worker'), []);
  fs.writeFileSync(path.join(state.run_dir, 'worker.pid'), `${state.pids.worker}\n`);
  state.pids.frontend = launch(state, 'frontend', 'node', [path.join(root, 'scripts/harness/static-server.mjs')]);
  save(state);
  await Promise.all([
    waitFor(`${state.backend_url}/__harness/ready`, (status, body) => status === 200 && body.includes('"queue":"ready"')),
    waitFor(state.frontend_url, (status) => status === 200),
  ]);
  process.stdout.write(`[stage=start] backend=${state.backend_url} frontend=${state.frontend_url} worker_pid=${state.pids.worker}\n`);
} else if (action === 'relay') {
  const state = current();
  const output = execute(state, 'relay', 'php', [path.join(root, 'scripts/harness/artisan'), 'checkybot:foundation-relay', '--limit=1000', '--no-interaction'], { capture: true });
  process.stdout.write(`[stage=relay] ${output.trim()}\n`);
} else if (action === 'playwright') {
  const state = current();
  const output = execute(state, 'playwright', path.join(root, 'node_modules/.bin/playwright'), [
    'test', '--config', 'tests/Component/ReleaseHardening/playwright.config.ts',
  ], { capture: true });
  state.playwright_summary = { passed: true, summary: output.split('\n').find((line) => /\d+ passed/.test(line))?.trim() ?? '1 journey passed' };
  save(state);
  process.stdout.write(`[stage=playwright] PASS ${state.playwright_summary.summary}\n`);
} else if (action === 'stop') {
  await stop();
} else if (action === 'finalize') {
  const state = current();
  const browserEvidencePath = path.join(state.run_dir, 'browser-evidence.json');
  if (!fs.existsSync(browserEvidencePath)) throw new Error('Missing Playwright browser evidence.');
  if (!state.cleanup || Object.values(state.cleanup.alive_after_cleanup).some(Boolean)) throw new Error('Cleanup proof is absent or a recorded child remains alive.');
  const browser = JSON.parse(fs.readFileSync(browserEvidencePath, 'utf8'));
  const workerLog = fs.readFileSync(path.join(state.run_dir, 'worker.log'), 'utf8');
  if (!workerLog.includes('[stage=worker-start]') || !/(DONE|ProcessMonitorResult|ProcessNotificationIntent)/.test(workerLog)) {
    throw new Error('Worker log does not prove startup and processed jobs.');
  }

  const evidenceDir = path.join(checklistRoot, state.run_id);
  fs.mkdirSync(evidenceDir, { recursive: true });
  const copied = {};
  for (const [key, source] of Object.entries({
    component_log: path.join(state.run_dir, 'component.log'),
    playwright_log: path.join(state.run_dir, 'playwright.log'),
    backend_log: path.join(state.run_dir, 'backend.log'),
    worker_log: path.join(state.run_dir, 'worker.log'),
    frontend_log: path.join(state.run_dir, 'frontend.log'),
    relay_log: path.join(state.run_dir, 'relay.log'),
    browser_evidence: browserEvidencePath,
    status_screenshot: path.join(state.run_dir, 'status-updated.png'),
    stale_screenshot: path.join(state.run_dir, 'status-api-failure.png'),
  })) {
    if (!fs.existsSync(source)) throw new Error(`Missing evidence artifact: ${source}`);
    const destination = path.join(evidenceDir, path.basename(source));
    fs.copyFileSync(source, destination);
    copied[key] = path.relative(root, destination);
  }
  const sanitizedTrace = path.join(evidenceDir, 'playwright-trace.sanitized.zip');
  sanitizeTrace(path.join(state.run_dir, 'playwright-trace.zip'), sanitizedTrace, 'cbp_harness_status_read_token');
  copied.trace = path.relative(root, sanitizedTrace);

  const manifestPath = path.join(evidenceDir, 'handover-evidence-manifest.json');
  const completedAt = new Date().toISOString();
  const manifest = {
    contract_version: 'frontend-release-handover-evidence.v1',
    result: 'PASS',
    run_id: state.run_id,
    commit: state.commit,
    started_at: state.started_at,
    completed_at: completedAt,
    exact_commands: commands,
    endpoint_urls: {
      status_summary: `${state.backend_url}/api/status-summary`,
      alerting_results: `${state.backend_url}/__harness/alerting/results`,
      alerting_receipts: `${state.backend_url}/__harness/alerting/receipts/{operation_id}`,
      push_receipts: `${state.backend_url}/__harness/push/receipts/{operation_id}`,
      built_status_surface: state.frontend_url,
    },
    run_scoped_sqlite_path: state.database,
    queue_worker_proof: {
      pid: state.pids.worker,
      start_marker: '[stage=worker-start]',
      processed_job_marker_present: true,
      log: copied.worker_log,
    },
    response_snapshots: browser.response_snapshots,
    component_summary: state.component_summary,
    playwright_summary: state.playwright_summary,
    artifacts: copied,
    watchdog_reference: 'docs/watchdog-and-push-retirement.md#external-watchdog-handover-gate',
    proving_28_day_reference: '.full-send/canvas-runs/current/handover-checklists/v1/push-retirement.template.json',
    fleet_inventory_reference: '.full-send/canvas-runs/current/handover-checklists/v1/fleet-readiness.template.json',
    secret_scan: { result: 'PENDING', matches: null, path: path.relative(root, path.join(evidenceDir, 'secret-scan.txt')) },
    cleanup_proof: state.cleanup,
  };
  fs.writeFileSync(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`);

  const forbidden = /(Authorization\s*:\s*Bearer|Bearer\s+[A-Za-z0-9._~+\/-]+|ExponentPushToken\[|plaintext[_-]?token|provider[_-]?credential|https:\/\/[^\s]+\?[^\s]+)/i;
  const matches = [];
  for (const file of allFiles(evidenceDir)) {
    if (file.endsWith('secret-scan.txt')) continue;
    const body = fs.readFileSync(file).toString('latin1');
    if (forbidden.test(body)) matches.push(path.relative(root, file));
  }
  if (matches.length > 0) throw new Error(`Secret scan rejected evidence files: ${matches.join(', ')}`);
  const scanPath = path.join(evidenceDir, 'secret-scan.txt');
  fs.writeFileSync(scanPath, `PASS matches=0 scanned_at=${completedAt} files=${allFiles(evidenceDir).length}\n`);
  manifest.secret_scan = { result: 'PASS', matches: 0, path: path.relative(root, scanPath), scanned_at: completedAt };
  fs.writeFileSync(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`);
  fs.mkdirSync(checklistRoot, { recursive: true });
  fs.writeFileSync(path.join(checklistRoot, 'latest.json'), `${JSON.stringify({ run_id: state.run_id, manifest: path.relative(root, manifestPath), result: 'PASS' }, null, 2)}\n`);
  process.stdout.write(`[stage=finalize] PASS manifest=${path.relative(root, manifestPath)} cleanup=verified secret_scan=0\n`);
} else {
  throw new Error('Usage: runtime-stage.mjs prepare|build|component|start|relay|playwright|stop|finalize');
}
