import { createCipheriv, createDecipheriv, createHash, randomBytes } from 'node:crypto';
import { BufferJSON, initAuthCreds, proto } from '@whiskeysockets/baileys';

function deriveKey() {
  const secret = process.env.WA_SESSION_KEY || '';
  if (secret.length < 16) throw new Error('WA_SESSION_KEY is required');
  return createHash('sha256').update(secret).digest();
}

function encrypt(plain, derivedKey) {
  const iv = randomBytes(12);
  const cipher = createCipheriv('aes-256-gcm', derivedKey, iv);
  const enc = Buffer.concat([cipher.update(plain, 'utf8'), cipher.final()]);
  return Buffer.concat([iv, cipher.getAuthTag(), enc]);
}

function decrypt(payload, derivedKey) {
  const iv = payload.subarray(0, 12);
  const tag = payload.subarray(12, 28);
  const enc = payload.subarray(28);
  const decipher = createDecipheriv('aes-256-gcm', derivedKey, iv);
  decipher.setAuthTag(tag);
  return Buffer.concat([decipher.update(enc), decipher.final()]).toString('utf8');
}

export async function usePostgresAuthState(pool, { logger = console } = {}) {
  const derivedKey = deriveKey();

  await pool.query(`CREATE TABLE IF NOT EXISTS wa_gateway_auth (
    id text PRIMARY KEY,
    payload bytea NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now()
  )`);

  let writeChain = Promise.resolve();
  function enqueueWrite(fn) {
    const next = writeChain.then(fn, fn);
    writeChain = next.catch(() => {});
    return next;
  }

  function decodeRow(id, payload) {
    return JSON.parse(decrypt(payload, derivedKey), BufferJSON.reviver);
  }

  function safeDecode(id, payload) {
    try {
      return decodeRow(id, payload);
    } catch (err) {
      logger.error({ err, id }, 'failed to decrypt auth state row');
      return null;
    }
  }

  async function read(id, { failOnDecryptError = false } = {}) {
    const result = await pool.query('SELECT payload FROM wa_gateway_auth WHERE id = $1', [id]);
    if (!result.rows[0]) return null;
    if (failOnDecryptError) {
      try {
        return decodeRow(id, result.rows[0].payload);
      } catch (err) {
        logger.error({ err, id }, 'failed to decrypt existing creds row');
        throw new Error(`Failed to decrypt existing ${id} row: ${err.message}`);
      }
    }
    return safeDecode(id, result.rows[0].payload);
  }

  async function writeWith(queryable, id, value) {
    const payload = encrypt(JSON.stringify(value, BufferJSON.replacer), derivedKey);
    await queryable.query(
      `INSERT INTO wa_gateway_auth (id, payload, updated_at) VALUES ($1, $2, now())
       ON CONFLICT (id) DO UPDATE SET payload = EXCLUDED.payload, updated_at = now()`,
      [id, payload],
    );
  }

  const creds = (await read('creds', { failOnDecryptError: true })) || initAuthCreds();
  return {
    state: {
      creds,
      keys: {
        get: async (type, ids) => {
          if (!ids || ids.length === 0) return {};
          const prefix = `key:${type}:`;
          const rowIds = ids.map((id) => `${prefix}${id}`);
          const result = await pool.query(
            'SELECT id, payload FROM wa_gateway_auth WHERE id = ANY($1::text[])',
            [rowIds],
          );
          const out = {};
          for (const row of result.rows) {
            const id = row.id.slice(prefix.length);
            let value = safeDecode(row.id, row.payload);
            if (type === 'app-state-sync-key' && value) {
              value = proto.Message.AppStateSyncKeyData.fromObject(value);
            }
            if (value) out[id] = value;
          }
          return out;
        },
        set: (data) =>
          enqueueWrite(async () => {
            const upsertIds = [];
            const upsertPayloads = [];
            const deleteIds = [];
            for (const [type, entries] of Object.entries(data)) {
              for (const [id, value] of Object.entries(entries || {})) {
                const rowId = `key:${type}:${id}`;
                if (value) {
                  upsertIds.push(rowId);
                  upsertPayloads.push(encrypt(JSON.stringify(value, BufferJSON.replacer), derivedKey));
                } else {
                  deleteIds.push(rowId);
                }
              }
            }
            if (upsertIds.length === 0 && deleteIds.length === 0) return;

            const client = await pool.connect();
            try {
              await client.query('BEGIN');
              if (upsertIds.length > 0) {
                await client.query(
                  `INSERT INTO wa_gateway_auth (id, payload, updated_at)
                   SELECT u.id, u.payload, now()
                   FROM UNNEST($1::text[], $2::bytea[]) AS u(id, payload)
                   ON CONFLICT (id) DO UPDATE SET payload = EXCLUDED.payload, updated_at = now()`,
                  [upsertIds, upsertPayloads],
                );
              }
              if (deleteIds.length > 0) {
                await client.query('DELETE FROM wa_gateway_auth WHERE id = ANY($1::text[])', [deleteIds]);
              }
              await client.query('COMMIT');
            } catch (err) {
              await client.query('ROLLBACK').catch(() => {});
              throw err;
            } finally {
              client.release();
            }
          }),
      },
    },
    saveCreds: () => enqueueWrite(() => writeWith(pool, 'creds', creds)),
    clear: () => enqueueWrite(() => pool.query('DELETE FROM wa_gateway_auth')),
  };
}
