import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const files = [
  'apps/storefront/src/app/account/returns/page.tsx',
  'apps/storefront/src/components/sophie/checkout/ContactSection.tsx',
];

const emptyCopy = [
  /<button\b[^>]*>\s*<\/button>/,
  /<Link\b[^>]*>\s*<\/Link>/,
  /<label\b[^>]*>\s*<\/label>/,
  /aria-label="\s*"/,
];

test('visible controls keep their labels', () => {
  for (const file of files) {
    const source = readFileSync(new URL('../' + file, import.meta.url), 'utf8');
    for (const pattern of emptyCopy) {
      assert.equal(pattern.test(source), false, `${file} matched ${pattern}`);
    }
  }
});
