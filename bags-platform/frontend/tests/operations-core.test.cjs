const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');
function load(relative, dependencies = {}) {
  const filename = path.resolve(__dirname, '..', relative), compiled = new Module(filename, module);
  compiled.filename = filename; compiled.paths = Module._nodeModulePaths(path.dirname(filename));
  compiled.require = name => name in dependencies ? dependencies[name] : Module.prototype.require.call(compiled, name);
  compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText, filename);
  return compiled.exports;
}
const base = 'packages/api-client/src/endpoints/operations/';
const http = load(base + 'http.ts', { '../../axios-instance': { defaultApiClient: {} } });
test('raw Laravel pagination retains the page and total', () => {
  const page = http.asPage({ data: { data: [{ id: 21 }], current_page: 2, last_page: 4, total: 61, per_page: 20, from: 21, to: 21 } });
  assert.equal(page.meta.current_page, 2); assert.equal(page.meta.total, 61); assert.equal(page.meta.last_page, 4);
});
test('an empty unpaginated list still has a usable page size', () => {
  const page = http.asPage({ data: { data: [] } });
  assert.ok(page.meta.per_page > 0); assert.equal(page.meta.total, 0);
});
test('Cairo calendar dates are ISO regardless of locale separator conventions', () => {
  const { toDate } = load('apps/backoffice/src/pages/admin/operations-mock/api/mappers.ts');
  assert.equal(toDate('2026-10-27T22:30:00Z'), '2026-10-28');
  assert.equal(toDate('bad-date'), '');
  assert.equal(toDate(null), '');
});
test('report periods reject impossible dates, missing boundaries, reversed and over 366 inclusive days', () => {
  const { validatePeriod } = load(base + 'reports.ts', { './http': http });
  for (const [from, to] of [['2026-02-30','2026-03-03'],['2026-01-02','2026-01-01'],['2024-01-01','2025-01-01'],['','2026-01-01']]) {
    assert.ok(validatePeriod(from, to), `${from}/${to}`);
  }
  assert.equal(validatePeriod('2024-01-01','2024-12-31'), null);
  assert.equal(validatePeriod('2026-10-27','2026-10-27'), null);
});
test('reports preserve rows/totals, encode booleans and notifications retain UUID/meta', async () => {
  const calls = [], envelope = { rows: [{ period: '2026-10-01', gross_sales: 200 }], totals: { gross_sales: 200 } };
  const api = { get: async (url, config) => { calls.push({url,config}); return { data: url.endsWith('notifications') ? {data:[],meta:{unread_count:5}} : {data:envelope} }; },
    post: async (url) => { calls.push({url}); return {data:{data:{id:'uuid-a',read_at:'now'},meta:{unread_count:4}}}; } };
  const { reportsApi, notificationsApi } = load(base + 'reports.ts', {'./http': {...http,api}});
  assert.deepEqual(await reportsApi.sales({from:'2026-10-01',to:'2026-10-27',granularity:'monthly'}),envelope);
  await reportsApi.shifts({only_differences:true}); assert.equal(calls[1].config.params.only_differences,1);
  const page = await notificationsApi.list(2); assert.equal(page.meta.unread_count,5); assert.equal(calls[2].config.params.page,2);
  const r = await notificationsApi.markRead('uuid-a'); assert.equal(r.meta.unread_count,4); assert.ok(calls[3].url.endsWith('/uuid-a/read'));
});
