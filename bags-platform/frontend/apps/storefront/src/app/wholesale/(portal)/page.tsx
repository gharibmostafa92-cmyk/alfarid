'use client';

import { useRouter } from 'next/navigation';
import { useBranding } from '@alfarid/ui/branding';
import { WholesalePortal } from '@alfarid/ui';
import { storeImageUrl } from '@/lib/catalog/store-image';
import { getLegalPolicyHref, LEGAL_POLICY_VERSION } from '@/lib/legal-policy-links';
import { logoutWeb, requestPasswordReset, resetWebPassword } from '@/services/auth.service';

export default function WholesalePortalPage() {
  const router = useRouter();
  const { branding } = useBranding();
  return (
    <WholesalePortal brandName={branding.name}
      productImageUrl={storeImageUrl}
      checkoutTerms={{ version: LEGAL_POLICY_VERSION, href: getLegalPolicyHref('terms-and-conditions') }}
      onLogout={logoutWeb}
      onForbidden={() => router.replace('/wholesale/application-status')}
      onForgotSubmit={requestPasswordReset}
      onResetSubmit={resetWebPassword}
    />
  );
}
