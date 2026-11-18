const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');

const assistantDir = path.join(__dirname, '../apps/backoffice/src/pages/admin/assistant');

function loadTs(file) {
  const src = fs.readFileSync(path.join(assistantDir, file), 'utf8');
  const code = ts.transpileModule(src, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.React },
  }).outputText;
  const mod = { exports: {} };
  const fakeReact = {
    useState: (v) => [typeof v === 'function' ? v() : v, () => {}],
    Fragment: Symbol.for('react.fragment'),
    createElement: (type, props, ...children) => ({ type, props: { ...props, children } }),
  };
  const fakeRequire = (id) => {
    if (id === 'react') return fakeReact;
    if (id === './icons') return { Icon: () => null };
    return {};
  };
  new Function('exports', 'require', 'module', 'React', code)(mod.exports, fakeRequire, mod, fakeReact);
  return mod.exports;
}

test('F-05: stream.ts ignores SSE comments, preserves leading spaces after data:, and handles trailing CR/U+FFFD', () => {
  const { parseAgentEvents, createIncrementalSseParser } = loadTs('stream.ts');
  const withComment = ': ping\n\n: keep-alive\nevent: reply\ndata: {"text":"  ok"}\n\n';
  assert.deepEqual(parseAgentEvents(withComment), [{ event: 'reply', data: { text: '  ok' } }]);

  // Incremental parser with chunk ending on \r and U+FFFD replacement
  const parser = createIncrementalSseParser();
  const c1 = 'event: reply\r\ndata: {"text":"مر\uFFFD';
  const c2 = 'event: reply\r\ndata: {"text":"مرحبا"}\r\n\r\n';
  assert.deepEqual(parser.feed(c1), []);
  assert.deepEqual(parser.feed(c2), [{ event: 'reply', data: { text: 'مرحبا' } }]);
});

test('F-01 & F-05: api.ts accepts AbortSignal and prioritizes reply over non-fatal error frames', () => {
  const apiSrc = fs.readFileSync(path.join(assistantDir, 'api.ts'), 'utf8');
  assert.match(apiSrc, /uploadAttachments:\s*\([^)]*signal\?:\s*AbortSignal/, 'uploadAttachments must accept signal?: AbortSignal');
  assert.match(apiSrc, /stream:\s*async\s*\([\s\S]*?signal\?:\s*AbortSignal/, 'stream must accept signal?: AbortSignal');
  assert.match(apiSrc, /const hasReply\s*=\s*events\.some/, 'stream should check reply before failing on partial error');
});

test('F-01, F-10, F-11, F-16: AssistantPage.tsx fixes abort/stale-closure, oncePassword modal, history.replaceState, and invalid dates', () => {
  const src = fs.readFileSync(path.join(assistantDir, 'AssistantPage.tsx'), 'utf8');
  assert.match(src, /AbortController/, 'must use AbortController for stream/upload');
  assert.match(src, /activeRequestIdRef/, 'must guard async completion with activeRequestIdRef');
  assert.match(src, /window\.history\.state/, 'must preserve window.history.state in replaceState');
  assert.doesNotMatch(src, /history\.replaceState\(null,\s*'',\s*'#\/assistant'\)/, 'must not hardcode #/assistant');
  assert.match(src, /Number\.isNaN\(d\.getTime\(\)\)/, 'dayKey/relTime must guard against Invalid Date');
  assert.match(src, /setCopied\(false\)/, 'oncePassword close must reset copied state');
});

test('F-15: RichText.tsx supports inline code inside bold text', () => {
  const { RichText } = loadTs('RichText.tsx');
  const tree = RichText({ text: '**المنتج `P00042` غير نشط**' });
  const serialized = JSON.stringify(tree);
  assert.ok(serialized.includes('"type":"strong"'), 'must render <strong> around bold text containing inline code');
  assert.ok(serialized.includes('"type":"code"'), 'must render <code> inside bold text');
  assert.ok(!serialized.includes('**'), 'must not leave raw ** markers');
});
