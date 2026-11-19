import { useEffect, useRef, useState, type FormEvent } from 'react';

export type PasswordFields = {
  current_password: string;
  password: string;
  password_confirmation: string;
};

export function ChangePasswordForm({ minimum, staff = false, onSubmit }: {
  minimum: number;
  staff?: boolean;
  onSubmit: (fields: PasswordFields, signal: AbortSignal) => Promise<void>;
}) {
  const inFlight = useRef<AbortController | null>(null);
  useEffect(() => () => inFlight.current?.abort(), []);
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState('');
  const [success, setSuccess] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (inFlight.current) return;
    const form = event.currentTarget;
    const data = new FormData(form);
    const fields = {
      current_password: String(data.get('current_password')),
      password: String(data.get('password')),
      password_confirmation: String(data.get('password_confirmation')),
    };
    setSuccess(false);
    if (fields.password !== fields.password_confirmation) {
      setMessage('كلمتا المرور غير متطابقتين.');
      return;
    }
    if ([...fields.password].length < minimum || new TextEncoder().encode(fields.password).length > 72
      || (staff && (!/\p{L}/u.test(fields.password) || !/\p{N}/u.test(fields.password)))) {
      setMessage(staff ? 'استخدم ١٢ حرفًا على الأقل، تتضمن حروفًا وأرقامًا، وبحد أقصى ٧٢ بايت.' : 'كلمة المرور الجديدة خارج الحد المسموح.');
      return;
    }
    const request = new AbortController();
    inFlight.current = request;
    setPending(true);
    setMessage('');
    try {
      await onSubmit(fields, request.signal);
      if (request.signal.aborted) return;
      form.reset();
      setSuccess(true);
      setMessage('تم تغيير كلمة المرور.');
    } catch (error) {
      if (request.signal.aborted) return;
      const response = (error as { response?: { status: number; data?: { errors?: Record<string, string[]> } } }).response;
      const status = response?.status;
      const field = response?.data?.errors;
      setMessage(status === 422 && field?.current_password ? 'تحقق من كلمة المرور الحالية.'
        : status === 422 ? 'تحقق من متطلبات كلمة المرور الجديدة.'
        : status === 429 ? 'محاولات كثيرة. انتظر قبل المحاولة مرة أخرى.'
        : status === 409 ? 'تغيّرت بيانات الحساب. حدّث الصفحة قبل إعادة المحاولة.'
        : status === 401 || status === 403 ? 'تعذر التحقق من الجلسة أو صلاحية الحساب.'
        : !response ? 'تعذر تأكيد التغيير. جرّب تسجيل الدخول بالكلمة الجديدة قبل إعادة المحاولة.'
        : 'تعذر تغيير كلمة المرور.');
    } finally {
      inFlight.current = null;
      if (!request.signal.aborted) setPending(false);
    }
  }

  return <section dir="rtl" lang="ar" style={{ maxWidth: 420, margin: '2rem auto', padding: '1rem' }}>
    <h1>تغيير كلمة المرور</h1>
    <form onSubmit={submit} aria-busy={pending}>
      <fieldset disabled={pending} style={{ display: 'grid', gap: '0.75rem' }}>
        <label>كلمة المرور الحالية <input name="current_password" type="password" autoComplete="current-password" required maxLength={255} /></label>
        <label>كلمة المرور الجديدة <input name="password" type="password" autoComplete="new-password" required maxLength={72} /></label>
        <label>تأكيد كلمة المرور الجديدة <input name="password_confirmation" type="password" autoComplete="new-password" required maxLength={72} /></label>
        <button type="submit">{pending ? 'جارٍ الحفظ…' : 'حفظ كلمة المرور'}</button>
      </fieldset>
      {message && <p role={success ? 'status' : 'alert'}>{message}</p>}
    </form>
  </section>;
}
