const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');

function loadTs(relativePath) {
  const code = ts.transpileModule(fs.readFileSync(path.join(__dirname, relativePath), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  const mod = { exports: {} };
  new Function('exports', 'module', code)(mod.exports, mod);
  return mod.exports;
}

const { formatDate } = loadTs('../packages/utils/src/date.ts');
const { formatCurrency, formatStorePrice } = loadTs('../packages/utils/src/currency.ts');
const { getStatusLabel, OrderStatusLabelsAr } = loadTs('../packages/utils/src/enums.ts');

test('formatDate handles nullish, empty, and invalid dates safely', () => {
  assert.equal(formatDate(null), '-');
  assert.equal(formatDate(undefined), '-');
  assert.equal(formatDate(''), '-');
  assert.equal(formatDate('   '), '-');
  assert.equal(formatDate('invalid-date-string'), '-');

  const d = new Date('2026-11-07T12:00:00Z');
  const result = formatDate(d, { locale: 'en-US' });
  assert.ok(result.includes('2026') || result.includes('Nov'));
});

test('formatStorePrice parses string amounts and falls back safely', () => {
  assert.ok(formatStorePrice('150.50').includes('150.5'));
  assert.ok(formatStorePrice(200).includes('200'));
  assert.ok(formatStorePrice(null).includes('0'));
  assert.ok(formatStorePrice(undefined).includes('0'));
  assert.ok(formatStorePrice('abc').includes('0'));
});

test('getStatusLabel handles case insensitivity and fallbacks', () => {
  assert.equal(getStatusLabel(OrderStatusLabelsAr, 'pending'), 'قيد المراجعة');
  assert.equal(getStatusLabel(OrderStatusLabelsAr, 'PENDING'), 'قيد المراجعة');
  assert.equal(getStatusLabel(OrderStatusLabelsAr, null), '-');
  assert.equal(getStatusLabel(OrderStatusLabelsAr, 'unknown_status'), 'unknown_status');
});
