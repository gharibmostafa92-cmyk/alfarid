import { useEffect, useRef } from 'react';
import { authEndpoints, resolveServiceUrl } from '@alfarid/api-client';
import { useAuthStore } from '../../stores/auth.store';
import { AdminLoading } from '../../components/admin/AdminLoading';
import { toStaffUser } from '../../stores/mappers';

const WEB_URL = resolveServiceUrl((import.meta.env.VITE_WEB_URL as string | undefined) ?? 'http://localhost:3000');

export function resolveSafeRedirectPath(path: unknown): string {
  if (
    typeof path === 'string' &&
    path.startsWith('/') &&
    !path.startsWith('//') &&
    !/[\x00-\x1f\x7f\\]/.test(path)
  ) {
    return path;
  }
  return '/dashboard';
}

export default function AuthCallbackPage() {
  const login = useAuthStore((s) => s.login);
  const ran = useRef(false); // Prevent double-execution in React StrictMode since handoff codes are single-use

  useEffect(() => {
    if (ran.current) return;
    ran.current = true;
    // Handoff code arrives in URL fragment to prevent exposure in server logs or Referer headers.
    const fragment = new URLSearchParams(window.location.hash.slice(1));
    const code = fragment.get('code');
    const lang = fragment.get('lang') === 'en' ? 'en' : 'ar';
    window.history.replaceState(null, '', window.location.pathname);
    if (!code) {
      window.location.replace(`${WEB_URL}/auth/login?error=handoff`);
      return;
    }
    const previous = localStorage.getItem('backoffice_token');
    authEndpoints
      .exchangeHandoff(code, previous)
      .then(async (res) => {
        const current = localStorage.getItem('backoffice_token');
        if (current !== previous) {
          // Another tab changed the session while this request was in flight.
          await authEndpoints.logout(AbortSignal.timeout(3000), res.token).catch(() => {});
          window.location.replace('/login');
          return;
        }
        login(res.token, toStaffUser(res.data));
        sessionStorage.setItem('alfarid_login_notice', lang);
        window.location.replace(resolveSafeRedirectPath(res.redirect?.path));
      })
      .catch((error) => {
        if (error?.response?.data?.code === 'active_session' || localStorage.getItem('backoffice_token') !== previous) {
          window.location.replace('/login');
        } else {
          window.location.replace(`${WEB_URL}/auth/login?error=handoff`);
        }
      });
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
      <AdminLoading compact label="جارٍ تسجيل الدخول" />
    </div>
  );
}
