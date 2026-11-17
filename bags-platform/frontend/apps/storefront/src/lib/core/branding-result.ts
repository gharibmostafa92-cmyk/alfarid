/** False when SSR branding timed out, so the client refetches the real identity. */
export function hasInitialBranding(fromFallback: boolean): boolean {
  return !fromFallback;
}
