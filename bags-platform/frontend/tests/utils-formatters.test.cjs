const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');

function loadTs(relativePath) {
  const fullPath = path.join(__dirname, relativePath);
  const code = ts.transpileModule(fs.readFileSync(fullPath, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  const mod = { exports: {} };
  const customRequire = (reqPath) => {
    if (reqPath.startsWith('.')) {
      const resolved = path.join(path.dirname(fullPath), reqPath.endsWith('.ts') ? reqPath : reqPath + '.ts');
      if (fs.existsSync(resolved)) {
        return loadTs(path.relative(__dirname, resolved));
      }
    }
    return require(reqPath);
  };
  new Function('exports', 'module', 'require', code)(mod.exports, mod, customRequire);
  return mod.exports;
}

const { formatDate } = loadTs('../packages/utils/src/date.ts');
const { formatCurrency, formatStorePrice, formatShippingPrice, parseNumericAmount } = loadTs('../packages/utils/src/currency.ts');
const { toLatinDigits, formatQty, QTY_RE, QTY_MAX } = loadTs('../packages/utils/src/numbers.ts');
const { getStatusLabel, OrderStatusLabelsAr } = loadTs('../packages/utils/src/enums.ts');
const { splitIndexedErrorKey, groupIndexedErrors } = loadTs('../packages/utils/src/errors.ts');

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
  assert.equal(parseNumericAmount('42.5'), 42.5);
  assert.equal(parseNumericAmount(undefined, 10), 10);
  assert.equal(parseNumericAmount('invalid', 5), 5);
});

test('formatShippingPrice handles free and formatted amounts', () => {
  assert.equal(formatShippingPrice(0), 'Free');
  assert.equal(formatShippingPrice('0'), 'Free');
  assert.equal(formatShippingPrice('0.00'), 'Free');
  assert.equal(formatShippingPrice(null), 'Free');
  assert.ok(formatShippingPrice(50).includes('50'));
});

test('getStatusLabel handles case insensitivity and fallbacks', () => {
  assert.equal(getStatusLabel(OrderStatusLabelsAr, 'pending'), 'قيد المراجعة');
  assert.equal(getStatusLabel(OrderStatusLabelsAr, 'PENDING'), 'قيد المراجعة');
  assert.equal(getStatusLabel(OrderStatusLabelsAr, null), '-');
  assert.equal(getStatusLabel(OrderStatusLabelsAr, 'unknown_status'), 'unknown_status');
});

test('toLatinDigits normalizes Arabic-Indic and Eastern Arabic digits and comma separators', () => {
  assert.equal(toLatinDigits(null), '');
  assert.equal(toLatinDigits(undefined), '');
  assert.equal(toLatinDigits(''), '');
  assert.equal(toLatinDigits('٠١٢٣٤٥٦٧٨٩'), '0123456789');
  assert.equal(toLatinDigits('۰۱۲۳۴۵۶۷۸۹'), '0123456789');
  assert.equal(toLatinDigits('١٢٫٣٤'), '12.34');
  assert.equal(toLatinDigits('١٢,٣٤'), '12.34');
  assert.equal(toLatinDigits(123), '123');
});

test('toLatinDigits strips unambiguous thousands separators without breaking decimal commas', () => {
  assert.equal(toLatinDigits('1,500'), '1500');
  assert.equal(toLatinDigits('1,234.50'), '1234.50');
  assert.equal(toLatinDigits('١٬٥٠٠'), '1500');
  assert.equal(parseNumericAmount('1,500'), 1500);
  // Pinned contract: a lone comma keeps decimal meaning.
  assert.equal(toLatinDigits('١٢,٣٤'), '12.34');
  assert.equal(toLatinDigits('١٢٫٣٤'), '12.34');
});

test('parseNumericAmount correctly parses Arabic numerals via toLatinDigits', () => {
  assert.equal(parseNumericAmount('١٥٠'), 150);
  assert.equal(parseNumericAmount('١٥٠.٥'), 150.5);
  assert.equal(parseNumericAmount('١٥٠٫٧٥'), 150.75);
});

test('formatQty formats quantities with up to 4 decimal places and handles Arabic input', () => {
  assert.equal(formatQty(null), '—');
  assert.equal(formatQty(undefined), '—');
  assert.equal(formatQty(''), '—');
  assert.equal(formatQty('invalid'), '—');
  assert.equal(formatQty(15), (15).toLocaleString('ar-EG', { maximumFractionDigits: 4 }));
  assert.equal(formatQty('25.5'), (25.5).toLocaleString('ar-EG', { maximumFractionDigits: 4 }));
  assert.equal(formatQty('١٠.٢٥'), (10.25).toLocaleString('ar-EG', { maximumFractionDigits: 4 }));
  assert.ok(QTY_RE.test('123.4567'));
  assert.ok(!QTY_RE.test('123.45678'));
  assert.equal(QTY_MAX, 99999999.9999);
});

test('splitIndexedErrorKey parses indexed error keys and returns null for non-matching keys', () => {
  assert.deepEqual(splitIndexedErrorKey('items.3.quantity'), { index: 3, field: 'quantity' });
  assert.deepEqual(splitIndexedErrorKey('variants.0.price', 'variants'), { index: 0, field: 'price' });
  assert.equal(splitIndexedErrorKey('items.notAnIndex.field'), null);
  assert.equal(splitIndexedErrorKey('other_key'), null);
  assert.equal(splitIndexedErrorKey(null), null);
});

test('groupIndexedErrors groups flat error keys into per-row dictionaries', () => {
  const fields = {
    'items.0.sku': ['SKU مطلوب'],
    'items.0.quantity': 'الكمية غير كافية',
    'items.2.barcode': ['الباركود مكرر'],
    'general_error': 'خطأ عام',
  };
  const grouped = groupIndexedErrors(fields);
  assert.deepEqual(grouped, {
    0: { sku: 'SKU مطلوب', quantity: 'الكمية غير كافية' },
    2: { barcode: 'الباركود مكرر' },
  });
  assert.deepEqual(groupIndexedErrors(null), {});
});
