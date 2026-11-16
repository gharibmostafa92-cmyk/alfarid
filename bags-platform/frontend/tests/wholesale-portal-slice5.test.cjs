const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

const FRONTEND_ROOT = path.resolve(__dirname, '..');

function transpileAndLoad(relativePath, mocks = {}) {
  const filename = path.resolve(FRONTEND_ROOT, relativePath);
  const compiled = new Module(filename, module);
  compiled.filename = filename;
  compiled.paths = Module._nodeModulePaths(path.dirname(filename));
  compiled.require = (name) => {
    if (Object.prototype.hasOwnProperty.call(mocks, name)) {
      return mocks[name];
    }
    if (name.startsWith('.')) {
      const base = path.resolve(path.dirname(filename), name);
      const candidates = [
        base,
        `${base}.js`,
        `${base}.jsx`,
        `${base}.ts`,
        `${base}.tsx`,
      ];
      const found = candidates.find((c) => fs.existsSync(c) && fs.statSync(c).isFile());
      if (found) {
        return transpileAndLoad(path.relative(FRONTEND_ROOT, found), mocks);
      }
    }
    return Module.prototype.require.call(compiled, name);
  };
  const source = fs.readFileSync(filename, 'utf8');
  const transpiled = ts.transpileModule(source, {
    compilerOptions: {
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2022,
      jsx: ts.JsxEmit.React,
    },
  }).outputText;
  compiled._compile(transpiled, filename);
  return compiled.exports;
}

function createReactMock(stateValues = [], refValues = []) {
  let stateIdx = 0;
  let refIdx = 0;
  const setStateCalls = [];
  const React = {
    createElement(type, props, ...children) {
      return { type, props: props || {}, children };
    },
    Fragment: Symbol('Fragment'),
    useState(initial) {
      const idx = stateIdx++;
      const val = idx < stateValues.length ? stateValues[idx] : (typeof initial === 'function' ? initial() : initial);
      const setter = (arg) => {
        setStateCalls.push({ idx, arg });
      };
      return [val, setter];
    },
    useRef(initial) {
      const idx = refIdx++;
      if (idx < refValues.length) return refValues[idx];
      const ref = { current: initial };
      refValues[idx] = ref;
      return ref;
    },
    useEffect() {},
    useCallback(fn) { return fn; },
    useMemo(fn) { return fn(); },
    createContext(v) { return { Provider: 'Provider', _defaultValue: v }; },
    useContext(ctx) { return ctx._defaultValue; },
  };
  React.default = React;
  React.__esModule = true;
  return { React, setStateCalls, resetIndices: () => { stateIdx = 0; refIdx = 0; } };
}

function findElements(node, predicate, out = []) {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    for (const child of node) findElements(child, predicate, out);
    return out;
  }
  if (predicate(node)) out.push(node);
  if (node.props && node.props.children) findElements(node.props.children, predicate, out);
  if (node.children) findElements(node.children, predicate, out);
  return out;
}

