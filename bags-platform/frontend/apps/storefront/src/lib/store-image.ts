/**
 * Resolves a safe image source object for Next.js Image components.
 *
 * @param source - Raw image URL or relative storage path from the API.
 * @returns Object with sanitized src URL, or null if invalid or unsafe.
 */
export function storeImageSource(source: string | null | undefined): { src: string } | null {
  const src = resolveStoreImage(source, false);
  return src === '' ? null : { src };
}

function resolveStoreImage(source: string | null | undefined, allowHttp: boolean): string {
  // Reject protocol-relative URLs, Windows backslashes, and control characters to prevent SSRF and open redirect exploits.
  if (!source?.trim() || source.startsWith('//') || source.includes('\\') || /[\t\r\n]/.test(source)) return '';
  try {
    const api = new URL(process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000');
    const url = new URL(source.startsWith('/storage/') ? api.origin + source : source);
    if (url.username || url.password) return '';
    const loopback = (host: string) => host === 'localhost' || host === '127.0.0.1';
    // Route API storage paths through the Next.js rewrite proxy (/store-media/) to preserve CSP and avoid mixed-content issues.
    if (['http:', 'https:'].includes(url.protocol) && url.protocol === api.protocol && url.port === api.port
      && (url.hostname === api.hostname || (loopback(url.hostname) && loopback(api.hostname)))
      && url.pathname.startsWith('/storage/')) {
      return `/store-media/${url.pathname.slice('/storage/'.length)}${url.search}`;
    }
    if (url.protocol === 'https:' || (allowHttp && url.protocol === 'http:')) return source;
  } catch {
    if (source.startsWith('/')) return source;
  }
  return '';
}

/**
 * Resolves a sanitized media URL string allowing HTTP protocol in development contexts.
 *
 * @param source - Raw image URL or relative path.
 * @returns Sanitized media URL string, or an empty string if rejected.
 */
export const storeImageUrl = (source: string | null | undefined) => resolveStoreImage(source, true);
