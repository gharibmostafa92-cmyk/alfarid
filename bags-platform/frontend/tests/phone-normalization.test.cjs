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

const { normalizePhone, normalizeCode, toAsciiDigits } = loadTs(
  '../apps/storefront/src/services/phone-format.ts',
);

test('eastern arabic digits normalize exactly like the backend ArabicDigits map', () => {
  assert.equal(toAsciiDigits('٠١٠١٢٣٤٥٦٧٨'), '01012345678');
  assert.equal(toAsciiDigits('۰۱۲۳۴۵۶۷۸۹'), '0123456789');
  assert.equal(toAsciiDigits('+٢٠1012345678'), '+201012345678');
  assert.equal(toAsciiDigits('01012345678'), '01012345678');
});

test('arabic-indic digits with country code canonicalize to local form', () => {
  assert.equal(normalizePhone('٠١٠١٢٣٤٥٦٧٨'), '01012345678');
  assert.equal(normalizePhone('+٢٠١٠١٢٣٤٥٦٧٨'), '01012345678');
  assert.equal(normalizePhone('00201012345678'), '01012345678');
});

test('ascii formats keep working and invalid input passes through untouched', () => {
  assert.equal(normalizePhone('01012345678'), '01012345678');
  assert.equal(normalizePhone('+201012345678'), '01012345678');
  assert.equal(normalizePhone(' 01012345678 '), '01012345678');
  assert.equal(normalizePhone('not-a-phone'), 'notaphone');
  assert.equal(normalizePhone('12345'), '12345');
});

test('otp codes strip everything but ascii digits', () => {
  assert.equal(normalizeCode('١٢٣٤٥٦'), '123456');
  assert.equal(normalizeCode('12 34-56'), '123456');
  assert.equal(normalizeCode('abc'), '');
});

test('toAsciiDigits keeps hyphens and the source uses unicode digit ranges', () => {
  assert.equal(toAsciiDigits('010-1234-5678'), '010-1234-5678');
  assert.equal(toAsciiDigits('٠١٠-١٢٣٤-٥٦٧٨'), '010-1234-5678');
  assert.equal(normalizePhone('010-1234-5678'), '01012345678');
  const source = fs.readFileSync(path.join(__dirname, '../apps/storefront/src/services/phone-format.ts'), 'utf8');
  assert.match(source, /\\u0660-\\u0669/);
  assert.match(source, /\\u06f0-\\u06f9/);
});
