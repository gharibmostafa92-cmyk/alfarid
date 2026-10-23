// Inventory integration suite: every screen against a mocked API whose fixtures
// mirror the backend Resources 1:1 (never the old mock shapes).
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const os = require('node:os');

const NOW = '2026-10-23T10:00:00.000Z';
let seq = 100;
let me = { id: 1, name: 'مدير المخزون', email: 'inv@example.test', roles: ['admin'], permissions: ['*'], is_active: true };

const branches = [
  { id: 1, name: 'المخزن الرئيسي', code: 'HQ', type: 'warehouse', phone: '0223456789', email: 'hq@example.test', address: 'القاهرة', city: 'القاهرة', city_id: null, is_active: true, locations_count: 2, employees_count: 3, created_at: NOW, updated_at: NOW },
  { id: 2, name: 'فرع مدينة نصر', code: 'BR-NSR', type: 'branch', phone: null, email: null, address: null, city: 'القاهرة', city_id: null, is_active: true, locations_count: 1, employees_count: 1, created_at: NOW, updated_at: NOW },
];
const locations = {
  1: [
    { id: 10, branch_id: 1, name: 'رف A-01', code: 'A-01', type: 'shelf', is_active: true, created_at: NOW, updated_at: NOW },
    { id: 11, branch_id: 1, name: 'منطقة الاستلام', code: 'RCV', type: 'receiving', is_active: true, created_at: NOW, updated_at: NOW },
  ],
  2: [{ id: 20, branch_id: 2, name: 'رف B-01', code: 'B-01', type: 'shelf', is_active: true, created_at: NOW, updated_at: NOW }],
};
const variant = (id, sku, ar, en) => ({ id, sku, barcode: null, product_id: 5, product_name_ar: ar, product_name_en: en });
const levels = [
  { id: 1, product_variant_id: 7, variant: variant(7, 'BAG-01', 'شنطة الفريد', 'Alfarid Bag'), branch: { id: 1, name: 'المخزن الرئيسي' }, location: { id: 10, name: 'رف A-01', code: 'A-01' }, on_hand: '100.0000', reserved: '10.0000', damaged: '2.0000', in_transit: '5.0000', available: '93.0000', reorder_level: '20.0000', is_low_stock: false, updated_at: NOW },
  { id: 2, product_variant_id: 8, variant: variant(8, 'BAG-02', 'شنطة سفر', 'Travel Bag'), branch: { id: 1, name: 'المخزن الرئيسي' }, location: null, on_hand: '3.0000', reserved: '0.0000', damaged: '0.0000', in_transit: '0.0000', available: '3.0000', reorder_level: '20.0000', is_low_stock: true, updated_at: NOW },
  { id: 3, product_variant_id: 7, variant: variant(7, 'BAG-01', 'شنطة الفريد', 'Alfarid Bag'), branch: { id: 2, name: 'فرع مدينة نصر' }, location: { id: 20, name: 'رف B-01', code: 'B-01' }, on_hand: '15.0000', reserved: '0.0000', damaged: '0.0000', in_transit: '0.0000', available: '15.0000', reorder_level: '5.0000', is_low_stock: false, updated_at: NOW },
];
const move = (id, type, qty, before, after, extra = {}) => ({
  id, reference_number: 'MV-' + id, product_variant_id: 7, variant: { id: 7, sku: 'BAG-01', product_id: 5 },
  branch: { id: 1, name: 'المخزن الرئيسي' }, location: { id: 10, name: 'رف A-01' }, type, affected_bucket: 'on_hand',
  quantity: qty, balance_before: before, balance_after: after, unit_cost: '75.0000',
  reference_type: null, reference_id: null, created_by: 1, creator_name: 'مدير المخزون', notes: null, created_at: NOW, ...extra,
});
const movementsPage1 = [move(1, 'production_receipt', '50.0000', '50.0000', '100.0000'), move(2, 'adjustment_in', '5.0000', '100.0000', '105.0000'), move(3, 'transfer_out', '-10.0000', '105.0000', '95.0000')];
const movementsPage2 = [move(4, 'sale', '-2.0000', '95.0000', '93.0000'), move(5, 'damage', '-2.0000', '95.0000', '93.0000', { affected_bucket: 'damaged' })];
let transfers = [{
  id: 1, version: 1, transfer_number: 'TRF-2026-00142', from_branch: { id: 1, name: 'المخزن الرئيسي' }, to_branch: { id: 2, name: 'فرع مدينة نصر' },
  status: 'draft', created_by: 1, creator_name: 'مدير المخزون', approved_by: null, shipped_at: null, received_at: null, notes: 'تغذية الفرع',
  items: [{ id: 11, product_variant_id: 7, sku: 'BAG-01', from_location: { id: 10, name: 'رف A-01' }, to_location: null, requested_qty: '10.0000', approved_qty: null, shipped_qty: null, received_qty: null }],
  created_at: NOW, updated_at: NOW,
}];
let stocktakes = [];
let receipts = [];
const seenKeys = new Map();
const payloads = [];
let largeCountScope = false;
let adjustCalls = 0, shipCalls = 0, approveCalls = 0;
const adjustKeys = [];
const rc = (data, total, per = 100) => ({ data, links: { first: null, last: null, prev: null, next: null }, meta: { current_page: 1, last_page: 1, per_page: per, total, from: data.length ? 1 : null, to: data.length || null } });

