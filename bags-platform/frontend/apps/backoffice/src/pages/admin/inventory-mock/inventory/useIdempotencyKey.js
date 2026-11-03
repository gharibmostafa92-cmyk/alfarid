import { useRef, useCallback } from 'react';

// Stable idempotency key per payload — same pattern as manufacturing-pages.jsx.
// Golden rule: never mint a new key when the user retries the SAME payload.
// A new key is minted only when the payload changes or after a success (reset).
//
// L12: crypto.randomUUID exists only in secure contexts (HTTPS/localhost).
// Fall back to getRandomValues-based UUID v4, then to a process-unique
// counter string. Note: the last fallback is NOT a valid UUID — it is fine
// for idempotency headers but must not be sent to endpoints that validate
// `uuid` (e.g. POS scan_id); serve those pages over a secure context.
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
