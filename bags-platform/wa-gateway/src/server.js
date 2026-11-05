import Fastify from 'fastify';
import pino from 'pino';
import pg from 'pg';
import QRCode from 'qrcode';
import makeWASocket, {
  Browsers,
  DisconnectReason,
  downloadMediaMessage,
  fetchLatestBaileysVersion,
  makeCacheableSignalKeyStore,
} from '@whiskeysockets/baileys';
import { usePostgresAuthState } from './auth-state.js';
import { toInbound } from './filter.js';
import { sign, verify } from './hmac.js';

const logger = pino({ level: process.env.LOG_LEVEL || 'info' });
const secret = process.env.WA_GATEWAY_SECRET || '';
const inboundUrl = process.env.WA_LARAVEL_INBOUND_URL || '';
if (!secret) throw new Error('WA_GATEWAY_SECRET is required');
if (!process.env.DATABASE_URL) throw new Error('DATABASE_URL is required');

const pool = new pg.Pool({ connectionString: process.env.DATABASE_URL });
const media = new Map();
const maxImageBytes = 4 * 1024 * 1024;
const runtime = {
  state: 'close',
  phone: null,
  qr: null,
  lastConnectedAt: null,
  sock: null,
  starting: false,
};

function remember(msg) {
  if (!msg?.key?.id) return;
  media.set(msg.key.id, { msg, at: Date.now() });
}

setInterval(() => {
  const cutoff = Date.now() - 15 * 60 * 1000;
  for (const [id, row] of media) {
    if (row.at < cutoff) media.delete(id);
  }
}, 60_000).unref();

async function postInbound(payload, rawMessage) {
  if (payload.media?.type === 'image') remember(rawMessage);
  if (!inboundUrl) return;
  const body = JSON.stringify(payload);
  const timestamp = String(Math.floor(Date.now() / 1000));
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    try {
      const response = await fetch(inboundUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WA-Timestamp': timestamp,
          'X-WA-Signature': sign(secret, timestamp, body),
        },
        body,
        signal: AbortSignal.timeout(5000),
      });
      if (response.ok) return;
      logger.warn({ status: response.status, attempt }, 'laravel rejected inbound');
    } catch (error) {
      logger.warn({ attempt, err: error.message }, 'laravel inbound failed');
    }
  }
}

let reconnectTimer = null;
let backoffMs = 2000;

function scheduleReconnect() {
  runtime.state = 'reconnecting';
  clearTimeout(reconnectTimer);
  reconnectTimer = setTimeout(connect, backoffMs);
  backoffMs = Math.min(backoffMs * 2, 30000);
}

async function connect() {
  if (runtime.starting || runtime.state === 'connected' || runtime.state === 'logged_out') return;
  runtime.starting = true;
  try {
    const auth = await usePostgresAuthState(pool);
    const { version } = await fetchLatestBaileysVersion();
    const sock = makeWASocket({
      version,
      auth: { creds: auth.state.creds, keys: makeCacheableSignalKeyStore(auth.state.keys, logger) },
      browser: Browsers.macOS('Alfarid'),
      markOnlineOnConnect: false,
      logger,
    });
    runtime.sock = sock;
    sock.ev.on('creds.update', () => {
      auth.saveCreds().catch((error) => logger.error({ err: error }, 'could not save credentials'));
    });
    sock.ev.on('connection.update', async (update) => {
      if (update.qr) {
        runtime.state = 'awaiting_scan';
        const qr = await QRCode.toDataURL(update.qr, { margin: 1, width: 320 });
        if (runtime.sock === sock && runtime.state === 'awaiting_scan') runtime.qr = qr;
      }
      if (update.connection === 'open') {
        runtime.state = 'connected';
        runtime.qr = null;
        runtime.lastConnectedAt = new Date().toISOString();
        runtime.phone = sock.user?.id ? sock.user.id.split(':')[0].split('@')[0] : null;
        backoffMs = 2000;
      }
      if (update.connection === 'close') {
        const code = update.lastDisconnect?.error?.output?.statusCode;
        runtime.sock = null;
        if (code === DisconnectReason.loggedOut) {
          runtime.state = 'logged_out';
          runtime.qr = null;
          runtime.phone = null;
          await auth.clear().catch((error) => logger.error({ err: error }, 'could not clear credentials'));
          return;
        }
        runtime.qr = null;
        scheduleReconnect();
      }
    });
    sock.ev.on('messages.upsert', ({ messages, type }) => {
      if (type !== 'notify') return;
      for (const msg of messages) {
        const inbound = toInbound(msg);
        if (inbound) postInbound(inbound, msg);
      }
    });
  } catch (error) {
    logger.error({ err: error }, 'could not start WhatsApp connection');
    scheduleReconnect();
  } finally {
    runtime.starting = false;
  }
}

