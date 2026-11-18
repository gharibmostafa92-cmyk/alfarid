import {
  DEFAULT_CONNECTION_CONFIG,
  DisconnectReason,
  fetchLatestBaileysVersion,
} from '@whiskeysockets/baileys';
import { sign } from './hmac.js';

export const MAX_MEDIA_ENTRIES = 500;

export function createPoolConfig(connectionString) {
  return {
    connectionString,
    max: 5,
    idleTimeoutMillis: 30000,
    connectionTimeoutMillis: 5000,
  };
}

export function createMediaStore({ maxEntries = MAX_MEDIA_ENTRIES } = {}) {
  const map = new Map();

  function remember(msg) {
    const id = msg?.key?.id;
    if (!id) return;
    if (map.has(id)) {
      map.delete(id);
    } else if (map.size >= maxEntries) {
      const oldestKey = map.keys().next().value;
      if (oldestKey !== undefined) map.delete(oldestKey);
    }
    map.set(id, { msg, at: Date.now() });
  }

  function prune(cutoff) {
    for (const [id, row] of map) {
      if (row.at < cutoff) map.delete(id);
    }
  }

  return {
    map,
    remember,
    get: (id) => map.get(id),
    delete: (id) => map.delete(id),
    prune,
  };
}

function defaultSleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

export function createPostInbound({
  inboundUrl,
  secret,
  mediaStore,
  fetchImpl = fetch,
  logger = console,
  sleep = defaultSleep,
  now = () => Date.now(),
}) {
  return async function postInbound(payload, rawMessage) {
    const mediaId = payload.media?.type === 'image' ? rawMessage?.key?.id : null;
    if (mediaId) mediaStore.remember(rawMessage);
    if (!inboundUrl) return true;

    const body = JSON.stringify(payload);
    const maxAttempts = 3;

    for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
      const timestamp = String(Math.floor(now() / 1000));
      try {
        const response = await fetchImpl(inboundUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WA-Timestamp': timestamp,
            'X-WA-Signature': sign(secret, timestamp, body),
          },
          body,
          signal: AbortSignal.timeout(5000),
        });
        if (response.ok) return true;
        logger.warn({ status: response.status, attempt }, 'laravel rejected inbound');
        if (response.status >= 400 && response.status < 500) break;
      } catch (error) {
        logger.warn({ attempt, err: error.message }, 'laravel inbound failed');
      }
      if (attempt < maxAttempts) {
        await sleep(attempt * 500);
      }
    }

    if (mediaId) mediaStore.delete(mediaId);
    return false;
  };
}

export async function withTimeout(promise, timeoutMs, message = 'operation timed out') {
  let timer;
  try {
    return await Promise.race([
      promise,
      new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error(message)), timeoutMs);
      }),
    ]);
  } finally {
    clearTimeout(timer);
  }
}

export async function consumeMediaStream(stream, { maxBytes = 4 * 1024 * 1024, timeoutMs = 15000 } = {}) {
  let timer;
  const timeoutPromise = new Promise((_, reject) => {
    timer = setTimeout(() => {
      stream?.destroy?.(new Error('media stream timeout'));
      reject(new Error('media stream timeout'));
    }, timeoutMs);
  });

  const readPromise = (async () => {
    const chunks = [];
    let size = 0;
    for await (const chunk of stream) {
      size += chunk.length;
      if (size > maxBytes) {
        return { tooLarge: true, buffer: null };
      }
      chunks.push(chunk);
    }
    return { tooLarge: false, buffer: Buffer.concat(chunks, size) };
  })();

  try {
    return await Promise.race([readPromise, timeoutPromise]);
  } finally {
    clearTimeout(timer);
    stream?.destroy?.();
  }
}

export async function resolveBaileysVersion({
  fetchLatest = fetchLatestBaileysVersion,
  fallbackVersion = DEFAULT_CONNECTION_CONFIG.version,
  timeoutMs = 3000,
  logger = console,
} = {}) {
  try {
    const result = await withTimeout(fetchLatest(), timeoutMs, 'fetchLatestBaileysVersion timed out');
    if (result?.version && !result?.error) {
      return result.version;
    }
    logger.warn({ err: result?.error }, 'fetchLatestBaileysVersion returned error, using built-in version');
  } catch (err) {
    logger.warn({ err }, 'fetchLatestBaileysVersion failed, using built-in version');
  }
  return fallbackVersion;
}

export async function logoutSession({ runtime, clearAuth, logger = console }) {
  const sock = runtime.sock;
  try {
    if (sock) await sock.logout();
  } catch (err) {
    logger.warn({ err }, 'sock.logout failed during logout');
  } finally {
    await clearAuth().catch((error) => logger.error({ err: error }, 'could not clear credentials'));
    closeSocketSafely(sock);
    runtime.sock = null;
    runtime.state = 'logged_out';
    runtime.phone = null;
    runtime.qr = null;
  }
}

export async function handleConnectionUpdate({
  update,
  sock,
  runtime,
  auth,
  scheduleReconnect,
  resetBackoff,
  qrToDataUrl,
  logger = console,
}) {
  if (runtime.sock !== sock) return;

  if (update.qr) {
    runtime.state = 'awaiting_scan';
    const qr = await qrToDataUrl(update.qr);
    if (runtime.sock === sock && runtime.state === 'awaiting_scan') runtime.qr = qr;
  }

  if (update.connection === 'open') {
    runtime.state = 'connected';
    runtime.qr = null;
    runtime.lastConnectedAt = new Date().toISOString();
    runtime.phone = sock.user?.id ? sock.user.id.split(':')[0].split('@')[0] : null;
    resetBackoff();
  }

  if (update.connection === 'close') {
    const code = update.lastDisconnect?.error?.output?.statusCode;
    runtime.sock = null;
    runtime.qr = null;
    if (code === DisconnectReason.loggedOut) {
      runtime.phone = null;
      await auth.clear().catch((error) => logger.error({ err: error }, 'could not clear credentials'));
      runtime.state = 'logged_out';
      return;
    }
    scheduleReconnect();
  }
}

export function closeSocketSafely(sock) {
  try {
    sock?.end?.(undefined);
  } catch {
    // Ignore errors when closing an already-closed socket
  }
}

export function buildQuotedMessage(toJid, quotedId, quotedFromMe = false) {
  if (!quotedId) return undefined;
  return {
    key: { remoteJid: toJid, id: quotedId, fromMe: Boolean(quotedFromMe) },
    message: { conversation: '' },
  };
}

export function buildDeleteForMeModify(toJid, id, timestampSec = Math.floor(Date.now() / 1000)) {
  return {
    deleteForMe: {
      deleteMedia: false,
      key: { id, fromMe: false, remoteJid: toJid },
      timestamp: timestampSec,
    },
  };
}
