const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');
function load(client) {
  const filename = path.resolve(__dirname, '../packages/api-client/src/endpoints/operations/commerce.ts');
  const compiled = new Module(filename, module);
  compiled.filename = filename; compiled.paths = Module._nodeModulePaths(path.dirname(filename));
  compiled.require = () => ({ api: client, ADMIN: '/api/v1/admin', cleanParams: p => p,
    asPage: r => r.data, crud: () => ({}), one: r => r.data.data, downloadFile() {} });
  compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText, filename);
  return compiled.exports;
}
test('variant choices traverse all product pages and fetch real nested variant IDs', async () => {
  const calls = [], ctrl = new AbortController();
  const { commerceLookupsApi } = load({ get: async (url, config) => {
    calls.push({ url, config });
    if (url.endsWith('/products')) return { data: { data: [{ id: config.params.page, name_ar: 'Bag' }], meta: { last_page: 2 } } };
    return { data: { data: [{ id: Number(url.split('/').at(-2)) + 100, sku: 'REAL' }] } };
  } });
  assert.deepEqual(await commerceLookupsApi.variants(ctrl.signal), [{ id: 101, label: 'Bag · REAL' }, { id: 102, label: 'Bag · REAL' }]);
  assert.equal(calls.length, 4);
  assert.ok(calls.every(c => c.config.signal === ctrl.signal));
});
test('price list item search loads every page instead of sending unsupported search filter', async () => {
  const calls = [];
  const { priceListItemsApi } = load({ get: async (url, config) => {
    calls.push({ url, config });
    return { data: { data: [{ id: config.params.page }], meta: { last_page: 2 } } };
  } });
  assert.deepEqual(await priceListItemsApi(17).all(), [{ id: 1 }, { id: 2 }]);
  assert.equal(calls[0].url, '/api/v1/admin/price-lists/17/items');
  assert.equal(calls[1].config.params.page, 2);
  assert.equal(calls[0].config.params.search, undefined);
});
test('commerce reuses the established brands API instead of publishing a second implementation', () => {
  assert.equal(load({}).opsBrandsApi, undefined);
});

function loadView(api) {
  const filename = path.resolve(__dirname, '../apps/backoffice/src/pages/admin/operations-mock/commerce.tsx');
  const compiled = new Module(filename, module);
  compiled.filename = filename; compiled.paths = Module._nodeModulePaths(path.dirname(filename));
  const react = { createElement: (type, props, ...children) => ({ type, props: { ...props, children } }),
    useState: value => [typeof value === 'function' ? value() : value, () => {}], useEffect() {}, useMemo: fn => fn() };
  const ui = new Proxy({ useToast: () => () => {} }, { get: (target, name) => name in target ? target[name] : name });
  compiled.require = name => {
    if (name === 'react') return { ...react, default: react };
    if (name === '@alfarid/api-client') return api;
    if (name === './ui') return ui;
    if (name === './data') return {};
    if (name === './api/RemoteFeedback') return { RemoteFeedback: 'RemoteFeedback' };
    if (name === './api/useFormState') return { useFormState: v => ({ v, errors: {}, on: () => () => {}, set() {}, setErrors() {} }) };
    if (name === './api/useRemote') return {
      useRemote: () => ({ data: [], loading: false, usingMock: false, reload() {} }),
      useAction: () => ({ busy: false, run: async fn => { try { return await fn(); } catch { return undefined; } } }),
    };
    if (name === './api/mappers') return { promoToBackend: () => 'fixed_discount', couponToBackend: () => 'percentage' };
    throw Error('Unexpected dependency ' + name);
  };
  compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.React },
  }).outputText, filename);
  return compiled.exports;
}
async function saveForm(element) {
  const buttons = element.props.footer.props.children.flat().filter(Boolean);
  const save = buttons.find(button => button.props?.children?.includes('حفظ'));
  assert.ok(save, 'save action should exist');
  await save.props.onClick();
}
test('editing a price list keeps its branch, customer and group relationships', async () => {
  let payload;
  const { PriceListForm } = loadView({ priceListsApi: { update: async (id, body) => { payload = body; return { id }; } } });
  await saveForm(PriceListForm({ row: { id: 7, name: 'Customer price', type: 'customer', prio: 5, active: true,
    from: '', to: null, raw: { customer: { id: 41 }, customer_group: null, branch: { id: 9 } } }, onClose() {} }));
  assert.equal(payload.customer_id, 41);
  assert.equal(payload.branch_id, 9);
  assert.equal(payload.customer_group_id, null);
});
test('editing a scoped promotion sends selected backend IDs instead of clearing its targets', async () => {
  let payload;
  const { PromoForm } = loadView({ promotionsApi: { update: async (id, body) => { payload = body; return { id }; } } });
  await saveForm(PromoForm({ row: { id: 1, name: 'Offer', type: 'amount', scope: 'brands', targetIds: [24, 51],
    targets: ['24', '51'], value: 30, min: null, from: '', to: null, active: true }, onClose() {} }));
  assert.deepEqual(payload.target_ids, [24, 51]);
  assert.equal(payload.starts_at, null);
});
test('unlimited per-customer coupon use remains zero on save', async () => {
  let payload;
  const { CouponForm } = loadView({ couponsApi: { update: async (id, body) => { payload = body; return { id }; } } });
  await saveForm(CouponForm({ row: { id: 1, code: 'ANY', type: 'percent', perCust: 0, active: true }, onClose() {} }));
  assert.equal(payload.usage_limit_per_customer, 0);
});
test('editing a brand preserves both descriptions and uses the existing multipart update method', async () => {
  let payload;
  const { BrandForm } = loadView({ brandsApi: { update: async (id, body) => { payload = body; return { id }; } } });
  await saveForm(BrandForm({ row: { id: 9, ar: 'حقائب', en: 'Bags', active: true, descAr: 'وصف', descEn: 'Existing description' }, onClose() {}, onSaved() {} }));
  assert.equal(payload.description_ar, 'وصف');
  assert.equal(payload.description_en, 'Existing description');
  assert.equal(payload.remove_logo, false);
});

test('a rejected used-coupon deletion keeps the editor and confirmation open', async () => {
  let closed = 0;
  const { CouponForm } = loadView({ couponsApi: { remove: async () => { throw Error('Used coupon'); } } });
  const view = CouponForm({ row: { id: 1, code: 'USED', type: 'percent', active: true }, onClose() { closed++; } });
  const confirmation = view.props.children.flat().find(child => child?.type === 'ConfirmModal');
  assert.equal(await confirmation.props.onConfirm(), false);
  assert.equal(closed, 0);
});
