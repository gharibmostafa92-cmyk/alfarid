const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');
const code = ts.transpileModule(fs.readFileSync(path.join(__dirname, '../packages/utils/src/permissions.ts'), 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const loaded = { exports: {} };
new Function('exports', 'module', code)(loaded.exports, loaded);
const { hasPermission, hasAnyPermission, hasAllPermissions } = loaded.exports;
for (const permission of ['*', 'users.*', 'super_admin']) {
  assert.equal(hasPermission([permission], 'users.update'), false);
}
assert.equal(hasPermission(['users.update'], 'users.update'), true);
assert.equal(hasPermission(undefined, 'users.view'), false);
assert.equal(hasAnyPermission(['users.view'], ['users.update', 'users.view']), true);
assert.equal(hasAllPermissions(['users.view'], ['users.update', 'users.view']), false);
console.log('Staff permissions match the server without wildcard aliases.');
