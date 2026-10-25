'use client';

import { BrandingProvider, type BrandIdentity } from '@alfarid/ui/branding';
import { brandingEndpoints } from '@alfarid/api-client';

export function StoreBrandingProvider({ children, initialBranding }: { children: React.ReactNode; initialBranding: BrandIdentity }) {
  return <BrandingProvider loadBranding={brandingEndpoints.get} initialBranding={initialBranding} manageHead={false} pollIntervalMs={0}>{children}</BrandingProvider>;
}
