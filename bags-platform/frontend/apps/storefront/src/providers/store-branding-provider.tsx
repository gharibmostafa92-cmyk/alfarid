'use client';

import { BrandingProvider, type BrandIdentity } from '@alfarid/ui/branding';
import { brandingEndpoints } from '@alfarid/api-client';
import { storeImageUrl } from '@/lib/store-image';

const loadBranding = async (signal?: AbortSignal) => {
  const data = await brandingEndpoints.get(signal);
  return { ...data, logo: data.logo ? storeImageUrl(data.logo) : null };
};

export function StoreBrandingProvider({ children, initialBranding }: { children: React.ReactNode; initialBranding: BrandIdentity }) {
  return <BrandingProvider loadBranding={loadBranding} initialBranding={{ ...initialBranding, logo: initialBranding.logo ? storeImageUrl(initialBranding.logo) : null }} manageHead={false} pollIntervalMs={0}>{children}</BrandingProvider>;
}
