import { createHmac, timingSafeEqual } from 'node:crypto';

export function sign(secret, timestamp, rawBody) {
  return createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex');
}

export function verify(secret, timestamp, rawBody, signature) {
  if (!secret || !timestamp || !signature || !/^\d+$/.test(timestamp)) return false;
  if (Math.abs(Date.now() / 1000 - Number(timestamp)) > 300) return false;
  const expected = sign(secret, timestamp, rawBody);
  const left = Buffer.from(expected);
  const right = Buffer.from(String(signature).trim().toLowerCase());
  if (left.length !== right.length) return false;
  return timingSafeEqual(left, right);
}
