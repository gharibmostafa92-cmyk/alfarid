const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');
function load(file) {
  const filename = path.resolve(__dirname, '../apps/backoffice/src/pages/admin/assistant/', file);
  const compiled = new Module(filename, module);
  compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText, filename);
  return compiled.exports;
}
test('SSE parser waits for complete frames and preserves Arabic status and reply', () => {
  const { parseAgentEvents } = load('stream.ts');
  const stream = 'event: status\ndata: {"message":"بدوّر على الفرع"}\n\nevent: reply\ndata: {"text":"جاهز","ui_blocks":[],"message_id":7}\n\n';
  assert.equal(parseAgentEvents(stream.slice(0, -2)).length, 1);
  const events = parseAgentEvents(stream);
  assert.equal(events[0].data.message, 'بدوّر على الفرع');
  assert.equal(events[1].data.message_id, 7);
  assert.deepEqual(parseAgentEvents('event: status\ndata: nope\n\n'), []);
});
