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
  compiled._compile(
    ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
      compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText,
    filename,
  );
  return compiled.exports;
}

const m = load('apps/backoffice/src/pages/admin/operations-mock/api/mappers.ts');

// task.txt القسم 3 (واجهات): toDate بتوقيت القاهرة.
test('toDate converts to Africa/Cairo calendar day', () => {
  assert.equal(m.toDate('2026-10-27T22:30:00Z'), '2026-10-28');
  assert.equal(m.toDate('2026-01-15T10:00:00Z'), '2026-01-15');
  assert.equal(m.toDate(null), '');
  assert.equal(m.toDate(''), '');
  assert.equal(m.toDate('not-a-date'), '');
});

test('toTime formats Cairo wall time', () => {
  assert.equal(m.toTime('2026-01-15T10:00:00Z'), '12:00');
  assert.equal(m.toTime(null), '');
  assert.equal(m.toTime('bogus'), '');
});

test('num coerces blanks to zero', () => {
  assert.equal(m.num(null), 0);
  assert.equal(m.num(undefined), 0);
  assert.equal(m.num(''), 0);
  assert.equal(m.num('12.5'), 12.5);
  assert.equal(m.num(7), 7);
});

test('mapReturn normalizes refund methods and falls back to #id', () => {
  const base = { id: 3, return_number: 'R-1', order_id: 44, status: 'pending', total_refund_amount: '50.00', created_at: '2026-01-15T10:00:00Z' };
  assert.equal(m.mapReturn({ ...base, refund_method: 'original_payment' }).method, 'original');
  assert.equal(m.mapReturn({ ...base, refund_method: 'bank_transfer' }).method, 'bank');
  assert.equal(m.mapReturn({ ...base, refund_method: 'cash' }).method, 'cash');
  assert.equal(m.mapReturn({ ...base, refund_method: 'wallet' }).method, 'wallet');
  assert.equal(m.mapReturn({ ...base, refund_method: null }).method, null);
  assert.equal(m.mapReturn({ ...base, refund_method: null }).order, '#44');
  assert.equal(m.mapReturn({ ...base, order_number: 'O-9', branch_id: 5 }).branch, '#5');
  assert.equal(m.mapReturn({ ...base, branch_id: null }).branch, null);
});

test('promo and coupon type mappings round-trip', () => {
  assert.equal(m.promoToBackend('percent'), 'percentage_discount');
  assert.equal(m.promoToBackend('amount'), 'fixed_discount');
  assert.equal(m.promoToBackend('bxgy'), 'buy_x_get_y');
  assert.equal(m.promoToBackend('free_ship'), 'free_shipping');
  assert.equal(m.couponToBackend('percent'), 'percentage');
  assert.equal(m.couponToBackend('amount'), 'fixed');
  assert.equal(m.couponToBackend('free_ship'), 'free_shipping');
});

test('mapErrors remaps known keys and passes the rest through', () => {
  assert.deepEqual(m.mapErrors({ a: 'x', b: 'y' }, { a: 'A' }), { A: 'x', b: 'y' });
});