test('F01: Notifications does not throw ReferenceError and preserves all-tab total in useRef', () => {
  const { React, resetIndices } = createReactMock(['all', 1]);
  let apiData = {
    items: [{ id: 'n1', title: 'T', body: 'B', unread: true, time: '10:00', icon: 'fa-bell' }],
    meta: { total: 42, unread_count: 5, last_page: 3, per_page: 20 },
  };

  const mocks = {
    react: React,
    '@alfarid/api-client': { wholesaleEndpoints: { notifications: async () => ({}) } },
    './context': { useApp: () => ({ go: () => {}, unread: 5, setUnread: () => {}, toast: () => {} }) },
    './data/useApi': { useApi: () => ({ state: 'ready', data: apiData, setData: () => {}, reload: async () => {} }) },
    './data/DataBoundary': { DataBoundary: ({ children }) => children },
    './data/mappers': { mapNotif: (x) => x, mapInvoice: (x) => x, mapNotice: (x) => x, mapStatement: (x) => x, mapWalletTx: (x) => x },
    './data/helpers': { apiError: (e) => e, saveBlob: () => {}, pageMeta: () => ({}), num: Number, calendarDay: () => '', today: () => '', allPages: async () => [] },
    './data/constants': { NOTICE_STATUS: {}, PAYMENT_METHOD: {} },
    './ui-primitives': {
      Async: ({ children }) => children,
      Badge: 'Badge',
      Crumb: 'Crumb',
      Empty: 'Empty',
      Money: 'Money',
      Modal: 'Modal',
      PageHead: ({ children }) => React.createElement('div', null, children),
      Pager: 'Pager',
      Seg: 'Seg',
      Stat: 'Stat',
      StatusBanner: 'StatusBanner',
      TabBar: 'TabBar',
    },
  };

  const { Notifications } = transpileAndLoad('packages/ui/src/wholesale-portal/finance-screens.jsx', mocks);

  // First render on 'all' tab
  const treeAll = Notifications();
  const segAll = findElements(treeAll, (n) => n.type === 'Seg')[0];
  assert.ok(segAll, 'Seg should be rendered');
  const allOpt1 = segAll.props.options.find((o) => o.value === 'all');
  const unreadOpt1 = segAll.props.options.find((o) => o.value === 'unread');
  assert.equal(allOpt1.n, 42);
  assert.equal(unreadOpt1.n, 5);

  // Second render after switching to 'unread' tab where meta.total becomes 5
  resetIndices();
  const { React: React2 } = createReactMock(['unread', 1], [{ current: 42 }]);
  mocks.react = React2;
  apiData = {
    items: [{ id: 'n1', title: 'T', body: 'B', unread: true, time: '10:00', icon: 'fa-bell' }],
    meta: { total: 5, unread_count: 5, last_page: 1, per_page: 20 },
  };
  const { Notifications: Notifications2 } = transpileAndLoad('packages/ui/src/wholesale-portal/finance-screens.jsx', mocks);
  const treeUnread = Notifications2();
  const segUnread = findElements(treeUnread, (n) => n.type === 'Seg')[0];
  const allOpt2 = segUnread.props.options.find((o) => o.value === 'all');
  const unreadOpt2 = segUnread.props.options.find((o) => o.value === 'unread');
  assert.equal(allOpt2.n, 42, 'all tab badge should preserve 42 in useRef when switching to unread');
  assert.equal(unreadOpt2.n, 5);
});

test('F12: ReturnForm and NoticeForm use single capped requests instead of allPages', async () => {
  const orderCalls = [];
  const invoiceCalls = [];
  let capturedReturnFetcher = null;
  let capturedNoticeFetcher = null;

  const { React } = createReactMock([null, {}, '', '', {}, false]);
  const baseMocks = {
    react: React,
    '@alfarid/api-client': {
      wholesaleEndpoints: {
        orders: async (p) => { orderCalls.push(p); return { data: [] }; },
        order: async () => ({}),
        invoices: async (p) => { invoiceCalls.push(p); return { data: [] }; },
      },
    },
    './context': { useApp: () => ({ go: () => {}, toast: () => {}, route: {}, refreshAccount: () => {} }) },
    './data/DataBoundary': { DataBoundary: ({ children }) => children },
    './data/mappers': { mapOrder: (x) => x, mapQuote: (x) => x, mapReturn: (x) => x, mapInvoice: (x) => x, mapNotice: (x) => x, mapStatement: (x) => x, mapWalletTx: (x) => x, mapNotif: (x) => x },
    './data/helpers': {
      apiError: (e) => e, saveBlob: () => {}, pageMeta: () => ({}), num: Number, calendarDay: () => '', today: () => '2026-11-16',
      allPages: async () => { throw new Error('allPages should not be called'); },
    },
    './data/validation': { validateReturnFiles: () => null },
    './data/constants': {
      ORDER_STATUS: {}, PAYMENT_METHOD: {}, QUOTE_STATUS: {}, RETURN_STATUS: {}, REFUND_METHOD: {}, NOTICE_STATUS: {}, NOTICE_METHOD: { bank_transfer: 'تحويل بنكي' },
    },
    './ui-primitives': {
      Async: ({ children }) => children, Badge: 'Badge', Crumb: 'Crumb', Empty: 'Empty', Money: 'Money', Modal: 'Modal',
      PageHead: ({ children }) => React.createElement('div', null, children), Pager: 'Pager', Seg: 'Seg', Stat: 'Stat',
      StatusBanner: 'StatusBanner', TabBar: 'TabBar',
    },
  };

  const { ReturnForm } = transpileAndLoad('packages/ui/src/wholesale-portal/orders-screens.jsx', {
    ...baseMocks,
    './data/useApi': {
      useApi: (fn) => {
        if (!capturedReturnFetcher) capturedReturnFetcher = fn;
        return { state: 'ready', data: [], setData: () => {}, reload: async () => {} };
      },
    },
  });
  ReturnForm({});
  await capturedReturnFetcher(new AbortController().signal);
  assert.deepEqual(orderCalls, [{ group: 'delivered', returnable: 1, per_page: 100 }]);

  const { React: ReactNotice } = createReactMock([
    { order: '', amount: '', method: 'bank_transfer', bank: '', ref: '', date: '2026-11-16' },
    null, {}, 'form', null,
  ]);
  const { NoticeForm } = transpileAndLoad('packages/ui/src/wholesale-portal/finance-screens.jsx', {
    ...baseMocks,
    react: ReactNotice,
    './data/useApi': {
      useApi: (fn) => {
        if (!capturedNoticeFetcher) capturedNoticeFetcher = fn;
        return { state: 'ready', data: [], setData: () => {}, reload: async () => {} };
      },
    },
  });
  NoticeForm();
  await capturedNoticeFetcher(new AbortController().signal);
  assert.deepEqual(invoiceCalls, [{ payment_status: 'unpaid', per_page: 100 }]);
});

