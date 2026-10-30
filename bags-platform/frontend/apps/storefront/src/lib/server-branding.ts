import { cache } from 'react';
import { DEFAULT_BRANDING, type BrandIdentity } from '@alfarid/ui/brand-identity';
import { storeImageUrl } from './store-image';

export const getServerBranding = cache(async (): Promise<BrandIdentity> => {
  try {
    const apiUrl = new URL(process.env.API_INTERNAL_URL || process.env.NEXT_PUBLIC_API_URL || 'http://127.0.0.1:8000');
    // Laravel's local server binds IPv4; Node may resolve localhost only to IPv6.
    if (!process.env.API_INTERNAL_URL && apiUrl.hostname === 'localhost') apiUrl.hostname = '127.0.0.1';
    // M12: branding is identical for every visitor — never forward the client
    // IP here. Per-visitor request headers both opt the route out of static
    // rendering (via next/headers) and partition the fetch cache, dropping the
    // hit rate to ~zero and hitting Laravel on every visit. The shared
    // revalidate:60 entry serves all visitors; the store-branding limiter
    // (1200/min on the server IP) is plenty for one fetch per minute.
    const response = await fetch(`${apiUrl.href.replace(/\/$/, '')}/api/v1/store/branding`, {
      next: { revalidate: 60, tags: ['branding'] }, signal: AbortSignal.timeout(1500),
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw new Error(`Branding API returned ${response.status}`);
    const { data } = await response.json() as { data: BrandIdentity };
    return { ...DEFAULT_BRANDING, ...data, name: data.name?.trim() || DEFAULT_BRANDING.name, logo: data.logo ? storeImageUrl(data.logo) : null };
  } catch (error) {
    console.warn('Server branding unavailable; using the default identity.', error instanceof Error ? error.message : 'Unknown error');
    return DEFAULT_BRANDING;
  }
});
