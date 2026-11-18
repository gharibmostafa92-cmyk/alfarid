import { registerFastifyTelemetry } from './instrumentation.js';
import Fastify from 'fastify';
import pino from 'pino';
import pg from 'pg';
import QRCode from 'qrcode';
import makeWASocket, {
  Browsers,
  downloadMediaMessage,
  makeCacheableSignalKeyStore,
} from '@whiskeysockets/baileys';
import { usePostgresAuthState } from './auth-state.js';
import { toInbound } from './filter.js';
import { createReplayGuard, verify } from './hmac.js';
import {
  buildDeleteForMeModify,
  buildQuotedMessage,
  closeSocketSafely,
  consumeMediaStream,
  createMediaStore,
  createPoolConfig,
  createPostInbound,
  handleConnectionUpdate,
  logoutSession,
  resolveBaileysVersion,
  withTimeout,
} from './gateway.js';

const logger = pino({ level: process.env.LOG_LEVEL || 'info' });
const secret = process.env.WA_GATEWAY_SECRET || '';
const inboundUrl = process.env.WA_LARAVEL_INBOUND_URL || '';
if (!secret) throw new Error('WA_GATEWAY_SECRET is required');
if (!process.env.DATABASE_URL) throw new Error('DATABASE_URL is required');

const pool = new pg.Pool(createPoolConfig(process.env.DATABASE_URL));
pool.on('error', (err) => logger.error({ err }, 'pg pool idle client error'));

const mediaStore = createMediaStore();
const maxImageBytes = 4 * 1024 * 1024;
const runtime = {
  state: 'close',
  phone: null,
  qr: null,
  lastConnectedAt: null,
  sock: null,
  auth: null,
  starting: false,
};

setInterval(() => {
  mediaStore.prune(Date.now() - 15 * 60 * 1000);
}, 60_000).unref();

const postInbound = createPostInbound({
  inboundUrl,
  secret,
  mediaStore,
  logger,
});

let reconnectTimer = null;
let backoffMs = 2000;

function scheduleReconnect(delayMs) {
  runtime.state = 'reconnecting';
  clearTimeout(reconnectTimer);
  const delay = delayMs ?? backoffMs;
  reconnectTimer = setTimeout(connect, delay);
  if (delayMs === undefined) {
    backoffMs = Math.min(backoffMs * 2, 30000);
  }
}

async function clearAuthState() {
  if (runtime.auth) {
    await runtime.auth.clear();
  } else {
    await pool.query('DELETE FROM wa_gateway_auth');
  }
}

