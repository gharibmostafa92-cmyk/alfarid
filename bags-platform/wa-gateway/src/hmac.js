import { createHmac, timingSafeEqual } from 'node:crypto';

export function sign(secret, timestamp, rawBody) {
  return createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex');
}

export function verify(secret, timestamp, rawBody, signature, nowMs = Date.now()) {
  if (!secret || !timestamp || !signature || !/^\d+$/.test(timestamp)) return false;
  if (Math.abs(nowMs / 1000 - Number(timestamp)) > 300) return false;
  const expected = sign(secret, timestamp, rawBody);
  const left = Buffer.from(expected);
  const right = Buffer.from(String(signature).trim().toLowerCase());
  if (left.length !== right.length) return false;
  return timingSafeEqual(left, right);
}

export function createReplayGuard({ ttlMs = 300_000, now = Date.now } = {}) {
  const seen = new Map();

  function prune(currentMs) {
    for (const [entryKey, expiresAt] of seen.entries()) {
      if (expiresAt <= currentMs) {
        seen.delete(entryKey);
      }
    }
  }

  return {
    consume(method, url, signature) {
      const currentMs = now();
      if (seen.size > 100) prune(currentMs);
      const entryKey = `${String(method).toUpperCase()}:${String(url)}:${String(signature).trim().toLowerCase()}`;
      const existing = seen.get(entryKey);
      if (existing !== undefined && existing > currentMs) {
        return false;
      }
      seen.set(entryKey, currentMs + ttlMs);
      return true;
    },
  };
}
