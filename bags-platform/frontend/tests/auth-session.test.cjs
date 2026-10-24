const { test, afterEach } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

function loadTypeScript(filename) {
  const compiled = new Module(filename, module);
  compiled.filename = filename;
  compiled.paths = Module._nodeModulePaths(path.dirname(filename));
  compiled.require = name => {
    const sibling = path.resolve(path.dirname(filename), name + '.ts');
    if (name.startsWith('.') && fs.existsSync(sibling)) return loadTypeScript(sibling);
    return Module.prototype.require.call(compiled, name);
  };
  compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, esModuleInterop: true },
  }).outputText, filename);
  return compiled.exports;
}
const { createApiClient, setAuthToken, setUnauthorizedHandler } = loadTypeScript(
  path.resolve(__dirname, '../packages/api-client/src/axios-instance.ts'),
);

afterEach(() => { setAuthToken(null); setUnauthorizedHandler(null); });

test('an explicit logout token is preserved when a new session is active', async () => {
  setAuthToken('new-token');
  const client = createApiClient();
  let authorization;
  client.defaults.adapter = async config => {
    authorization = config.headers.get('Authorization');
    return { data: {}, status: 204, statusText: '', headers: {}, config };
  };
  await client.post('/api/v1/auth/logout', {}, { headers: { authorization: 'Bearer old-token' } });
  assert.equal(authorization, 'Bearer old-token');
});

test('a delayed 401 from an old session cannot clear a newer session', async () => {
  let release;
  let started;
  const ready = new Promise(resolve => { started = resolve; });
  let unauthorized = 0;
  setUnauthorizedHandler(() => { unauthorized++; });
  setAuthToken('old-token');
  const client = createApiClient();
  client.defaults.adapter = config => new Promise((resolve, reject) => {
    release = () => reject({ config, response: { status: 401 } });
    started();
  });
  const pending = client.get('/api/v1/auth/me');
  await ready;
  setAuthToken('new-token');
  release();
  await assert.rejects(pending);
  assert.equal(unauthorized, 0);
});

test('a 401 for the current token clears the current session', async () => {
  let unauthorized = 0;
  setAuthToken('current-token');
  setUnauthorizedHandler(() => { unauthorized++; });
  const client = createApiClient();
  client.defaults.adapter = async config => { throw { config, response: { status: 401 } }; };
  await assert.rejects(client.get('/api/v1/auth/me'));
  assert.equal(unauthorized, 1);
});

test('a failed sign-in does not clear an existing session', async () => {
  let unauthorized = 0;
  setAuthToken('current-token');
  setUnauthorizedHandler(() => { unauthorized++; });
  const client = createApiClient();
  client.defaults.adapter = async config => { throw { config, response: { status: 401 } }; };
  await assert.rejects(client.post('/api/v1/auth/login', {}));
  await assert.rejects(client.post('/api/v1/auth/handoff/exchange', {}));
  assert.equal(unauthorized, 0);
});
