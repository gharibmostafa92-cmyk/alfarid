import { useEffect, useRef } from 'react';
import { authEndpoints } from '@alfarid/api-client';
import { useAuthStore } from '../../stores/auth.store';
import { toStaffUser } from '../../stores/mappers';

export default function AuthCallbackPage() {
  const login = useAuthStore((s) => s.login);
  const ran = useRef(false); // React StrictMode بيشغّل الـ effect مرتين، والكود بيُستخدم مرة واحدة بس

  useEffect(() => {
    if (ran.current) return;
    ran.current = true;
    const code = new URLSearchParams(window.location.search).get('code');
    if (!code) {
      window.location.replace(`${import.meta.env.VITE_WEB_URL}/auth/login`);
      return;
    }
    authEndpoints
      .exchangeHandoff(code)
      .then((res) => {
        login(res.token, toStaffUser(res.data));
        window.location.replace(res.redirect.path);
      })
      .catch(() => window.location.replace(`${import.meta.env.VITE_WEB_URL}/auth/login?error=handoff`));
  }, []);

  return <div style={{ padding: 40, textAlign: 'center' }}>جارٍ تسجيل الدخول…</div>;
}
