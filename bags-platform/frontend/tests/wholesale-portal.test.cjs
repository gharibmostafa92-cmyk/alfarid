const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

function load(relative) {
  const filename = path.resolve(__dirname, '..', relative);
  const compiled = new Module(filename, module);
  compiled.filename = filename;
  compiled.paths = Module._nodeModulePaths(path.dirname(filename));
  compiled.require = name => name === '@alfarid/api-client'
    ? load('packages/api-client/src/endpoints/operations/http.ts')
    : name === '../../axios-instance' ? { defaultApiClient: {} }
    : name.startsWith('.')
    ? load(path.relative(path.resolve(__dirname, '..'), path.resolve(path.dirname(filename), `${name}.js`)))
    : Module.prototype.require.call(compiled, name);
  compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText, filename);
  return compiled.exports;
}

const root = 'packages/ui/src/wholesale-portal/data/';
const mappers = load(`${root}mappers.js`);

test('invoice money is normalized before rendering and keeps the server invoice number', () => {
  const invoice = mappers.mapInvoice({ id: 72, invoice_number: 'INV-72', order_number: 'ORD-72',
    created_at: '2026-11-03T12:00:00Z', subtotal: '142650.00', discount_amount: '650.00',
    shipping_cost: '120.00', tax_amount: '0.00', grand_total: '142120.00' });
  assert.equal(invoice.number, 'INV-72');
  assert.equal(invoice.total, 142120);
  assert.equal(invoice.discount, 650);
  assert.equal(invoice.date, '2026-11-03');
});

test('statement and checkout totals keep server values without deriving prices', () => {
  const statement = mappers.mapStatement({ opening_balance: '220.00', closing_balance: '310.00',
    entries: [{ date: '2026-11-02', reference: 'PN-2', description: 'Payment', debit: '0.00', credit: '90.00', balance: '310.00' }] });
  assert.equal(statement.opening_balance, 220);
  assert.deepEqual(statement.entries[0], { date: '2026-11-02', ref: 'PN-2', desc: 'Payment', debit: 0, credit: 90, bal: 310 });
  const shipping = mappers.mapShippingOptions({ options: [{ key: 'standard', cost: '120.00' }],
    payment_methods: ['credit'], preview: { items_total: '142000.00', coupon_discount: '0.00', shipping: '120.00', cod_fee: '0.00', tax: '0.00', grand_total: '142120.00' } });
  assert.equal(shipping.preview.grand_total, 142120);
  assert.equal(shipping.options[0].cost, 120);
});

test('lookup converts decimal strings so totals cannot concatenate', () => {
  const lookup = mappers.mapQuickLookup({ data: [{ code: 'BAG', qty: '2.00', available_qty: '10.00',
    unit_price: '90.00', line_total: '180.00', found: true, in_stock: true }], meta: { total: '180.00', found: 1 } });
  assert.equal(lookup.data[0].line_total, 180);
  assert.equal(lookup.meta.total, 180);
});

test('cart retains server totals and zero max quantity for an uncapped wholesale line', () => {
  const cart = mappers.mapCart({ id: 4, items: [{ id: 10, qty: '24', unit_price: '85.00', base_price: '100.00',
    line_total: '2040.00', max_qty: '0', available: true, priced: true }],
    totals: { subtotal: '2400.00', pricing_discount: '360.00', items_total: '2040.00', coupon_discount: '0.00', total: '2040.00' } });
  assert.equal(cart.lines[0].max_qty, 0);
  assert.equal(cart.lines[0].unit, 85);
  assert.equal(cart.totals.total, 2040);
});

test('return upload validation rejects excess files and size before making a request', () => {
  const { validateReturnFiles } = load(`${root}validation.js`);
  const image = { name: 'photo.jpg', type: 'image/jpeg', size: 1024 };
  assert.equal(validateReturnFiles([Array(5).fill(image), Array(5).fill(image)]), null);
  assert.match(validateReturnFiles([Array(6).fill(image)]), /5/);
  assert.match(validateReturnFiles([Array(4).fill(image), Array(4).fill(image), Array(3).fill(image)]), /10/);
  assert.match(validateReturnFiles([[{ ...image, size: 6 * 1024 * 1024 }]]), /5/);
  assert.match(validateReturnFiles([[{ ...image, type: 'image/svg+xml' }]]), /JPG/);
  assert.match(validateReturnFiles([Array(5).fill({ ...image, size: 5 * 1024 * 1024 }), [image]]), /25/);
});

test('dates use the local calendar at the start of a month', () => {
  const { calendarDay } = load(`${root}helpers.js`);
  const localMidnight = new Date(2026, 10, 1);
  assert.equal(calendarDay(localMidnight), '2026-11-01');
});

test('eligible order selectors read every API page and surface a failed later page', async () => {
  const { allPages } = load(`${root}helpers.js`);
  const pages = [];
  const controller = new AbortController();
  const rows = await allPages(async (page, signal) => {
    assert.equal(signal, controller.signal);
    pages.push(page);
    return { data: [{ id: page + 70 }], meta: { last_page: 3 } };
  }, controller.signal);
  assert.deepEqual(pages, [1, 2, 3]);
  assert.deepEqual(rows.map(row => row.id), [71, 72, 73]);
  await assert.rejects(allPages(async page => {
    if (page === 2) throw new Error('Page unavailable');
    return { data: [{ id: 1 }], meta: { last_page: 2 } };
  }), /Page unavailable/);
});

test('cancelled order history follows time order even when Laravel returns latest first', () => {
  const order = mappers.mapOrder({ status: 'cancelled', status_history: [
    { status: 'cancelled', at: '2026-11-03T15:00:00Z' },
    { status: 'confirmed', at: '2026-11-03T14:00:00Z' },
    { status: 'pending', at: '2026-11-03T13:00:00Z' },
  ] });
  assert.deepEqual(order.track.map(step => [step[0], step[2]]), [
    ['pending', 'done'], ['confirmed', 'done'], ['cancelled', 'now'],
  ]);
});
