import { randomUUID } from 'node:crypto';
import { appendFileSync, existsSync, readFileSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { relative, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import process from 'node:process';

const root = resolve(import.meta.dirname, '../..');
const runId = randomUUID();
const runDir = resolve(root, 'build/harness-runs', runId);
const startedAt = new Date().toISOString();
const commands = [];
let backendStarted = false;
let frontendStarted = false;
let backendUrl = '';
let frontendUrl = '';

function workspacePath(path) {
  return relative(root, path) || '.';
}

function freePort() {
  return new Promise((resolvePort, reject) => {
    const server = createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      const port = typeof address === 'object' && address ? address.port : 0;
      server.close((error) => error ? reject(error) : resolvePort(port));
    });
  });
}

function execute(label, command, args, { env = {}, log = `${label}.log`, allowFailure = false } = {}) {
  const display = [command, ...args].join(' ');
  const started = new Date().toISOString();
  console.log(`[integration:${label}] ${display}`);
  const result = spawnSync(command, args, {
    cwd: root,
    env: { ...process.env, ...env },
    encoding: 'utf8',
    timeout: 180_000,
  });
  const ended = new Date().toISOString();
  const output = `${result.stdout || ''}${result.stderr || ''}`;
  const logPath = resolve(runDir, log);
  appendFileSync(logPath, `[${started}] $ ${display}\n${output}[${ended}] exit=${result.status ?? 'null'}\n`);
  if (output.trim()) console.log(output.trim());
  commands.push({ label, display, started, ended, exit: result.status });

  if (!allowFailure && (result.error || result.status !== 0)) {
    throw new Error(`${label} failed with exit ${result.status ?? 'null'}; see ${workspacePath(logPath)}`);
  }
  return result;
}

function recordedPid(role) {
  const path = resolve(runDir, `${role}.pid`);
  if (!existsSync(path)) return 0;
  return Number(readFileSync(path, 'utf8').trim());
}

function ownedPidAlive(pid) {
  if (!Number.isInteger(pid) || pid < 2) return false;
  try {
    process.kill(pid, 0);
    const environment = readFileSync(`/proc/${pid}/environ`, 'utf8');
    return environment.includes(`HARNESS_RUN_ID=${runId}\0`)
      && environment.includes(`HARNESS_RUN_DIR=${runDir}\0`);
  } catch {
    return false;
  }
}

function cleanup() {
  if (frontendStarted || existsSync(resolve(runDir, 'fixture.pid'))) {
    execute('frontend-stop', 'scripts/runtime/frontend', ['stop', '--run-dir', runDir], {
      log: 'cleanup.log',
      allowFailure: true,
    });
    frontendStarted = false;
  }
  if (backendStarted || existsSync(resolve(runDir, 'run.id'))) {
    execute('backend-stop', 'scripts/runtime/backend', ['stop', '--run-dir', runDir], {
      log: 'cleanup.log',
      allowFailure: true,
    });
    backendStarted = false;
  }
}

function renderProof(evidence) {
  const proofPath = resolve(runDir, 'integration-proof.md');
  const values = {
    RUN_ID: runId,
    COMMIT: spawnSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).stdout.trim(),
    STARTED_AT: startedAt,
    COMPLETED_AT: new Date().toISOString(),
    RESULT: 'PASS',
    COMMANDS: commands.map(({ display, started, ended, exit }) => `- \`${display}\` — ${started} to ${ended}, exit ${exit}`).join('\n'),
    BACKEND_URL: backendUrl,
    FRONTEND_URL: frontendUrl,
    PROBE_ID: evidence.probe_id,
    ACCEPTED_AT: evidence.accepted_at,
    PROCESSED_AT: evidence.processed_at,
    POST_STATUS: String(evidence.post_status),
    GET_STATUS: String(evidence.get_status),
    RESULT_SUMMARY: 'Laravel server, real database queue worker, locked Expo web build, component states, static fixture server, and headless Playwright queue journey passed. Scoped cleanup confirmed no recorded child remains alive.',
    PROOF_PATH: workspacePath(proofPath),
    RUNTIME_LOG: workspacePath(resolve(runDir, 'runtime.log')),
    APP_LOG: workspacePath(resolve(runDir, 'app.log')),
    WORKER_LOG: workspacePath(resolve(runDir, 'worker.log')),
    FIXTURE_LOG: workspacePath(resolve(runDir, 'fixture.log')),
    COMPONENT_LOG: workspacePath(resolve(runDir, 'component-tests.log')),
    PLAYWRIGHT_LOG: workspacePath(resolve(runDir, 'playwright.log')),
    PROBE_EVIDENCE: workspacePath(resolve(runDir, 'probe-evidence.json')),
    SCREENSHOT: workspacePath(resolve(runDir, 'processed.png')),
    TRACE_PATH: workspacePath(resolve(runDir, 'playwright')),
  };
  let proof = readFileSync(resolve(root, '.full-send/canvas-runs/current/integration-verification-template.md'), 'utf8');
  for (const [key, value] of Object.entries(values)) {
    proof = proof.replaceAll(`{{${key}}}`, value);
  }
  if (/\{\{[A-Z_]+\}\}/.test(proof)) throw new Error('Integration proof template contains unresolved fields');
  writeFileSync(proofPath, proof);
  return proofPath;
}

