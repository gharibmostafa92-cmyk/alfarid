import { useEffect } from 'react';
import { AdminLoading } from '../../components/admin/AdminLoading';

export default function LoginPage() {
  useEffect(() => {
    const page = new URL('/auth/session', window.location.origin);
    page.hash = new URLSearchParams({
      nonce: 'backoffice-login',
      page: '/auth/login',
    }).toString();
    window.location.replace(page.href);
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
