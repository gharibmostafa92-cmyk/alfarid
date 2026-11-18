import { useRef, useCallback } from 'react';
import { createClientRequestId } from '../../../../lib/idempotency';

// Stable idempotency key per payload — same pattern as manufacturing-pages.jsx.
// Golden rule: never mint a new key when the user retries the SAME payload.
// A new key is minted only when the payload changes or after a success (reset).
//
// crypto.randomUUID exists only in secure contexts (HTTPS/localhost).
// Fall back across crypto.randomUUID, getRandomValues UUID v4, and unique counter string.
export function useIdempotencyKey() {
  const ref = useRef(null);
  const keyFor = useCallback((payload) => {
    const hash = JSON.stringify(payload);
    if (!ref.current || ref.current.hash !== hash) {
      ref.current = { hash, key: createClientRequestId() };
    }
    return ref.current.key;
  }, []);
  const reset = useCallback(() => { ref.current = null; }, []); // call only after success
  return { keyFor, reset };
}
