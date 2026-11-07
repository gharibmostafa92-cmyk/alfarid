'use strict';

// task.txt item 35 (frontend): incremental SSE parser — split frames,
// multi-line data, bad JSON, and cursor resets on shorter text.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ts = require('typescript');

let cachedModule = null;
async function load() {
  if (!cachedModule) {
    const code = ts.transpileModule(
      fs.readFileSync(path.join(__dirname, '../apps/backoffice/src/pages/admin/assistant/stream.ts'), 'utf8'),
      { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }
    ).outputText;
    const mod = { exports: {} };
    new Function('exports', 'module', 'require', code)(mod.exports, mod, () => ({}));
    cachedModule = mod.exports;
  }
  return cachedModule;
}

test('full frames parse in one feed, trailing partial frame buffered', async () => {
  const { createIncrementalSseParser } = await load();
  const parser = createIncrementalSseParser();
  const first = parser.feed('event: status\ndata: {"message":"a"}\n\nevent: reply\ndata: {"text":"hi"}\n\n');
  assert.deepEqual(first, [
    { event: 'status', data: { message: 'a' } },
    { event: 'reply', data: { text: 'hi' } },
  ]);
  // A trailing partial frame yields nothing until completed.
  const partial = parser.feed('event: reply\ndata: {"text":"par');
  assert.deepEqual(partial, []);
  const done = parser.feed('event: reply\ndata: {"text":"par');
  assert.deepEqual(done, []);
});

test('split frames reassemble across chunk boundaries', async () => {
  const { createIncrementalSseParser } = await load();
  const full = 'event: status\ndata: {"message":"working"}\n\nevent: reply\ndata: {"text":"done"}\n\n';
  const parser = createIncrementalSseParser();
  const events = [];
  // Feed one character at a time: every completed frame must arrive exactly once.
  for (let i = 1; i <= full.length; i++) {
    events.push(...parser.feed(full.slice(0, i)));
  }
  assert.deepEqual(events, [
    { event: 'status', data: { message: 'working' } },
    { event: 'reply', data: { text: 'done' } },
  ]);
});

test('multi-line data joins with newlines and bad JSON is skipped', async () => {
  const { parseAgentEvents } = await load();
  const text = 'event: reply\ndata: {"a": 1}\ndata: trailing\n\nevent: broken\ndata: not-json\n\nevent: ok\ndata: {"b": 2}\n\n';
  assert.deepEqual(parseAgentEvents(text), [{ event: 'ok', data: { b: 2 } }]);
});

test('shorter text resets the cursor (new response)', async () => {
  const { createIncrementalSseParser } = await load();
  const parser = createIncrementalSseParser();
  parser.feed('event: reply\ndata: {"text":"first response is long"}\n\n');
  const events = parser.feed('event: reply\ndata: {"text":"second"}\n\n');
  assert.deepEqual(events, [{ event: 'reply', data: { text: 'second' } }]);
});
