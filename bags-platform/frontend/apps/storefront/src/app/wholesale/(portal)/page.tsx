'use client';

import { useRouter } from 'next/navigation';
import { useBranding } from '@alfarid/ui/branding';
import { WholesalePortal } from '@alfarid/ui';
import { logoutWeb, requestPasswordReset, resetWebPassword } from '@/services/auth.service';

export default function WholesalePortalPage() {
  const router = useRouter();
  const { branding } = useBranding();
  return (
    <WholesalePortal brandName={branding.name}
      onLogout={logoutWeb}
      onForbidden={() => router.replace('/wholesale/application-status')}
      onForgotSubmit={requestPasswordReset}
      onResetSubmit={resetWebPassword}
    />
  );
}