const app = Fastify({ loggerInstance: logger });
app.addContentTypeParser('application/json', { parseAs: 'string' }, (request, body, done) => {
  request.rawBody = body;
  try { done(null, body ? JSON.parse(body) : {}); } catch (error) { done(error); }
});
app.addHook('preHandler', async (request, reply) => {
  const raw = request.rawBody ?? '';
  if (!verify(secret, request.headers['x-wa-timestamp'], raw, request.headers['x-wa-signature'])) {
    return reply.code(401).send({ message: 'invalid signature' });
  }
});

app.get('/status', async () => ({
  state: runtime.state,
  phone: runtime.phone,
  last_connected_at: runtime.lastConnectedAt,
}));
app.get('/qr', async (_request, reply) => {
  if (runtime.state !== 'awaiting_scan' || !runtime.qr) return reply.code(409).send({ message: 'no qr' });
  return { data_url: runtime.qr };
});
app.post('/logout', async (_request, reply) => {
  const sock = runtime.sock;
  if (!sock || runtime.state !== 'connected') return reply.code(409).send({ message: 'not connected' });
  await sock.logout();
  runtime.state = 'logged_out';
  runtime.phone = null;
  runtime.qr = null;
  return { ok: true };
});
app.post('/send', async (request, reply) => {
  const { to_jid: toJid, text, quoted_id: quotedId } = request.body || {};
  if (!runtime.sock || !toJid || !text) return reply.code(409).send({ message: 'not ready' });
  const quoted = quotedId ? { key: { remoteJid: toJid, id: quotedId, fromMe: true }, message: { conversation: '' } } : undefined;
  const sent = await runtime.sock.sendMessage(toJid, { text }, quoted ? { quoted } : undefined);
  return { wa_message_id: sent?.key?.id ?? null };
});
app.post('/presence', async (request, reply) => {
  const { to_jid: toJid, state } = request.body || {};
  if (!runtime.sock || !toJid) return reply.code(409).send({ message: 'not ready' });
  await runtime.sock.sendPresenceUpdate(state === 'composing' ? 'composing' : 'paused', toJid);
  return { ok: true };
});
app.post('/delete', async (request) => {
  const { to_jid: toJid, wa_message_id: id } = request.body || {};
  if (runtime.sock && toJid && id) {
    await runtime.sock.sendMessage(toJid, { delete: { remoteJid: toJid, fromMe: false, id } });
  }
  return { ok: true };
});
app.get('/media/:id/meta', async (request, reply) => {
  const row = media.get(request.params.id);
  if (!row) return reply.code(404).send({ message: 'gone' });
  const inbound = toInbound(row.msg);
  return { mime: inbound?.media?.mime ?? null };
});
app.post('/media/:id', async (request, reply) => {
  const row = media.get(request.params.id);
  if (!row || !runtime.sock) return reply.code(404).send({ message: 'gone' });
  const stream = await downloadMediaMessage(row.msg, 'stream', {}, { logger, reuploadRequest: runtime.sock.updateMediaMessage });
  const chunks = [];
  let size = 0;
  for await (const chunk of stream) {
    size += chunk.length;
    if (size > maxImageBytes) return reply.code(413).send({ message: 'image too large' });
    chunks.push(chunk);
  }
  return reply.type('application/octet-stream').send(Buffer.concat(chunks, size));
});
app.post('/encode-qr', async (request, reply) => {
  const text = request.body?.text;
  if (!text || String(text).length > 2000) return reply.code(422).send({ message: 'text required' });
  return { data_url: await QRCode.toDataURL(String(text), { margin: 1, width: 320 }) };
});

const port = Number(process.env.PORT || 3310);
const host = process.env.HOST || '127.0.0.1';
await app.listen({ port, host });
connect();
