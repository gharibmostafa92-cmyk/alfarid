import { useEffect, useState } from 'react';
import { resolveServiceUrl } from '@alfarid/api-client';
import { useAuthStore } from '../../stores/auth.store';
import { AdminLoading } from '../admin/AdminLoading';
import { RotateStaffPassword } from './RotateStaffPassword';

const WEB_URL = resolveServiceUrl(import.meta.env.VITE_WEB_URL ?? 'http://localhost:3000');

export function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const token = useAuthStore((s) => s.token);
  const user = useAuthStore((s) => s.user);
  const [verifiedToken, setVerifiedToken] = useState<string | null>(null);
  const [error, setError] = useState(false);
  const signingOut = useAuthStore((s) => s.signingOut);
  const [notice, setNotice] = useState<'ar' | 'en' | null>(null);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    if (!token) {
      if (!signingOut) window.location.replace(`${WEB_URL}/auth/login`);
      return;
    }
    const controller = new AbortController();
    setError(false);
    useAuthStore.getState().bootstrap(controller.signal)
      .then(() => { if (!controller.signal.aborted) setVerifiedToken(token); })
      .catch((err) => {
        if (controller.signal.aborted) return;
        if (useAuthStore.getState().token !== token) return;
        if (err?.response?.status === 403) {
          const reason = err.response.data?.code === 'pos_branch_unavailable' ? 'branch' : undefined;
          void useAuthStore.getState().logoutRemote('unified', reason);
        } else if (err?.response?.status === 401) {
          useAuthStore.getState().logout();
          window.location.replace(`${WEB_URL}/auth/login`);
        } else setError(true);
      });
    return () => controller.abort();
  }, [token, attempt, signingOut]);

  useEffect(() => {
    if (!token || verifiedToken !== token || user?.must_change_password) return;
    const language = sessionStorage.getItem('alfarid_login_notice');
    sessionStorage.removeItem('alfarid_login_notice');
    if (language === 'ar' || language === 'en') setNotice(language);
  }, [verifiedToken, token, user?.must_change_password]);

  if (error && token) return (
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
  if (token && user?.must_change_password) {
    return <RotateStaffPassword key={user.id} token={token} version={user.version} />;
  }
  return <>
    {notice && <div role="status" lang={notice} dir={notice === 'ar' ? 'rtl' : 'ltr'} className="flex items-center justify-between gap-4 bg-emerald-50 px-4 py-2 text-emerald-950">
      {notice === 'ar' ? 'تم تسجيل الدخول بنجاح.' : 'You signed in successfully.'}
      <button type="button" className="underline" onClick={() => setNotice(null)}>{notice === 'ar' ? 'إغلاق' : 'Dismiss'}</button>
    </div>}
    {children}
  </>;
}
