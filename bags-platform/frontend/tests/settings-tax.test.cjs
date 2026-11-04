const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');

// Execute the actual component's calculation, rather than a copied formula.
const filename = path.resolve(__dirname, '../apps/backoffice/src/pages/admin/admin-mock/settings.tsx');
const source = fs.readFileSync(filename, 'utf8');
const ast = ts.createSourceFile(filename, source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
const component = ast.statements.find(node => ts.isFunctionDeclaration(node) && node.name?.text === 'TaxTab');
assert.ok(component, 'Tax preview component must exist');
const code = ts.transpileModule(component.getText(ast) + '\nexports.preview = TaxTab;', {
  compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.React, target: ts.ScriptTarget.ES2022 },
}).outputText;

function preview(sample, rate, enabled, inclusive) {
  const amounts = [];
  const exported = {};
  new Function('exports', 'useState', 'fmtMoney', 'React', 'SectionCard', 'Icon', 'Toggle', 'Field', 'TextInput', 'Badge', code)(
    exported, () => [sample, () => {}], value => { amounts.push(value); return String(value); },
    { createElement: () => null }, 'SectionCard', 'Icon', 'Toggle', 'Field', 'TextInput', 'Badge',
  );
  exported.preview({ errors: {}, v: { default_tax_rate: rate, tax_enabled: enabled, prices_include_tax: inclusive }, set() {} });
  assert.equal(amounts.length, 3, 'Preview must render net, tax and gross');
  return amounts;
}

function close(actual, expected) {
  assert.ok(Math.abs(actual - expected) <= 1e-9 * Math.max(1, Math.abs(expected)), `${actual} != ${expected}`);
}

test('tax preview handles disabled, inclusive and exclusive tax', () => {
  assert.deepEqual(preview(1000, 14, false, true), [1000, 0, 1000]);
  const inclusive = preview(1140, 14, true, true);
  inclusive.forEach((value, index) => close(value, [1000, 140, 1140][index]));
  assert.deepEqual(preview(1000, 14, true, false), [1000, 140, 1140]);
});

test('tax preview preserves net plus tax equals gross across amounts and rates', () => {
  let seed = 20261104;
  const random = () => { seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0; return seed / 2 ** 32; };
  const cases = [[0, 0], [0, 100], [10000, 0], [10000, 100]];
  for (let i = 0; i < 500; i++) cases.push([random() * 10000, random() * 100]);
  for (const [amount, rate] of cases) {
    for (const enabled of [false, true]) {
      for (const inclusive of [false, true]) {
        const [net, tax, gross] = preview(amount, rate, enabled, inclusive);
        assert.ok(net >= 0 && tax >= 0 && gross >= 0);
        close(net + tax, gross);
        close(enabled && !inclusive ? net : gross, amount);
        if (!enabled) close(tax, 0);
      }
    }
  }
});
