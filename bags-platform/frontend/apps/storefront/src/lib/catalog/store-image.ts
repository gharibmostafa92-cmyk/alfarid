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

function configuredUrl(raw: string | undefined, fallback: string): URL {
  const value = (raw ?? '').trim();
  try {
    return new URL(value || fallback, fallback);
  } catch {
    return new URL(fallback);
  }
}

/** Same segment rules as the /store-media middleware, including a literal percent in the filename. */
function safeStoreMediaPath(pathname: string): string | null {
  const segments = pathname.split('/').filter(Boolean);
  if (segments.length === 0) return null;
  for (const segment of segments) {
    if (segment === '.' || segment === '..' || segment.includes('\\') || /[\u0000-\u001f\u007f]/.test(segment)) return null;
    if (/%(?:2e|2f|5c)/i.test(segment)) return null;
    try {
      const decoded = decodeURIComponent(segment);
      if (!decoded || decoded === '.' || decoded === '..' || decoded.includes('\\') || decoded.includes('/')) return null;
      if (/[\u0000-\u001f\u007f]/.test(decoded) || /%(?:2e|2f|5c)/i.test(decoded)) return null;
    } catch {
      return null;
    }
  }
  return segments.join('/');
}

function isR2Host(hostname: string): boolean {
  const host = hostname.toLowerCase();
  return host.endsWith('.r2.dev') || host.endsWith('.r2.cloudflarestorage.com');
}

/**
 * Maps an https object URL onto same-origin /store-media without its query string.
 * A configured CDN origin is returned unchanged. Any other host is blank.
 */
export function publicObjectImageUrl(source: string, cdnPublicUrl = ''): string {
  let url: URL;
  try {
    url = new URL(source);
  } catch {
    return '';
  }
  if (url.protocol !== 'https:' || url.username || url.password) return '';
  let cdnOrigin = '';
  const cdnRaw = cdnPublicUrl.trim();
  if (cdnRaw) {
    try { cdnOrigin = new URL(cdnRaw).origin; } catch { cdnOrigin = ''; }
  }
  if (cdnOrigin && url.origin === cdnOrigin) return source;
  if (cdnOrigin || !isR2Host(url.hostname)) return '';
  const mediaPath = safeStoreMediaPath(url.pathname);
  return mediaPath ? `/store-media/${mediaPath}` : '';
}

function resolveStoreImage(source: string | null | undefined, allowHttp: boolean): string {
  // Reject protocol-relative URLs, Windows backslashes, traversal and control characters to prevent SSRF and open redirect exploits.
  if (!source || source !== source.trim() || source.startsWith('//') || source.includes('\\') || source.includes('..') || /%(?:2e|2f|5c)/i.test(source) || /[\u0000-\u001f\u007f]/.test(source)) return '';
  if (STATIC_IMAGE_PREFIXES.some((prefix) => source.startsWith(prefix))) return source;
  try {
    const api = configuredUrl(process.env.NEXT_PUBLIC_API_URL, 'http://localhost:8000');
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
    if (url.protocol === 'https:' && (url.origin === api.origin || url.hostname === 'images.unsplash.com')) return source;
    // The public R2 origin is inlined at build time. When it is missing, keep a
    // known object-storage host reachable through the same-origin media proxy.
    if (url.protocol === 'https:') return publicObjectImageUrl(source, process.env.NEXT_PUBLIC_R2_PUBLIC_URL ?? '');
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
