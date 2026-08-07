import { randomUUID } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import net from 'node:net';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const root = resolve(import.meta.dirname, '../../..');
const evidenceDirectory = resolve(root, 'build/agent-evaluator-playwright');
await mkdir(evidenceDirectory, { recursive: true });
const runId = randomUUID();
const runDirectory = resolve(root, 'build/harness-runs', runId);
const port = await new Promise((resolvePort, reject) => {
  const server = net.createServer();
  server.once('error', reject);
  server.listen(0, '127.0.0.1', () => {
    const address = server.address();
    if (!address || typeof address === 'string') return reject(new Error('Unable to allocate a loopback port.'));
    const selected = address.port;
    server.close(() => resolvePort(selected));
  });
});

const start = spawnSync('scripts/runtime/backend', [
  'start', '--run-dir', runDirectory, '--port', String(port), '--timeout', '15',
], {
  cwd: root,
  env: {
    ...process.env,
    HARNESS_DB_CONNECTION: 'sqlite',
    HARNESS_DB_DATABASE: resolve(runDirectory, 'database.sqlite'),
  },
  encoding: 'utf8',
  timeout: 45_000,
});
process.stdout.write(start.stdout || '');
process.stderr.write(start.stderr || '');
if (start.status !== 0) process.exit(start.status ?? 1);

const runtime = JSON.parse(await readFile(resolve(runDirectory, 'runtime.json'), 'utf8'));
await writeFile(resolve(evidenceDirectory, 'runtime.json'), `${JSON.stringify(runtime, null, 2)}\n`);
console.log(`[stage=agent-evaluator-runtime-ready] run_id=${runtime.run_id} url=${runtime.backend_url}`);
