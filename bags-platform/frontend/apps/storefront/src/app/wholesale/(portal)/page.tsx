'use client';

import { useRouter } from 'next/navigation';
import { WholesalePortal } from '@alfarid/ui';
import { logoutWeb, requestPasswordReset, resetWebPassword } from '@/services/auth.service';

export default function WholesalePortalPage() {
  const router = useRouter();
  return (
    <WholesalePortal
      onLogout={logoutWeb}
      onForbidden={() => router.replace('/wholesale/application-status')}
      onForgotSubmit={requestPasswordReset}
      onResetSubmit={resetWebPassword}
    />
  );
}
