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
    compilerOptions: {
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2022,
      ...(filename.endsWith('.tsx') ? { jsx: ts.JsxEmit.React } : {}),
    },
  }).outputText, filename);
  return compiled.exports;
}
const base = 'packages/api-client/src/endpoints/operations/';
const http = load(base + 'http.ts', { '../../axios-instance': { defaultApiClient: {} } });

test('server clients keep request tokens separate and reject global token mutation', () => {
  const requests = [];
  const axios = { create: () => ({ interceptors: {
    request: { use: callback => requests.push(callback) },
    response: { use() {} },
  } }) };
  const client = load('packages/api-client/src/axios-instance.ts', {
    axios: { default: axios }, './service-url': { resolveServiceUrl: url => url },
  });
  assert.throws(() => client.setAuthToken('server-user'), /must not be called on the server/);
  client.createApiClient({ getToken: () => 'user-a' });
  client.createApiClient({ getToken: () => 'user-b' });
  assert.equal(requests[1]({ headers: {} }).headers.Authorization, 'Bearer user-a');
  assert.equal(requests[2]({ headers: {} }).headers.Authorization, 'Bearer user-b');
  assert.equal(requests[0]({ headers: {} }).headers.Authorization, undefined);
});

test('production ignores financial mock fallback flags without crashing the application', () => {
  const filename = path.resolve(__dirname, '../apps/backoffice/src/pages/admin/operations-mock/api/source.ts');
  const code = ts.transpileModule(fs.readFileSync(filename, 'utf8').replaceAll('import.meta.env', 'env'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  const exports = {};
  new Function('env', 'exports', code)({ PROD: true, VITE_OPERATIONS_FALLBACK_ON_ERROR: 'true' }, exports);
  assert.equal(exports.FALLBACK_ON_ERROR, false);
});

test('protected files isolate the new tab and use the fetched bytes for blocked or unsafe previews', async () => {
  const previous = { window: global.window, document: global.document, setTimeout: global.setTimeout,
    createObjectURL: URL.createObjectURL, revokeObjectURL: URL.revokeObjectURL };
  let requests = 0, clicks = 0, closes = 0, opened, fetched;
  const tab = { opener: { location: 'admin' }, location: { href: '' }, close() { closes++; }, addEventListener(name, callback) { this[name] = callback; } };
  global.window = { open() { opened = tab; return tab; }, setTimeout() { return 1; } };
  global.document = { body: { appendChild() {} }, createElement: () => ({ click() { clicks++; }, remove() {} }) };
  global.setTimeout = () => 1;
  URL.createObjectURL = blob => { fetched = blob; return 'blob:safe'; };
  let revocations = 0;
  URL.revokeObjectURL = () => { revocations++; };
  let type = 'application/pdf';
  const files = load(base + 'http.ts', { '../../axios-instance': { defaultApiClient: { get: async () => {
    requests++; return { data: new Blob(['contents'], { type }), headers: { 'content-type': type } };
  } } } });
  try {
    await files.openProtectedFile('/receipt');
    assert.equal(opened.opener, null);
    assert.equal(opened.location.href, 'blob:safe');
    assert.equal(fetched.type, 'application/pdf');
    assert.equal(typeof opened.pagehide, 'function', 'the blob URL should be released when its tab closes');
    opened.pagehide();
    opened.pagehide();
    assert.equal(revocations, 1, 'the release handler must be idempotent');
    type = 'text/html';
    await files.openProtectedFile('/unsafe');
    assert.equal(requests, 2, 'unsafe files must download the existing response without another fetch');
    assert.equal(clicks, 1); assert.equal(closes, 1);
    global.window.open = () => null;
    type = 'image/png';
    await files.openProtectedFile('/blocked');
    assert.equal(clicks, 2, 'blocked popups must fall back to downloading');
    assert.equal(requests, 3);
  } finally {
    Object.assign(global, { window: previous.window, document: previous.document, setTimeout: previous.setTimeout });
    URL.createObjectURL = previous.createObjectURL; URL.revokeObjectURL = previous.revokeObjectURL;
  }
});
test('raw Laravel pagination retains the page and total', () => {
  const page = http.asPage({ data: { data: [{ id: 21 }], current_page: 2, last_page: 4, total: 61, per_page: 20, from: 21, to: 21 } });
  assert.equal(page.meta.current_page, 2); assert.equal(page.meta.total, 61); assert.equal(page.meta.last_page, 4);
});
test('an empty unpaginated list still has a usable page size', () => {
  const page = http.asPage({ data: { data: [] } });
  assert.ok(page.meta.per_page > 0); assert.equal(page.meta.total, 0);
});

test('reports and exports allow the slow backend to finish', async () => {
  const calls = [];
  const api = { get: async (url, config) => { calls.push({ url, config }); return { data: { data: [] } }; } };
  const { reportsApi } = load(base + 'reports.ts', { './http': { ...http, api } });
  await reportsApi.sales();
  assert.ok(calls[0].config.timeout > 180_000);
});

test('protected signed attachments navigate directly without fetching R2 bytes', async () => {
  const previous = global.window;
  const tab = { opener: {}, location: { href: '' }, close() {} };
  global.window = { open: () => tab };
  const signedUrl = 'https://private.example/receipt?signature=abc';
  const files = load(base + 'http.ts', { '../../axios-instance': { defaultApiClient: { defaults: { baseURL: 'https://private.example' }, get: async (url, config) => {
    assert.equal(config.params.signed, 1);
    return { data: new Blob([JSON.stringify({ url: signedUrl })]), headers: { 'content-type': 'application/json' } };
  } } } });
  try {
    await files.openProtectedFile('/receipt');
    assert.equal(tab.location.href, signedUrl);
    assert.equal(tab.opener, null);
  } finally {
    global.window = previous;
  }
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
  const day = 86_400_000;
  const stamp = (value) => Date.parse(value + 'T00:00:00Z');
  for (let i = 0; i < 40; i++) {
    const start = Date.UTC(2020, 0, 1) + (i * 17) * day;
    const inclusive = 1 + (i % 400);
    const from = new Date(start).toISOString().slice(0, 10);
    const to = new Date(start + (inclusive - 1) * day).toISOString().slice(0, 10);
    const backendAccepts = (stamp(to) - stamp(from)) / day + 1 <= 366;
    assert.equal(validatePeriod(from, to) === null, backendAccepts, `${from}/${to}`);
  }
});
test('cleanParams drops empty query values before they reach Laravel', () => {
  assert.deepEqual(http.cleanParams({ branch_id: '', dead_days: 30, only_differences: false, q: undefined, note: null }), {
    dead_days: 30, only_differences: 0,
  });
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

test('allPages enforces a default page cap to prevent unbounded memory and network exhaustion', async () => {
  let calls = 0;
  await assert.rejects(
    http.allPages(async (page) => {
      calls++;
      return { data: [{ id: page }], meta: { last_page: 100 } };
    }),
    /max|limit|page/i,
  );
  assert.equal(calls, 20);
});

test('idempotent posts reuse a retry key, rotate for changed input, and clear after success', async () => {
  const keys = [];
  let fail = true;
  const client = { post: async (_url, _body, config) => {
    keys.push(config.headers['Idempotency-Key']);
    if (fail) throw new Error('network timeout');
    return { data: { ok: true } };
  } };
  const requests = load(base + 'http.ts', { '../../axios-instance': { defaultApiClient: client } });

  await assert.rejects(requests.idempotentPost('/payments/1/approve', { amount: '12.00' }));
  await assert.rejects(requests.idempotentPost('/payments/1/approve', { amount: '12.00' }));
  assert.equal(keys[0], keys[1], 'retrying the same action must reuse its original key');

  await assert.rejects(requests.idempotentPost('/payments/1/approve', { amount: '13.00' }));
  assert.notEqual(keys[1], keys[2], 'changed operation input must get a new key');

  fail = false;
  await requests.idempotentPost('/payments/1/approve', { amount: '13.00' });
  await requests.idempotentPost('/payments/1/approve', { amount: '13.00' });
  assert.notEqual(keys[3], keys[4], 'a completed action must not reuse its key');
});

test('idempotent posts isolate payloads on one URL and discard keys after permanent validation errors', async () => {
  const keys = [];
  let status = 500;
  const client = { post: async (_url, body, config) => {
    keys.push({ body, key: config.headers['Idempotency-Key'] });
    throw { response: { status } };
  } };
  const requests = load(base + 'http.ts', { '../../axios-instance': { defaultApiClient: client } });
  await assert.rejects(requests.idempotentPost('/returns', { order_id: 101 }));
  await assert.rejects(requests.idempotentPost('/returns', { order_id: 102 }));
  await assert.rejects(requests.idempotentPost('/returns', { order_id: 101 }));
  assert.equal(keys[0].key, keys[2].key, 'a retry must recover its matching URL and payload key');
  assert.notEqual(keys[0].key, keys[1].key, 'different payloads on the same URL are independent');
  status = 422;
  await assert.rejects(requests.idempotentPost('/returns', { order_id: 101 }));
  await assert.rejects(requests.idempotentPost('/returns', { order_id: 101 }));
  assert.notEqual(keys[3].key, keys[4].key, 'a permanent 4xx must release its key');
});

test('signedAttachmentUrl rejects insecure http:// and untrusted external hosts', async () => {
  const mkResp = (url) => ({
    headers: { 'content-type': 'application/json' },
    data: new Blob([JSON.stringify({ url })], { type: 'application/json' }),
  });
  await assert.rejects(http.signedAttachmentUrl(mkResp('http://evil.example/file.pdf')), /Invalid attachment URL/i);
  await assert.rejects(http.signedAttachmentUrl(mkResp('https://evil.example/phish.html')), /Invalid attachment URL|Untrusted/i);
});

test('openProtectedFile decodes JSON Blob errors so server messages are not lost', async () => {
  const previous = { window: global.window };
  const tab = { opener: {}, location: { href: '' }, close() {} };
  global.window = { open: () => tab, location: { origin: 'https://admin.alfarid.test' } };
  const errBlob = new Blob([JSON.stringify({ message: 'ليس لديك صلاحية لهذا المرفق' })], { type: 'application/json' });
  const files = load(base + 'http.ts', {
    '../../axios-instance': {
      defaultApiClient: {
        defaults: { baseURL: 'https://api.alfarid.test' },
        get: async () => {
          const e = new Error('Forbidden');
          e.response = { status: 403, headers: { 'content-type': 'application/json' }, data: errBlob };
          throw e;
        },
      },
    },
  });
  try {
    await assert.rejects(
      files.openProtectedFile('/receipt'),
      (err) => files.parseApiError(err).message === 'ليس لديك صلاحية لهذا المرفق',
    );
  } finally {
    global.window = previous.window;
  }
});

test('pagerWindow renders sliding window around current page with ellipses', () => {
  const ui = load('apps/backoffice/src/pages/admin/operations-mock/ui.tsx', {
    react: { default: { createElement: () => null }, createContext: () => ({}), createElement: () => null, Fragment: 'fragment', useContext: () => ({}), useEffect: () => {}, useRef: () => ({}), useState: (v) => [v, () => {}] },
    'react-dom': { createPortal: (x) => x },
    '@alfarid/ui': {},
  });
  assert.deepEqual(ui.pagerWindow(1, 4), [1, 2, 3, 4]);
  assert.deepEqual(ui.pagerWindow(6, 10), [1, '...', 5, 6, 7, '...', 10]);
  assert.deepEqual(ui.pagerWindow(1, 10), [1, 2, '...', 10]);
  assert.deepEqual(ui.pagerWindow(10, 10), [1, '...', 9, 10]);
  assert.equal(ui.parseMaxBytes('10MB'), 10 * 1024 * 1024);
  assert.ok(ui.validateDropzoneFile({ name: 'big.pdf', size: 11 * 1024 * 1024, type: 'application/pdf' }, '.pdf', '10MB'));
  assert.ok(ui.validateDropzoneFile({ name: 'bad.exe', size: 100, type: 'application/x-msdownload' }, '.pdf,.png', '10MB'));
  assert.equal(ui.validateDropzoneFile({ name: 'ok.pdf', size: 1024, type: 'application/pdf' }, '.pdf,.png', '10MB'), null);
});

test('canViewOperation fails closed for unknown pages and parseOperationsHash parses route', () => {
  const noop = new Proxy({}, { get: () => () => null });
  const pageMod = load('apps/backoffice/src/pages/admin/operations-mock/OperationsMockPage.tsx', {
    react: { default: {}, useLayoutEffect: () => {}, useEffect: () => {}, useRef: () => ({}), useState: (v) => [v, () => {}], useMemo: (f) => f() },
    'react-dom': { createPortal: (x) => x },
    './operations-reference.css?inline': '',
    './api/useNotifications': { useNotifications: () => ({}) },
    './api/source': { USE_MOCK: false },
    './ui': noop, './orders': noop, './commerce': noop, './finance': noop, './reports': noop,
  });
  assert.equal(pageMod.canViewOperation('unknown_secret_page', () => true), false);
  assert.equal(pageMod.canViewOperation('orders', () => false), false);
  assert.equal(pageMod.canViewOperation('orders', (p) => p === 'orders.view'), true);
  assert.deepEqual(pageMod.parseOperationsHash('#/wholesale?sub=notices'), { m: 'wholesale', id: undefined, sub: 'notices' });
  assert.deepEqual(pageMod.parseOperationsHash('#/orders/123'), { m: 'orders', id: '123', sub: undefined });
});