test('F14: acceptQuote sends expected_revision and mapQuote preserves revision', async () => {
  const postCalls = [];
  const { wholesaleEndpoints } = transpileAndLoad('packages/api-client/src/endpoints/wholesale.ts', {
    '../axios-instance': {
      defaultApiClient: {
        post: async (url, body) => {
          postCalls.push({ url, body });
          return { data: { data: { id: 12, status: 'accepted' } } };
        },
      },
    },
    './operations/http': { SLOW_REQUEST_TIMEOUT: 30000 },
  });

  await wholesaleEndpoints.acceptQuote(12, { expected_revision: 7 });
  assert.deepEqual(postCalls, [{ url: '/api/v1/wholesale/quotations/12/accept', body: { expected_revision: 7 } }]);

  const mappers = transpileAndLoad('packages/ui/src/wholesale-portal/data/mappers.js', {
    './helpers': { num: Number, day: (x) => x },
    './constants': { ORDER_ST: {}, QUOTE_ST: {}, NOTICE_ST: {}, RETURN_ST: {}, WALLET_TYPE: {} },
  });
  const mapped = mappers.mapQuote({ id: 12, quotation_number: 'Q-12', revision: 7, items: [] });
  assert.equal(mapped.revision, 7);

  const acceptCalls = [];
  const { React } = createReactMock(['accept', false]);
  const { QuoteDetail } = transpileAndLoad('packages/ui/src/wholesale-portal/orders-screens.jsx', {
    react: React,
    '@alfarid/api-client': {
      wholesaleEndpoints: {
        quote: async () => ({}),
        acceptQuote: async (...args) => { acceptCalls.push(args); },
        rejectQuote: async () => {},
      },
    },
    './context': { useApp: () => ({ go: () => {}, toast: () => {}, refreshAccount: () => {} }) },
    './data/useApi': {
      useApi: () => ({
        state: 'ready',
        data: { id: 12, number: 'Q-12', status: 'priced', revision: 7, items: [] },
        reload: async () => {},
      }),
    },
    './data/DataBoundary': { DataBoundary: ({ children }) => children },
    './data/mappers': { mapOrder: (x) => x, mapQuote: (x) => x, mapReturn: (x) => x },
    './data/helpers': { apiError: (e) => e, pageMeta: () => ({}), saveBlob: () => {}, allPages: async () => [] },
    './data/validation': { validateReturnFiles: () => null },
    './data/constants': { ORDER_STATUS: {}, PAYMENT_METHOD: {}, QUOTE_STATUS: {}, RETURN_STATUS: {}, REFUND_METHOD: {} },
    './ui-primitives': {
      Async: ({ children }) => children, Badge: 'Badge', Crumb: 'Crumb', Empty: 'Empty', Money: 'Money', Modal: 'Modal',
      PageHead: ({ children }) => React.createElement('div', null, children), Pager: 'Pager', Seg: 'Seg', Stat: 'Stat',
      StatusBanner: 'StatusBanner', TabBar: 'TabBar',
    },
  });

  const tree = QuoteDetail({ id: 12 });
  const modal = findElements(tree, (n) => n.type === 'Modal')[0];
  assert.ok(modal, 'Confirm Modal should render when confirm === "accept"');
  const confirmBtn = findElements(modal.props.foot, (n) => n.type === 'button' && n.props.onClick && String(n.props.className || '').includes('is-dark'))[0];
  assert.ok(confirmBtn, 'Confirm button should exist');
  await confirmBtn.props.onClick();
  assert.deepEqual(acceptCalls, [[12, { expected_revision: 7 }]]);
});

