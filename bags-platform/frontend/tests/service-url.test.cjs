const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const ts = require('typescript');

const source = ts.transpileModule(fs.readFileSync('packages/api-client/src/service-url.ts', 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS },
}).outputText;
function resolve(configured, hostname) {
  const exports = {};
  vm.runInNewContext(source, { exports, URL, ...(hostname ? { window: { location: { hostname } } } : {}) });
  return exports.resolveServiceUrl(configured);
}

test('LAN browsers reach the API, storefront and backoffice on the serving machine', () => {
  for (const port of [8000, 3000, 5173]) {
    assert.equal(resolve('http://localhost:' + port, '192.168.1.3'), 'http://192.168.1.3:' + port);
  }
  assert.equal(resolve('http://127.0.0.1:8000', '10.0.0.7'), 'http://10.0.0.7:8000');
  assert.equal(resolve('http://[::1]:8000', '172.16.1.2'), 'http://172.16.1.2:8000');
});

test('explicit remote services, URL paths, and same-origin relative URLs are preserved', () => {
  assert.equal(resolve('https://api.example.test/v1', '192.168.1.3'), 'https://api.example.test/v1');
  assert.equal(resolve('/api', '192.168.1.3'), '/api');
  assert.equal(resolve('http://localhost:8000/prefix', '192.168.1.3'), 'http://192.168.1.3:8000/prefix');
});

test('localhost, production hosts, and server rendering keep configured addresses', () => {
  for (const host of ['localhost', '127.0.0.1', 'shop.example.test', '172.32.0.1', '192.168.1.example.test', undefined]) {
    assert.equal(resolve('http://localhost:8000', host), 'http://localhost:8000');
  }
});
