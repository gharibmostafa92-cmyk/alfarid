import { useRef, useCallback } from 'react';

// Stable idempotency key per payload — same pattern as manufacturing-pages.jsx.
// Golden rule: never mint a new key when the user retries the SAME payload.
// A new key is minted only when the payload changes or after a success (reset).
//
// crypto.randomUUID exists only in secure contexts (HTTPS/localhost).
// Fall back across crypto.randomUUID, getRandomValues UUID v4, and unique counter string.
function newKey() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  }
  newKey.counter = (newKey.counter || 0) + 1;
  return `fallback-${Date.now().toString(36)}-${newKey.counter.toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

export function useIdempotencyKey() {
  const ref = useRef(null);
  const keyFor = useCallback((payload) => {
    const hash = JSON.stringify(payload);
    if (!ref.current || ref.current.hash !== hash) {
      ref.current = { hash, key: newKey() };
    }
    return ref.current.key;
  }, []);
  const reset = useCallback(() => { ref.current = null; }, []); // call only after success
  return { keyFor, reset };
}
