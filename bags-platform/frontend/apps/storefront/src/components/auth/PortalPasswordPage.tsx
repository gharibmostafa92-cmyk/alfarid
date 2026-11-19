'use client';

import { authEndpoints } from '@alfarid/api-client';
import { ChangePasswordForm } from '@alfarid/ui';
import { useAuthStore } from '@/store/auth.store';

export function PortalPasswordPage({ channel }: { channel: 'customer' | 'supplier' }) {
  const { token, destination } = useAuthStore();
  if (!token) return null;
  return <ChangePasswordForm key={token} minimum={channel === 'customer' && destination !== 'retail' ? 12 : 8}
    onSubmit={(fields, signal) => authEndpoints.changePortalPassword(channel, fields, token, signal)} />;
}
