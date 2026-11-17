'use client';

import { BrandingProvider, type BrandIdentity } from '@alfarid/ui/branding';
import { brandingEndpoints } from '@alfarid/api-client';
import { storeImageUrl } from '@/lib/catalog/store-image';
import { hasInitialBranding } from '@/lib/core/branding-result';

const loadBranding = async (signal?: AbortSignal) => {
  const data = await brandingEndpoints.get(signal);
  return { ...data, logo: data.logo ? storeImageUrl(data.logo) : null };
};

export function StoreBrandingProvider({ children, initialBranding, fromFallback = false }: {
  children: React.ReactNode;
  initialBranding: BrandIdentity;
  fromFallback?: boolean;
}) {
  return <BrandingProvider loadBranding={loadBranding} initialBranding={{ ...initialBranding, logo: initialBranding.logo ? storeImageUrl(initialBranding.logo) : null }} hasInitialBranding={hasInitialBranding(fromFallback)} manageHead={false} pollIntervalMs={0}>{children}</BrandingProvider>;
}
