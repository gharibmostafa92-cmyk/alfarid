import { useState, type FormEvent } from 'react';
import { authEndpoints } from '@alfarid/api-client';
import { useAuthStore } from '../../stores/auth.store';
import { toStaffUser } from '../../stores/mappers';
import './rotate-staff-password.css';

type RotationError = {
  response?: {
    status?: number;
    data?: { message?: string; errors?: Record<string, string[]> };
  };
};

export function RotateStaffPassword({ token, version }: { token: string; version?: number }) {
  const logout = useAuthStore((state) => state.logoutRemote);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState('');
  const [requiresReload, setRequiresReload] = useState(version == null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (pending || version == null || requiresReload) return;
    const form = new FormData(event.currentTarget);
    setPending(true);
    setError('');
    try {
      const profile = await authEndpoints.staffRotatePassword({
        current_password: String(form.get('current_password')),
        password: String(form.get('password')),
        expected_version: version,
      }, token);
      const session = useAuthStore.getState();
      if (session.token === token && localStorage.getItem('backoffice_token') === token) {
        session.setUser(toStaffUser(profile));
      }
    } catch (failure) {
      const response = (failure as RotationError)?.response;
      if (response?.status === 409) setRequiresReload(true);
      setError(response?.data?.errors?.current_password?.[0]
        || response?.data?.errors?.password?.[0]
        || response?.data?.message
        || 'تعذر تغيير كلمة المرور. حاول مرة أخرى.');
    } finally {
      setPending(false);
    }
  }

  return (
    <main className="staff-rotation" dir="rtl" lang="ar">
      <form className="staff-rotation__card" onSubmit={submit} aria-labelledby="staff-rotation-title" aria-busy={pending}>
        <h1 id="staff-rotation-title">تغيير كلمة المرور</h1>
        <p>استبدل كلمة المرور المؤقتة للمتابعة.</p>
        <label htmlFor="staff-current-password">كلمة المرور الحالية</label>
        <input id="staff-current-password" name="current_password" type="password" autoComplete="current-password"
          dir="ltr" autoFocus required maxLength={255} disabled={pending || requiresReload} />
        <label htmlFor="staff-new-password">كلمة المرور الجديدة</label>
        <input id="staff-new-password" name="password" type="password" autoComplete="new-password" dir="ltr"
          aria-describedby="staff-password-hint" required minLength={12} maxLength={255} disabled={pending || requiresReload} />
        <p id="staff-password-hint" className="staff-rotation__hint">استخدم ١٢ خانة على الأقل تتضمن حروفًا وأرقامًا.</p>
        {(error || requiresReload) && (
          <p role="alert" className="staff-rotation__error">{error || 'أعد تحميل الصفحة لتحديث بيانات حسابك.'}</p>
        )}
        {requiresReload ? (
          <button type="button" className="staff-rotation__primary" onClick={() => window.location.reload()}>إعادة التحميل</button>
        ) : (
          <button type="submit" className="staff-rotation__primary" disabled={pending}>
            {pending ? 'جارٍ الحفظ…' : 'حفظ كلمة المرور'}
          </button>
        )}
        <button type="button" className="staff-rotation__logout" onClick={() => void logout('unified')} disabled={pending}>تسجيل الخروج</button>
      </form>
    </main>
  );
}