async function mockApi(context) {
  const requests = [];
  await context.route('**/api/v1/admin/**', async route => {
    const req = route.request(), url = new URL(req.url()), p = url.pathname, method = req.method();
    const seg = p.split('/').filter(Boolean);
    requests.push(method + ' ' + p);
    if (['POST', 'PUT', 'PATCH'].includes(method)) payloads.push({ path: p, method, body: req.postDataJSON(), key: req.headers()['idempotency-key'] });
    const bad = (status, message, errors = {}) => route.fulfill({ status, json: { message, errors } });
    if (p.endsWith('/me')) return route.fulfill({ json: { data: me } });
    if (p.endsWith('/production-receipts/options')) {
      const q = (url.searchParams.get('search') || '').trim();
      const all = [{ id: 7, label: 'شنطة الفريد / BAG-01' }, { id: 8, label: 'شنطة سفر / BAG-02' }];
      return route.fulfill({ json: { data: { branches: [{ id: 1, name: 'المخزن الرئيسي', locations: [{ id: 10, name: 'رف A-01' }, { id: 11, name: 'منطقة الاستلام' }] }, { id: 2, name: 'فرع مدينة نصر', locations: [{ id: 20, name: 'رف B-01' }] }], variants: q ? all.filter(v => v.label.includes(q)) : all.slice(0, 50) } } });
    }
    if (p.endsWith('/production-receipts') && method === 'POST') {
      const key = req.headers()['idempotency-key'];
      if (!key) return bad(422, 'Idempotency-Key مطلوب لمنع تكرار الاستلام.', { key: ['مطلوب'] });
      const data = req.postDataJSON();
      if (seenKeys.has(key)) return route.fulfill({ status: 201, json: { message: 'تم تسجيل استلام الإنتاج', data: seenKeys.get(key) } });
      const rec = { id: seq++, batch_number: data.batch_number, branch_id: data.branch_id, location_id: data.location_id ?? null, created_by: 1, notes: data.notes, items: data.items, created_at: NOW };
      seenKeys.set(key, rec); receipts.push(rec);
      return route.fulfill({ status: 201, json: { message: 'تم تسجيل استلام الإنتاج', data: rec } });
    }
    if (p.endsWith('/production-receipts')) return route.fulfill({ json: { data: receipts, current_page: 1, last_page: 1, per_page: 15, total: receipts.length } });
    const n = (v, d = null) => (v === null || v === undefined || v === '' ? d : Number(v));
    // ---- branches ----
    if (seg.at(-1) === 'branches' && method === 'GET') return route.fulfill({ json: rc(branches, branches.length) });
    if (seg.at(-1) === 'branches' && method === 'POST') {
      const b = req.postDataJSON();
      if (!b.name || !b.code) return bad(422, 'بيانات غير صالحة.', { ...(!b.name ? { name: ['الاسم مطلوب'] } : {}), ...(!b.code ? { code: ['الكود مطلوب'] } : {}) });
      if (branches.some(x => x.code === String(b.code).toUpperCase())) return bad(422, 'الكود مستخدم من قبل.', { code: ['الكود مستخدم من قبل.'] });
      const nb = { id: seq++, name: b.name, code: String(b.code).toUpperCase(), type: b.type || 'branch', phone: b.phone ?? null, email: b.email ?? null, address: b.address ?? null, city: b.city ?? null, city_id: null, is_active: b.is_active ?? true, locations_count: 0, employees_count: 0, created_at: NOW, updated_at: NOW };
      branches.push(nb); locations[nb.id] = [];
      return route.fulfill({ status: 201, json: { data: nb } });
    }
    if (seg.at(-2) === 'branches' && /^\d+$/.test(seg.at(-1))) {
      const br = branches.find(x => x.id === Number(seg.at(-1)));
      if (!br) return bad(404, 'العنصر المطلوب غير موجود');
      if (method === 'GET') return route.fulfill({ json: { data: br } });
      if (method === 'PUT') { Object.assign(br, req.postDataJSON(), { updated_at: NOW }); return route.fulfill({ json: { data: br } }); }
      if (method === 'DELETE') {
        if (br.id === 1) return bad(409, 'لا يمكن حذف الفرع لارتباطه بحركات مخزنية.');
        branches.splice(branches.indexOf(br), 1); delete locations[br.id];
        return route.fulfill({ status: 204, body: '' });
      }
    }
    // ---- locations (nested) ----
    const li = seg.indexOf('locations');
    if (li > 0) {
      const bid = Number(seg[li - 1]), list = locations[bid] || [];
      if (method === 'GET' && /^\d+$/.test(seg.at(-1)) === false) return route.fulfill({ json: rc(list, list.length) });
      if (method === 'POST') {
        const b = req.postDataJSON();
        if (!b.name || !b.code) return bad(422, 'بيانات غير صالحة.', {});
        if (list.some(l => l.code === String(b.code).toUpperCase())) return bad(422, 'الكود مستخدم داخل الفرع.', { code: ['الكود مستخدم داخل الفرع.'] });
        const nl = { id: seq++, branch_id: bid, name: b.name, code: String(b.code).toUpperCase(), type: b.type || 'shelf', is_active: b.is_active ?? true, created_at: NOW, updated_at: NOW };
        list.push(nl); const br = branches.find(x => x.id === bid); if (br) br.locations_count = list.length;
        return route.fulfill({ status: 201, json: { data: nl } });
      }
      const loc = list.find(x => x.id === Number(seg.at(-1)));
      if (!loc) return bad(404, 'العنصر المطلوب غير موجود');
      if (method === 'GET') return route.fulfill({ json: { data: loc } });
      if (method === 'PUT') { Object.assign(loc, req.postDataJSON(), { updated_at: NOW }); return route.fulfill({ json: { data: loc } }); }
      if (method === 'DELETE') {
        if (loc.id === 10) return bad(409, 'لا يمكن حذف الموقع لارتباطه بحركات مخزنية.');
        list.splice(list.indexOf(loc), 1);
        return route.fulfill({ status: 204, body: '' });
      }
    }
    // ---- stock levels ----
    if (p.endsWith('/inventory') && method === 'GET') {
      if (url.searchParams.has('low_stock')) assert.match(url.searchParams.get('low_stock'), /^[01]$/, 'Laravel boolean filters require 1/0');
      if (largeCountScope) {
        const page = Number(url.searchParams.get('page') || 1);
        const all = Array.from({ length: 501 }, (_, i) => ({ ...levels[0], id: i + 1000, product_variant_id: i + 1000, variant: { ...levels[0].variant, id: i + 1000, sku: 'SKU-' + i } }));
        return route.fulfill({ json: { ...rc(all.slice((page - 1) * 100, page * 100), 501), meta: { current_page: page, last_page: 6, total: 501, per_page: 100 } } });
      }
      let rows = levels.filter(r =>
        (n(url.searchParams.get('branch_id'), null) === null || r.branch.id === n(url.searchParams.get('branch_id'))) &&
        (n(url.searchParams.get('location_id'), null) === null || (r.location && r.location.id === n(url.searchParams.get('location_id')))) &&
        (n(url.searchParams.get('product_variant_id'), null) === null || r.product_variant_id === n(url.searchParams.get('product_variant_id'))) &&
        (url.searchParams.get('low_stock') === null || r.is_low_stock));
      const per = n(url.searchParams.get('per_page'), 15), pg = n(url.searchParams.get('page'), 1);
      const slice = rows.slice((pg - 1) * per, pg * per);
      return route.fulfill({ json: { data: slice, links: { first: null, last: null, prev: null, next: null }, meta: { current_page: pg, last_page: Math.max(1, Math.ceil(rows.length / per)), per_page: per, total: rows.length } } });
    }
    // ---- movements (cursor) ----
    if (p.endsWith('/inventory/movements') && method === 'GET') {
      const cur = url.searchParams.get('cursor');
      const second = cur === 'p2';
      const page = second ? movementsPage2 : movementsPage1;
      return route.fulfill({ json: { data: page, meta: { path: p, per_page: 15, next_cursor: second ? null : 'p2', prev_cursor: second ? 'p1' : null }, links: { first: null, last: null, prev: null, next: null } } });
    }
    // ---- adjustments ----
    if (p.endsWith('/inventory/adjustments') && method === 'POST') {
      adjustCalls++;
      const key = req.headers()['idempotency-key'];
      adjustKeys.push(key);
      assert.ok(key, 'adjustments require Idempotency-Key');
      const body = req.postDataJSON();
      const dec = (body.items || []).findIndex(it => ['decrease', 'write_off_damaged'].includes(it.adjustment_type) && Number(it.quantity) > 90);
      if (dec >= 0) return bad(422, 'الرصيد غير كافٍ لتنفيذ التسوية.', { ['items.' + dec + '.quantity']: ['الكمية تتجاوز الرصيد المتاح.'] });
      if (adjustCalls === 1 && body.items.length === 1 && body.items[0].adjustment_type === 'increase') return bad(409, 'المخزون مشغولة بعملية أخرى. أعد المحاولة بنفس المفتاح.');
      const out = (body.items || []).map((it, i) => move(seq++, it.adjustment_type === 'decrease' ? 'adjustment_out' : 'adjustment_in', String(it.quantity), '100.0000', '105.0000', { notes: it.reason || null }));
      return route.fulfill({ status: 201, json: { message: 'تم تنفيذ التسوية بنجاح', data: out } });
    }
    // ---- transfers ----
    if (seg.at(-1) === 'transfers' && method === 'GET') return route.fulfill({ json: rc(transfers, transfers.length) });
    if (seg.at(-1) === 'transfers' && method === 'POST') {
      const key = req.headers()['idempotency-key'];
      assert.ok(key, 'transfers require Idempotency-Key');
      const b = req.postDataJSON();
      const nt = { id: seq++, version: 1, transfer_number: 'TRF-2026-00' + seq, from_branch: branches.find(x => x.id === b.from_branch_id), to_branch: branches.find(x => x.id === b.to_branch_id), status: 'draft', created_by: 1, creator_name: 'مدير المخزون', approved_by: null, shipped_at: null, received_at: null, notes: b.notes || null, items: (b.items || []).map(it => ({ id: seq++, product_variant_id: it.product_variant_id, sku: 'BAG-0' + it.product_variant_id, from_location: it.from_location_id ? { id: it.from_location_id, name: 'رف' } : null, to_location: it.to_location_id ? { id: it.to_location_id, name: 'رف' } : null, requested_qty: String(it.requested_qty), approved_qty: null, shipped_qty: null, received_qty: null })), created_at: NOW, updated_at: NOW };
      transfers.push(nt);
      return route.fulfill({ status: 201, json: { message: 'تم إنشاء التحويل بنجاح', data: nt } });
    }
    const tix = seg.indexOf('transfers');
    if (tix > 0 && /^\d+$/.test(seg[tix + 1] || '')) {
      const t = transfers.find(x => x.id === Number(seg[tix + 1]));
      if (!t) return bad(404, 'العنصر المطلوب غير موجود');
      const action = seg[tix + 2];
      if (!action && method === 'GET') return route.fulfill({ json: { data: t } });
      if (!action && method === 'PUT') { const b = req.postDataJSON(); Object.assign(t, { notes: b.notes ?? t.notes, from_branch: branches.find(x => x.id === b.from_branch_id) || t.from_branch, to_branch: branches.find(x => x.id === b.to_branch_id) || t.to_branch, updated_at: NOW }); return route.fulfill({ json: { data: t } }); }
      if (!action && method === 'DELETE') { transfers.splice(transfers.indexOf(t), 1); return route.fulfill({ json: { message: 'تم حذف التحويل' } }); }
      if (action === 'cancel' && method === 'POST') { t.status = 'cancelled'; t.updated_at = NOW; return route.fulfill({ json: { message: 'تم إلغاء التحويل', data: t } }); }
      const stageMap = { approve: ['approved', 'approved_qty'], ship: ['shipped', 'shipped_qty'], receive: ['received', 'received_qty'] };
      if (stageMap[action] && method === 'POST') {
        const [st, qf] = stageMap[action];
        const b = req.postDataJSON();
        if (b.expected_version !== t.version) return bad(409, 'تم تعديل التحويل؛ أعد تحميله ثم راجع الكميات.');
        if (action === 'ship' && shipCalls++ === 0) return bad(409, 'تم تعديل التحويل؛ أعد تحميله ثم راجع الكميات.');
        for (const it of (b.items || [])) { const line = t.items.find(x => x.id === it.id); if (line) line[qf] = String(it[qf] ?? line[qf] ?? 0); }
        t.status = st; t.version++; t.updated_at = NOW;
        if (st === 'shipped') t.shipped_at = NOW; if (st === 'received') t.received_at = NOW;
        const msgs = { approved: 'تم اعتماد التحويل', shipped: 'تم شحن التحويل', received: 'تم استلام التحويل' };
        return route.fulfill({ json: { message: msgs[st], data: t } });
      }
    }
    // ---- stocktakes ----
    if (seg.at(-1) === 'stocktakes' && method === 'GET') return route.fulfill({ json: rc(stocktakes, stocktakes.length) });
    if (seg.at(-1) === 'stocktakes' && method === 'POST') {
      const key = req.headers()['idempotency-key'];
      assert.ok(key, 'stocktakes require Idempotency-Key');
      const b = req.postDataJSON();
      const items = (b.items || []).map(it => ({ id: seq++, product_variant_id: it.product_variant_id, sku: 'BAG-0' + it.product_variant_id, system_qty: '50.0000', counted_qty: it.counted_qty ?? null, difference: null }));
      const st = { id: seq++, stocktake_number: 'CNT-2026-00' + seq, branch: branches.find(x => x.id === b.branch_id), location: b.location_id ? { id: b.location_id, name: 'رف A-01' } : null, status: 'draft', items_count: items.length, created_by: 1, creator_name: 'مدير المخزون', approved_by: null, approved_at: null, notes: b.notes || null, items, created_at: NOW, updated_at: NOW };
      stocktakes.push(st);
      return route.fulfill({ status: 201, json: { message: 'تم إنشاء الجرد', data: st } });
    }
    const six = seg.indexOf('stocktakes');
    if (six > 0 && /^\d+$/.test(seg[six + 1] || '')) {
      const st = stocktakes.find(x => x.id === Number(seg[six + 1]));
      if (!st) return bad(404, 'العنصر المطلوب غير موجود');
      const action = seg[six + 2];
      if (!action && method === 'GET') return route.fulfill({ json: { data: st } });
      if (!action && method === 'DELETE') { stocktakes.splice(stocktakes.indexOf(st), 1); return route.fulfill({ json: { message: 'تم حذف الجرد' } }); }
      if (!action && method === 'PUT') { Object.assign(st, req.postDataJSON(), { updated_at: NOW }); return route.fulfill({ json: { data: st } }); }
      if (action === 'items' && method === 'PATCH') {
        for (const it of (req.postDataJSON().items || [])) {
          const line = st.items.find(x => x.product_variant_id === it.product_variant_id);
          if (line) { line.counted_qty = it.counted_qty == null ? null : Number(it.counted_qty).toFixed(4); line.difference = line.counted_qty === null ? null : String((Number(line.counted_qty) - Number(line.system_qty)).toFixed(4)); }
          else st.items.push({ id: seq++, product_variant_id: it.product_variant_id, sku: 'BAG-0' + it.product_variant_id, system_qty: '0.0000', counted_qty: it.counted_qty == null ? null : Number(it.counted_qty).toFixed(4), difference: it.counted_qty == null ? null : Number(it.counted_qty).toFixed(4) });
        }
        st.items_count = st.items.length;
        return route.fulfill({ json: { data: st } });
      }
      if (action === 'approve' && method === 'POST') {
        if (st.items.some(x => x.counted_qty === null)) return bad(422, 'لا يمكن الاعتماد: توجد عناصر لم تُعدّ بعد.', {});
        approveCalls++;
        if (approveCalls === 1) return bad(422, 'توجد أصناف تغيّر رصيدها بعد عدّها. راجع الجرد وأعد عدّها.', { recount_item_ids: [st.items[0].id], recount_variant_ids: [st.items[0].product_variant_id] });
        st.status = 'approved'; st.approved_at = NOW; st.approved_by = 1;
        return route.fulfill({ json: { message: 'تم اعتماد الجرد وترحيل الفروق', data: st } });
      }
    }
    return route.fulfill({ status: 500, json: { message: 'Unexpected inventory request: ' + method + ' ' + p } });
  });
  return requests;
}

