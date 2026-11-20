'use client';

import { useRouter } from 'next/navigation';
import { authEndpoints } from '@alfarid/api-client';
import { wholesalePortal } from '@alfarid/ui';
import { useBranding } from '@alfarid/ui/branding';
import { RequireActor } from '@/components/auth/RequireActor';
import { logoutWeb } from '@/services/auth.service';
import { useAuthStore } from '@/store/auth.store';

export default function ApplicationStatusPage() {
  const router = useRouter();
  const { branding } = useBranding();

  const enterPortal = async () => {
    const { token } = useAuthStore.getState();
    if (!token) return;
    const me = await authEndpoints.me();
    const current = useAuthStore.getState();
    if (current.token !== token) return;
    if (me.actor?.type !== 'customer' || me.redirect?.destination !== 'wholesale') {
      throw new Error('Wholesale approval required');
    }

    current.setSession({
      token,
      actorType: 'customer',
      destination: 'wholesale',
      home: '/wholesale',
      user: me.data ?? {},
    });
    router.replace('/wholesale');
  };

  return (
    <RequireActor allow={['wholesale_pending', 'wholesale']}>
      <div className="wholesale-portal-app" dir="rtl">
        <wholesalePortal.ApplicationStatus
          brandName={branding.name}
          onLogout={() => logoutWeb()}
          onEnter={enterPortal}
          passwordHref="/security/password"
        />
      </div>
    </RequireActor>
  );
}
