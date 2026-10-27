const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const path=require('node:path');const Module=require('node:module');const ts=require('typescript');
function harness(relative,flags={USE_MOCK:false,FALLBACK_ON_ERROR:false},other={}) {
 const slots=[],effects=[];let cursor=0,pending=[];
 const react={useState(initial){const i=cursor++;if(!(i in slots))slots[i]=typeof initial==='function'?initial():initial;return [slots[i],v=>{slots[i]=typeof v==='function'?v(slots[i]):v;}];},useRef(initial){const i=cursor++;return slots[i]??(slots[i]={current:initial});},useCallback(fn,deps){const i=cursor++,prev=slots[i];if(!prev||!deps||deps.some((d,j)=>!Object.is(d,prev.deps[j])))slots[i]={fn,deps};return slots[i].fn;},useEffect(fn,deps){const i=cursor++;const prev=effects[i];if(!prev||deps.some((d,j)=>!Object.is(d,prev.deps[j])))pending.push(()=>{prev?.cleanup?.();effects[i]={deps,cleanup:fn()};});}};
 const filename=path.resolve(__dirname,'../apps/backoffice/src/pages/admin/operations-mock/api',relative),mod=new Module(filename,module);mod.filename=filename;mod.paths=Module._nodeModulePaths(path.dirname(filename));
 mod.require=name=>name==='react'?react:name==='./source'?flags:name==='@alfarid/api-client'?{isAxiosCancel:()=>false,parseApiError:e=>({status:e.status??null,message:e.message??'Failed',fieldErrors:e.fields??{}}),...other.api}:name==='../data'?{NOTIFS:other.notes??[]}:Module.prototype.require.call(mod,name);
 mod._compile(ts.transpileModule(fs.readFileSync(filename,'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText,filename);
 return {hook:mod.exports,slots,render(fn){cursor=0;const r=fn();const queue=pending;pending=[];queue.forEach(run=>run());return r;},unmount(){effects.forEach(e=>e?.cleanup?.());}};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
test('mock mode never makes network requests even without a fixture',async()=>{
 const h=harness('useRemote.ts',{USE_MOCK:true,FALLBACK_ON_ERROR:true});let requests=0;
 const r=h.render(()=>h.hook.useRemote(async()=>{requests++;return 1;},[]));await tick();
 assert.equal(requests,0);assert.equal(h.slots[0],null);assert.equal(h.slots[1],false);assert.equal(h.slots[3],true);h.unmount();
});
test('an aborted late response cannot overwrite the next filter result',async()=>{
 const h=harness('useRemote.ts');let oldResolve;
 h.render(()=>h.hook.useRemote(()=>new Promise(resolve=>{oldResolve=resolve;}),['old']));
 h.render(()=>h.hook.useRemote(async()=>({id:2}),['new']));await tick();oldResolve({id:1});await tick();
 assert.deepEqual(h.slots[0],{id:2});assert.equal(h.slots[1],false);h.unmount();
});
test('403 clears prior rows and does not fall back to demonstrations',async()=>{
 const h=harness('useRemote.ts',{USE_MOCK:false,FALLBACK_ON_ERROR:true});
 h.render(()=>h.hook.useRemote(async()=>[{id:1}],['first'],{mock:()=>[{id:999}]}));await tick();
 h.render(()=>h.hook.useRemote(async()=>{throw {status:403,message:'Forbidden'};},['second'],{mock:()=>[{id:999}]}));await tick();
 assert.equal(h.slots[0],null);assert.equal(h.slots[2].status,403);assert.equal(h.slots[3],false);h.unmount();
});
test('fallback is explicitly flagged only for network/server failures',async()=>{
 const h=harness('useRemote.ts',{USE_MOCK:false,FALLBACK_ON_ERROR:true});
 h.render(()=>h.hook.useRemote(async()=>{throw {status:500,message:'Unavailable'};},[],{mock:()=>[{id:999}]}));await tick();
 assert.deepEqual(h.slots[0],[{id:999}]);assert.equal(h.slots[3],true);assert.equal(h.slots[2].status,500);h.unmount();
});
test('mutations reject double clicks synchronously and retain 422 field errors',async()=>{
 const h=harness('useRemote.ts');const {run}=h.render(()=>h.hook.useAction());let release,requests=0,fields,toast=[];
 const first=run(()=>{requests++;return new Promise(resolve=>{release=resolve;});},{toast:(...x)=>toast.push(x),success:'Saved'});
 const second=await run(async()=>{requests++;},{toast:()=>{}});assert.equal(second,undefined);assert.equal(requests,1);release({id:1});await first;
 assert.equal(h.slots[0],false);assert.deepEqual(toast,[['Saved','green']]);
 await run(async()=>{throw {status:422,message:'Invalid',fields:{code:'Duplicate'}};},{toast:()=>{},onFieldErrors:e=>{fields=e;}});assert.deepEqual(fields,{code:'Duplicate'});
});

test('notification polling stops while hidden and resumes while visible',async()=>{
 const previous={document:global.document,setInterval:global.setInterval,clearInterval:global.clearInterval};let poll,requests=0;
 global.document={visibilityState:'hidden',addEventListener(){},removeEventListener(){}};global.setInterval=fn=>{poll=fn;return 1;};global.clearInterval=()=>{};
 let h;
 try{
  h=harness('useNotifications.ts',undefined,{api:{notificationsApi:{list:async()=>{requests++;return {data:[],meta:{current_page:1,last_page:1,unread_count:0}};}}}});
  h.render(()=>h.hook.useNotifications());await tick();assert.equal(requests,1);
  poll();await tick();assert.equal(requests,1);
  global.document.visibilityState='visible';poll();await tick();assert.equal(requests,2);
 }finally{h?.unmount();Object.assign(global,previous);}
});
test('notification reads keep UUIDs, reject duplicate clicks and roll back failures',async()=>{
 const previous={document:global.document,setInterval:global.setInterval,clearInterval:global.clearInterval};global.document={visibilityState:'visible',addEventListener(){},removeEventListener(){}};global.setInterval=()=>1;global.clearInterval=()=>{};
 let h,release,fail=true;const calls=[];
 try{
  h=harness('useNotifications.ts',undefined,{api:{notificationsApi:{list:async()=>({data:[{id:'uuid-test',type:'AdminOrderCreatedNotification',data:{order_id:7},read_at:null,created_at:null}],meta:{current_page:1,last_page:1,unread_count:1}}),markRead:async id=>{calls.push(id);if(fail)return new Promise((resolve,reject)=>{release=()=>reject({status:500,message:'Read failed'});});return {data:{id,read_at:'now'},meta:{unread_count:0}};}}}});
  h.render(()=>h.hook.useNotifications());await tick();let n=h.render(()=>h.hook.useNotifications());
  const pending=n.markRead('uuid-test');await n.markRead('uuid-test');assert.deepEqual(calls,['uuid-test']);assert.equal(h.slots[2],0);release();await pending;
  assert.equal(h.slots[0][0].read_at,null);assert.equal(h.slots[2],1);assert.equal(h.slots[6],'Read failed');
  fail=false;n=h.render(()=>h.hook.useNotifications());await n.markAllRead();assert.equal(h.slots[2],0);assert.equal(h.slots[6],null);assert.equal(h.slots[0][0].read_at,'now');
 }finally{h?.unmount();Object.assign(global,previous);}
});
