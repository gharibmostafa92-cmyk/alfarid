import assert from 'node:assert/strict';
import test from 'node:test';
import { DisconnectReason } from '@whiskeysockets/baileys';
import {
  MAX_MEDIA_ENTRIES,
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
} from '../src/gateway.js';
import { createReplayGuard, sign, verify } from '../src/hmac.js';
import { toInbound } from '../src/filter.js';

test('keeps an incoming voice note available after webhook delivery', async () => {
  const store = createMediaStore();
  const message = {
    key: { id: 'voice-1', remoteJid: '201000000000@s.whatsapp.net' },
    message: { audioMessage: { ptt: true, mimetype: 'audio/ogg; codecs=opus' } },
  };
  const payload = toInbound(message);
  assert.equal(payload.text, '');
  assert.deepEqual(payload.media, { id: 'voice-1', type: 'audio', mime: 'audio/ogg' });
  const post = createPostInbound({
    inboundUrl: 'https://laravel.test/api/wa/inbound',
    secret: 'test-secret',
    mediaStore: store,
    fetchImpl: async () => ({ ok: true }),
  });
  assert.equal(await post(payload, message), true);
  assert.deepEqual(store.get('voice-1').msg, message);
  assert.equal(toInbound({ ...message, key: { ...message.key, remoteJid: '123@g.us' } }), null);
});

test('createPoolConfig sets bounded pool options', () => {
  const cfg = createPoolConfig('postgres://user:pass@localhost:5432/db');
  assert.deepEqual(cfg, {
    connectionString: 'postgres://user:pass@localhost:5432/db',
    max: 5,
    idleTimeoutMillis: 30000,
    connectionTimeoutMillis: 5000,
  });
});

test('media store caps entries at MAX_MEDIA_ENTRIES (500) with FIFO/LRU eviction', () => {
  assert.equal(MAX_MEDIA_ENTRIES, 500);
  const store = createMediaStore();
  for (let i = 0; i < 510; i += 1) {
    store.remember({ key: { id: `msg-${i}` } });
  }
  assert.equal(store.map.size, 500);
  assert.equal(store.get('msg-0'), undefined);
  assert.equal(store.get('msg-9'), undefined);
  assert.ok(store.get('msg-10'));
  assert.ok(store.get('msg-509'));
});

test('postInbound retries with backoff, regenerates timestamp/signature, and deletes media on failure', async () => {
  const store = createMediaStore();
  const attempts = [];
  const sleeps = [];
  let nowSec = 1000;

  const postInbound = createPostInbound({
    inboundUrl: 'https://laravel.test/api/wa/inbound',
    secret: 'super-secret-key',
    mediaStore: store,
    now: () => (nowSec += 1) * 1000,
    sleep: async (ms) => { sleeps.push(ms); },
    logger: { warn() {} },
    fetchImpl: async (_url, opts) => {
      attempts.push({
        timestamp: opts.headers['X-WA-Timestamp'],
        signature: opts.headers['X-WA-Signature'],
      });
      return { ok: false, status: 500 };
    },
  });

  const rawMsg = { key: { id: 'img-1' } };
  const ok = await postInbound({ media: { type: 'image' }, text: 'hi' }, rawMsg);

  assert.equal(ok, false);
  assert.equal(attempts.length, 3);
  assert.deepEqual(sleeps, [500, 1000]);
  assert.notEqual(attempts[0].timestamp, attempts[1].timestamp);
  assert.notEqual(attempts[0].signature, attempts[1].signature);
  assert.equal(store.get('img-1'), undefined, 'media entry must be deleted when postInbound fails');
});

test('postInbound keeps media when Laravel returns ok', async () => {
  const store = createMediaStore();
  const postInbound = createPostInbound({
    inboundUrl: 'https://laravel.test/api/wa/inbound',
    secret: 'super-secret-key',
    mediaStore: store,
    sleep: async () => {},
    logger: { warn() {} },
    fetchImpl: async () => ({ ok: true, status: 200 }),
  });

  const rawMsg = { key: { id: 'img-ok' } };
  const ok = await postInbound({ media: { type: 'image' }, text: 'hi' }, rawMsg);

  assert.equal(ok, true);
  assert.ok(store.get('img-ok'));
});

test('consumeMediaStream destroys stream on success and on size overflow', async () => {
  let destroyed1 = false;
  const goodStream = {
    async *[Symbol.asyncIterator]() {
      yield Buffer.from('hello ');
      yield Buffer.from('world');
    },
    destroy() { destroyed1 = true; },
  };
  const res1 = await consumeMediaStream(goodStream, { maxBytes: 100, timeoutMs: 1000 });
  assert.equal(destroyed1, true);
  assert.equal(res1.tooLarge, false);
  assert.equal(res1.buffer.toString('utf8'), 'hello world');

  let destroyed2 = false;
  const bigStream = {
    async *[Symbol.asyncIterator]() {
      yield Buffer.alloc(60);
      yield Buffer.alloc(60);
    },
    destroy() { destroyed2 = true; },
  };
  const res2 = await consumeMediaStream(bigStream, { maxBytes: 100, timeoutMs: 1000 });
  assert.equal(destroyed2, true);
  assert.equal(res2.tooLarge, true);
});

