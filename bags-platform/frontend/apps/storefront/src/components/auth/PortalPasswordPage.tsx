'use client';

import { authEndpoints } from '@alfarid/api-client';
import { ChangePasswordForm } from '@alfarid/ui';
import { useAuthStore } from '@/store/auth.store';
import { useStoreLang } from '@/providers/store-lang-provider';

export function PortalPasswordPage({ channel }: { channel: 'customer' | 'supplier' }) {
  const { token, destination } = useAuthStore();
  const { lang } = useStoreLang();
  if (!token) return null;
  return <ChangePasswordForm key={token} lang={channel === 'customer' ? lang : 'ar'} minimum={channel === 'customer' && destination !== 'retail' ? 12 : 8}
    onSubmit={(fields, signal) => authEndpoints.changePortalPassword(channel, fields, token, signal)} />;
}
