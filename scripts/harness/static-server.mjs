import { createReadStream, existsSync, statSync } from 'node:fs';
import { createServer } from 'node:http';
import { extname, join, normalize, resolve } from 'node:path';

const host = process.env.HARNESS_FRONTEND_HOST || '127.0.0.1';
const port = Number(process.env.HARNESS_FRONTEND_PORT || 8797);
const root = resolve(process.env.HARNESS_FIXTURE_DIST || 'build/harness-fixture');
const backendUrl = new URL(process.env.HARNESS_BACKEND_URL || 'http://127.0.0.1:8787');

if (host !== '127.0.0.1') throw new Error('Harness fixture server must bind to 127.0.0.1');
if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new Error('Invalid fixture port');
if (!existsSync(join(root, 'index.html'))) throw new Error(`Missing Expo web build: ${root}/index.html`);
if (backendUrl.hostname !== '127.0.0.1') throw new Error('Harness backend proxy must target loopback');

const mime = {
  '.css': 'text/css; charset=utf-8',
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
  '.woff2': 'font/woff2',
};

function proxyRequest(request, response) {
  const target = new URL(request.url, backendUrl);
  const proxy = fetch(target, {
    method: request.method,
    headers: {
      accept: request.headers.accept || 'application/json',
      'content-type': request.headers['content-type'] || 'application/json',
      ...(request.headers.authorization ? { authorization: request.headers.authorization } : {}),
    },
    body: request.method === 'GET' || request.method === 'HEAD' ? undefined : request,
    duplex: 'half',
  });

  proxy.then(async (upstream) => {
    response.writeHead(upstream.status, {
      'content-type': upstream.headers.get('content-type') || 'application/json',
      'cache-control': 'no-store',
    });
    response.end(Buffer.from(await upstream.arrayBuffer()));
  }).catch((error) => {
    response.writeHead(502, { 'content-type': 'application/json' });
    response.end(JSON.stringify({ message: `Harness backend unavailable: ${error.message}` }));
  });
}

const server = createServer((request, response) => {
  const requestUrl = new URL(request.url || '/', `http://${host}:${port}`);
  if (requestUrl.pathname.startsWith('/__harness/') || requestUrl.pathname.startsWith('/api/')) {
    proxyRequest(request, response);
    return;
  }

  const relative = normalize(decodeURIComponent(requestUrl.pathname)).replace(/^[/\\]+/, '');
  let file = resolve(root, relative || 'index.html');
  if (!file.startsWith(`${root}/`)) {
    response.writeHead(400);
    response.end('Bad request');
    return;
  }
  if (!existsSync(file) || !statSync(file).isFile()) file = join(root, 'index.html');

  response.writeHead(200, {
    'content-type': mime[extname(file)] || 'application/octet-stream',
    'cache-control': 'no-store',
  });
  createReadStream(file).pipe(response);
});

server.listen(port, host, () => {
  console.log(`[fixture-ready] http://${host}:${port} proxy=${backendUrl.origin}`);
});

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => server.close(() => process.exit(0)));
}
