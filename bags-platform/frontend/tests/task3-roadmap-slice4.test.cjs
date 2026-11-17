const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

function loadAuthService() {
  const filename = path.resolve(__dirname, '../apps/storefront/src/services/auth.service.ts');
  const loaded = new Module(filename, module);
  loaded.filename = filename;
  loaded.require = (name) => {
    if (name === '@alfarid/api-client') {
      return {
        defaultApiClient: {
          post: async () => ({ data: { token: 't', data: {}, cart_merged: true } }),
        },
        authEndpoints: { login: async () => ({}), logout: async () => ({}) },
        resolveServiceUrl: (v) => v,
      };
    }
    if (name === '@/store/auth.store') {
      return {
        useAuthStore: {
          getState: () => ({ token: null, setSession: () => {}, clear: () => {} }),
        },
      };
    }
    if (name === '@/stores/cart-store') {
      return {
        getCartSession: () => null,
        restoreGuestCartSession: () => {},
        clearGuestCartSession: () => {},
      };
    }
    if (name === '@/lib/orders/safe-return-path') {
      const redirectFile = path.resolve(__dirname, '../apps/storefront/src/lib/orders/safe-return-path.ts');
      const m = new Module(redirectFile, module);
      m.filename = redirectFile;
      m._compile(
        ts.transpileModule(fs.readFileSync(redirectFile, 'utf8'), {
          compilerOptions: { module: ts.ModuleKind.CommonJS },
        }).outputText,
        redirectFile,
      );
      return m.exports;
    }
    throw new Error(`Unexpected import: ${name}`);
  };
  loaded._compile(
    ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
      compilerOptions: { module: ts.ModuleKind.CommonJS },
    }).outputText,
    filename,
  );
  return loaded.exports;
}

test('HIGH-05: safeLogoutNext rejects ASCII control characters and protocol-relative bypasses', () => {
  const { safeLogoutNext } = loadAuthService();
  assert.equal(safeLogoutNext('/\t/evil.com'), '/auth/login');
  assert.equal(safeLogoutNext('/\n/evil.com'), '/auth/login');
  assert.equal(safeLogoutNext('/\r/evil.com'), '/auth/login');
  assert.equal(safeLogoutNext('/shop?page=2'), '/shop?page=2');
});

test('HIGH-05: registerWeb preserves safe redirect path for retail shoppers', async () => {
  const { registerWeb } = loadAuthService();
  const dest = await registerWeb({ name: 'A', phone: '01012345678', password: 'password123' }, false, [], '/checkout');
  assert.equal(dest, '/checkout');

  const fallback = await registerWeb({ name: 'A', phone: '01012345678', password: 'password123' }, false, [], 'https://evil.com');
  assert.equal(fallback, '/shop');
});

test('HIGH-05: RequireActor does not rely on key-order-sensitive JSON.stringify equality', () => {
  const src = fs.readFileSync(path.resolve(__dirname, '../apps/storefront/src/components/auth/RequireActor.tsx'), 'utf8');
  assert.doesNotMatch(src, /JSON\.stringify\(current\.user\)\s*!==\s*JSON\.stringify\(nextUser\)/);
});

test('HIGH-03: LoginScreen disables submit whenever challengeRequired && !turnstileToken', () => {
  const src = fs.readFileSync(path.resolve(__dirname, '../apps/storefront/src/components/auth/LoginScreen.tsx'), 'utf8');
  assert.match(src, /challengeRequired\s*&&\s*!turnstileToken/);
  assert.doesNotMatch(src, /challengeRequired\s*&&\s*!!siteKey\s*&&\s*!turnstileToken/);
});

test('MEDIUM-07: static lightweight session-probe.html exists and LoginScreen points to it', () => {
  const htmlPath = path.resolve(__dirname, '../apps/backoffice/public/auth/session-probe.html');
  assert.ok(fs.existsSync(htmlPath), 'session-probe.html must exist');
  const html = fs.readFileSync(htmlPath, 'utf8');
  assert.ok(Buffer.byteLength(html, 'utf8') < 2500, 'session-probe.html must be lightweight');
  assert.match(html, /session-probe\.js/);

  const scriptPath = path.resolve(__dirname, '../apps/backoffice/public/auth/session-probe.js');
  assert.ok(fs.existsSync(scriptPath), 'session-probe.js must exist');
  const script = fs.readFileSync(scriptPath, 'utf8');
  assert.match(script, /backoffice_token/);
  assert.match(script, /new URL\(document\.referrer\)\.origin/);
  assert.doesNotMatch(script, /postMessage\([^;]+,\s*['"]\*['"]\s*\)/s);
  assert.match(script, /alfarid:staff-session/);

  const loginSrc = fs.readFileSync(path.resolve(__dirname, '../apps/storefront/src/components/auth/LoginScreen.tsx'), 'utf8');
  assert.match(loginSrc, /\/auth\/session-probe\.html/);
  assert.match(loginSrc, /referrerPolicy = 'origin'/);

  const vite = fs.readFileSync(path.resolve(__dirname, '../apps/backoffice/vite.config.ts'), 'utf8');
  assert.match(vite, /auth\/session-probe\.html/);
  assert.match(vite, /frame-ancestors 'self' \$\{webOrigin\}/);
});
