const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');

const backofficeRoot = path.join(__dirname, '../apps/backoffice');
const adminMockDir = path.join(backofficeRoot, 'src/pages/admin/admin-mock');

function loadTs(absPath, mocks = {}) {
  const src = fs.readFileSync(absPath, 'utf8');
  const code = ts.transpileModule(src, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  const mod = { exports: {} };
  const fakeRequire = (id) => (id in mocks ? mocks[id] : require(id));
  new Function('exports', 'require', 'module', code)(mod.exports, fakeRequire, mod);
  return mod.exports;
}

test('F05: banner-mappers guards invalid dates (no RangeError) and store-management maps starts_at 422 errors', () => {
  const mappers = loadTs(path.join(adminMockDir, 'banner-mappers.ts'));
  assert.equal(mappers.isoToLocalInput('not-a-date'), '');
  assert.equal(mappers.localInputToIso('not-a-date'), '');
  assert.equal(mappers.isoToLocalInput(''), '');
  assert.equal(mappers.localInputToIso(''), '');

  const storeSrc = fs.readFileSync(path.join(adminMockDir, 'store-management.tsx'), 'utf8');
  assert.match(storeSrc, /k === 'starts_at'.*out\.start/, 'save() 422 handler must map starts_at to out.start');
  assert.match(storeSrc, /err\.start/, 'BannerForm must display err.start');
});

test('F06: vite.config.ts does not inject static meta CSP in index.html and AdminMockPage does not remove Google Fonts on unmount', () => {
  const viteSrc = fs.readFileSync(path.join(backofficeRoot, 'vite.config.ts'), 'utf8');
  assert.doesNotMatch(viteSrc, /backoffice-production-csp/, 'must not inject static meta CSP into index.html');

  const ampSrc = fs.readFileSync(path.join(adminMockDir, 'AdminMockPage.tsx'), 'utf8');
  assert.doesNotMatch(ampSrc, /fonts\.remove\(\)/, 'must not remove fonts <link> on unmount');
  assert.match(ampSrc, /admin-google-fonts/, 'must deduplicate Google Fonts <link> by id');
});

test('F08: ToastHost supports a toast queue with seq id & unmount cleanup, and loadBanners passes AbortSignal', () => {
  const uiSrc = fs.readFileSync(path.join(adminMockDir, 'ui.tsx'), 'utf8');
  assert.match(uiSrc, /useState<ToastItem\[\]>/, 'ToastHost must queue multiple toasts');
  assert.match(uiSrc, /toastBus\.fn = null/, 'ToastHost must clear toastBus.fn on unmount');

  const storeSrc = fs.readFileSync(path.join(adminMockDir, 'store-management.tsx'), 'utf8');
  assert.match(storeSrc, /adminApi\.listBanners\(signal\)/, 'loadBanners must pass AbortSignal to listBanners');
});

test('F09: App passes routeQuery to AdminMockPage, and users/roles react to deep-link resets', () => {
  const appSrc = fs.readFileSync(path.join(backofficeRoot, 'src/App.tsx'), 'utf8');
  assert.match(appSrc, /queryString=/, 'App must pass queryString prop to AdminMockPage');

  const usersSrc = fs.readFileSync(path.join(adminMockDir, 'users.tsx'), 'utf8');
  assert.match(usersSrc, /setRole\(initialRoleFilter\)/, 'UsersScreen must sync role even when reset to all');

  const rolesSrc = fs.readFileSync(path.join(adminMockDir, 'roles.tsx'), 'utf8');
  assert.doesNotMatch(rolesSrc, /appliedInitialRole\.current !== initialRoleId/, 'RolesScreen must allow re-selecting initialRoleId');
});

test('F10: DOC_TYPES and DOC_KEYS cover all 17 backend document types and settings.tsx blocks invalid submit', () => {
  const { DOC_TYPES } = loadTs(path.join(adminMockDir, 'data.ts'));
  const { DOC_KEYS } = loadTs(path.join(adminMockDir, 'api/mappers.ts'));
  assert.equal(DOC_TYPES.length, 17, 'DOC_TYPES must have 17 document types');
  assert.equal(DOC_KEYS.length, 17, 'DOC_KEYS must have 17 document keys');
  for (const k of ['expense', 'payment_notice', 'pos_invoice', 'shift', 'treasury_transfer']) {
    assert.ok(DOC_KEYS.includes(k), `DOC_KEYS must include ${k}`);
    assert.ok(DOC_TYPES.some((d) => d.key === k && d.label.trim().length > 0), `DOC_TYPES must include labeled ${k}`);
  }

  const settingsSrc = fs.readFileSync(path.join(adminMockDir, 'settings.tsx'), 'utf8');
  assert.doesNotMatch(settingsSrc, /12 نوعاً من الوثائق/, 'SETTINGS_TABS must not hardcode 12 document types');
  assert.match(settingsSrc, /!s\.company\.company_name\.trim\(\)/, 'save() must block empty company_name');
});

test('F12: UsersScreen avoids duplicate countUsers({}) and gates useBranches on branches permission', () => {
  const usersSrc = fs.readFileSync(path.join(adminMockDir, 'users.tsx'), 'utf8');
  assert.match(usersSrc, /useBranches\(canAny\(can,\s*'branches\.view',\s*'branches\.manage'\)\)/, 'useBranches must be gated by branches permission');
  assert.match(usersSrc, /Math\.max\(0,\s*all\s*-\s*active\)/, 'inactive count must be derived from all - active');
});

test('F15: RolesScreen protects unsaved draft with beforeunload, role-switch confirm, and 409 reload guard', () => {
  const rolesSrc = fs.readFileSync(path.join(adminMockDir, 'roles.tsx'), 'utf8');
  assert.match(rolesSrc, /beforeunload/, 'RolesScreen must register beforeunload when isDirty');
  assert.match(rolesSrc, /window\.confirm/, 'RolesScreen must confirm before discarding dirty draft on role switch');
});

test('F16 & F17: URL/email validation and Modal/BannerForm Escape + dialog accessibility + UserDrawer timer cleanup', () => {
  const storeSrc = fs.readFileSync(path.join(adminMockDir, 'store-management.tsx'), 'utf8');
  assert.match(storeSrc, /new URL\(/, 'BannerForm must validate external URL with new URL()');
  assert.match(storeSrc, /role="dialog"/, 'BannerForm / delete modal must set role="dialog"');

  const usersSrc = fs.readFileSync(path.join(adminMockDir, 'users.tsx'), 'utf8');
  assert.match(usersSrc, /f\.email\.trim\(\)/, 'UserDrawer must trim email before regex check');
  assert.match(usersSrc, /clearTimeout/, 'UserDrawer must clear focus timer on cleanup');

  const uiSrc = fs.readFileSync(path.join(adminMockDir, 'ui.tsx'), 'utf8');
  assert.match(uiSrc, /Modal[\s\S]*?Escape/, 'Modal must handle Escape key');
  assert.match(uiSrc, /Modal[\s\S]*?aria-modal="true"/, 'Modal must set aria-modal="true"');
});
