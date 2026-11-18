const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const assistantDir = path.join(__dirname, '../apps/backoffice/src/pages/admin/assistant');

test('F-02, F-09, F-12: WhatsAppPanel & AssistantAdministration fix partial onSaved, numeric drafts/validation, and staff/logout races', () => {
  const wp = fs.readFileSync(path.join(assistantDir, 'WhatsAppPanel.tsx'), 'utf8');
  const aa = fs.readFileSync(path.join(assistantDir, 'AssistantAdministration.tsx'), 'utf8');

  // F-02: saveChannel must merge only saved data onto original, not { ...settings }
  assert.doesNotMatch(wp, /onSaved\(\{\s*\.\.\.settings\s*\}\)/, 'saveChannel must not overwrite original with full settings');
  assert.match(wp, /onSaved\(\{\s*\.\.\.original,\s*\.\.\.data\s*\}\)/, 'saveChannel must merge only saved data onto original');

  // F-09: WhatsAppPanel must have numeric drafts & range validation
  assert.match(wp, /channelDrafts/, 'WhatsAppPanel must keep draft text for numeric inputs');
  assert.match(wp, /Number\.isInteger/, 'WhatsAppPanel must validate integer bounds before saving');
  assert.match(aa, /Number\.isInteger/, 'AssistantAdministration must validate integer bounds before saving');

  // F-12: loggingOut lock, userId reset when missing from staff, userId reset on saveIdentity
  assert.match(wp, /loggingOut/, 'WhatsAppPanel must lock logout button with loggingOut state');
  assert.match(wp, /rows\.some\(r => r\.id === (?:prev|userId)\)/, 'WhatsAppPanel must clear userId if absent from staff results');
});

test('F-03, F-04, F-06, F-16, F-17: AssistantBlock fixes coupon_batch preview, CSS color_hex injection, ttlTotal memo, safe dates, and 60s revokeObjectURL', () => {
  const ab = fs.readFileSync(path.join(assistantDir, 'AssistantBlock.tsx'), 'utf8');
  const ap = fs.readFileSync(path.join(assistantDir, 'AssistantPage.tsx'), 'utf8');

  // F-03
  assert.match(ab, /block\.type === 'coupon_batch'[\s\S]*?DiffTable|block\.type === 'confirm_action' \|\| block\.type === 'coupon_batch'/, 'coupon_batch confirmation must render details');

  // F-04
  assert.match(ab, /isSafeHexColor/, 'AssistantBlock must validate color_hex with isSafeHexColor');
  assert.doesNotMatch(ab, /style=\{\{\s*background:\s*[rv]\.color_hex\s*\}\}/, 'must not inject raw color_hex into background shorthand');

  // F-06
  assert.doesNotMatch(ab, /useState<number \| null>\(\(\) =>/, 'ttlTotal/undoTotal must use useMemo instead of one-time useState');
  assert.match(ap, /`\$\{m\.id\}-\$\{block\.type\}-/, 'AssistantPage block key must include block.type');

  // F-16
  assert.match(ab, /safeFormatDate/, 'AssistantBlock must format dates via safeFormatDate');

  // F-17
  assert.doesNotMatch(ab, /URL\.revokeObjectURL\(href\),\s*1000\)/, 'revokeObjectURL timeout must not be 1000ms');
  assert.match(ab, /URL\.revokeObjectURL\(href\),\s*60_?000\)/, 'revokeObjectURL timeout must be 60000ms');
});

test('F-07: AssistantComposer splits @ quota, updates title, and preserves boundary on mention pick/prune', () => {
  const ac = fs.readFileSync(path.join(assistantDir, 'AssistantComposer.tsx'), 'utf8');
  assert.match(ac, /variants\.slice\(0,\s*6\)/, 'must cap variants at 6 when merging with users');
  assert.match(ac, /users\.slice\(0,\s*6\)/, 'must cap users at 6 when merging with variants');
  assert.ok(ac.includes('(^|\\s)([@#!$&])'), 'pickMention must preserve leading whitespace boundary');
  assert.doesNotMatch(ac, /items\.some\(m => !text\.includes\(m\.label\)\)/, 'mention pruning must not use raw text.includes');
});

test('F-08: assistant.css defines all inventory and finance block classes', () => {
  const css = fs.readFileSync(path.join(assistantDir, 'assistant.css'), 'utf8');
  for (const cls of ['.as-tablewrap', '.as-linkbtn', '.as-pick', '.as-pickbtn', '.as-pickmain', '.as-list', '.as-stepdot']) {
    assert.ok(css.includes(cls), `assistant.css must define ${cls}`);
  }
});
