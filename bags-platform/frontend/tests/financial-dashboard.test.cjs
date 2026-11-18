const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

const root = path.join(__dirname, '..');

function load(relative) {
  const filename = path.resolve(root, relative);
  const compiled = new Module(filename, module);
  compiled.filename = filename;
  compiled.paths = Module._nodeModulePaths(path.dirname(filename));
  compiled._compile(
    ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
      compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
        allowJs: true,
      },
    }).outputText,
    filename,
  );
  return compiled.exports;
}

const state = load('packages/ui/src/financial-dashboard/initial-state.js');

test('changeTheme resolves the requested mode without double-toggling', () => {
  // A caller asking for dark while already dark must stay dark; the old
  // implementation inverted the value whenever it equalled the current mode.
  assert.equal(state.resolveDarkMode(true), true);
  assert.equal(state.resolveDarkMode(false), false);
  assert.equal(state.resolveDarkMode(undefined), false);
  assert.equal(state.resolveDarkMode(null), false);
});

test('currency state has a real symbol per currency and no raw card PAN/CVV', () => {
  for (const currency of state.initialState.currencies) {
    assert.ok(currency.symbol && currency.symbol.length > 0, `${currency.code} needs a symbol`);
  }
  for (const card of state.initialState.cards) {
    assert.doesNotMatch(String(card.full), /\b\d{4} \d{4} \d{4} \d{4}\b/);
    assert.doesNotMatch(String(card.cvv), /^\d{3}$/);
  }
});
