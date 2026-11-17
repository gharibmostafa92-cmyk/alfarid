const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

function loadCatalogApi(mockClient) {
  const filename = path.resolve(__dirname, '../packages/api-client/src/endpoints/catalog.ts');
  const constantsPath = path.resolve(__dirname, '../packages/api-client/src/endpoints/constants.ts');

  function compileTs(file, deps) {
    const mod = new Module(file, module);
    mod.filename = file;
    mod.paths = Module._nodeModulePaths(path.dirname(file));
    mod.require = (name) => {
      if (name in deps) return deps[name];
      return Module.prototype.require.call(mod, name);
    };
    const js = ts.transpileModule(fs.readFileSync(file, 'utf8'), {
      compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    mod._compile(js, file);
    return mod.exports;
  }

  const constants = compileTs(constantsPath, {});
  return compileTs(filename, {
    '../axios-instance': { defaultApiClient: mockClient },
    './constants': constants,
  });
}

test('productsApi.remove forwards expected_version query param when provided', async () => {
  const calls = [];
  const client = {
    delete: async (url, config) => {
      calls.push({ url, config });
      return { data: { message: 'deleted' } };
    },
  };
  const { productsApi } = loadCatalogApi(client);

  await productsApi.remove(42, 7);
  await productsApi.remove(43);

  assert.equal(calls[0].url, '/api/v1/admin/products/42');
  assert.deepEqual(calls[0].config, { params: { expected_version: 7 } });
  assert.equal(calls[1].url, '/api/v1/admin/products/43');
  assert.equal(calls[1].config, undefined);
});

test('productMediaApi.uploadVideo uses a bounded 300_000ms timeout', async () => {
  const posts = [];
  const client = {
    post: async (url, body, config) => {
      posts.push({ url, body, config });
      return { data: { data: [] } };
    },
  };
  const { productMediaApi } = loadCatalogApi(client);

  await productMediaApi.uploadVideo(12, new Blob(['video'], { type: 'video/mp4' }));

  assert.equal(posts.length, 1);
  assert.equal(posts[0].config.timeout, 300_000);
});

test('upload progress handler ignores unknown/zero totals and clamps percentage to 100', async () => {
  let capturedProgress;
  const client = {
    post: async (_url, _body, config) => {
      capturedProgress = config.onUploadProgress;
      return { data: { data: [] } };
    },
  };
  const { productMediaApi } = loadCatalogApi(client);

  const reported = [];
  await productMediaApi.uploadVideo(12, new Blob(['video']), (pct) => reported.push(pct));

  capturedProgress({ loaded: 50, total: undefined });
  capturedProgress({ loaded: 50, total: 0 });
  capturedProgress({ loaded: 50, total: -10 });
  capturedProgress({ loaded: 50, total: 200 });
  capturedProgress({ loaded: 250, total: 200 });

  assert.deepEqual(reported, [25, 100]);
});
