import { useEffect, useRef } from 'react';
import { authEndpoints, resolveServiceUrl } from '@alfarid/api-client';
import { useAuthStore } from '../../stores/auth.store';
import { toStaffUser } from '../../stores/mappers';

const WEB_URL = resolveServiceUrl((import.meta.env.VITE_WEB_URL as string | undefined) ?? 'http://localhost:3000');

export default function AuthCallbackPage() {
  const login = useAuthStore((s) => s.login);
  const ran = useRef(false); // Prevent double-execution in React StrictMode since handoff codes are single-use

  useEffect(() => {
    if (ran.current) return;
    ran.current = true;
    // Handoff code arrives in URL fragment to prevent exposure in server logs or Referer headers.
    const fromHash = new URLSearchParams(window.location.hash.replace(/^#/, '')).get('code');
    const code = fromHash;
    window.history.replaceState(null, '', window.location.pathname);
    if (!code) {
      window.location.replace(`${WEB_URL}/auth/login?error=handoff`);
      return;
    }
    authEndpoints
      .exchangeHandoff(code)
      .then(async (res) => {
        // Revoke previous token using raw fetch before persisting the new token to prevent interceptor misrouting.
        const prev = localStorage.getItem('backoffice_token');
        if (prev && prev !== res.token) {
          const baseURL =
            resolveServiceUrl((import.meta.env.VITE_API_URL as string | undefined) ?? 'http://localhost:8000');
          const ctrl = new AbortController();
          const timer = setTimeout(() => ctrl.abort(), 10000);
          try {
            await fetch(`${baseURL}/api/v1/admin/auth/logout`, {
              method: 'POST',
              headers: { Authorization: `Bearer ${prev}`, 'Content-Type': 'application/json' },
              signal: ctrl.signal,
            });
          } catch {
            // Best-effort logout: former token may already be expired or invalidated
          } finally {
            clearTimeout(timer);
          }
        }
        login(res.token, toStaffUser(res.data));
        window.location.replace(res.redirect.path);
      })
      .catch(() => window.location.replace(`${WEB_URL}/auth/login?error=handoff`));
  }, []);

  return (
    <div
      role="status"
      dir="rtl"
      style={{
        boxSizing: 'border-box',
        minHeight: '100svh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '24px',
        backgroundColor: '#f2eff3',
        color: '#18151c',
        textAlign: 'center',
        fontSize: '15px',
        fontWeight: 500,
      }}
    >
      جارٍ تسجيل الدخول…
    </div>
  );
}
