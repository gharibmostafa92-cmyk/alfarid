import { useEffect, useState } from 'react';
import { useAuthStore } from '../../stores/auth.store';

const WEB_URL = import.meta.env.VITE_WEB_URL ?? 'http://localhost:3000';

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
    <div role="alert" style={{ padding: 40, textAlign: 'center' }}>
      <p>تعذر التحقق من الجلسة. تحقق من الاتصال وحاول مرة أخرى.</p>
      <button type="button" onClick={() => setAttempt((n) => n + 1)}>إعادة المحاولة</button>
    </div>
  );
  if (!token || verifiedToken !== token) return <p role="status">جارٍ التحقق من الجلسة…</p>;
  return <>{children}</>;
}
