const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),ts=require('typescript');
function load(file,modules={}){const exports={};vm.runInNewContext(ts.transpileModule(fs.readFileSync(file,'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText,{exports,require:name=>modules[name],history:{replaceState(){}},crypto:require('node:crypto').webcrypto});return exports;}
const base='apps/backoffice/src/pages/admin/catalog/';
const mappers=load(base+'mappers.ts');
const media=()=>({general:[],byVariant:{},video:null});
test('create uses server identifiers before uploading files and never invents a SKU',async()=>{
 const calls=[];let uploaded;
 const api={productsApi:{create:async payload=>{calls.push('create');assert.ok(!('sku' in payload.variants[0]));return {id:4,code:'P00004',status:'draft',variants:[{id:12,sku:'P00004-01',barcode:'2000000000008'}]};}},productMediaApi:{uploadImages:async(id,files)=>{calls.push('upload');assert.equal(id,4);assert.equal(files.length,1);return [{id:42,url:'https://r2.example/image.png'}];},reorder:async(id,ids)=>{calls.push('reorder');assert.equal(ids[0],42);}},variantsApi:{}};
 const {persistProduct}=load(base+'persist.ts',{'@alfarid/api-client':api,'./mappers':mappers});
 const state={basic:mappers.blankUiBasic(),rows:[mappers.emptyUiRow()],media:{...media(),general:[{id:'pending',file:{},status:'queued'}]}};
 const saved=await persistProduct(state,{rows:[],media:media()},false,value=>uploaded=value);
 assert.equal(saved.rows[0].serverId,12);assert.equal(saved.rows[0].sku,'P00004-01');assert.equal(uploaded.media.general[0].serverId,42);assert.equal(calls.join(','),'create,upload,reorder');
});
test('existing product saves basic fields separately and creates replacements before removing old variants',async()=>{
 const calls=[];const row={...mappers.emptyUiRow(),serverId:1,size:'Old',sku:'S1'};
 const api={productsApi:{update:async(id,payload)=>{calls.push('basic');assert.ok(!('variants' in payload));}},variantsApi:{create:async()=>{calls.push('create variant');return {id:2,sku:'S2',barcode:'B2'};},remove:async()=>calls.push('remove variant')},productMediaApi:{}};
 const {persistProduct}=load(base+'persist.ts',{'@alfarid/api-client':api,'./mappers':mappers});
 await persistProduct({id:5,status:'draft',basic:mappers.blankUiBasic(),rows:[{...mappers.emptyUiRow(),size:'New'}],media:media()},{rows:[row],media:media()},false,()=>{});
 assert.equal(calls.join(','),'basic,create variant,remove variant');
});

test('partial variant errors identify the failed row and stop later writes',async()=>{
 const calls=[],failure={response:{status:422,data:{errors:{retail_price:['Invalid price']}}}};
 const old={...mappers.emptyUiRow(),serverId:1,retail:'10'},current={...old,retail:'20'};
 const api={productsApi:{update:async()=>{}},variantsApi:{update:async()=>{calls.push('update');throw failure;},remove:async()=>calls.push('delete')},productMediaApi:{}};
 const {persistProduct}=load(base+'persist.ts',{'@alfarid/api-client':api,'./mappers':mappers});
 await assert.rejects(persistProduct({id:5,basic:mappers.blankUiBasic(),rows:[current],media:media()},{rows:[old,{...old,serverId:2,size:'Different'}],media:media()},false,()=>{}),error=>error.catalogRowKey===old._id&&error.catalogProductId===5);
 assert.deepEqual(calls,['update']);
});

test('image uploads use at most two requests and a retry skips completed files',async()=>{
 let active=0,max=0,attempts=0,failed=true,latest;const uploadIds=[];
 const api={productsApi:{update:async()=>{}},variantsApi:{},productMediaApi:{uploadImages:async(id,files,variantId,onProgress,ids)=>{assert.match(ids[0],/^[0-9a-f-]{36}$/);if(files[0].fail)uploadIds.push(ids[0]);attempts++;active++;max=Math.max(max,active);await new Promise(resolve=>setTimeout(resolve,5));active--;if(files[0].fail&&failed){failed=false;throw {response:{data:{message:'Upload refused'}}};}return [{id:attempts,url:'https://r2.example/image.png'}];},reorder:async()=>{}}};
 const {persistProduct}=load(base+'persist.ts',{'@alfarid/api-client':api,'./mappers':mappers});
 const original={rows:[],media:media()},state={id:5,basic:mappers.blankUiBasic(),rows:[],media:{...media(),general:[{id:'a',file:{fail:true}},{id:'b',file:{}},{id:'c',file:{}}]}};
 await assert.rejects(persistProduct(state,original,false,v=>latest=v));
 assert.equal(max,2);assert.equal(latest.media.general.filter(f=>f.status==='done').length,2);
 await persistProduct(latest,original,false,()=>{});assert.equal(attempts,4);assert.equal(uploadIds.length,2);assert.equal(uploadIds[0],uploadIds[1]);
});

test('rejected published image removal rolls the tile back into its position',async()=>{
 const image={id:'srv-3',serverId:3,status:'done'},original={rows:[],media:{...media(),general:[image]}};let latest;
 const api={productsApi:{update:async()=>{}},variantsApi:{},productMediaApi:{remove:async()=>{throw {response:{status:422}};}}};
 const {persistProduct}=load(base+'persist.ts',{'@alfarid/api-client':api,'./mappers':mappers});
 await assert.rejects(persistProduct({id:5,basic:mappers.blankUiBasic(),rows:[],media:media()},original,false,v=>latest=v));
 assert.equal(latest.media.general[0].serverId,3);
});

test('color uploads resolve an already saved variant even if local color data is stale',async()=>{
 const row={...mappers.emptyUiRow(),serverId:12,color:null};let uploaded;
 const api={productsApi:{update:async()=>{}},variantsApi:{list:async()=>[{id:12,color:{name_en:' Charcoal '}}]},productMediaApi:{uploadImages:async(id,files,variantId)=>{uploaded=variantId;return [{id:42,url:'/image.png'}];}}};
 const {persistProduct}=load(base+'persist.ts',{'@alfarid/api-client':api,'./mappers':mappers});
 const state={id:5,basic:mappers.blankUiBasic(),rows:[row],media:{...media(),byVariant:{CHARCOAL:[{id:'pending',file:{}}]}}};
 const saved=await persistProduct(state,{rows:[row],media:media()},false,()=>{});
 assert.equal(uploaded,12);assert.equal(saved.media.byVariant.CHARCOAL[0].serverId,42);
});

test('color upload uses a saved ID when the create response omitted local variant IDs',async()=>{
 const row={...mappers.emptyUiRow(),color:{ar:'زيتي',en:'Olive',hex:'#6b6b3f'}};let uploaded;
 const api={productsApi:{create:async()=>({id:5,code:'P00005',status:'draft'})},variantsApi:{list:async()=>[{id:27,color:{name_en:'Olive'}}]},productMediaApi:{uploadImages:async(id,files,variantId)=>{uploaded=variantId;return [{id:42,url:'/image.png'}];}}};
 const {persistProduct}=load(base+'persist.ts',{'@alfarid/api-client':api,'./mappers':mappers});
 const saved=await persistProduct({basic:mappers.blankUiBasic(),rows:[row],media:{...media(),byVariant:{Olive:[{id:'pending',file:{}}]}}},{rows:[],media:media()},false,()=>{});
 assert.equal(uploaded,27);assert.equal(saved.rows[0].serverId,27);
});

test('create sends a stable idempotency key across retries',async()=>{
 const keys=[];let attempts=0,latest;
 const api={productsApi:{create:async payload=>{keys.push(payload.create_key);attempts++;if(attempts===1)throw{response:{status:0}};return{id:7,code:'P00007',status:'draft',variants:[]};}},variantsApi:{},productMediaApi:{reorder:async()=>{}}};
 const {persistProduct}=load(base+'persist.ts',{'@alfarid/api-client':api,'./mappers':mappers});
 const state={basic:mappers.blankUiBasic(),rows:[],media:media()};
 await assert.rejects(persistProduct(state,{rows:[],media:media()},false,v=>latest=v));
 assert.match(keys[0],/^[0-9a-f-]{36}$/);
 const saved=await persistProduct(latest,{rows:[],media:media()},false,()=>{});
 assert.equal(keys.length,2);assert.equal(keys[0],keys[1]);assert.equal(saved.id,7);
});

test('uploads run before deletions so replacing the last image succeeds',async()=>{
 const calls=[];const old={id:'srv-9',serverId:9,status:'done'};
 const api={productsApi:{update:async()=>{}},variantsApi:{},productMediaApi:{
  uploadImages:async()=>{calls.push('upload');return[{id:10,url:'https://r2.example/new.png'}];},
  remove:async()=>{calls.push('remove');},reorder:async()=>{calls.push('reorder');}}};
 const {persistProduct}=load(base+'persist.ts',{'@alfarid/api-client':api,'./mappers':mappers});
 await persistProduct({id:5,basic:mappers.blankUiBasic(),rows:[],media:{...media(),general:[{id:'n',file:{}}]}},{rows:[],media:{...media(),general:[old]}},false,()=>{});
 assert.deepEqual(calls,['upload','remove','reorder']);
});

test('timestamp-only copies require a reload and versioned copies send expected_version',async()=>{
 let sent;
 const api={productsApi:{update:async(id,payload)=>{sent=payload;}},variantsApi:{},productMediaApi:{}};
 const {persistProduct}=load(base+'persist.ts',{'@alfarid/api-client':api,'./mappers':mappers});
 await assert.rejects(persistProduct({id:5,basic:mappers.blankUiBasic(),rows:[],media:media()},{rows:[],media:media(),updated_at:'2026-10-23T10:31:00.000000Z'},false,()=>{}), /رقم الإصدار/);
 assert.equal(sent,undefined);
 await persistProduct({id:5,basic:mappers.blankUiBasic(),rows:[],media:media()},{rows:[],media:media(),lock_version:3},false,()=>{});
 assert.equal(sent.expected_version,3);
 await persistProduct({id:5,basic:mappers.blankUiBasic(),rows:[],media:media()},{rows:[],media:media()},false,()=>{});
 assert.ok(!('expected_updated_at' in sent));
});

test('a genuinely missing color preserves pending files and blocks media deletions/uploads',async()=>{
 const row={...mappers.emptyUiRow(),serverId:12,color:null},file={id:'pending',file:{name:'olive.png'}};let latest;const calls=[];
 const api={productsApi:{update:async()=>{}},variantsApi:{list:async()=>[{id:12,color:{name_en:'Charcoal'}}]},productMediaApi:{remove:async()=>calls.push('remove'),uploadImages:async()=>calls.push('upload')}};
 const {persistProduct}=load(base+'persist.ts',{'@alfarid/api-client':api,'./mappers':mappers});
 const state={id:5,basic:mappers.blankUiBasic(),rows:[row],media:{...media(),byVariant:{Olive:[file]}}};
 await assert.rejects(persistProduct(state,{rows:[row],media:{...media(),general:[{id:'old',serverId:3}]}},false,v=>latest=v),/Olive/);
 assert.deepEqual(calls,[]);assert.equal(latest.media.byVariant.Olive[0].file.name,'olive.png');assert.equal(file.status,'failed');
});

test('retry after a partial save uses returned product and variant versions',async()=>{
 const productVersions=[],variantVersions=[];let latest,uploads=0;
 const old={...mappers.emptyUiRow(),serverId:12,retail:'10',lock_version:3};
 const original={id:5,lock_version:4,basic:mappers.blankUiBasic(),rows:[old],media:media()};
 const state={...original,rows:[{...old,retail:'20'}],media:{...media(),general:[{id:'pending',file:{}}]}};
 const api={productsApi:{update:async(id,p)=>{productVersions.push(p.expected_version);return {lock_version:p.expected_version+1,updated_at:'2026-10-24T10:00:00Z'};}},
  variantsApi:{update:async(id,variantId,p)=>{variantVersions.push(p.expected_version);return {lock_version:p.expected_version+1};}},
  productMediaApi:{uploadImages:async()=>{if(++uploads===1)throw Error('Network');return [{id:9,url:'/saved.jpg'}];},reorder:async()=>{}}};
 const {persistProduct}=load(base+'persist.ts',{'@alfarid/api-client':api,'./mappers':mappers});
 await assert.rejects(persistProduct(state,original,false,v=>latest=v));
 assert.equal(latest.lock_version,5);assert.equal(latest.rows[0].lock_version,4);
 await persistProduct(latest,original,false,()=>{});
 assert.deepEqual(productVersions,[4,5]);assert.deepEqual(variantVersions,[3,4]);
});

test('a stale basic save identifies the product for the existing reload flow',async()=>{
 const failure={response:{status:409,data:{message:'Reload product'}}};
 const api={productsApi:{update:async()=>{throw failure;}},variantsApi:{},productMediaApi:{}};
 const {persistProduct}=load(base+'persist.ts',{'@alfarid/api-client':api,'./mappers':mappers});
 await assert.rejects(persistProduct({id:5,lock_version:2,basic:mappers.blankUiBasic(),rows:[],media:media()},{rows:[],media:media()},false,()=>{}),error=>error.catalogProductId===5);
});
