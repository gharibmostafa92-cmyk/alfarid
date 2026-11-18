const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const posDir = path.join(__dirname, '../apps/backoffice/src/pages/pos');

test('F-13: StocktakeVoiceCount configures continuous/interimResults=false, 15s safety timeout, normalizes words digits, and resets request.current on field edits', () => {
  const src = fs.readFileSync(path.join(posDir, 'StocktakeVoiceCount.tsx'), 'utf8');
  assert.match(src, /listener\.continuous\s*=\s*false/, 'must set listener.continuous = false');
  assert.match(src, /listener\.interimResults\s*=\s*false/, 'must set listener.interimResults = false');
  assert.match(src, /15_?000/, 'must set 15s safety timeout for hung speech engine');
  assert.match(src, /toAsciiDigits\(words\)/, 'must normalize Arabic/Persian digits in words');
  assert.match(src, /request\.current\s*=\s*null;[\s\S]*?setBarcode/, 'must clear request.current on barcode edit');
});

test('F-14: PosPage gates syncActor and style injection on mountFullPos (branchId && canPos && canCreateSales)', () => {
  const src = fs.readFileSync(path.join(posDir, 'PosPage.tsx'), 'utf8');
  assert.match(src, /mountFullPos\s*=\s*Boolean\(branchId\s*&&\s*canPos\s*&&\s*canCreateSales\)/, 'must define mountFullPos');
  assert.match(src, /if\s*\(!mountFullPos\)\s*return;\s*pos\.syncActor\(user\)/, 'must guard syncActor on branchId, POS and sales permissions');
  assert.match(src, /if\s*\(!mountFullPos\)\s*return;\s*const styleEl/, 'must guard style injection on mountFullPos');
});
