const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');

const base = path.resolve('apps/backoffice/src/pages/admin/categories-mock');
const cache = new Map();
function load(file, overrides = {}) {
  const target = path.resolve(file);
  const bypassCache = Object.keys(overrides).length > 0;
  if (!bypassCache && cache.has(target)) return cache.get(target);
  const module = { exports: {} };
  const requireModule = name => {
    if (name in overrides) return overrides[name];
    const sibling = path.resolve(path.dirname(target), name);
    for (const [dependency, value] of Object.entries(overrides)) {
      if (path.resolve('packages/api-client/src/endpoints', dependency) === sibling) return value;
    }
    const extension = fs.existsSync(sibling + '.ts') ? '.ts' : '.js';
    return load(sibling + extension, overrides);
  };
  const compiled = ts.transpileModule(fs.readFileSync(target, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  vm.runInNewContext(compiled, { module, exports: module.exports, require: requireModule, FormData, Blob, Map });
  if (!bypassCache) cache.set(target, module.exports);
  return module.exports;
}
const plain = value => JSON.parse(JSON.stringify(value));
const { buildReorderItems, toPayload, toUiTree } = load(path.join(base, 'categories-adapter.js'));
const { normalizeSearch, toLatinDigits } = load(path.join(base, 'i18n-utils.js'));
const node = (id, order, children = []) => ({ id, order, children });

test('moving into a group with sparse sort values preserves the intended visual order', () => {
  const child = node(11, 40);
  const existing = node(21, 100);
  const before = [node(1, 10, [child]), node(2, 30, [existing])];
  const after = [node(1, 10), node(2, 30, [existing, child])];
  assert.deepEqual(plain(buildReorderItems(after, before)), [
    { id: 21, parent_id: 2, sort_order: 0 },
    { id: 11, parent_id: 2, sort_order: 1 },
  ]);
  assert.deepEqual(plain(buildReorderItems(before, before)), [], 'Unchanged sparse groups must not be renumbered');
});

test('reordering siblings sends the changed positions and excludes unrelated groups', () => {
  const a = node(11, 0), b = node(12, 1);
  const before = [node(1, 10, [a, b]), node(2, 20)];
  const after = [node(1, 10, [b, a]), node(2, 20)];
  assert.deepEqual(plain(buildReorderItems(after, before)), [
    { id: 12, parent_id: 1, sort_order: 0 },
    { id: 11, parent_id: 1, sort_order: 1 },
  ]);
});

test('Arabic and Persian order digits, Arabic search variants and bilingual descriptions survive conversion', () => {
  assert.equal(toLatinDigits('١۲٣'), '123');
  assert.equal(normalizeSearch('إضَــاءَة'), normalizeSearch('اضاءه'));
  const result = toPayload({ ar: ' قسم ', en: ' Name ', parentId: null, order: '٣', status: 'inactive', desc_ar: ' عربي ', desc_en: ' English ', imageRemoved: true }, { isEdit: true });
  assert.deepEqual(plain(result), {
    name_ar: 'قسم', name_en: 'Name', parent_id: null, slug: null,
    description_ar: 'عربي', description_en: 'English', sort_order: 3, is_active: false, remove_image: true,
  });
});

test('API resource conversion keeps direct product counts separate from the child counts', () => {
  const resource = { id: 1, parent_id: null, name_ar: 'أب', name_en: 'Parent', is_active: true, sort_order: 10, products_count: 2,
    children: [{ id: 2, parent_id: 1, name_ar: 'ابن', name_en: 'Child', is_active: false, products_count: 3 }] };
  const [result] = toUiTree([resource]);
  assert.equal(result.products, 2);
  assert.equal(result.children[0].products, 3);
  assert.equal(result.children[0].parentId, 1);
  assert.equal(result.children[0].status, 'inactive');
});

test('API client serializes nullable values, false and method-spoofed multipart updates correctly', async () => {
  let sent;
  const client = { post: async (url, data, options) => { sent = { url, data, options }; return { data: { data: { id: 7 } } }; } };
  const { categoriesApi } = load(path.resolve('packages/api-client/src/endpoints/index.ts'), { './inventory': {}, './operations': {}, '../axios-instance': { defaultApiClient: client, createApiClient: () => client } });
  await categoriesApi.update(7, { parent_id: null, is_active: false, remove_image: true });
  assert.equal(sent.url, '/api/v1/admin/categories/7');
  assert.equal(sent.data.get('_method'), 'PUT');
  assert.equal(sent.data.get('parent_id'), '');
  assert.equal(sent.data.get('is_active'), '0');
  assert.equal(sent.data.get('remove_image'), '1');
  assert.equal(sent.data.has('name_ar'), false, 'A status-only update must omit unrelated fields');
});

test('F11: catalog api client forwards expected_version and expected_ids for optimistic locking', async () => {
  const calls = [];
  const client = {
    post: async (url, data) => { calls.push({ method: 'POST', url, data }); return { data: { data: { id: 7 } } }; },
    put: async (url, data) => { calls.push({ method: 'PUT', url, data }); return { data: { message: 'ok' } }; },
    delete: async (url, config) => { calls.push({ method: 'DELETE', url, config }); return { data: { message: 'ok' } }; },
  };
  const { categoriesApi, brandsApi, variantsApi, productMediaApi } = load(path.resolve('packages/api-client/src/endpoints/index.ts'), {
    './inventory': {},
    './operations': {},
    '../axios-instance': { defaultApiClient: client, createApiClient: () => client },
  });

  await categoriesApi.update(7, { is_active: false, expected_version: 3 });
  assert.equal(calls[0].data.get('expected_version'), '3');

  await brandsApi.update(4, { is_active: true, expected_version: 2 });
  assert.equal(calls[1].data.get('expected_version'), '2');

  await categoriesApi.remove(7, 3);
  assert.deepEqual(plain(calls[2].config), { params: { expected_version: 3 } });

  await brandsApi.remove(4, 2);
  assert.deepEqual(plain(calls[3].config), { params: { expected_version: 2 } });

  await variantsApi.remove(5, 12, 8);
  assert.deepEqual(plain(calls[4].config), { params: { expected_version: 8 } });

  await productMediaApi.reorder(5, [2, 1], [1, 2]);
  assert.deepEqual(plain(calls[5].data), { ids: [2, 1], expected_ids: [1, 2] });
});
