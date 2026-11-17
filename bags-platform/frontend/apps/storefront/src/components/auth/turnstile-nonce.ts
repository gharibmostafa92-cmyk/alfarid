/** CSP nonce for the Turnstile script. Empty means the attribute must stay unset. */
export function turnstileScriptNonce(nonce: string): string | undefined {
  return nonce ? nonce : undefined;
}
