'use strict';

// task6 findings F2/F6/F7/F18 (frontend): allowlists, SSE framing, settings diff.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

const SRC = process.env.TASK6_ASSISTANT_DIR
  || path.join(__dirname, '../apps/backoffice/src/pages/admin/assistant');

const streamExports = (() => {
  const filename = path.resolve(SRC, 'stream.ts');
  const compiled = new Module(filename, module);
  compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText, filename);
  return compiled.exports;
})();

function load(file) {
  if (file === 'stream.ts') return streamExports;
  const filename = path.resolve(SRC, file);
  const code = ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  const mod = { exports: {} };
  const stubRequire = (spec) => {
    if (spec === './stream') return streamExports;
    if (spec === '@alfarid/api-client') return { defaultApiClient: { defaults: {} } };
    return {};
  };
  new Function('exports', 'module', 'require', code)(mod.exports, mod, stubRequire);
  return mod.exports;
}

test('live parse drops only the unterminated tail; complete keeps it', () => {
  const { parseAgentEvents } = load('stream.ts');
  const body = 'event: status\ndata: {"message":"x"}\n\nevent: reply\ndata: {"text":"done"}\n\n';
  assert.equal(parseAgentEvents(body).length, 2, 'terminated body parses fully even live');
  const cut = body.slice(0, -2);
  assert.equal(parseAgentEvents(cut).length, 1, 'live parse drops the trailing partial frame');
  const done = parseAgentEvents(cut, true);
  assert.equal(done.length, 2);
  assert.equal(done[1].event, 'reply');
});

test('complete parse keeps a final frame without trailing blank line', () => {
  const { parseAgentEvents } = load('stream.ts');
  const done = parseAgentEvents('event: reply\ndata: {"text":"done"}', true);
  assert.equal(done.length, 1);
  assert.equal(done[0].event, 'reply');
});

test('incremental parser flush drains a proxy-stripped tail', () => {
  const { parseAgentEvents, createIncrementalSseParser } = load('stream.ts');
  // A proxy that closes the stream without the trailing blank line: the
  // final reply sits in the buffer until flush() instead of being re-parsed.
  const stripped = 'event: reply\ndata: {"text":"done"}';
  const parser = createIncrementalSseParser();
  assert.deepEqual(parser.feed(stripped), []);
  assert.deepEqual(parser.flush(), parseAgentEvents(stripped, true));
  assert.deepEqual(parser.flush(), [], 'flush drains exactly once');
});

test('non-JSON error frame surfaces instead of vanishing', () => {
  const { parseAgentEvents } = load('stream.ts');
  const events = parseAgentEvents('event: error\ndata: Bad Gateway\n\n', true);
  assert.equal(events.length, 1);
  assert.equal(events[0].event, 'error');
});

test('download allowlist: own admin paths only', () => {
  const { resolveSafeDownloadPath } = load('api.ts');
  assert.equal(resolveSafeDownloadPath('/api/v1/admin/documents/abc123/download'), '/api/v1/admin/documents/abc123/download');
  assert.equal(resolveSafeDownloadPath('/api/v1/admin/dealers/D1/documents/doc-2/download'), '/api/v1/admin/dealers/D1/documents/doc-2/download');
  assert.equal(resolveSafeDownloadPath('https://attacker.tld/collect'), undefined);
  assert.equal(resolveSafeDownloadPath('//evil.tld/api/v1/admin/documents/x/download'), undefined);
  assert.equal(resolveSafeDownloadPath('/api/v1/admin/users/export'), undefined);
  assert.equal(resolveSafeDownloadPath('/api/v1/admin/documents/../secret/download'), undefined);
  assert.equal(resolveSafeDownloadPath('/api/v1/admin/documents/x/download?token=abc'), undefined);
  assert.equal(resolveSafeDownloadPath(123), undefined);
});

test('filenames are stripped of traversal and control chars', () => {
  const { sanitizeDownloadName } = load('api.ts');
  assert.equal(sanitizeDownloadName('../../etc/passwd', 'document'), 'passwd');
  assert.equal(sanitizeDownloadName('', 'document'), 'document');
  assert.equal(sanitizeDownloadName('  تقرير.pdf  ', 'document'), 'تقرير.pdf');
});

test('QR data URLs: strict base64 images only', () => {
  const { isSafeQrDataUrl } = load('api.ts');
  assert.equal(isSafeQrDataUrl('data:image/png;base64,iVBORw0KGgo='), true);
  assert.equal(isSafeQrDataUrl('https://example.test/qr.png'), false);
  assert.equal(isSafeQrDataUrl('javascript:alert(1)'), false);
  assert.equal(isSafeQrDataUrl('data:text/html;base64,PGI+'), false);
  assert.equal(isSafeQrDataUrl(null), false);
});

test('image sources: no script/blob/credential URLs', () => {
  const { isSafeImageSrc } = load('api.ts');
  assert.equal(isSafeImageSrc('/media/thumb.png'), true);
  assert.equal(isSafeImageSrc('data:image/png;base64,iVBORw0KGgo='), true);
  assert.equal(isSafeImageSrc('javascript:alert(1)'), false);
  assert.equal(isSafeImageSrc('blob:https://x/y'), false);
  assert.equal(isSafeImageSrc('https://cdn.example.test/a.png'), true);
  assert.equal(isSafeImageSrc('https://user:pass@cdn.example.test/a.png'), false);
});

test('settings diff carries changed keys only, with matching expected', () => {
  const { diffSettings, changedSettingKeys } = load('api.ts');
  const original = { ai_enabled: true, ai_daily_token_budget_per_user: 100, ai_max_steps: 6 };
  const current = { ai_enabled: true, ai_daily_token_budget_per_user: 200, ai_max_steps: 6 };
  assert.deepEqual(changedSettingKeys(current, original), ['ai_daily_token_budget_per_user']);
  const { data, expected } = diffSettings(current, original);
  assert.deepEqual(data, { ai_daily_token_budget_per_user: 200 });
  assert.deepEqual(expected, { ai_daily_token_budget_per_user: 100 });
  assert.deepEqual(Object.keys(data).sort(), Object.keys(expected).sort());
});

test('bold regex tolerates a single asterisk inside', () => {
  const src = fs.readFileSync(path.join(SRC, 'RichText.tsx'), 'utf8');
  const m = src.match(/const re = (\/.*?\/g);/);
  assert.ok(m, 'bold pattern must exist in RichText.tsx');
  const re = eval(m[1]);
  const hits = '**الإجمالي: 5 * 120**'.match(re);
  assert.ok(hits && hits[0] === '**الإجمالي: 5 * 120**', 'single * must not break bold');
  assert.ok('plain **a** text'.match(re));
});