const terminate = (signal) => {
  console.error(`[integration:signal] ${signal}`);
  cleanup();
  process.exit(1);
};
process.once('SIGINT', () => terminate('SIGINT'));
process.once('SIGTERM', () => terminate('SIGTERM'));

try {
  const backendPort = await freePort();
  const frontendPort = await freePort();
  backendUrl = `http://127.0.0.1:${backendPort}`;
  frontendUrl = `http://127.0.0.1:${frontendPort}`;

  execute('backend-start', 'scripts/runtime/backend', [
    'start', '--run-dir', runDir, '--port', String(backendPort), '--timeout', '15',
  ], {
    log: 'backend-start.log',
    env: {
      HARNESS_DB_CONNECTION: 'sqlite',
      HARNESS_DB_DATABASE: resolve(runDir, 'database.sqlite'),
    },
  });
  backendStarted = true;

  execute('expo-build', 'npm', ['run', 'harness:frontend:build'], { log: 'expo-build.log' });
  execute('component-tests', 'npm', ['run', 'harness:test:component'], { log: 'component-tests.log' });

  execute('frontend-start', 'scripts/runtime/frontend', [
    'start', '--run-dir', runDir, '--port', String(frontendPort), '--backend-url', backendUrl,
  ], { log: 'frontend-start.log' });
  frontendStarted = true;

  execute('playwright', 'npm', ['run', 'harness:test:browser'], {
    log: 'playwright.log',
    env: {
      HARNESS_FRONTEND_URL: frontendUrl,
      HARNESS_PLAYWRIGHT_OUTPUT: resolve(runDir, 'playwright'),
      HARNESS_PROBE_EVIDENCE: resolve(runDir, 'probe-evidence.json'),
      HARNESS_SCREENSHOT: resolve(runDir, 'processed.png'),
    },
  });

  const evidencePath = resolve(runDir, 'probe-evidence.json');
  if (!existsSync(evidencePath)) throw new Error('Playwright did not emit queue probe evidence');
  const evidence = JSON.parse(readFileSync(evidencePath, 'utf8'));
  if (!evidence.processed_at || evidence.post_status !== 202 || evidence.get_status !== 200) {
    throw new Error('Queue probe evidence does not prove accepted and processed contract responses');
  }

  const recordedPids = Object.fromEntries(
    ['fixture', 'worker', 'app'].map((role) => [role, recordedPid(role)]),
  );
  cleanup();
  for (const [role, pid] of Object.entries(recordedPids)) {
    if (ownedPidAlive(pid)) throw new Error(`Cleanup left recorded ${role} process ${pid} alive`);
  }

  const proofPath = renderProof(evidence);
  console.log(`[integration:complete] PASS proof=${workspacePath(proofPath)}`);
} catch (error) {
  cleanup();
  const failurePath = resolve(runDir, 'integration-failure.log');
  writeFileSync(failurePath, `${error instanceof Error ? error.stack : String(error)}\n`);
  console.error(`[integration:failed] ${error instanceof Error ? error.message : error}`);
  console.error(`[integration:failed] logs=${workspacePath(runDir)}`);
  process.exitCode = 1;
}
