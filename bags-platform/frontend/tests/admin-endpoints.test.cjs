const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');
const calls = [];
const controller = new AbortController();
const api = {get: async (url, config) => {
  calls.push({url,...config});
  const paginated = url.endsWith('/roles') || url.endsWith('/branches');
  return {data:{data:paginated?[{id:config.params.page}]:[],meta:{total:42,last_page:paginated?2:1}}};
}};
const code = ts.transpileModule(fs.readFileSync(path.join(__dirname,'../apps/backoffice/src/pages/admin/admin-mock/api/endpoints.ts'),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText;
const moduleObject = {exports:{}};
new Function('exports','module','require',code)(moduleObject.exports,moduleObject,()=>({defaultApiClient:api}));
const {adminApi} = moduleObject.exports;
(async () => {
  await adminApi.listUsers({branch_id:'none',is_active:false,search:'  أحمد ',role:'مشرف'},controller.signal);
  assert.deepEqual(calls[0].params,{page:1,per_page:8,branch_id:'',is_active:0,search:'أحمد',role:'مشرف'});
  assert.equal(calls[0].signal,controller.signal);
  await adminApi.listUsers({is_active:true});
  assert.equal(calls.at(-1).params.is_active,1);
  assert.equal(await adminApi.countUsers({},controller.signal),42);
  assert.equal(calls.at(-1).params.per_page,1);
  assert.deepEqual(await adminApi.listRoles(controller.signal),[{id:1},{id:2}]);
  assert.deepEqual(await adminApi.listBranches(controller.signal),[{id:1},{id:2}]);
  assert.equal(calls.at(-1).signal,controller.signal);
  process.stdout.write('Admin query serialization, pagination and AbortSignal tests passed.\n');
})().catch(error=>{console.error(error);process.exitCode=1;});