test('F17: ReturnForm addFiles keeps setSel pure without calling validateReturnFiles/setErrs/toast inside updater', () => {
  const selState = { 101: { qty: 1, reason: '', files: [] } };
  let updaterCalled = false;
  let sideEffectDuringUpdater = false;
  let inUpdater = false;

  const stateValues = [
    1,
    selState,
    '',
    '',
    {},
    false,
  ];
  let stateIdx = 0;
  const React = {
    createElement(type, props, ...children) { return { type, props: props || {}, children }; },
    Fragment: Symbol('Fragment'),
    useState(initial) {
      const idx = stateIdx++;
      const val = idx < stateValues.length ? stateValues[idx] : initial;
      const setter = (arg) => {
        if (idx === 1 && typeof arg === 'function') {
          updaterCalled = true;
          inUpdater = true;
          arg(selState);
          inUpdater = false;
        }
      };
      return [val, setter];
    },
    useEffect() {},
  };
  React.default = React;
  React.__esModule = true;

  const { ReturnForm } = transpileAndLoad('packages/ui/src/wholesale-portal/orders-screens.jsx', {
    react: React,
    '@alfarid/api-client': { wholesaleEndpoints: { orders: async () => ({ data: [] }), order: async () => ({}) } },
    './context': {
      useApp: () => ({
        go: () => {},
        toast: () => { if (inUpdater) sideEffectDuringUpdater = true; },
        route: {},
      }),
    },
    './data/useApi': (() => {
      let call = 0;
      return {
        useApi: () => {
          call += 1;
          if (call === 1) return { state: 'ready', data: [{ id: 1, number: 'ORD-1', returnable: true, items: [] }] };
          return {
            state: 'ready',
            data: { id: 1, returnable: true, items: [{ id: 101, name: 'Bag', sku: 'B1', qty: 2 }] },
          };
        },
      };
    })(),
    './data/DataBoundary': { DataBoundary: ({ children }) => children },
    './data/mappers': { mapOrder: (x) => x, mapQuote: (x) => x, mapReturn: (x) => x },
    './data/helpers': { apiError: (e) => e, pageMeta: () => ({}), saveBlob: () => {}, allPages: async () => [] },
    './data/validation': {
      validateReturnFiles: () => {
        if (inUpdater) sideEffectDuringUpdater = true;
        return 'too many';
      },
    },
    './data/constants': { ORDER_STATUS: {}, PAYMENT_METHOD: {}, QUOTE_STATUS: {}, RETURN_STATUS: {}, REFUND_METHOD: {} },
    './ui-primitives': {
      Async: ({ children }) => children, Badge: 'Badge', Crumb: 'Crumb', Empty: 'Empty', Money: 'Money', Modal: 'Modal',
      PageHead: ({ children }) => React.createElement('div', null, children), Pager: 'Pager', Seg: 'Seg', Stat: 'Stat',
      StatusBanner: 'StatusBanner', TabBar: 'TabBar',
    },
  });

  const tree = ReturnForm({ orderId: 1 });
  const fileInputs = findElements(tree, (n) => n.type === 'input' && n.props.type === 'file');
  assert.equal(fileInputs.length, 1);
  fileInputs[0].props.onChange({ target: { files: [{ name: 'a.jpg', size: 100, type: 'image/jpeg' }], value: 'x' } });
  assert.equal(sideEffectDuringUpdater, false, 'validateReturnFiles/toast must not run inside setSel updater');
  assert.equal(updaterCalled, false, 'setSel should not need an impure updater');
});

