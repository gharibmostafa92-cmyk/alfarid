let previousToken: string | null = null;
let previousKey = 'guest';

/** Opaque per-session cache identity; contains no token and has no hash collisions. */
export function viewerKey(token: string | null): string {
  if (token !== previousToken) {
    previousToken = token;
    previousKey = token ? `viewer:${crypto.randomUUID()}` : 'guest';
  }
  return previousKey;
}
