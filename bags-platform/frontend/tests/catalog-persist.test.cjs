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
 let active=0,max=0,attempts=0,failed=true,latest;
 const api={productsApi:{update:async()=>{}},variantsApi:{},productMediaApi:{uploadImages:async(id,files)=>{attempts++;active++;max=Math.max(max,active);await new Promise(resolve=>setTimeout(resolve,5));active--;if(files[0].fail&&failed){failed=false;throw {response:{data:{message:'Upload refused'}}};}return [{id:attempts,url:'https://r2.example/image.png'}];},reorder:async()=>{}}};
 const {persistProduct}=load(base+'persist.ts',{'@alfarid/api-client':api,'./mappers':mappers});
 const original={rows:[],media:media()},state={id:5,basic:mappers.blankUiBasic(),rows:[],media:{...media(),general:[{id:'a',file:{fail:true}},{id:'b',file:{}},{id:'c',file:{}}]}};
 await assert.rejects(persistProduct(state,original,false,v=>latest=v));
 assert.equal(max,2);assert.equal(latest.media.general.filter(f=>f.status==='done').length,2);
 await persistProduct(latest,original,false,()=>{});assert.equal(attempts,4);
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

test('a genuinely missing color preserves pending files and blocks media deletions/uploads',async()=>{
 const row={...mappers.emptyUiRow(),serverId:12,color:null},file={id:'pending',file:{name:'olive.png'}};let latest;const calls=[];
 const api={productsApi:{update:async()=>{}},variantsApi:{list:async()=>[{id:12,color:{name_en:'Charcoal'}}]},productMediaApi:{remove:async()=>calls.push('remove'),uploadImages:async()=>calls.push('upload')}};
 const {persistProduct}=load(base+'persist.ts',{'@alfarid/api-client':api,'./mappers':mappers});
 const state={id:5,basic:mappers.blankUiBasic(),rows:[row],media:{...media(),byVariant:{Olive:[file]}}};
 await assert.rejects(persistProduct(state,{rows:[row],media:{...media(),general:[{id:'old',serverId:3}]}},false,v=>latest=v),/Olive/);
 assert.deepEqual(calls,[]);assert.equal(latest.media.byVariant.Olive[0].file.name,'olive.png');assert.equal(file.status,'failed');
});
