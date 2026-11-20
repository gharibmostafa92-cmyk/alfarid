const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const actorPath = path.join(__dirname, '../apps/storefront/src/components/auth/RequireActor.tsx');
const servicePath = path.join(__dirname, '../apps/storefront/src/services/auth.service.ts');
const loginPath = path.join(__dirname, '../apps/storefront/src/components/auth/LoginScreen.tsx');

test('RequireActor reuses the shared redirect builder and preserves the query string', () => {
  const source = fs.readFileSync(actorPath, 'utf8');
  // One builder for both the global 401 handler and the component, so the
  // full-page reload and the SPA navigation converge on the same URL.
  assert.match(source, /unauthorizedLoginRedirect/);
  assert.match(source, /window\.location\.search/);
  assert.doesNotMatch(source, /redirect=\$\{encodeURIComponent\(pathname\)\}/);
  assert.doesNotMatch(source, /redirect=' \+ encodeURIComponent\(pathname\)/);
});

test('unified login clears the guest cart on the server merge flag alone', () => {
  const source = fs.readFileSync(servicePath, 'utf8');
  // The server merges retail carts only; gating on subtype here reintroduced
  // the asymmetry where wholesale restored an already-consumed session.
  assert.match(source, /const merged = res\.cart_merged === true;/);
  assert.doesNotMatch(source, /subtype === 'retail' && res\.cart_merged/);
  // Unified login never yields a supplier actor (supplier portal is separate).
  assert.doesNotMatch(source, /actor\.type as 'customer' \| 'supplier'/);
});

test('LoginScreen restores channel deep links for wholesale and supplier destinations', () => {
  const source = fs.readFileSync(loginPath, 'utf8');
  assert.match(source, /authenticatedReturnPath\(destination, home, params\.get\('redirect'\)\)/);
});
