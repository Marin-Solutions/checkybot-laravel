import http from 'node:http';
import fs from 'node:fs';

const port = Number(process.env.CHECKYBOT_WEB_UPSTREAM_PORT);
const evidencePath = process.env.CHECKYBOT_WEB_UPSTREAM_EVIDENCE;
const server = http.createServer((request, response) => {
  fs.appendFileSync(evidencePath, `${JSON.stringify({ path: request.url, authorization: request.headers.authorization ? '[PRESENT]' : null })}\n`);
  response.setHeader('Content-Type', 'application/json');
  if (request.url === '/auth') {
    response.statusCode = 401;
    response.end(JSON.stringify({ private: 'upstream body must stay redacted' }));
    return;
  }
  response.statusCode = 200;
  response.end(JSON.stringify({ data: [{ id: 41, state: 'ready' }], meta: { count: 1 } }));
});
server.listen(port, '127.0.0.1', () => process.stdout.write(`[stage=upstream-ready] port=${port}\n`));
