import { request } from 'node:https';
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

function get(host, path, headers = {}) {
  return new Promise((resolve, reject) => {
    const req = request({ hostname: '127.0.0.1', port: process.env.HTTPS_PORT || 8443,
      path, rejectUnauthorized: false, headers: { Host: host, ...headers } }, res => {
      let body = ''; res.on('data', chunk => body += chunk);
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body }));
    });
    req.setTimeout(15000, () => req.destroy(new Error('Smoke request timed out')));
    req.on('error', reject); req.end();
  });
}
const results = [];
for (const [host, path, expected] of [
  ['api.alfarid.example', '/api/v1/store/branding', 200],
  ['api.alfarid.example', '/health/ready', 404],
  ['api.alfarid.example', '/up', 404],
  ['admin.alfarid.example', '/healthz', 404],
  ['pos.alfarid.example', '/healthz', 404],
  ['alfarid.example', '/', 200],
]) {
  const response = await get(host, path);
  assert.equal(response.status, expected, `${host}${path}`);
  results.push({ host, path, status: response.status });
}
const servedBy = new Set();
for (let i = 0; i < 12; i++) {
  const response = await get('api.alfarid.example', '/api/v1/store/branding', { 'X-Forwarded-For': '1.2.3.4' });
  assert.equal(response.status, 200);
  assert.match(response.headers['x-request-id'], /^[A-Za-z0-9._:-]{8,128}$/);
  if (response.headers['x-served-by']) servedBy.add(response.headers['x-served-by']);
}
if (process.env.EXPECT_NODES) assert.equal(servedBy.size, Number(process.env.EXPECT_NODES));
for (const host of ['admin.alfarid.example', 'pos.alfarid.example']) {
  const response = await get(host, '/');
  assert.equal(response.status, 200);
  assert.ok(response.headers['content-security-policy'], `${host}: missing CSP`);
}
const output = { at: new Date().toISOString(), results, servedBy: [...servedBy] };
const directory = new URL('../k6/results/', import.meta.url);
mkdirSync(directory, { recursive: true });
writeFileSync(fileURLToPath(new URL('smoke.json', directory)), JSON.stringify(output, null, 2));
console.log(JSON.stringify(output, null, 2));
