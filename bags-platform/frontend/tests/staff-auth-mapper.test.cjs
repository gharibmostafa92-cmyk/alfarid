const { test } = require('node:test');
const assert = require('node:assert/strict');
require('./helpers/register-ts.cjs');
const { toStaffUser } = require('../apps/backoffice/src/stores/mappers.ts');

test('staff profile mapping preserves a forced password rotation and version zero', () => {
  const profile = { id: 23, name: 'Cashier', email: 'cashier@example.test', version: 0, must_change_password: true };
  for (const response of [profile, { data: profile }]) {
    const user = toStaffUser(response);
    assert.equal(user.must_change_password, true);
    assert.equal(user.version, 0);
  }
});