async function connect() {
  clearTimeout(reconnectTimer);
  reconnectTimer = null;
  if (runtime.starting || runtime.state === 'connected' || runtime.state === 'logged_out') return;
  runtime.starting = true;
  try {
    if (runtime.sock) {
      closeSocketSafely(runtime.sock);
      runtime.sock = null;
    }
    const auth = await usePostgresAuthState(pool, { logger });
    runtime.auth = auth;
    const version = await resolveBaileysVersion({ logger });
    const sock = makeWASocket({
      version,
      auth: { creds: auth.state.creds, keys: makeCacheableSignalKeyStore(auth.state.keys, logger) },
      browser: Browsers.macOS('VARYA'),
      markOnlineOnConnect: false,
      logger,
    });
    runtime.sock = sock;
    sock.ev.on('creds.update', () => {
      if (runtime.sock !== sock) return;
      auth.saveCreds().catch((error) => logger.error({ err: error }, 'could not save credentials'));
    });
    sock.ev.on('connection.update', (update) => {
      handleConnectionUpdate({
        update,
        sock,
        runtime,
        auth,
        scheduleReconnect,
        resetBackoff: () => { backoffMs = 2000; },
        qrToDataUrl: (qr) => QRCode.toDataURL(qr, { margin: 1, width: 320 }),
        logger,
      }).catch((error) => logger.error({ err: error }, 'connection update handling failed'));
    });
    sock.ev.on('messages.upsert', ({ messages, type }) => {
      if (runtime.sock !== sock) return;
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

const replayGuard = createReplayGuard();
const MUTATING_REPLAY_PATHS = new Set(['/send', '/logout', '/reconnect', '/delete']);

const app = Fastify({ loggerInstance: logger });
registerFastifyTelemetry(app);
app.addContentTypeParser('application/json', { parseAs: 'string' }, (request, body, done) => {
  request.rawBody = body;
  try { done(null, body ? JSON.parse(body) : {}); } catch (error) { done(error); }
});
app.addHook('preHandler', async (request, reply) => {
  const raw = request.rawBody ?? '';
  const sig = request.headers['x-wa-signature'];
  if (!verify(secret, request.headers['x-wa-timestamp'], raw, sig)) {
    return reply.code(401).send({ message: 'invalid signature' });
  }
  const path = request.url.split('?')[0];
  if (request.method === 'POST' && MUTATING_REPLAY_PATHS.has(path) && !replayGuard.consume(request.method, path, sig)) {
    return reply.code(401).send({ message: 'replayed request' });
  }
});

app.get('/status', async () => ({
  state: runtime.state,
  phone: runtime.phone,
  last_connected_at: runtime.lastConnectedAt,
}));
app.get('/qr', async (_request, reply) => {
  if (runtime.state === 'logged_out') {
    await clearAuthState().catch((error) => logger.error({ err: error }, 'could not clear credentials'));
    runtime.state = 'close';
    connect();
  }
  if (runtime.state !== 'awaiting_scan' || !runtime.qr) return reply.code(409).send({ message: 'no qr' });
  return { data_url: runtime.qr };
});
app.post('/reconnect', async () => {
  if (runtime.state === 'logged_out') {
    await clearAuthState().catch((error) => logger.error({ err: error }, 'could not clear credentials'));
    runtime.state = 'close';
  }
  if (runtime.state !== 'connected') {
    backoffMs = 2000;
    connect();
  }
  return { ok: true, state: runtime.state };
});
app.post('/logout', async (_request, reply) => {
  if (!runtime.sock || runtime.state !== 'connected') return reply.code(409).send({ message: 'not connected' });
  await logoutSession({
    runtime,
    clearAuth: clearAuthState,
    logger,
  });
  return { ok: true };
});
app.post('/send', async (request, reply) => {
  const { to_jid: toJid, text, quoted_id: quotedId, quoted_from_me: quotedFromMe = false } = request.body || {};
  if (!runtime.sock || !toJid || !text) return reply.code(409).send({ message: 'not ready' });
  const quoted = buildQuotedMessage(toJid, quotedId, quotedFromMe);
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
    try {
      await runtime.sock.chatModify(buildDeleteForMeModify(toJid, id), toJid);
    } catch (err) {
      logger.warn({ err }, 'chatModify deleteForMe failed');
    }
  }
  return { ok: true };
});
app.get('/media/:id/meta', async (request, reply) => {
  const row = mediaStore.get(request.params.id);
  if (!row) return reply.code(404).send({ message: 'gone' });
  const inbound = toInbound(row.msg);
  return { mime: inbound?.media?.mime ?? null };
});
app.post('/media/:id', async (request, reply) => {
  const row = mediaStore.get(request.params.id);
  if (!row || !runtime.sock) return reply.code(404).send({ message: 'gone' });
  const stream = await withTimeout(
    downloadMediaMessage(row.msg, 'stream', {}, { logger, reuploadRequest: runtime.sock.updateMediaMessage }),
    15000,
    'downloadMediaMessage timed out',
  );
  const { tooLarge, buffer } = await consumeMediaStream(stream, { maxBytes: maxImageBytes, timeoutMs: 15000 });
  if (tooLarge) return reply.code(413).send({ message: 'image too large' });
  return reply.type('application/octet-stream').send(buffer);
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
