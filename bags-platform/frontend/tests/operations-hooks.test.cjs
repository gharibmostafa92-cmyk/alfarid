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
  h=harness('useNotifications.ts',undefined,{api:{notificationsApi:{list:async()=>({data:[{id:'uuid-test',type:'AdminOrderCreatedNotification',data:{order_id:7},read_at:null,created_at:null}],meta:{current_page:1,last_page:1,unread_count:1}}),markRead:async id=>{calls.push(id);if(fail)return new Promise((resolve,reject)=>{release=()=>reject({status:500,message:'Read failed'});});return {data:{id,read_at:'now'},meta:{unread_count:0}};},readAll:async()=>{calls.push('read-all');return {meta:{unread_count:0}};}}}});
  h.render(()=>h.hook.useNotifications());await tick();let n=h.render(()=>h.hook.useNotifications());
  const pending=n.markRead('uuid-test');await n.markRead('uuid-test');assert.deepEqual(calls,['uuid-test']);assert.equal(h.slots[2],0);release();await pending;
  assert.equal(h.slots[0][0].read_at,null);assert.equal(h.slots[2],1);assert.equal(h.slots[6],'Read failed');
  fail=false;n=h.render(()=>h.hook.useNotifications());await n.markAllRead();assert.equal(h.slots[2],0);assert.equal(h.slots[6],null);assert.ok(h.slots[0][0].read_at);assert.deepEqual(calls,['uuid-test','read-all']);
 }finally{h?.unmount();Object.assign(global,previous);}
});

function notification(id, read_at=null) {
 return {id,type:'AdminOrderCreatedNotification',data:{order_id:7},read_at,created_at:null};
}
function notificationPage(data, current_page=1, last_page=1, unread_count=0) {
 return {data,meta:{current_page,last_page,unread_count}};
}
function notificationBrowser() {
 const previous={document:global.document,setInterval:global.setInterval,clearInterval:global.clearInterval};
 let poll;
 global.document={visibilityState:'visible',addEventListener(){},removeEventListener(){}};
 global.setInterval=fn=>{poll=fn;return 1;};global.clearInterval=()=>{};
 return {poll:()=>poll(),restore:()=>Object.assign(global,previous)};
}

test('mark all reads unloaded notifications in one request even when loaded items are already read',async()=>{
 const browser=notificationBrowser();let h,bulk=0;
 try{
  h=harness('useNotifications.ts',undefined,{api:{notificationsApi:{
   list:async()=>notificationPage([notification('read-on-page-one','2026-10-28T10:00:00Z')],1,3,25),
   readAll:async()=>{bulk++;return {meta:{unread_count:0}};},
   markRead:async()=>{assert.fail('bulk reading must not issue individual requests');},
  }}});
  h.render(()=>h.hook.useNotifications());await tick();
  const before=h.render(()=>h.hook.useNotifications());assert.equal(before.unread,25);
  await before.markAllRead();
  assert.equal(bulk,1);assert.equal(h.render(()=>h.hook.useNotifications()).unread,0);
 }finally{h?.unmount();browser.restore();}
});

test('mark all rejects duplicate bulk requests while the first request is pending',async()=>{
 const browser=notificationBrowser();let h,bulk=0,pending;const releases=[];
 try{
  h=harness('useNotifications.ts',undefined,{api:{notificationsApi:{
   list:async()=>notificationPage([notification('unread')],1,2,11),
   readAll:()=>{bulk++;return new Promise(resolve=>{releases.push(()=>resolve({meta:{unread_count:0}}));});},
  }}});
  h.render(()=>h.hook.useNotifications());await tick();
  const n=h.render(()=>h.hook.useNotifications());pending=n.markAllRead();
  void n.markAllRead();assert.equal(bulk,1);
  releases.forEach(release=>release());await pending;
 }finally{releases.forEach(release=>release());await pending;h?.unmount();browser.restore();}
});

test('failed bulk read restores the server unread count including unloaded notifications',async()=>{
 const browser=notificationBrowser();let h;
 try{
  h=harness('useNotifications.ts',undefined,{api:{notificationsApi:{
   list:async()=>notificationPage([notification('unread'),notification('read','2026-10-28T10:00:00Z')],1,4,27),
   readAll:async()=>{throw {status:500,message:'Bulk failed'};},
  }}});
  h.render(()=>h.hook.useNotifications());await tick();
  await h.render(()=>h.hook.useNotifications()).markAllRead();
  const n=h.render(()=>h.hook.useNotifications());
  assert.equal(n.unread,27);assert.equal(n.items[0].read_at,null);
  assert.equal(n.items[1].read_at,'2026-10-28T10:00:00Z');assert.equal(n.error,'Bulk failed');
 }finally{h?.unmount();browser.restore();}
});

test('polling prepends new notifications and preserves loaded later pages and pagination',async()=>{
 const browser=notificationBrowser();let h,firstPageCalls=0;const pages=[];
 try{
  h=harness('useNotifications.ts',undefined,{api:{notificationsApi:{
   list:async page=>{
    pages.push(page);
    if(page===1){firstPageCalls++;return notificationPage(firstPageCalls===1?[notification('2'),notification('3')]:[notification('1'),notification('2')],1,3,5);}
    if(page===2)return notificationPage([notification('4'),notification('5')],2,3,5);
    return notificationPage([notification('6')],3,3,5);
   },
  }}});
  h.render(()=>h.hook.useNotifications());await tick();
  h.render(()=>h.hook.useNotifications()).loadMore();await tick();
  assert.deepEqual(h.render(()=>h.hook.useNotifications()).items.map(n=>n.id),['2','3','4','5']);
  browser.poll();await tick();
  const refreshed=h.render(()=>h.hook.useNotifications());
  assert.deepEqual(refreshed.items.map(n=>n.id),['1','2','3','4','5']);
  refreshed.loadMore();await tick();
  assert.deepEqual(pages,[1,2,1,3]);
  assert.deepEqual(h.render(()=>h.hook.useNotifications()).items.map(n=>n.id),['1','2','3','4','5','6']);
 }finally{h?.unmount();browser.restore();}
});

test('pending bulk read blocks notification polling until it completes',async()=>{
 const browser=notificationBrowser();let h,release,pending,requests=0;
 try{
  h=harness('useNotifications.ts',undefined,{api:{notificationsApi:{
   list:async()=>{requests++;return notificationPage([notification('unread')],1,1,1);},
   readAll:()=>new Promise(resolve=>{release=()=>resolve({meta:{unread_count:0}});}),
  }}});
  h.render(()=>h.hook.useNotifications());await tick();
  pending=h.render(()=>h.hook.useNotifications()).markAllRead();
  browser.poll();await tick();assert.equal(requests,1);
  release();await pending;browser.poll();await tick();assert.equal(requests,2);
 }finally{release?.();await pending;h?.unmount();browser.restore();}
});
