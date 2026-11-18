import assert from 'node:assert/strict';
import test from 'node:test';
import { usePostgresAuthState } from '../src/auth-state.js';

process.env.WA_SESSION_KEY = '0123456789abcdef0123456789abcdef';

function createMockPool() {
  const rows = new Map();
  const queries = [];
  let inFlightTx = false;
  let maxConcurrentTx = 0;
  let activeTx = 0;

  async function runQuery(sql, params = [], clientTag = 'pool') {
    queries.push({ sql: sql.trim(), params, clientTag });

    if (sql.includes('CREATE TABLE IF NOT EXISTS wa_gateway_auth')) {
      return { rows: [] };
    }
    if (sql === 'BEGIN') {
      activeTx += 1;
      maxConcurrentTx = Math.max(maxConcurrentTx, activeTx);
      inFlightTx = true;
      return { rows: [] };
    }
    if (sql === 'COMMIT' || sql === 'ROLLBACK') {
      activeTx = Math.max(0, activeTx - 1);
      inFlightTx = false;
      return { rows: [] };
    }
    if (sql.includes('SELECT payload FROM wa_gateway_auth WHERE id = $1')) {
      const payload = rows.get(params[0]);
      return { rows: payload ? [{ payload }] : [] };
    }
    if (sql.includes('SELECT id, payload FROM wa_gateway_auth WHERE id = ANY($1::text[])')) {
      const ids = params[0] || [];
      const found = [];
      for (const id of ids) {
        if (rows.has(id)) {
          found.push({ id, payload: rows.get(id) });
        }
      }
      return { rows: found };
    }
    if (sql.includes('INSERT INTO wa_gateway_auth')) {
      // Simulate async DB latency to catch race conditions if writes are not serialized
      await new Promise((r) => setTimeout(r, 5));
      rows.set(params[0], params[1]);
      return { rows: [] };
    }
    if (sql.includes('DELETE FROM wa_gateway_auth WHERE id = $1')) {
      rows.delete(params[0]);
      return { rows: [] };
    }
    if (sql.includes('DELETE FROM wa_gateway_auth')) {
      rows.clear();
      return { rows: [] };
    }
    throw new Error(`Unexpected SQL: ${sql}`);
  }

  return {
    rows,
    queries,
    get maxConcurrentTx() {
      return maxConcurrentTx;
    },
    get inFlightTx() {
      return inFlightTx;
    },
    query(sql, params) {
      return runQuery(sql, params, 'pool');
    },
    async connect() {
      let released = false;
      return {
        query: (sql, params) => runQuery(sql, params, 'client'),
        release: () => {
          released = true;
        },
        get released() {
          return released;
        },
      };
    },
  };
}

test('keys.get batches multiple ids into a single ANY($1::text[]) query', async () => {
  const pool = createMockPool();
  const auth = await usePostgresAuthState(pool);

  await auth.state.keys.set({
    session: {
      'user-1': { data: 'a' },
      'user-2': { data: 'b' },
    },
  });

  pool.queries.length = 0;
  const result = await auth.state.keys.get('session', ['user-1', 'user-2', 'missing']);

  assert.deepEqual(result, {
    'user-1': { data: 'a' },
    'user-2': { data: 'b' },
  });
  const selectQueries = pool.queries.filter((q) => q.sql.startsWith('SELECT'));
  assert.equal(selectQueries.length, 1);
  assert.match(selectQueries[0].sql, /ANY\(\$1::text\[\]\)/);
});

test('keys.set serializes concurrent writes and wraps each call in a DB transaction', async () => {
  const pool = createMockPool();
  const auth = await usePostgresAuthState(pool);

  pool.queries.length = 0;
  await Promise.all([
    auth.state.keys.set({ session: { a: { v: 1 } } }),
    auth.state.keys.set({ session: { b: { v: 2 } } }),
  ]);

  assert.equal(pool.maxConcurrentTx, 1, 'transactions must be serialized, never concurrent');
  const beginCount = pool.queries.filter((q) => q.sql === 'BEGIN').length;
  const commitCount = pool.queries.filter((q) => q.sql === 'COMMIT').length;
  assert.equal(beginCount, 2);
  assert.equal(commitCount, 2);
});

test('corrupt auth row logs error and returns null instead of crashing', async () => {
  const pool = createMockPool();
  const errors = [];
  const logger = { error: (obj, msg) => errors.push({ obj, msg }) };

  // Seed corrupt payload for creds and a key
  pool.rows.set('creds', Buffer.from('corrupt-bytes'));
  pool.rows.set('key:session:bad', Buffer.from('corrupt-bytes'));

  const auth = await usePostgresAuthState(pool, { logger });
  assert.ok(auth.state.creds.noiseKey, 'falls back to initAuthCreds when creds row is corrupt');

  const keys = await auth.state.keys.get('session', ['bad']);
  assert.deepEqual(keys, {});
  assert.ok(errors.length >= 2, 'logs decryption errors');
});
