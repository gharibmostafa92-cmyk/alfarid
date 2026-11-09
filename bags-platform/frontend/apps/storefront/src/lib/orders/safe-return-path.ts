/** Only local application paths can be used after authentication. */
export function safeReturnPath(value: string | null): string | null {
  if (!value || !value.startsWith('/') || value.startsWith('//') || /[\\\u0000-\u0020]/.test(value)) return null;
  try {
    const decoded = decodeURIComponent(value);
    if (decoded.startsWith('//') || /[\\\u0000-\u0020]/.test(decoded)) return null;
    const url = new URL(value, 'https://store.invalid');
    if (url.origin !== 'https://store.invalid' || /^\/(auth|supplier|wholesale)(?:\/|$)/i.test(decodeURIComponent(url.pathname))) return null;
    return url.pathname + url.search + url.hash;
  } catch { return null; }
}
