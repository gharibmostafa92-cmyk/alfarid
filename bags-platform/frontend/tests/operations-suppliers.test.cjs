const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), Module = require('node:module'), ts = require('typescript');
function load(relative, deps = {}) {
 const filename = path.resolve(__dirname, '..', relative), m = new Module(filename, module);
 m.filename = filename; m.paths = Module._nodeModulePaths(path.dirname(filename));
 m.require = n => n in deps ? deps[n] : Module.prototype.require.call(m, n);
 m._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }}).outputText, filename); return m.exports;
}
test('procurement preserves receipt item ids and retry keys', async () => {
 const calls = [], client = { post: async (...args) => { calls.push(args); return {data: {data: {id: 9, status: 'partially_received'}}}; }};
 const http = load('packages/api-client/src/endpoints/operations/http.ts', {'../../axios-instance': {defaultApiClient:client}});
 const {purchaseOrdersApi} = load('packages/api-client/src/endpoints/operations/suppliers.ts', {'./http':http});
 const body = {items: [{purchase_order_item_id: 42, qty: 3}]};
 assert.equal((await purchaseOrdersApi.receive(9, body, 'receipt-key')).status, 'partially_received');
 assert.deepEqual(calls[0], ['/api/v1/admin/purchase-orders/9/receive', body, {headers:{'Idempotency-Key':'receipt-key'}}]);
 await purchaseOrdersApi.closeShort(9); assert.equal(calls[1][0], '/api/v1/admin/purchase-orders/9/close-short');
});
test('supplier payment allocation sends one invoice and void uses DELETE body', async () => {
 const calls = [], client = {post:async (...a)=>{calls.push(['post',...a]);return {data:{data:{id:1}}};}, delete:async (...a)=>{calls.push(['delete',...a]);return {data:{message:'voided'}};}};
 const http = load('packages/api-client/src/endpoints/operations/http.ts', {'../../axios-instance': {defaultApiClient:client}});
 const {supplierPaymentsApi} = load('packages/api-client/src/endpoints/operations/suppliers.ts', {'./http':http});
 await supplierPaymentsApi.create({supplier_id:2, amount:5, payment_method:'cash',payment_date:'2026-10-27'},'payment-key');
 assert.equal(calls[0][3].headers['Idempotency-Key'],'payment-key');
 await supplierPaymentsApi.allocate(1, 7); assert.deepEqual(calls[1], ['post','/api/v1/admin/supplier-payments/1/allocate',{supplier_invoice_id:7}]);
 await supplierPaymentsApi.void(1,'duplicate'); assert.deepEqual(calls[2],['delete','/api/v1/admin/supplier-payments/1',{data:{void_reason:'duplicate'}}]);
});
test('supplier invoice update uses multipart method spoofing and preserves attachment', async () => {
 const calls = [], client = {post:async (...a)=>{calls.push(a);return {data:{data:{id:1}}};}};
 const http = load('packages/api-client/src/endpoints/operations/http.ts', {'../../axios-instance': {defaultApiClient:client}});
 const {supplierInvoicesApi} = load('packages/api-client/src/endpoints/operations/suppliers.ts', {'./http':http});
 await supplierInvoicesApi.update(1,{supplier_invoice_no:'INV-2',attachment:new Blob(['pdf'],{type:'application/pdf'})});
 assert.equal(calls[0][1].get('_method'),'PUT'); assert.equal(calls[0][1].get('supplier_invoice_no'),'INV-2'); assert.equal(calls[0][1].get('attachment').size,3);
});
