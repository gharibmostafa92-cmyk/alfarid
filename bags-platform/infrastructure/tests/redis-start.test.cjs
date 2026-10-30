const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

test('Redis startup upgrades persisted eviction policy while preserving the promoted role', () => {
  const folder = fs.mkdtempSync(path.join(os.tmpdir(), 'alfarid-redis-'));
  const unixPath = value => value.replaceAll('\\', '/').replace(/^([A-Za-z]):/, (_, drive) => `/${drive.toLowerCase()}`);
  const bash = process.platform === 'win32' ? 'C:/Program Files/Git/bin/bash.exe' : 'bash';
  const runtime = path.join(folder, 'runtime.conf');
  const script = path.join(folder, 'start.sh');
  const entrypoint = path.join(folder, 'docker-entrypoint.sh');
  try {
    fs.writeFileSync(script, fs.readFileSync(path.resolve(__dirname, '../redis/start.sh'), 'utf8')
      .replaceAll('/data', unixPath(folder)).replaceAll('\r\n', '\n'));
    fs.writeFileSync(entrypoint, '#!/bin/sh\ncat "$2"\n', { mode: 0o755 });
    fs.writeFileSync(runtime, 'port 6379\nmaxmemory-policy volatile-lru\nreplicaof 172.31.0.32 6379\n');
    const run = role => {
      const result = spawnSync(bash, ['-c', 'chown() { :; }; export -f chown; export PATH="$2:$PATH"; bash "$1" "$3"',
        'test', unixPath(script), unixPath(folder), role], {
        encoding: 'utf8', env: { ...process.env, REDIS_PASSWORD: 'test-password' },
      });
      assert.equal(result.status, 0, result.stderr);
      return result.stdout;
    };
    const upgraded = run('replica');
    assert.match(upgraded, /^maxmemory-policy noeviction$/m);
    assert.doesNotMatch(upgraded, /volatile-lru/);
    assert.match(upgraded, /^replicaof 172\.31\.0\.32 6379$/m);
    assert.equal(run('replica').match(/^maxmemory-policy /gm).length, 1, 'restart must not duplicate the policy');

    fs.writeFileSync(runtime, 'port 26379\nsentinel down-after-milliseconds mymaster 3000\n');
    const sentinel = run('sentinel');
    assert.doesNotMatch(sentinel, /maxmemory-policy/);
    assert.match(sentinel, /sentinel down-after-milliseconds mymaster 3000/);
  } finally {
    fs.rmSync(folder, { recursive: true, force: true });
  }
});
