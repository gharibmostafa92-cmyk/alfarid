import { cache } from 'react';
import { DEFAULT_BRANDING, type BrandIdentity } from '@alfarid/ui/brand-identity';
import { storeImageUrl } from './store-image';

export const getServerBranding = cache(async (): Promise<BrandIdentity> => {
  try {
    const apiUrl = new URL(process.env.API_INTERNAL_URL || process.env.NEXT_PUBLIC_API_URL || 'http://127.0.0.1:8000');
    // Laravel's local server binds IPv4; Node may resolve localhost only to IPv6.
    if (!process.env.API_INTERNAL_URL && apiUrl.hostname === 'localhost') apiUrl.hostname = '127.0.0.1';
    // Forward the real client IP so Laravel rate limiters (store-catalog) see
    // the shopper, not the Next.js node. Safe: fe_public strips any
    // client-supplied X-Forwarded-For before it reaches Next.js.
    let clientIp: string | undefined;
    try {
      const { headers } = await import('next/headers');
      const h = await headers();
      clientIp = h.get('x-forwarded-for')?.split(',')[0]?.trim() || undefined;
    } catch {
      clientIp = undefined;
    }
    const response = await fetch(`${apiUrl.href.replace(/\/$/, '')}/api/v1/store/branding`, {
      next: { revalidate: 60, tags: ['branding'] }, signal: AbortSignal.timeout(1500),
      headers: { Accept: 'application/json', ...(clientIp ? { 'X-Forwarded-For': clientIp } : {}) },
    });
    if (!response.ok) throw new Error(`Branding API returned ${response.status}`);
    const { data } = await response.json() as { data: BrandIdentity };
    return { ...DEFAULT_BRANDING, ...data, name: data.name?.trim() || DEFAULT_BRANDING.name, logo: data.logo ? storeImageUrl(data.logo) : null };
  } catch (error) {
    console.warn('Server branding unavailable; using the default identity.', error instanceof Error ? error.message : 'Unknown error');
    return DEFAULT_BRANDING;
  }
});
