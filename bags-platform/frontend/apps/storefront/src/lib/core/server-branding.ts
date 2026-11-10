import { cache } from 'react';
import { DEFAULT_BRANDING, type BrandIdentity } from '@alfarid/ui/brand-identity';
import { storeImageUrl } from '@/lib/catalog/store-image';
import { cloudRunIdentityToken } from '@/lib/core/cloud-run-api';

export const getServerBranding = cache(async (): Promise<BrandIdentity> => {
  try {
    const privateOrigin = process.env.PRIVATE_CLOUD_RUN_API_ORIGIN;
    const apiUrl = new URL(privateOrigin || process.env.API_INTERNAL_URL || process.env.NEXT_PUBLIC_API_URL || 'http://127.0.0.1:8000');
    // Laravel's local server binds IPv4; Node may resolve localhost only to IPv6.
    if (!process.env.API_INTERNAL_URL && apiUrl.hostname === 'localhost') apiUrl.hostname = '127.0.0.1';
    // Branding is identical for all visitors; do not forward client IP headers to allow global fetch cache sharing across requests.
    const headers = new Headers({ Accept: 'application/json' });
    if (privateOrigin) headers.set('X-Serverless-Authorization', 'Bearer ' + await cloudRunIdentityToken(apiUrl.origin));
    const response = await fetch(new URL('/api/v1/store/branding', apiUrl), {
      next: { revalidate: 60, tags: ['branding'] }, signal: AbortSignal.timeout(privateOrigin ? 12000 : 1500),
      headers, redirect: 'error',
    });
    if (!response.ok) throw new Error(`Branding API returned ${response.status}`);
    const { data } = await response.json() as { data: BrandIdentity };
    return { ...DEFAULT_BRANDING, ...data, name: data.name?.trim() || DEFAULT_BRANDING.name, logo: data.logo ? storeImageUrl(data.logo) : null };
  } catch (error) {
    console.warn('Server branding unavailable; using the default identity.', error instanceof Error ? error.message : 'Unknown error');
    return DEFAULT_BRANDING;
  }
});
