'use strict';
// Lets node:test files require('.ts') sources (and extensionless relative imports)
// by transpiling them to CommonJS with the repo's TypeScript.
const fs = require('node:fs');
const ts = require('typescript');

if (!require.extensions['.ts']) {
  require.extensions['.ts'] = (module, filename) => {
    const out = ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
      compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
      fileName: filename,
    });
    module._compile(out.outputText, filename);
  };
}