test('F18: VariantModal tracks busy state and Catalog only closes modal when onAdd returns true', async () => {
  const { React, setStateCalls } = createReactMock([{ 10: 2 }, false]);
  const { VariantModal, Catalog } = transpileAndLoad('packages/ui/src/wholesale-portal/buy-screens.jsx', {
    react: React,
    '@alfarid/api-client': { wholesaleEndpoints: {} },
    './context': { useApp: () => ({ go: () => {}, toast: () => {}, addToCart: async () => false, applyCartResponse: () => {} }) },
    './data/useApi': { useApi: () => ({ state: 'ready', data: { items: [], meta: {} } }) },
    './data/DataBoundary': { DataBoundary: ({ children }) => children },
    './data/mappers': { mapProduct: (x) => x, mapQuickLookup: (x) => x, mapCart: (x) => x },
    './data/helpers': { apiError: (e) => e, fmt: (v) => String(v), tierPrice: () => 50, pageMeta: () => ({}), saveBlob: () => {} },
    './data/constants': { ORDER_STATUS: {} },
    './ui-primitives': {
      Async: ({ children }) => children, Empty: 'Empty', Money: 'Money', Modal: 'Modal',
      PageHead: 'PageHead', Pager: 'Pager', Qty: 'Qty', Seg: 'Seg', Thumb: 'Thumb',
    },
  });

  let closeCalls = 0;
  const product = { id: 1, name_ar: 'حقيبة', name_en: 'Bag', base: 100, variants: [{ id: 10, sku: 'S1', qty: 5, price: 80, tiers: [] }] };
  const tree = VariantModal({
    product,
    onClose: () => { closeCalls += 1; },
    onAdd: async () => {},
  });
  const modal = findElements(tree, (n) => n.type === 'Modal')[0];
  const submitBtn = findElements(modal.props.foot, (n) => n.type === 'button' && String(n.props.className || '').includes('is-dark'))[0];
  await submitBtn.props.onClick();
  assert.ok(setStateCalls.some((c) => c.arg === true) && setStateCalls.some((c) => c.arg === false), 'VariantModal must toggle busy true then false');
  assert.equal(closeCalls, 0);

  // Verify WholesalePortal.jsx addToCart returns boolean and Catalog checks it
  const portalSrc = fs.readFileSync(path.resolve(FRONTEND_ROOT, 'packages/ui/src/wholesale-portal/WholesalePortal.jsx'), 'utf8');
  assert.match(portalSrc, /return ok;/);
  const buySrc = fs.readFileSync(path.resolve(FRONTEND_ROOT, 'packages/ui/src/wholesale-portal/buy-screens.jsx'), 'utf8');
  assert.match(buySrc, /await addToCart\(/);
});

test('F22: AuthFrame does not render dead English language button', () => {
  const { React } = createReactMock();
  const { AuthFrame } = transpileAndLoad('packages/ui/src/wholesale-portal/auth-screens.jsx', {
    react: React,
    '../components/auth-halftone': { AuthHalftone: 'AuthHalftone' },
    '@alfarid/api-client': { wholesaleEndpoints: {} },
    './data/useApi': { useApi: () => ({}) },
    './data/DataBoundary': { DataBoundary: ({ children }) => children },
    './data/helpers': { apiError: (e) => e, day: (x) => x },
    './data/constants': { APP_STATUS: {}, DOC_STATUS: {}, DOC_TYPE: {} },
  });
  const tree = AuthFrame({ children: null });
  const langButtons = findElements(tree, (n) => n.type === 'button' && String(n.props.className || '').includes('luxury-lang-btn'));
  assert.equal(langButtons.length, 0, 'dead English button should be removed');
});

test('F24: orphan src/app/wholesale directory is removed', () => {
  const orphanDir = path.resolve(FRONTEND_ROOT, 'src/app/wholesale');
  assert.equal(fs.existsSync(orphanDir), false, 'orphan src/app/wholesale directory should not exist');
});

test('F16: QuickOrder lookup sends numeric and alphanumeric codes cleanly', async () => {
  const lookupCalls = [];
  const { React } = createReactMock([
    'manual',
    [{ code: '12345678', qty: '2' }, { code: 'BAG-01', qty: '3' }],
    false,
    false,
    false,
    null,
    null,
  ]);
  const { QuickOrder } = transpileAndLoad('packages/ui/src/wholesale-portal/buy-screens.jsx', {
    react: React,
    '@alfarid/api-client': {
      wholesaleEndpoints: {
        quickLookup: async (items) => {
          lookupCalls.push(items);
          return { data: [], meta: { total: 0, found: 0 } };
        },
      },
    },
    './context': { useApp: () => ({ go: () => {}, toast: () => {}, applyCartResponse: () => {} }) },
    './data/useApi': { useApi: () => ({ state: 'ready', data: {} }) },
    './data/DataBoundary': { DataBoundary: ({ children }) => children },
    './data/mappers': { mapProduct: (x) => x, mapQuickLookup: (x) => x, mapCart: (x) => x },
    './data/helpers': { apiError: (e) => e, fmt: (v) => String(v), tierPrice: () => 50, pageMeta: () => ({}), saveBlob: () => {} },
    './data/constants': { ORDER_STATUS: {} },
    './ui-primitives': {
      Async: ({ children }) => children, Empty: 'Empty', Money: 'Money', Modal: 'Modal',
      PageHead: ({ children }) => React.createElement('div', null, children), Pager: 'Pager', Qty: 'Qty', Seg: 'Seg', Thumb: 'Thumb',
    },
  });
  const tree = QuickOrder();
  const lookupBtn = findElements(tree, (n) => n.type === 'button' && String(n.props.className || '').includes('btn-block'))[0];
  assert.ok(lookupBtn, 'Lookup button should render');
  await lookupBtn.props.onClick();
  assert.deepEqual(lookupCalls, [[{ barcode: '12345678', qty: 2 }, { sku: 'BAG-01', qty: 3 }]]);
});
