const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const probePath = path.join(__dirname, '../apps/backoffice/src/pages/auth/SessionProbePage.tsx');

test('session probe queries the unified identity endpoint for all staff tokens', () => {
  const source = fs.readFileSync(probePath, 'utf8');
  // UnifiedAuthController::me serves both admin ('*') and POS ('pos') tokens
  // and returns { actor, redirect: { path }, data }; /api/v1/admin/me returns
  // only a UserResource with no redirect, so the probe could never succeed.
  assert.match(source, /\/api\/v1\/auth\/me/);
  assert.doesNotMatch(source, /\/api\/v1\/admin\/me/);
});

test('session probe still maps dashboard and pos destinations only', () => {
  const source = fs.readFileSync(probePath, 'utf8');
  assert.match(source, /redirect\?\.path/);
  assert.match(source, /'\/dashboard'/);
  assert.match(source, /'\/pos'/);
});
