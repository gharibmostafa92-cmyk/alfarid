const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const ts=require('typescript');
const exportsMap={};
vm.runInNewContext(ts.transpileModule(fs.readFileSync('apps/backoffice/src/pages/admin/catalog/mappers.ts','utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText,{exports:exportsMap,crypto:require('node:crypto').webcrypto});
const {productFromApi,productToPayload,variantToPayload,emptyUiRow,blankUiBasic}=exportsMap;
test('product dimensions and descriptions round trip without exposing hidden cost',()=>{
 const product={id:1,name_ar:'حقيبة',name_en:'Bag',slug:'bag',status:'draft',sales_channel:'both',dimensions:{length_cm:'12',width_cm:'3',height_cm:'20'},category:{id:4},brand:null,supplier_id:null,variants:[{id:7,sku:'P00001-01',barcode:'2000000000008',color:{name_ar:'أزرق',name_en:'Blue',hex:'#0000FF'},size:'Large',capacity:null,retail_price:'10',wholesale_price:'8',cost_price:'4',sort_order:0,is_active:true}],description_ar:'وصف',description_en:'Description'};
 product.lock_version=4;product.updated_at='2026-10-24T10:00:00Z';product.variants[0].lock_version=7;
 const state=productFromApi(product,[],{canViewCost:false});
 assert.equal(state.lock_version,4);assert.equal(state.updated_at,product.updated_at);assert.equal(state.rows[0].lock_version,7);
 const payload=productToPayload(state.basic);
 assert.equal(payload.length_cm,'12');assert.equal(payload.description_ar,'وصف');assert.equal(payload.category_id,4);
 assert.equal(state.rows[0].serverId,7);assert.equal(state.rows[0].cost,'');assert.equal(state.axes.colors[0].en,'Blue');
 assert.ok(!('cost_price' in variantToPayload(state.rows[0],{canViewCost:false,isCreate:false})));
});
test('new variants leave system identifiers to the server and send empty numeric values as null',()=>{
 const row=emptyUiRow();const payload=variantToPayload(row,{canViewCost:false,isCreate:true});
 assert.ok(!('sku' in payload));assert.ok(!('barcode' in payload));assert.ok(!('cost_price' in payload));
 assert.equal(payload.retail_price,null);assert.equal(payload.weight_kg,null);assert.equal(productToPayload(blankUiBasic()).warranty_months,null);
});
test('variant images are grouped by the actual nested API color',()=>{
 const state=productFromApi({name_ar:'',name_en:'',slug:'',dimensions:{},variants:[{id:3,sku:'S',color:{name_en:'Blue'},sort_order:0,is_active:true}]},[{id:9,collection:'images',variant_id:3,url:'https://r2.example/image.png',order:1}],{canViewCost:false});
 assert.equal(state.media.general.length,0);assert.equal(state.media.byVariant.Blue[0].serverId,9);
});
