'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');

test('both POS entry points use the shared terminal and styles', () => {
  const standalone = 'apps/pos/src';
  const backoffice = 'apps/backoffice/src/pages/pos';
  const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

  assert.match(read(`${standalone}/App.tsx`), /import \{ PosApp, ToastHost \} from '@alfarid\/pos-core'/);
  assert.match(read(`${backoffice}/PosPage.tsx`), /import \{ PosApp, ToastHost, pos \} from '@alfarid\/pos-core'/);
  for (const file of [`${standalone}/index.css`, `${backoffice}/PosPage.tsx`]) {
    for (const stylesheet of ['pos.css', 'tokens.css']) {
      assert.ok(read(file).includes(`@alfarid/pos-core/styles/${stylesheet}`));
    }
  }
  for (const app of [standalone, backoffice]) {
    for (const directory of ['components', 'lib', 'services', 'stores', 'styles', 'types']) {
      assert.equal(fs.existsSync(path.join(root, app, directory)), false, `${app}/${directory}`);
    }
  }
});
