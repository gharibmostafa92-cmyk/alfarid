/** Public API media uses the store origin, preserving CSP, HTTPS and LAN previews. */
export function storeImageSource(source: string | null | undefined): { src: string } | null {
  if (!source?.trim()) return null;
  try {
    const api = new URL(process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000');
    const url = new URL(source.startsWith('/storage/') ? api.origin + source : source);
    const loopback = (host: string) => host === 'localhost' || host === '127.0.0.1';
    if (['http:', 'https:'].includes(url.protocol) && url.protocol === api.protocol && url.port === api.port
      && (url.hostname === api.hostname || (loopback(url.hostname) && loopback(api.hostname)))
      && url.pathname.startsWith('/storage/') && !url.username && !url.password) {
      return { src: `/store-media/${url.pathname.slice('/storage/'.length)}${url.search}` };
    }
    if (url.protocol === 'https:') return { src: source };
  } catch {
    if (source.startsWith('/') && !source.startsWith('//')) return { src: source };
  }
  return null;
}

export const storeImageUrl = (source: string | null | undefined) => storeImageSource(source)?.src ?? source ?? '';
