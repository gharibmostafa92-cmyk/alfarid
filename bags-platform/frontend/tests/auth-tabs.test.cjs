const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { createRequire } = require('node:module');
const { webcrypto } = require('node:crypto');
const ts = require('typescript');

function browser(token = null) {
  const values = new Map(token ? [['backoffice_token', token]] : []);
  const listeners = {};
  const storage = {
    getItem: key => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: key => values.delete(key),
  };
  const state = { reloads: 0, messages: [] };
  const window = {
    location: { pathname: '/dashboard', hash: '#nonce=verification', reload: () => state.reloads++ },
    parent: { postMessage: (data, origin) => state.messages.push({ data, origin }) },
    addEventListener: (name, callback) => { (listeners[name] ??= new Set()).add(callback); },
    removeEventListener: (name, callback) => listeners[name]?.delete(callback),
  };
  return { window, localStorage: storage, state,
    change(key) { for (const callback of listeners.storage ?? []) callback({ key, storageArea: storage }); },
  };
}

function load(relative, env, api = {}, extra = {}) {
  const filename = path.resolve(__dirname, '..', relative);
  const sourceRoot = path.resolve(__dirname, '../apps', relative.split('/')[1], 'src');
  const modules = new Map();
  let authToken = null;
  const apiModule = { setAuthToken(token) { authToken = token; }, getAuthToken: () => authToken,
    setUnauthorizedHandler() {}, resolveServiceUrl: url => url, ...api };
  function evaluate(file) {
    if (modules.has(file)) return modules.get(file);
    const requireFromApp = createRequire(file);
    const source = fs.readFileSync(file, 'utf8').replaceAll('import.meta.env', '({})');
    const exports = {};
    modules.set(file, exports);
    vm.runInNewContext(ts.transpileModule(source, {
      compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
    }).outputText, {
      exports, ...env, URL, URLSearchParams, crypto: webcrypto,
      require: name => {
        if (name === '@alfarid/api-client') return apiModule;
        if (name === './mappers') return { toStaffUser: user => user };
        if (extra[name]) return extra[name];
        if (name.startsWith('@/')) return evaluate(path.join(sourceRoot, `${name.slice(2)}.ts`));
        return requireFromApp(name);
      },
    }, { filename: file });
    return exports;
  }
  return evaluate(filename);
}

const staffStore = 'apps/backoffice/src/stores/auth.store.ts';
test('staff tabs reload on account replacement and logout, not unrelated storage writes', () => {
  const env = browser('first');
  load(staffStore, env);
  env.change('backoffice_token');
  env.localStorage.setItem('other', 'value'); env.change('other');
  assert.equal(env.state.reloads, 0);
  env.localStorage.setItem('backoffice_token', 'second'); env.change('backoffice_token');
  assert.equal(env.state.reloads, 1);
  env.localStorage.removeItem('backoffice_token'); env.change(null);
  assert.equal(env.state.reloads, 2);
});

test('a delayed staff identity response cannot overwrite a newly selected account', async () => {
  let finish;
  const env = browser('first');
  const { useAuthStore } = load(staffStore, env, {
    authEndpoints: { staffMe: () => new Promise(resolve => { finish = resolve; }) },
  });
  const request = useAuthStore.getState().bootstrap();
  useAuthStore.getState().login('second', { id: 2 });
  finish({ id: 1 }); await request;
  assert.equal(useAuthStore.getState().user.id, 2);
});

test('web tabs reload only when the persisted session changes', () => {
  const env = browser();
  const { useAuthStore } = load('apps/storefront/src/store/auth.store.ts', env, {}, {
    'zustand/middleware': { persist: initialize => initialize },
  });
  useAuthStore.getState().setSession({ token: 'first' });
  env.localStorage.setItem('alfarid_web_auth', JSON.stringify({ state: { token: 'first', user: { name: 'updated' } } }));
  env.change('alfarid_web_auth'); assert.equal(env.state.reloads, 0);
  env.localStorage.setItem('alfarid_web_auth', JSON.stringify({ state: { token: 'second' } }));
  env.change('alfarid_web_auth'); assert.equal(env.state.reloads, 1);
  env.localStorage.removeItem('alfarid_web_auth'); env.change(null);
  assert.equal(env.state.reloads, 2);
});

async function probe(env, get) {
  let effect;
  const component = load('apps/backoffice/src/pages/auth/SessionProbePage.tsx', env,
    { createApiClient: () => ({ get }) }, { react: { useEffect: callback => { effect = callback; } } });
  component.default();
  const cleanup = effect();
  await new Promise(resolve => setImmediate(resolve));
  return cleanup;
}

test('session discovery validates staff on the server and never sends the token to the other origin', async () => {
  const env = browser('private-token');
  let endpoint;
  await probe(env, async url => { endpoint = url; });
  assert.equal(endpoint, '/api/v1/admin/me');
  assert.equal(env.state.messages[0].origin, 'http://localhost:3000');
  assert.equal(env.state.messages[0].data.authenticated, true);
  assert.equal(env.state.messages[0].data.nonce, 'verification');
  assert.ok(!JSON.stringify(env.state.messages).includes('private-token'));
});

test('an expired staff session does not redirect the login page', async () => {
  const env = browser('expired');
  await probe(env, async () => { throw new Error('401'); });
  assert.equal(env.state.messages[0].data.authenticated, false);
});

test('discovery ignores a successful response after the session was removed', async () => {
  const env = browser('first');
  await probe(env, async () => { env.localStorage.removeItem('backoffice_token'); });
  assert.equal(env.state.messages[0].data.authenticated, false);
});

test('discovery on a top-level page does not expose session status', async () => {
  const env = browser('first'); env.window.parent = env.window;
  await probe(env, async () => { throw new Error('must not request'); });
  assert.equal(env.state.messages.length, 0);
});
