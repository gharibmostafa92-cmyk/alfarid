const { test } = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { randomUUID } = require('node:crypto');

test('an isolated full Redis refuses new writes without evicting security locks', { timeout: 60000 }, () => {
  const name = `alfarid-test-noeviction-${randomUUID()}`;
  const docker = (args, input) => {
    const result = spawnSync('docker', args, { encoding: 'utf8', input, timeout: 10000 });
    assert.equal(result.status, 0, result.stderr || result.error?.message);
    return result.stdout.trim();
  };
  let started = false;
  try {
    docker(['run', '-d', '--rm', '--name', name, '--network', 'none', 'redis:7-alpine',
      'redis-server', '--save', '', '--appendonly', 'no', '--maxmemory', '4mb', '--maxmemory-policy', 'noeviction']);
    started = true;
    docker(['exec', name, 'sh', '-c', 'for attempt in 1 2 3 4 5 6 7 8 9 10; do redis-cli ping 2>/dev/null && exit 0; sleep 0.2; done; exit 1']);
    const cli = (...args) => docker(['exec', name, 'redis-cli', '--raw', ...args]);
    assert.equal(cli('PING'), 'PONG');
    assert.equal(cli('SET', 'security-lock', 'held', 'NX', 'PX', '60000'), 'OK');
    let refused = false;
    for (let index = 0; index < 60; index++) {
      const reply = docker(['exec', '-i', name, 'redis-cli', '--raw', '-x', 'SET', `payload-${index}`], 'x'.repeat(128 * 1024));
      if (reply.includes('OOM')) {
        refused = true;
        break;
      }
      assert.equal(reply, 'OK');
    }
    assert.equal(refused, true, 'The memory limit must be reached by the isolated workload.');
    assert.equal(cli('GET', 'security-lock'), 'held');
    assert.match(cli('INFO', 'stats'), /^evicted_keys:0\r?$/m);
  } finally {
    if (started) docker(['stop', name]);
  }
});
