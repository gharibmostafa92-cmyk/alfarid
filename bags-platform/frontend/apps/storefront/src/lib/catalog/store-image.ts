/**
 * Resolves a safe image source object for Next.js Image components.
 *
 * @param source - Raw image URL or relative storage path from the API.
 * @returns Object with sanitized src URL, or null if invalid or unsafe.
 */
export function storeImageSource(source: string | null | undefined): { src: string } | null {
  const src = resolveStoreImage(source, process.env.NODE_ENV !== 'production');
  return src === '' ? null : { src };
}

// Only these static asset prefixes may be served without an absolute origin.
const STATIC_IMAGE_PREFIXES = ['/images/', '/store-media/'];
const loopback = (host: string) => host === 'localhost' || host === '127.0.0.1';
const privateHost = (host: string) => /^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(host);

function resolveStoreImage(source: string | null | undefined, allowHttp: boolean): string {
  // Reject protocol-relative URLs, Windows backslashes, traversal and control characters to prevent SSRF and open redirect exploits.
  if (!source || source !== source.trim() || source.startsWith('//') || source.includes('\\') || source.includes('..') || /[\u0000-\u001f\u007f]/.test(source)) return '';
  if (STATIC_IMAGE_PREFIXES.some((prefix) => source.startsWith(prefix))) return source;
  try {
    const api = new URL(process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000');
    const url = new URL(source.startsWith('/storage/') ? api.origin + source : source);
    if (url.username || url.password) return '';
    const sameApiHost = url.hostname === api.hostname
      || (loopback(api.hostname) && (loopback(url.hostname) || (allowHttp && privateHost(url.hostname))));
    // Route API storage paths through the Next.js rewrite proxy (/store-media/) to preserve CSP and avoid mixed-content issues.
    if (['http:', 'https:'].includes(url.protocol) && url.protocol === api.protocol && url.port === api.port
      && sameApiHost
      && url.pathname.startsWith('/storage/')) {
      return `/store-media/${url.pathname.slice('/storage/'.length)}${url.search}`;
    }
    const cdn = process.env.NEXT_PUBLIC_R2_PUBLIC_URL;
    if (url.protocol === 'https:' && (url.origin === api.origin || (cdn && url.origin === new URL(cdn).origin)
      || url.hostname === 'images.unsplash.com')) return source;
  } catch {
    // An invalid URL cannot be used as an image source.
  }
  return '';
}

/**
 * Resolves a sanitized media URL string allowing HTTP protocol in development contexts.
 *
 * @param source - Raw image URL or relative path.
 * @returns Sanitized media URL string, or an empty string if rejected.
 */
export const storeImageUrl = (source: string | null | undefined) =>
  resolveStoreImage(source, process.env.NODE_ENV !== 'production');
