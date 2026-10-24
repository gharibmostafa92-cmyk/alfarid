const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('apps/backoffice/src/pages/admin/catalog-mock/catalog-reference.jsx','utf8');
const context={};vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('const vKey ='),source.indexOf('function ChipInput(')),context);
const row=patch=>({_id:'one',color:null,size:'',capacity:'',sku:'',barcode:'',retail:'',wholesale:'',cost:'',compare:'',status:'active',...patch});
test('draft permits missing prices but still rejects invalid decimal prices',()=>{
 assert.equal(Object.keys(context.validateVariants([row()], 'both','single',false)).length,0);
 assert.ok(context.validateVariants([row({retail:'-1'})],'retail','single',false).one.retail);
 assert.ok(context.validateVariants([row({retail:'10000000'})],'retail','single',false).one.retail);
 assert.ok(context.validateVariants([row()],'retail','single',true).one.retail);
});
test('variant combinations, SKU casing and manual system barcodes are validated',()=>{
 const rows=[row({sku:'SKU',color:{en:'Red'},size:'XL'}),row({_id:'two',sku:'sku',color:{en:' red '},size:'xl',barcode:'2000000000008'})];
 const errors=context.validateVariants(rows,'retail','matrix',false);
 assert.ok(errors.two.dup);assert.ok(errors.two.sku);assert.ok(errors.two.barcode);
});

test('four decimal costs round trip while selling prices remain two decimals',()=>{
 assert.equal(Object.keys(context.validateVariants([row({cost:'1.2345'})],'retail','single',false)).length,0);
 assert.ok(context.validateVariants([row({cost:'1.23456'})],'retail','single',false).one.cost);
 assert.ok(context.validateVariants([row({retail:'1.2345'})],'retail','single',false).one.retail);
});
