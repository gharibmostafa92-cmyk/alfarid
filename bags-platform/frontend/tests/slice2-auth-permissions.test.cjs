const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');

const backofficeSrc = path.join(__dirname, '../apps/backoffice/src');

function loadTsExports(relPath, mocks = {}) {
  const src = fs.readFileSync(path.join(backofficeSrc, relPath), 'utf8')
    .replace(/import\.meta\.env/g, '({ DEV: false })');
  const code = ts.transpileModule(src, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.React },
  }).outputText;
  const mod = { exports: {} };
  const fakeRequire = (id) => {
    if (id in mocks) return mocks[id];
    if (id.endsWith('.css')) return {};
    return require(id);
  };
  new Function('exports', 'require', 'module', code)(mod.exports, fakeRequire, mod);
  return mod.exports;
}

test('F01: AuthCallbackPage validates redirect.path as safe same-origin relative path', () => {
  const src = fs.readFileSync(path.join(backofficeSrc, 'pages/auth/AuthCallbackPage.tsx'), 'utf8');
  assert.doesNotMatch(src, /window\.location\.replace\(res\.redirect\.path\)/, 'must not pass raw res.redirect.path to window.location.replace');
  assert.match(src, /resolveSafeRedirectPath/, 'should use resolveSafeRedirectPath helper');

  const { resolveSafeRedirectPath } = loadTsExports('pages/auth/AuthCallbackPage.tsx', {
    react: { useEffect: () => {}, useRef: () => ({ current: false }) },
    '@alfarid/api-client': { authEndpoints: {}, resolveServiceUrl: (u) => u },
    '../../stores/auth.store': { useAuthStore: () => {} },
    '../../stores/mappers': { toStaffUser: () => ({}) },
  });
  assert.equal(resolveSafeRedirectPath('/pos'), '/pos');
  assert.equal(resolveSafeRedirectPath('/orders?id=1'), '/orders?id=1');
  assert.equal(resolveSafeRedirectPath('https://evil.example'), '/dashboard');
  assert.equal(resolveSafeRedirectPath('//evil.example'), '/dashboard');
  assert.equal(resolveSafeRedirectPath('javascript:alert(1)'), '/dashboard');
  assert.equal(resolveSafeRedirectPath('/\n/evil'), '/dashboard');
  assert.equal(resolveSafeRedirectPath(undefined), '/dashboard');
});

test('F07 & F13: auth.store uses Super Admin role constant, exact login path check, and preserves token on non-auth bootstrap failures', () => {
  const storeSrc = fs.readFileSync(path.join(backofficeSrc, 'stores/auth.store.ts'), 'utf8');
  assert.doesNotMatch(storeSrc, /roles:\s*\['super_admin'\]/, 'DEV mock user must not use lowercase super_admin');
  assert.match(storeSrc, /SUPER_ADMIN_ROLE\s*=\s*'Super Admin'/, 'should define SUPER_ADMIN_ROLE = Super Admin');
  assert.doesNotMatch(storeSrc, /!window\.location\.pathname\.includes\('\/login'\)/, 'must not use loose includes(/login) check');

  // In bootstrap(), non-401/403 catch block in prod must not wipe token via get().logout()
  const bootstrapMatch = storeSrc.match(/bootstrap:\s*async[\s\S]*?\n\s{4}\},/);
  assert.ok(bootstrapMatch, 'bootstrap method must exist');
  const bootstrapBody = bootstrapMatch[0];
  assert.doesNotMatch(bootstrapBody, /get\(\)\.logout\(\)/, 'bootstrap must not call get().logout() on invalid payload or network errors');
});

test('F14: RotateStaffPassword uses staff logout scope and enforces password complexity + confirmation', () => {
  const src = fs.readFileSync(path.join(backofficeSrc, 'components/auth/RotateStaffPassword.tsx'), 'utf8');
  assert.doesNotMatch(src, /logout\('unified'\)/, 'must not call logout(unified)');
  assert.match(src, /logout\('staff'\)/, 'must call logout(staff)');
  assert.match(src, /password_confirmation/, 'must include password confirmation input');
  assert.match(src, /\[A-Za-z\\p\{L\}\]/u, 'must validate letter presence');
  assert.match(src, /\\d/, 'must validate digit presence');

  const { validateNewStaffPassword } = loadTsExports('components/auth/RotateStaffPassword.tsx', {
    react: { useState: () => [false, () => {}] },
    '@alfarid/api-client': { authEndpoints: {} },
    '../../stores/auth.store': { useAuthStore: () => {} },
    '../../stores/mappers': { toStaffUser: () => ({}) },
  });
  assert.ok(validateNewStaffPassword('short1', 'short1'), 'rejects < 12 chars');
  assert.ok(validateNewStaffPassword('abcdefghijkl', 'abcdefghijkl'), 'rejects letters-only');
  assert.ok(validateNewStaffPassword('123456789012', '123456789012'), 'rejects digits-only');
  assert.ok(validateNewStaffPassword('ValidPass1234', 'Mismatch1234'), 'rejects mismatch');
  assert.equal(validateNewStaffPassword('ValidPass1234', 'ValidPass1234'), null, 'accepts valid matching password');
});

test('F04: store.manage users have access to store management without settings.view and StoreManagementTab fails closed', () => {
  const appSrc = fs.readFileSync(path.join(backofficeSrc, 'App.tsx'), 'utf8');
  assert.match(appSrc, /hasAdminPermissions[\s\S]*?'store\.manage'/, 'hasAdminPermissions must include store.manage');
  assert.match(appSrc, /canAny\(can,\s*'settings\.view',\s*'settings\.manage',\s*'store\.manage'\)/, 'catalogNav must allow store.manage');

  const adminMockSrc = fs.readFileSync(path.join(backofficeSrc, 'pages/admin/admin-mock/AdminMockPage.tsx'), 'utf8');
  assert.match(adminMockSrc, /store\.manage/, 'AdminMockPage must handle store.manage for store tab');

  const settingsSrc = fs.readFileSync(path.join(backofficeSrc, 'pages/admin/admin-mock/settings.tsx'), 'utf8');
  assert.match(settingsSrc, /visibleTabs/, 'SettingsScreen must filter SETTINGS_TABS by permission');

  const storeSrc = fs.readFileSync(path.join(backofficeSrc, 'pages/admin/admin-mock/store-management.tsx'), 'utf8');
  assert.doesNotMatch(storeSrc, /const canEdit = can \? \(can\('store\.manage'\) \|\| can\('\*'\)\) : true/, 'StoreManagementTab must fail-closed');
});
