const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

function load(relative) {
  const filename = path.resolve(__dirname, '..', relative);
  const compiled = new Module(filename, module);
  compiled.filename = filename;
  compiled.paths = Module._nodeModulePaths(path.dirname(filename));
  compiled._compile(
    ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
      compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText,
    filename,
  );
  return compiled.exports;
}

const { safeReturnPath } = load('apps/storefront/src/lib/safe-return-path.ts');

// task.txt (28): كل الحالات الخطرة ترجع null.
const dangerous = [
  ['open redirect', '//evil.com'],
  ['encoded double slash', '/%2F%2Fevil.com'],
  ['backslash', '/\\evil.com'],
  ['encoded backslash', '/%5Cevil'],
  ['auth route', '/auth/login'],
  ['wholesale route (case-insensitive)', '/WHOLESALE'],
  ['encoded auth route', '/%61uth'],
  ['null input', null],
  ['broken percent-encoding', '%E0%A4'],
];

for (const [name, input] of dangerous) {
  test(`safeReturnPath blocks ${name}`, () => {
    assert.equal(safeReturnPath(input), null);
  });
}

test('safeReturnPath keeps a local path with query and hash', () => {
  assert.equal(safeReturnPath('/account?x=1#h'), '/account?x=1#h');
});

test('safeReturnPath keeps plain local paths', () => {
  assert.equal(safeReturnPath('/account/orders'), '/account/orders');
  assert.equal(safeReturnPath('/'), '/');
});

// خاصية: أي مسار يبدأ ببادئة خطرة يبقى مرفوضًا مهما كانت اللاحقة.
test('safeReturnPath rejects dangerous prefixes with any suffix (property sweep)', () => {
  const prefixes = ['//evil.com', '/%2F%2Fevil.com', '/\\evil.com', '/%5Cevil', '/auth/login', '/WHOLESALE', '/%61uth'];
  const suffixes = ['', '/x', '?a=1', '#h'];
  for (const prefix of prefixes) {
    for (const suffix of suffixes) {
      assert.equal(safeReturnPath(prefix + suffix), null, `${prefix}${suffix}`);
    }
  }
});

test('safeReturnPath canonicalizes dot-segments before the blocklist check', () => {
  // '/WHOLESALE/%2e%2e/admin' يُطبَّع إلى '/admin' وهو مسار محلي مسموح،
  // والفحص يجري على المسار المطبَّع فلا يمكن الالتفاف عليه للوصول لمسار محظور.
  assert.equal(safeReturnPath('/WHOLESALE/%2e%2e/admin'), '/admin');
  assert.equal(safeReturnPath('/auth/%2e%2e/account'), '/account');
});

test('safeReturnPath never returns a blocked or external path (security invariant sweep)', () => {
  const tricky = [
    '//evil.com', '/%2F%2Fevil.com', '/\\evil.com', '/%5Cevil', '/auth/login',
    '/WHOLESALE', '/%61uth', '/auth/%2e%2e/auth/login', '/WHOLESALE/%2e%2e/auth/x',
    '/supplier/invoices', 'https://evil.com/', 'javascript:alert(1)', '', '/ account',
  ];
  const blocked = /^\/(auth|supplier|wholesale)(?:\/|$)/i;
  for (const input of tricky) {
    const out = safeReturnPath(input);
    assert.ok(
      out === null || (out.startsWith('/') && !out.startsWith('//') && !blocked.test(out.split(/[?#]/)[0])),
      `${input} -> ${out}`,
    );
  }
});

// خاصية: المسارات المحلية البسيطة ترجع كما هي.
test('safeReturnPath round-trips simple local paths (property sweep)', () => {
  const locals = ['/', '/account', '/account/orders/123', '/ar/account?tab=returns#top'];
  for (const local of locals) {
    assert.equal(safeReturnPath(local), local, local);
  }
});
