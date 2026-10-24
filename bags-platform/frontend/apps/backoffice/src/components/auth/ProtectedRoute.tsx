import { useEffect, useState } from 'react';
import { resolveServiceUrl } from '@alfarid/api-client';
import { useAuthStore } from '../../stores/auth.store';

const WEB_URL = resolveServiceUrl(import.meta.env.VITE_WEB_URL ?? 'http://localhost:3000');

export function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const token = useAuthStore((s) => s.token);
  const [verifiedToken, setVerifiedToken] = useState<string | null>(null);
  const [error, setError] = useState(false);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    if (!token) {
      window.location.replace(`${WEB_URL}/auth/login`);
      return;
    }
    let cancelled = false;
    setError(false);
    useAuthStore.getState().bootstrap()
      .then(() => { if (!cancelled) setVerifiedToken(token); })
      .catch((err) => {
        if (cancelled) return;
        if (err?.response?.status === 403) {
          useAuthStore.getState().logout();
          window.location.replace(`${WEB_URL}/auth/login`);
        } else if (err?.response?.status !== 401) setError(true);
      });
    return () => { cancelled = true; };
  }, [token, attempt]);

  if (error) return (
    <div
      role="alert"
      dir="rtl"
      style={{
        boxSizing: 'border-box',
        minHeight: '100svh',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '24px',
        backgroundColor: '#f2eff3',
        color: '#18151c',
        textAlign: 'center',
      }}
    >
      <div
        style={{
          boxSizing: 'border-box',
          width: '100%',
          maxWidth: '380px',
          backgroundColor: '#ffffff',
          borderRadius: '22px',
          padding: '28px 24px',
          border: '1px solid rgba(24, 21, 28, 0.08)',
          boxShadow: '0 12px 32px rgba(24, 21, 28, 0.06)',
        }}
      >
        <p style={{ margin: '0 0 20px', fontSize: '15px', lineHeight: 1.7, fontWeight: 500 }}>
          تعذر التحقق من الجلسة. تحقق من الاتصال وحاول مرة أخرى.
        </p>
        <button
          type="button"
          onClick={() => setAttempt((n) => n + 1)}
          style={{
            width: '100%',
            minHeight: '48px',
            padding: '12px 20px',
            borderRadius: '999px',
            border: 0,
            backgroundColor: '#18151c',
            color: '#ffffff',
            fontSize: '14px',
            fontWeight: 600,
            cursor: 'pointer',
          }}
        >
          إعادة المحاولة
        </button>
      </div>
    </div>
  );
  if (!token || verifiedToken !== token) return (
    <div
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
      }}
    >
      <p
        role="status"
        style={{
          margin: 0,
          padding: '14px 24px',
          borderRadius: '999px',
          backgroundColor: '#ffffff',
          border: '1px solid rgba(24, 21, 28, 0.08)',
          fontSize: '14px',
          fontWeight: 500,
        }}
      >
        جارٍ التحقق من الجلسة…
      </p>
    </div>
  );
  return <>{children}</>;
}
