const { test } = require('node:test');
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
  new Function('exports', 'module', 'require', code)(mod.exports, mod, require);
  return mod.exports;
}

const { normalizePhone } = loadTs(
  '../apps/storefront/src/services/phone-format.ts',
);

test('bidi marks pasted from phone apps do not break normalization', () => {
  assert.equal(normalizePhone('\u202A+20 101 234 5678\u202C'), '01012345678');
  assert.equal(normalizePhone('\u200E0101\u200F234\u20665678\u2069'), '01012345678');
});

test('zero-width characters inside the number are stripped', () => {
  assert.equal(normalizePhone('0101\u200B234\u200C5678'), '01012345678');
});

test('letters and symbols are still rejected the same way', () => {
  assert.equal(normalizePhone('0101abc5678'), '0101abc5678');
});
