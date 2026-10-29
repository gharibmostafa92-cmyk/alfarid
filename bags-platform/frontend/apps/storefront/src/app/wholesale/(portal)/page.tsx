'use client';

import { WholesalePortal } from '@alfarid/ui';
import { logoutWeb } from '@/services/auth.service';

export default function WholesalePortalPage() {
  return <WholesalePortal onLogout={logoutWeb} />;
}
