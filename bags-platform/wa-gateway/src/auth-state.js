import { createCipheriv, createDecipheriv, createHash, randomBytes } from 'node:crypto';
import { BufferJSON, initAuthCreds, proto } from '@whiskeysockets/baileys';

function key() {
  const secret = process.env.WA_SESSION_KEY || '';
  if (secret.length < 16) throw new Error('WA_SESSION_KEY is required');
  return createHash('sha256').update(secret).digest();
}

function encrypt(plain) {
  const iv = randomBytes(12);
  const cipher = createCipheriv('aes-256-gcm', key(), iv);
  const enc = Buffer.concat([cipher.update(plain, 'utf8'), cipher.final()]);
  return Buffer.concat([iv, cipher.getAuthTag(), enc]);
}

function decrypt(payload) {
  const iv = payload.subarray(0, 12);
  const tag = payload.subarray(12, 28);
  const enc = payload.subarray(28);
  const decipher = createDecipheriv('aes-256-gcm', key(), iv);
  decipher.setAuthTag(tag);
  return Buffer.concat([decipher.update(enc), decipher.final()]).toString('utf8');
}

export async function usePostgresAuthState(pool) {
  await pool.query(`CREATE TABLE IF NOT EXISTS wa_gateway_auth (
    id text PRIMARY KEY,
    payload bytea NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now()
  )`);

  async function read(id) {
    const result = await pool.query('SELECT payload FROM wa_gateway_auth WHERE id = $1', [id]);
    if (!result.rows[0]) return null;
    return JSON.parse(decrypt(result.rows[0].payload), BufferJSON.reviver);
  }

  async function write(id, value) {
    const payload = encrypt(JSON.stringify(value, BufferJSON.replacer));
    await pool.query(
      `INSERT INTO wa_gateway_auth (id, payload, updated_at) VALUES ($1, $2, now())
       ON CONFLICT (id) DO UPDATE SET payload = EXCLUDED.payload, updated_at = now()`,
      [id, payload],
    );
  }

  const creds = (await read('creds')) || initAuthCreds();
  return {
    state: {
      creds,
      keys: {
        get: async (type, ids) => {
          const out = {};
          for (const id of ids) {
            let value = await read(`key:${type}:${id}`);
            if (type === 'app-state-sync-key' && value) {
              value = proto.Message.AppStateSyncKeyData.fromObject(value);
            }
            if (value) out[id] = value;
          }
          return out;
        },
        set: async (data) => {
          for (const [type, entries] of Object.entries(data)) {
            for (const [id, value] of Object.entries(entries || {})) {
              const rowId = `key:${type}:${id}`;
              if (value) await write(rowId, value);
              else await pool.query('DELETE FROM wa_gateway_auth WHERE id = $1', [rowId]);
            }
          }
        },
      },
    },
    saveCreds: () => write('creds', creds),
    clear: () => pool.query('DELETE FROM wa_gateway_auth'),
  };
}
