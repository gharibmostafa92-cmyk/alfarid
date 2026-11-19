import { useEffect } from 'react';
import { resolveServiceUrl } from '@alfarid/api-client';
import { AdminLoading } from '../../components/admin/AdminLoading';
import { useAuthStore } from '../../stores/auth.store';

export default function LoginPage() {
  useEffect(() => {
    if (useAuthStore.getState().token) {
      window.location.replace('/dashboard');
      return;
    }
    window.location.replace(`${resolveServiceUrl(import.meta.env.VITE_WEB_URL ?? 'http://localhost:3000')}/auth/login`);
  }, []);
  return (
    <div
      style={{
        minHeight: '100svh',
        display: 'grid',
        placeItems: 'center',
        backgroundColor: '#f2eff3',
      }}
    >
      <AdminLoading compact label="جارٍ التحميل" />
    </div>
  );
}
