const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

function finance() {
  const calls = [];
  const api = Object.fromEntries(['get', 'post', 'put', 'delete'].map(method => [method, async (url, body, config) => {
    calls.push({ method, url, body, config });
    return { data: { data: { id: 7, current_balance: '750.00' } } };
  }]));
  const filename = path.resolve(__dirname, '../packages/api-client/src/endpoints/operations/finance.ts');
  const compiled = new Module(filename, module);
  compiled.require = name => name === './http' ? {
    ADMIN: '/api/v1/admin', api,
    cleanParams: p => Object.fromEntries(Object.entries(p ?? {}).filter(([, v]) => v !== undefined && v !== '').map(([k, v]) => [k, typeof v === 'boolean' ? Number(v) : v])),
    one: r => r.data.data, asPage: r => r.data,
    crud: base => ({ list: (p, signal) => api.get(base, { params: p, signal }), create: body => api.post(base, body), update: (id, body) => api.put(`${base}/${id}`, body) }),
    newIdempotencyKey: () => 'finance-key', openProtectedFile: url => { calls.push({ method: 'file', url }); },
    downloadFile: (url, filename) => { calls.push({ method: 'download', url, filename }); },
  } : Module.prototype.require.call(compiled, name);
  compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText, filename);
  return { ...compiled.exports, calls };
}

test('quotation pricing posts actual offered prices and expiry to the admin quotation contract', async () => {
  const f = finance();
  const payload = { items: [{ id: 8, offered_unit_price: 125.5 }], valid_until: '2027-01-01', admin_notes: 'تسليم جزئي' };
  await f.quotationsApi.price(7, payload);
  assert.equal(f.calls[0].url, '/api/v1/admin/quotations/7/price');
  assert.deepEqual(f.calls[0].body, payload);
  await f.quotationsApi.expire(7);
  assert.equal(f.calls[1].url, '/api/v1/admin/quotations/7/expire');
});

test('payment notices preserve treasury and rejection notes and use protected receipt route', async () => {
  const f = finance();
  await f.paymentNoticesApi.approve(7, { treasury_id: 3, admin_notes: 'تمت المطابقة' });
  await f.paymentNoticesApi.reject(9, 'مرجع غير صحيح');
  await f.paymentNoticesApi.openReceipt(7);
  await f.paymentNoticesApi.downloadReceipt(7);
  assert.equal(f.calls[0].url, '/api/v1/admin/payment-notices/7/approve');
  assert.deepEqual(f.calls[0].body, { treasury_id: 3, admin_notes: 'تمت المطابقة' });
  assert.deepEqual(f.calls[1].body, { admin_notes: 'مرجع غير صحيح' });
  assert.equal(f.calls[2].url, '/api/v1/admin/payment-notices/7/receipt');
  assert.equal(f.calls[3].url, '/api/v1/admin/payment-notices/7/receipt');
  assert.equal(f.calls[3].filename, 'payment-notice-7-receipt');
});

function viewMappers() {
  const filename = path.resolve(__dirname, '../apps/backoffice/src/pages/admin/operations-mock/finance.tsx');
  const compiled = new Module(filename, module);
  compiled.paths = Module._nodeModulePaths(path.dirname(filename));
  compiled.require = name => name === './api/mappers' ? { num: value => Number(value ?? 0) }
    : name === './api/source' ? { USE_MOCK: false }
    : name.startsWith('./') || name === '@alfarid/api-client' ? {} : Module.prototype.require.call(compiled, name);
  compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.React } }).outputText, filename);
  return compiled.exports;
}

test('finance views use server balances, cashier relations and actual shift cash fields', () => {
  const { mapTreasury, mapExpense, mapShift } = viewMappers();
  const treasury = mapTreasury({ id: 3, code: 'TR-REAL', name: 'Bank', type: 'bank_account', current_balance: '952.75', branch_id: null, is_active: true });
  assert.equal(treasury.balance, 952.75);
  assert.equal(treasury.code, 'TR-REAL');
  assert.equal(treasury.type, 'bank');
  const shift = mapShift({ id: 9, shift_number: 'SH-REAL', cashier: { name: 'Sara' }, branch: { name: 'Cairo' }, treasury_id: 3, status: 'closed_pending_approval', opened_at: '2026-10-27T08:00:00Z', closed_at: '2026-10-27T16:00:00Z', expected_closing_cash: '900.50', actual_closing_cash: '880.25' });
  assert.equal(shift.cashier, 'Sara');
  assert.equal(shift.tr, 3);
  assert.equal(shift.status, 'closed');
  assert.equal(shift.act - shift.exp, -20.25);
  const expense = mapExpense({ id: 1, expense_number: 'EXP-REAL', branch: { name: 'Cairo' }, treasury_id: 3, creator: { name: 'Sara' }, expense_date: '2026-10-27', category: 'Rent', amount: '120.50', shift_id: 9 });
  assert.equal(expense.no, 'EXP-REAL');
  assert.equal(expense.by, 'Sara');
  assert.equal(expense.amount, 120.5);
});

test('wholesale views use real customer, grand total, converted order and receipt fields', () => {
  const { mapQuote, mapNotice } = viewMappers();
  const quote = mapQuote({ id: 6, quotation_number: 'Q-6', customer: { name: 'Ali', company_name: 'Real Company' }, status: 'converted_to_order', grand_total: '2500.50', valid_until: '2026-11-01', created_at: '2026-10-27', converted_order_id: 28 });
  assert.equal(quote.company, 'Real Company');
  assert.equal(quote.total, 2500.5);
  assert.equal(quote.order, '#28');
  const notice = mapNotice({ id: 5, notice_number: 'PN-5', customer: { name: 'Ali', company_name: 'Real Company' }, status: 'pending_review', amount: '120.75', payment_method: 'bank_transfer', transfer_date: '2026-10-26', reference_number: 'BANK-REF', bank_name: 'Real Bank', has_receipt: true, order_number: 'ORD-28', treasury_id: 3, admin_notes: null });
  assert.equal(notice.status, 'pending');
  assert.equal(notice.file, true);
  assert.equal(notice.ref, 'BANK-REF');
  assert.equal(notice.method, 'bank');
});

test('shift filters preserve dates and serialize booleans using the shared contract cleaner', async () => {
  const f = finance(), signal = new AbortController().signal;
  await f.shiftsApi.list({ page: 2, status: 'closed_pending_approval', from_date: '2026-10-01', to_date: '2026-10-27', unused: undefined }, signal);
  assert.deepEqual(f.calls[0].body.params, { page: 2, status: 'closed_pending_approval', from_date: '2026-10-01', to_date: '2026-10-27' });
  assert.equal(f.calls[0].body.signal, signal);
});