test('logoutSession clears auth and stays logged out without scheduling reconnect', async () => {
  let loggedOutCalled = false;
  let authCleared = false;
  let reconnectCalled = false;

  const runtime = {
    state: 'connected',
    phone: '201000000000',
    qr: 'data:image/png;base64,old',
    sock: {
      async logout() { loggedOutCalled = true; },
    },
  };

  await logoutSession({
    runtime,
    clearAuth: async () => { authCleared = true; },
    logger: { warn() {}, error() {} },
  });

  assert.equal(loggedOutCalled, true);
  assert.equal(authCleared, true);
  assert.equal(reconnectCalled, false);
  assert.equal(runtime.sock, null);
  assert.equal(runtime.state, 'logged_out');
  assert.equal(runtime.phone, null);
  assert.equal(runtime.qr, null);
});

test('handleConnectionUpdate ignores stale socket events even after active socket is cleared', async () => {
  let reconnects = 0;
  const stale = { user: { id: '201000:1@s.whatsapp.net' } };
  const runtime = { state: 'logged_out', sock: null, phone: null, qr: null };
  await handleConnectionUpdate({
    update: { connection: 'open' },
    sock: stale,
    runtime,
    auth: { clear: async () => {} },
    scheduleReconnect: () => { reconnects += 1; },
    resetBackoff: () => {},
    qrToDataUrl: async () => '',
  });
  assert.equal(runtime.state, 'logged_out');
  assert.equal(runtime.sock, null);
  assert.equal(reconnects, 0);
});

test('handleConnectionUpdate transitions to logged_out on DisconnectReason.loggedOut without auto-reconnect', async () => {
  let authCleared = false;
  let scheduledDelay = null;
  const sock = { user: { id: '201000:1@s.whatsapp.net' } };
  const runtime = {
    state: 'connected',
    phone: '201000',
    qr: null,
    sock,
    lastConnectedAt: null,
  };

  await handleConnectionUpdate({
    update: {
      connection: 'close',
      lastDisconnect: { error: { output: { statusCode: DisconnectReason.loggedOut } } },
    },
    sock,
    runtime,
    auth: { clear: async () => { authCleared = true; } },
    scheduleReconnect: (delay) => { scheduledDelay = delay; },
    resetBackoff: () => {},
    qrToDataUrl: async () => '',
  });

  assert.equal(authCleared, true);
  assert.equal(runtime.sock, null);
  assert.equal(runtime.state, 'logged_out');
  assert.equal(runtime.phone, null);
  assert.equal(runtime.qr, null);
  assert.equal(scheduledDelay, null);
});

test('resolveBaileysVersion falls back to built-in version on timeout or error', async () => {
  const fallback = [2, 3000, 123456];
  const warnings = [];
  const logger = { warn: (obj, msg) => warnings.push({ obj, msg }) };

  const fromTimeout = await resolveBaileysVersion({
    fetchLatest: () => new Promise(() => {}),
    fallbackVersion: fallback,
    timeoutMs: 30,
    logger,
  });
  assert.deepEqual(fromTimeout, fallback);

  const fromError = await resolveBaileysVersion({
    fetchLatest: async () => { throw new Error('network down'); },
    fallbackVersion: fallback,
    timeoutMs: 100,
    logger,
  });
  assert.deepEqual(fromError, fallback);
  assert.equal(warnings.length, 2);
});

test('createReplayGuard rejects duplicate mutating signatures within TTL and allows after expiry', () => {
  let nowMs = 1_000_000;
  const guard = createReplayGuard({ ttlMs: 300_000, now: () => nowMs });
  const secret = 'super-secret-key';
  const ts = String(Math.floor(nowMs / 1000));
  const body = JSON.stringify({ to_jid: '123@s.whatsapp.net', text: 'hi' });
  const sig = sign(secret, ts, body);

  assert.equal(verify(secret, ts, body, sig, nowMs), true);
  assert.equal(guard.consume('POST', '/send', sig), true);
  assert.equal(guard.consume('POST', '/send', sig), false, 'replay must be rejected within TTL');

  nowMs += 300_001;
  assert.equal(guard.consume('POST', '/send', sig), true, 'allowed after TTL expiry');
});

test('closeSocketSafely closes existing socket and ignores errors', () => {
  let ended = false;
  closeSocketSafely({ end() { ended = true; } });
  assert.equal(ended, true);

  assert.doesNotThrow(() => closeSocketSafely({ end() { throw new Error('already closed'); } }));
  assert.doesNotThrow(() => closeSocketSafely(null));
});

test('buildQuotedMessage defaults quoted_from_me to false and buildDeleteForMeModify targets local device', () => {
  assert.deepEqual(buildQuotedMessage('2010@s.whatsapp.net', 'msg-1'), {
    key: { remoteJid: '2010@s.whatsapp.net', id: 'msg-1', fromMe: false },
    message: { conversation: '' },
  });
  assert.deepEqual(buildQuotedMessage('2010@s.whatsapp.net', 'msg-2', true), {
    key: { remoteJid: '2010@s.whatsapp.net', id: 'msg-2', fromMe: true },
    message: { conversation: '' },
  });
  assert.equal(buildQuotedMessage('2010@s.whatsapp.net', null), undefined);

  const mod = buildDeleteForMeModify('2010@s.whatsapp.net', 'pin-1', 1700000000);
  assert.deepEqual(mod, {
    deleteForMe: {
      deleteMedia: false,
      key: { id: 'pin-1', fromMe: false, remoteJid: '2010@s.whatsapp.net' },
      timestamp: 1700000000,
    },
  });
});
