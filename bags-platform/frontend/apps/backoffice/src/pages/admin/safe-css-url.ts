/** Build a CSS url() value only from a parsed, inert URL. */
export function safeCssUrl(raw: unknown): string | null {
  if (typeof raw !== 'string' || raw.trim() === '') return null;
  try {
    const base = typeof window === 'undefined' ? 'https://localhost/' : window.location.origin;
    const url = new URL(raw, base);
    if (!['https:', 'http:', 'blob:'].includes(url.protocol)) return null;
    if (url.username || url.password) return null;
    // URL.href percent-encodes quotes and control characters, keeping the CSS
    // string boundary intact even for values copied from untrusted API data.
    return `url("${url.href}")`;
  } catch {
    return null;
  }
}
