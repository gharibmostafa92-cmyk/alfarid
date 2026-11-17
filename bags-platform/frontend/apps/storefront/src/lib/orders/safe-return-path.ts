/** Only local application paths can be used after authentication. */
export function safeReturnPath(value: string | null, allowedRoots: readonly string[] = []): string | null {
  if (!value || !value.startsWith('/') || value.startsWith('//') || /[\\\u0000-\u0020]/.test(value)) return null;
  try {
    const decoded = decodeURIComponent(value);
    if (decoded.startsWith('//') || /[\\\u0000-\u0020]/.test(decoded)) return null;
    const url = new URL(value, 'https://store.invalid');
    if (url.origin !== 'https://store.invalid') return null;
    const pathname = decodeURIComponent(url.pathname);
    // /auth/* can never be a post-login landing page (login-loop guard).
    if (/^\/(auth)(?:\/|$)/i.test(pathname)) return null;
    // Channel portals are cross-channel by default; the caller's own channel
    // root may opt back in so wholesalers/suppliers keep their deep links.
    const allowed = allowedRoots.some((root) => {
      const clean = root.endsWith('/') && root.length > 1 ? root.slice(0, -1) : root;
      return pathname === clean || pathname.toLowerCase().startsWith(clean.toLowerCase() + '/');
    });
    if (!allowed && /^\/(supplier|wholesale)(?:\/|$)/i.test(pathname)) return null;
    return url.pathname + url.search + url.hash;
  } catch { return null; }
}
