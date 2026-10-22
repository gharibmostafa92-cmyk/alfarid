// Session discovery must work on browsers that expose getRandomValues but not randomUUID.
export function createSessionNonce(cryptoApi: Crypto | undefined = globalThis.crypto): string | null {
  try {
    if (typeof cryptoApi?.randomUUID === 'function') return cryptoApi.randomUUID();
    if (typeof cryptoApi?.getRandomValues !== 'function') return null;
    const bytes = cryptoApi.getRandomValues(new Uint8Array(16));
    return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
  } catch {
    // Skip optional staff discovery if secure randomness is unavailable.
    return null;
  }
}