(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const errors = [];
  let page;
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await context.addInitScript(() => localStorage.setItem('backoffice_token', 'inventory-integration-test'));
    const requests = await mockApi(context);
    page = await context.newPage();
    page.setDefaultTimeout(12000);
    page.on('pageerror', e => errors.push(e.message));
    const base = process.env.BACKOFFICE_URL || 'http://127.0.0.1:5173/dashboard';
    const screen = page.locator('[data-inventory-screen]');
    const visit = async (pg, sc, route, title) => {
      await pg.evaluate(r => { location.hash = '/inventory/' + r; }, route);
      await sc.locator('h1').filter({ hasText: title }).waitFor();
      await pg.waitForTimeout(600);
    };
    const modal = () => page.locator('.modal-in');
    const pickVariant = async (scope, label) => {
      const [nm, sku] = label.split('/').map(s => s.trim());
      await scope.getByRole('button', { name: /ابحث|كل الأصناف|أضف SKU|ابحث بالاسم/ }).first().click();
      await scope.getByPlaceholder(/اسم المنتج/).fill(nm);
      await scope.getByRole('button', { name: new RegExp(sku.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&')) }).click();
    };
    await page.goto(base + '#/inventory/stock', { waitUntil: 'domcontentloaded' });
    await screen.locator('h1').filter({ hasText: 'أرصدة المخزون' }).waitFor();

    if (!process.env.INVENTORY_ERRORS_ONLY) {
    // ---- branches CRUD + 409 delete + disable instead ----
    await visit(page, screen, 'branches', 'الفروع والمخازن');
    await screen.getByText('المخزن الرئيسي', { exact: true }).waitFor();
    await screen.getByRole('button', { name: /إضافة فرع/ }).click();
    await modal().waitFor();
    await modal().getByLabel(/الاسم/).fill('مخزن التجربة');
    await modal().getByLabel(/الكود/).fill('tst-1');
    await modal().getByLabel(/المدينة/).fill('القاهرة');
    await modal().getByRole('button', { name: /تخزين وتوزيع فقط/ }).click();
    await modal().getByRole('button', { name: 'إضافة', exact: true }).click();
    await screen.getByText('مخزن التجربة', { exact: true }).waitFor();
    assert.equal(branches.find(b => b.code === 'TST-1').type, 'warehouse');
    const hqRow = screen.getByRole('row', { name: /HQ/ });
    await hqRow.getByTitle('حذف').click();
    await modal().getByRole('button', { name: 'حذف نهائي', exact: true }).click();
    await modal().getByText('لا يمكن حذف الفرع').waitFor();
    await modal().getByRole('button', { name: 'تعطيل بدلًا من الحذف', exact: true }).click();
    await screen.getByText('غير نشط', { exact: true }).first().waitFor();
    await hqRow.getByTitle('تعديل').click();
    await modal().locator('.vtoggle').click();
    await modal().getByRole('button', { name: 'حفظ التعديلات', exact: true }).click();
    await modal().waitFor({ state: 'hidden' });
    const tRow = screen.getByRole('row', { name: /TST-1/ });
    await tRow.getByTitle('حذف').click();
    await modal().getByRole('button', { name: 'حذف نهائي', exact: true }).click();
    await modal().waitFor({ state: 'hidden' });
    await screen.getByText('مخزن التجربة', { exact: true }).waitFor({ state: 'hidden' });
    assert.equal(await screen.getByText('مخزن التجربة', { exact: true }).count(), 0);

    // ---- locations ----
    await visit(page, screen, 'locations?branchId=1', 'مواقع التخزين');
    await screen.getByText('رف A-01', { exact: true }).waitFor();
    await screen.getByRole('button', { name: /إضافة موقع/ }).click();
    await modal().getByLabel(/الاسم/).fill('رف تجربة');
    await modal().getByLabel(/الكود/).fill('t-9');
    await modal().getByRole('button', { name: 'إضافة', exact: true }).click();
    await screen.getByText('رف تجربة', { exact: true }).waitFor();
    await screen.getByRole('button', { name: /إضافة موقع/ }).click();
    await modal().getByLabel(/الاسم/).fill('مكرر');
    await modal().getByLabel(/الكود/).fill('t-9');
    await modal().getByRole('button', { name: 'إضافة', exact: true }).click();
    await modal().getByText('الكود مستخدم داخل الفرع.', { exact: true }).last().waitFor();
    await modal().getByRole('button', { name: 'إلغاء', exact: true }).click();
    const locationRow = screen.getByRole('row', { name: /T-9/ });
    await locationRow.getByTitle('تعديل').click();
    await modal().getByLabel(/الاسم/).fill('رف معدل');
    await modal().getByRole('button', { name: 'حفظ', exact: true }).click();
    await screen.getByText('رف معدل', { exact: true }).waitFor();
    await locationRow.getByTitle('حذف').click();
    await modal().getByRole('button', { name: 'حذف', exact: true }).click();
    await modal().waitFor({ state: 'hidden' });

    // ---- view-only user: action buttons hidden ----
    me = { ...me, roles: ['staff'], permissions: ['inventory.view', 'branches.view', 'locations.view', 'transfers.view', 'stocktakes.view'] };
    await page.goto(base + '#/inventory/stock'); await page.reload();
    await screen.locator('h1').filter({ hasText: 'أرصدة المخزون' }).waitFor();
    assert.equal(await screen.getByRole('button', { name: 'تسوية جديدة' }).count(), 0);
    await visit(page, screen, 'branches', 'الفروع والمخازن');
    assert.equal(await screen.getByRole('button', { name: /إضافة فرع/ }).count(), 0);
    await visit(page, screen, 'adjust', 'تسوية المخزون');
    await screen.getByText('لا تملك صلاحية الوصول').waitFor();
    await visit(page, screen, 'moves', 'حركات المخزون');
    assert.equal(await screen.getByRole('columnheader', { name: 'تكلفة الوحدة' }).count(), 0);

    // ---- branch-restricted user: locked to their branch ----
    me = { ...me, branch_id: 2, permissions: ['inventory.view', 'inventory.adjust', 'branches.view', 'locations.view', 'transfers.view', 'stocktakes.view', 'products.view_cost'] };
    await page.goto(base + '#/inventory/stock'); await page.reload();
    await screen.locator('h1').filter({ hasText: 'أرصدة المخزون' }).waitFor();
    const branchSel = screen.locator('select').first();
    assert.equal(await branchSel.isDisabled(), true);
    await screen.getByText('فرع مدينة نصر', { exact: true }).first().waitFor();
    assert.equal(await branchSel.locator('option[value="1"]').count(), 0);
    await visit(page, screen, 'counts', 'الجرد');
    assert.equal(await screen.locator('select').first().isDisabled(), true);
    await visit(page, screen, 'production', 'استلام الإنتاج');
    await screen.getByRole('button', { name: /تسجيل استلام/ }).first().click();
    assert.equal(await modal().getByLabel(/الفرع/).isDisabled(), true);
    assert.equal(await modal().getByLabel(/الفرع/).inputValue(), '2');
    await modal().getByRole('button', { name: 'إلغاء', exact: true }).click();

    // ---- stock filters + deep link ----
    me = { id: 1, name: 'مدير المخزون', email: 'inv@example.test', roles: ['admin'], permissions: ['*'], is_active: true };
    await page.goto(base + '#/inventory/stock'); await page.reload();
    await screen.locator('h1').filter({ hasText: 'أرصدة المخزون' }).waitFor();
    assert.equal(await screen.getByRole('row').count(), 4); // header + 3 levels
    await screen.getByText('منخفض', { exact: true }).first().waitFor();
    await screen.locator('select').first().selectOption('2');
    await screen.getByText('رف B-01', { exact: true }).waitFor();
    assert.equal(await screen.getByRole('row').count(), 2);
    await screen.getByTitle('تسوية', { exact: true }).click();
    await screen.getByLabel(/الفرع/).waitFor();
    assert.equal(await screen.getByLabel(/الفرع/).inputValue(), '2');
    assert.equal(await screen.getByLabel(/موقع التخزين/).inputValue(), '20');
    await visit(page, screen, 'stock?low=1', 'أرصدة المخزون');
    assert.equal(await screen.getByRole('row').count(), 2);
    await visit(page, screen, 'stock', 'أرصدة المخزون');
    await pickVariant(screen, 'شنطة الفريد / BAG-01');
    await page.waitForFunction(() => location.hash.includes('variantId=7'));
    assert.equal(await screen.getByRole('row').count(), 3); // header + 2 rows variant 7

    // ---- movements cursor ----
    await visit(page, screen, 'moves', 'حركات المخزون');
    await screen.getByText('MV-1', { exact: true }).waitFor();
    await screen.getByRole('button', { name: /التالي/ }).click();
    await screen.getByText('MV-4', { exact: true }).waitFor();
    await screen.getByRole('button', { name: /السابق/ }).click();
    await screen.getByText('MV-1', { exact: true }).waitFor();

    // ---- adjustments: 409 lock retry reuses the same key, then 5 types, then insufficient ----
    const fillAdjustLine = async (line, typeLabel, variantLabel, qty, reason) => {
      await line.getByRole('combobox').first().selectOption({ label: typeLabel });
      await pickVariant(line, variantLabel);
      await line.getByPlaceholder('0.0000').fill(qty);
      await line.getByPlaceholder(/مثال/).fill(reason);
    };
    await visit(page, screen, 'adjust', 'تسوية المخزون');
    await screen.locator('select').first().selectOption('1');
    const firstLine = screen.getByRole('row').nth(1);
    await pickVariant(firstLine, 'شنطة الفريد / BAG-01');
    await firstLine.getByPlaceholder('0.0000').fill('٥٫٥');
    await firstLine.getByPlaceholder(/مثال/).fill('تجربة قفل');
    await screen.getByRole('button', { name: 'تنفيذ التسوية', exact: true }).click();
    await modal().getByRole('button', { name: 'تنفيذ الآن', exact: true }).click();
    await modal().getByText('مشغولة بعملية أخرى').waitFor();
    await modal().getByRole('button', { name: 'تنفيذ الآن', exact: true }).click();
    await page.getByText('تم تنفيذ التسوية بنجاح').waitFor();
    assert.equal(adjustKeys[0], adjustKeys[1], 'lock retry must reuse the same Idempotency-Key');
    assert.equal(payloads.find(p => p.path.endsWith('/adjustments')).body.items[0].quantity, 5.5);
    await visit(page, screen, 'adjust', 'تسوية المخزون');
    await screen.locator('select').first().selectOption('1');
    const types = [['زيادة', 'شنطة الفريد / BAG-01', '10', 'زيادة تجربة'], ['نقصان', 'شنطة سفر / BAG-02', '2', 'نقصان تجربة'], ['تسجيل تالف', 'شنطة الفريد / BAG-01', '1', 'تالف تجربة'], ['إعدام تالف', 'شنطة سفر / BAG-02', '1', 'إعدام تجربة'], ['استرجاع من التالف', 'شنطة الفريد / BAG-01', '1', 'استرجاع تجربة']];
    for (let i = 0; i < types.length; i++) {
      if (i > 0) await screen.getByRole('button', { name: /إضافة بند/ }).click();
      await fillAdjustLine(screen.getByRole('row').nth(i + 1), ...types[i]);
    }
    await screen.getByRole('button', { name: 'تنفيذ التسوية', exact: true }).click();
    await modal().getByRole('button', { name: 'تنفيذ الآن', exact: true }).click();
    await page.getByText('تم تنفيذ التسوية بنجاح').waitFor();
    await visit(page, screen, 'adjust', 'تسوية المخزون');
    await screen.locator('select').first().selectOption('1');
    const badLine = screen.getByRole('row').nth(1);
    await badLine.getByRole('combobox').first().selectOption({ label: 'نقصان' });
    await pickVariant(badLine, 'شنطة الفريد / BAG-01');
    await badLine.getByPlaceholder('0.0000').fill('999');
    await badLine.getByPlaceholder(/مثال/).fill('رصيد غير كاف');
    await screen.getByRole('button', { name: 'تنفيذ التسوية', exact: true }).click();
    await modal().getByRole('button', { name: 'تنفيذ الآن', exact: true }).click();
    await modal().getByText('الرصيد غير كافٍ').waitFor();
    await modal().getByText('تتجاوز الرصيد المتاح').waitFor();
    await modal().getByRole('button', { name: 'تراجع', exact: true }).click();

    // ---- transfers: full lifecycle, 409 version conflict, cancel rules ----
    await visit(page, screen, 'transfers', 'التحويلات المخزنية');
    await screen.getByRole('button', { name: /تحويل جديد/ }).click();
    await screen.getByLabel(/فرع المصدر/).selectOption('1');
    await screen.getByLabel(/فرع الوجهة/).selectOption('2');
    const trLine = screen.getByRole('row').nth(1);
    await pickVariant(trLine, 'شنطة الفريد / BAG-01');
    await trLine.getByPlaceholder('0.0000').fill('10');
    await screen.getByRole('button', { name: 'حفظ كمسودة', exact: true }).click();
    await screen.locator('h1').filter({ hasText: /TRF-/ }).waitFor();
    await screen.getByRole('button', { name: 'اعتماد', exact: true }).click();
    await screen.getByRole('button', { name: 'تأكيد الاعتماد', exact: true }).click();
    await modal().getByRole('button', { name: 'تأكيد', exact: true }).click();
    await page.getByText('تم اعتماد التحويل').waitFor();
    await screen.getByRole('button', { name: 'شحن', exact: true }).click();
    await screen.getByRole('button', { name: 'تأكيد الشحن', exact: true }).click();
    await modal().getByRole('button', { name: 'تأكيد', exact: true }).click();
    await screen.getByText('تعارض — تم تعديل التحويل').waitFor(); // 409 conflict banner
    await screen.getByRole('button', { name: /إعادة تحميل/ }).click();
    await screen.getByRole('button', { name: 'شحن', exact: true }).click();
    await screen.getByRole('button', { name: 'تأكيد الشحن', exact: true }).click();
    await modal().getByRole('button', { name: 'تأكيد', exact: true }).click();
    await page.getByText('تم شحن التحويل').waitFor();
    await screen.getByRole('button', { name: 'استلام', exact: true }).click();
    assert.equal(await screen.getByRole('button', { name: 'إلغاء التحويل', exact: true }).count(), 0);
    await screen.getByPlaceholder('0').first().fill('8');
    await screen.getByRole('button', { name: 'تأكيد الاستلام', exact: true }).click();
    await modal().getByText('يوجد عجز في الاستلام').waitFor();
    await modal().getByRole('button', { name: 'تأكيد', exact: true }).click();
    await page.getByText('تم استلام التحويل').waitFor();
    await visit(page, screen, 'transfers', 'التحويلات المخزنية');
    await screen.getByRole('button', { name: /تحويل جديد/ }).click();
    await screen.getByLabel(/فرع المصدر/).selectOption('1');
    await screen.getByLabel(/فرع الوجهة/).selectOption('2');
    await pickVariant(screen.getByRole('row').nth(1), 'شنطة الفريد / BAG-01');
    await screen.getByRole('row').nth(1).getByPlaceholder('0.0000').fill('3');
    await screen.getByRole('button', { name: 'حفظ كمسودة', exact: true }).click();
    await screen.locator('h1').filter({ hasText: /TRF-/ }).waitFor();
    await screen.getByRole('button', { name: 'إلغاء التحويل', exact: true }).click();
    await modal().getByRole('button', { name: 'إلغاء التحويل', exact: true }).click();
    await page.getByText('تم إلغاء التحويل').waitFor();
    assert.equal(await screen.getByRole('button', { name: 'شحن', exact: true }).count(), 0);

    // ---- stocktakes: zero vs uncounted, approve guards, stale recount ----
    await visit(page, screen, 'counts', 'الجرد');
    await screen.getByRole('button', { name: /جرد جديد/ }).first().click();
    await modal().getByLabel(/الفرع/).selectOption('1');
    await modal().getByRole('button', { name: /إنشاء وبدء العدّ/ }).click();
    await screen.locator('h1').filter({ hasText: /CNT-/ }).waitFor();
    const cntRows = screen.getByRole('row');
    await cntRows.nth(1).getByPlaceholder(/لم يُعدّ/).fill('50');
    page.once('dialog', d => d.dismiss());
    await page.evaluate(() => { location.hash = '/inventory/stock'; });
    await page.waitForTimeout(150);
    assert.match(await page.evaluate(() => location.hash), /inventory\/count\?/);
    await screen.getByRole('button', { name: /حفظ العدّ/ }).click();
    await page.getByText('تم حفظ العدّ').waitFor();
    const firstSave = payloads.filter(p => p.method === 'PATCH' && /stocktakes.*items$/.test(p.path)).at(-1);
    assert.equal(firstSave.body.items.length, 1, 'save only changed quantities');
    assert.equal(await screen.getByRole('button', { name: /اعتماد الجرد/ }).isDisabled(), true, 'approve stays disabled until every line is counted');
    await cntRows.nth(2).getByRole('button', { name: 'صفر', exact: true }).click();
    await screen.getByRole('button', { name: /حفظ العدّ/ }).click();
    await page.getByText('تم حفظ العدّ').waitFor();
    assert.equal(payloads.filter(p => p.method === 'PATCH' && /stocktakes.*items$/.test(p.path)).at(-1).body.items.length, 1, 'unchanged decimal-string quantity must stay clean');
    await screen.getByRole('button', { name: /اعتماد الجرد/ }).click();
    await modal().getByRole('button', { name: /اعتماد وترحيل/, exact: true }).click();
    await page.getByText('تحتاج إعادة عدّ').first().waitFor();
    await modal().getByRole('button', { name: 'تراجع', exact: true }).click();
    await screen.getByRole('button', { name: 'إعادة العدّ', exact: true }).first().click();
    await page.getByText('تم حفظ العدّ').waitFor();
    await screen.getByRole('button', { name: /اعتماد الجرد/ }).click();
    await modal().getByRole('button', { name: /اعتماد وترحيل/, exact: true }).click();
    await page.getByText('تم اعتماد الجرد وترحيل الفروق').waitFor();
    assert.equal(await screen.getByPlaceholder(/لم يُعدّ/).count(), 0, 'approved count is read-only');

    // More than 500 distinct variants: POST first chunk, PATCH remainder.
    largeCountScope = true;
    await visit(page, screen, 'counts', 'الجرد');
    await screen.getByRole('button', { name: /جرد جديد/ }).first().click();
    await modal().getByLabel(/الفرع/).selectOption('1');
    await modal().getByText('501', { exact: true }).waitFor();
    await modal().getByRole('button', { name: /إنشاء وبدء العدّ/ }).click();
    await screen.locator('h1').filter({ hasText: /CNT-/ }).waitFor();
    assert.equal(stocktakes.at(-1).items.length, 501);
    assert.equal(payloads.filter(p => p.method === 'POST' && p.path.endsWith('/stocktakes')).at(-1).body.items.length, 500);
    largeCountScope = false;
    await screen.getByRole('button', { name: 'إلغاء الجرد', exact: true }).click();
    await modal().getByRole('button', { name: 'إلغاء الجرد', exact: true }).click();
    await page.getByText('تم إلغاء الجرد', { exact: true }).waitFor();
    await screen.getByRole('button', { name: 'حذف الجرد', exact: true }).click();
    await modal().getByRole('button', { name: 'حذف الجرد', exact: true }).click();
    await screen.locator('h1').filter({ hasText: 'الجرد' }).waitFor();

    // ---- production receipts: Idempotency-Key required, no duplicates ----
    await visit(page, screen, 'production', 'استلام الإنتاج');
    await screen.getByRole('button', { name: /تسجيل استلام/ }).first().click();
    await modal().getByLabel(/رقم الدفعة/).fill('B-INT-1');
    await modal().getByLabel(/الفرع/).selectOption('1');
    await pickVariant(modal(), 'شنطة الفريد / BAG-01');
    await modal().getByPlaceholder('0', { exact: true }).first().fill('12');
    await modal().getByPlaceholder('0.00', { exact: true }).first().fill('75');
    const prodPosts = [];
    const prodRoute = requests.filter(r => r === 'POST /api/v1/admin/production-receipts').length;
    await modal().getByRole('button', { name: /تسجيل الاستلام/, exact: true }).dblclick();
    await page.getByText('تم تسجيل استلام الإنتاج').waitFor();
    await screen.getByText('B-INT-1', { exact: true }).waitFor();
    assert.equal(requests.filter(r => r === 'POST /api/v1/admin/production-receipts').length, prodRoute + 1, 'one receipt POST');
    assert.equal(await screen.getByRole('row', { name: /B-INT-1/ }).count(), 1, 'no duplicate receipt rows');
    void prodPosts;

    }
    // ---- RTL, themes, mobile, no errors ----
    assert.equal(await page.evaluate(() => document.documentElement.dir), 'rtl');
    await page.screenshot({ timeout: 60000, path: path.join(os.tmpdir(), 'inventory-desktop.png'), fullPage: true, animations: 'disabled' });
    await page.getByTitle('Dark Mode', { exact: true }).click();
    await page.locator('#theme-wipe-overlay').waitFor({ state: 'hidden' });
    await page.screenshot({ timeout: 60000, path: path.join(os.tmpdir(), 'inventory-dark.png'), fullPage: true, animations: 'disabled' });
    await page.getByTitle('Light Mode', { exact: true }).click();
    await page.locator('#theme-wipe-overlay').waitFor({ state: 'hidden' });
    await page.setViewportSize({ width: 390, height: 844 });
    await visit(page, screen, 'stock', 'أرصدة المخزون');
    await page.screenshot({ timeout: 60000, path: path.join(os.tmpdir(), 'inventory-mobile.png'), fullPage: true, animations: 'disabled' });

    // Each read failure gets its own page, so aborted requests from a previous
    // failure cannot race the next case. Responses still mirror the API contract.
    for (const status of [403, 404, 409, 'network', 'empty']) {
      const statePage = await context.newPage();
      statePage.setDefaultTimeout(20000);
      statePage.on('pageerror', e => errors.push(e.message));
      let readFailure = status;
      await statePage.route('**/api/v1/admin/inventory**', async route => {
        if (!new URL(route.request().url()).pathname.endsWith('/inventory')) return route.fallback();
        if (readFailure === 'network') return route.abort('failed');
        if (readFailure === 'empty') return route.fulfill({ json: rc([], 0) });
        if (readFailure) return route.fulfill({ status: readFailure, json: { message: 'رسالة الخادم للاختبار ' + readFailure } });
        return route.fallback();
      });
      await statePage.goto(base + '#/inventory/stock', { waitUntil: 'domcontentloaded' });
      const stateScreen = statePage.locator('[data-inventory-screen]');
      try {
        if (typeof status === 'number') {
          await stateScreen.getByText('رسالة الخادم للاختبار ' + status, { exact: true }).waitFor();
          if (status === 404) await stateScreen.getByRole('button', { name: 'رجوع للقائمة', exact: true }).waitFor();
          if (status === 409) {
            readFailure = null;
            await stateScreen.getByRole('button', { name: 'إعادة تحميل', exact: true }).click();
            await stateScreen.getByRole('row', { name: /BAG-01/ }).first().waitFor();
          }
        } else if (status === 'network') {
          await stateScreen.getByText(/تعذّر الاتصال/).waitFor();
          readFailure = null;
          await stateScreen.getByRole('button', { name: 'إعادة المحاولة', exact: true }).click();
          await stateScreen.getByRole('row', { name: /BAG-01/ }).first().waitFor();
        } else await stateScreen.getByText('لا توجد أرصدة مسجّلة بعد', { exact: true }).waitFor();
      } catch (error) {
        console.error('Read state ' + status + ':', await stateScreen.innerText().catch(() => 'unavailable'));
        throw error;
      } finally { await statePage.close(); }
    }
    assert.deepEqual(errors, []);
    console.log(process.env.INVENTORY_ERRORS_ONLY
      ? 'PASS: inventory read failures — 403/404/409/network/empty/retry, RTL/themes/mobile, no pageerror'
      : 'PASS: inventory integration — branches/locations CRUD+409, view-only, restriction, stock, moves cursor, adjustments (5 types/insufficient/lock retry), transfers lifecycle+409+cancel, stocktakes (zero/uncounted/stale/501/cancel/delete), production double-click dedupe, read failures/retry, RTL/themes/mobile, no pageerror');
  } catch (error) {
    console.error('Inventory screen at failure:', await page?.locator('[data-inventory-screen]').innerText({ timeout: 1000 }).catch(() => 'unavailable'));
    throw error;
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
