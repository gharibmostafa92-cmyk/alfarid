import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { Eye, EyeOff, Lock } from 'lucide-react';

export type PasswordFields = {
  current_password: string;
  password: string;
  password_confirmation: string;
};

export function ChangePasswordForm({ minimum, staff = false, lang = 'ar', variant = 'page', onSubmit }: {
  minimum: number;
  staff?: boolean;
  lang?: 'en' | 'ar';
  variant?: 'page' | 'pos';
  onSubmit: (fields: PasswordFields, signal: AbortSignal) => Promise<void>;
}) {
  const isAr = lang === 'ar';
  const t = isAr ? {
    title: 'تغيير كلمة المرور',
    subtitleStaff: 'استخدم ١٢ حرفًا على الأقل تتضمن حروفًا وأرقامًا لحماية حسابك.',
    subtitleCustomer: `أدخل كلمة المرور الحالية ثم اختر كلمة مرور جديدة لا تقل عن ${minimum} أحرف.`,
    currentLabel: 'كلمة المرور الحالية',
    newLabel: 'كلمة المرور الجديدة',
    confirmLabel: 'تأكيد كلمة المرور الجديدة',
    showCurrent: 'إظهار كلمة المرور الحالية',
    hideCurrent: 'إخفاء كلمة المرور الحالية',
    showNew: 'إظهار كلمة المرور الجديدة',
    hideNew: 'إخفاء كلمة المرور الجديدة',
    showConfirm: 'إظهار تأكيد كلمة المرور',
    hideConfirm: 'إخفاء تأكيد كلمة المرور',
    save: 'حفظ كلمة المرور',
    saving: 'جارٍ الحفظ…',
    mismatch: 'كلمتا المرور غير متطابقتين.',
    invalidStaff: 'استخدم ١٢ حرفًا على الأقل، تتضمن حروفًا وأرقامًا، وبحد أقصى ٧٢ بايت.',
    invalidRange: 'كلمة المرور الجديدة خارج الحد المسموح.',
    saved: 'تم تغيير كلمة المرور.',
    errCurrent: 'تحقق من كلمة المرور الحالية.',
    errNew: 'تحقق من متطلبات كلمة المرور الجديدة.',
    errRate: 'محاولات كثيرة. انتظر قبل المحاولة مرة أخرى.',
    errConflict: 'تغيّرت بيانات الحساب. حدّث الصفحة قبل إعادة المحاولة.',
    errAuth: 'تعذر التحقق من الجلسة أو صلاحية الحساب.',
    errNetwork: 'تعذر تأكيد التغيير. جرّب تسجيل الدخول بالكلمة الجديدة قبل إعادة المحاولة.',
    errGeneric: 'تعذر تغيير كلمة المرور.',
  } : {
    title: 'Change Password',
    subtitleStaff: 'Use at least 12 characters including letters and numbers to protect your account.',
    subtitleCustomer: `Enter your current password, then choose a new password of at least ${minimum} characters.`,
    currentLabel: 'Current Password',
    newLabel: 'New Password',
    confirmLabel: 'Confirm New Password',
    showCurrent: 'Show current password',
    hideCurrent: 'Hide current password',
    showNew: 'Show new password',
    hideNew: 'Hide new password',
    showConfirm: 'Show confirm password',
    hideConfirm: 'Hide confirm password',
    save: 'Save Password',
    saving: 'Saving…',
    mismatch: 'The two passwords do not match.',
    invalidStaff: 'Use at least 12 characters with letters and numbers, up to 72 bytes.',
    invalidRange: 'New password is outside the allowed length.',
    saved: 'Password updated successfully.',
    errCurrent: 'Please check your current password.',
    errNew: 'Please check the new password requirements.',
    errRate: 'Too many attempts. Please wait before trying again.',
    errConflict: 'Account details changed. Refresh the page before trying again.',
    errAuth: 'Session expired or insufficient permissions.',
    errNetwork: 'Could not confirm the update. Try signing in with the new password before retrying.',
    errGeneric: 'Could not update password.',
  };
  const inFlight = useRef<AbortController | null>(null);
  useEffect(() => () => inFlight.current?.abort(), []);
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState('');
  const [success, setSuccess] = useState(false);
  const [showCurrent, setShowCurrent] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);
  const baseId = useId();

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
      setMessage(t.mismatch);
      return;
    }
    if ([...fields.password].length < minimum || new TextEncoder().encode(fields.password).length > 72
      || (staff && (!/\p{L}/u.test(fields.password) || !/\p{N}/u.test(fields.password)))) {
      setMessage(staff ? t.invalidStaff : t.invalidRange);
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
      setMessage(t.saved);
    } catch (error) {
      if (request.signal.aborted) return;
      const response = (error as { response?: { status: number; data?: { errors?: Record<string, string[]> } } }).response;
      const status = response?.status;
      const field = response?.data?.errors;
      setMessage(status === 422 && field?.current_password ? t.errCurrent
        : status === 422 ? t.errNew
        : status === 429 ? t.errRate
        : status === 409 ? t.errConflict
        : status === 401 || status === 403 ? t.errAuth
        : !response ? t.errNetwork
        : t.errGeneric);
    } finally {
      inFlight.current = null;
      if (!request.signal.aborted) setPending(false);
    }
  }

  if (variant === 'pos') {
    const hintId = `${baseId}-hint`;
    const fields = [
      { name: 'current_password', label: t.currentLabel, show: showCurrent, toggle: setShowCurrent, showLabel: t.showCurrent, hideLabel: t.hideCurrent, autoComplete: 'current-password', maxLength: 255 },
      { name: 'password', label: t.newLabel, show: showNew, toggle: setShowNew, showLabel: t.showNew, hideLabel: t.hideNew, autoComplete: 'new-password', maxLength: 72 },
      { name: 'password_confirmation', label: t.confirmLabel, show: showConfirm, toggle: setShowConfirm, showLabel: t.showConfirm, hideLabel: t.hideConfirm, autoComplete: 'new-password', maxLength: 72 },
    ];

    return (
      <form className="pos-password-form" dir={isAr ? 'rtl' : 'ltr'} lang={lang} onSubmit={submit} aria-busy={pending}>
        <fieldset disabled={pending}>
          <legend>{t.title}</legend>
          {fields.map((field) => (
            <div className="pos-password-field" key={field.name}>
              <label htmlFor={`${baseId}-${field.name}`}>{field.label}</label>
              <div className="pos-password-control">
                <input
                  id={`${baseId}-${field.name}`}
                  name={field.name}
                  type={field.show ? 'text' : 'password'}
                  autoComplete={field.autoComplete}
                  maxLength={field.maxLength}
                  aria-describedby={field.name === 'password' ? hintId : undefined}
                  dir="ltr"
                  required
                />
                <button type="button" onClick={() => field.toggle(!field.show)} aria-label={field.show ? field.hideLabel : field.showLabel}>
                  {field.show ? <EyeOff size={18} aria-hidden="true" /> : <Eye size={18} aria-hidden="true" />}
                </button>
              </div>
              {field.name === 'password' && <p id={hintId}>{staff ? t.subtitleStaff : t.subtitleCustomer}</p>}
            </div>
          ))}
          {message && <p className="pos-password-message" data-success={success} role={success ? 'status' : 'alert'}>{message}</p>}
          <button className="btn btn-primary pos-password-save" type="submit">{pending ? t.saving : t.save}</button>
        </fieldset>
      </form>
    );
  }

  const inputClass =
    'w-full rounded-xl border border-slate-300 bg-slate-50/60 py-2.5 pe-11 ps-3.5 text-sm text-slate-900 placeholder:text-slate-400 transition focus:border-[#111111] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#111111]/10 disabled:opacity-60';

  return (
    <section dir={isAr ? 'rtl' : 'ltr'} lang={lang} className="mx-auto w-full max-w-[460px] px-4 py-8 sm:py-12">
      <div className="rounded-2xl border border-slate-200/90 bg-white p-6 shadow-[0_16px_40px_-16px_rgba(15,23,42,0.12)] sm:p-8">
        <div className="mb-6 flex items-start gap-3.5">
          <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#111111] text-white shadow-sm">
            <Lock className="h-5 w-5" aria-hidden="true" />
          </div>
          <div>
            <h1 className="text-xl font-bold tracking-tight text-slate-900">{t.title}</h1>
            <p className="mt-1 text-xs leading-relaxed text-slate-500">
              {staff ? t.subtitleStaff : t.subtitleCustomer}
            </p>
          </div>
        </div>

        <form onSubmit={submit} aria-busy={pending} className="space-y-4">
          <fieldset disabled={pending} className="space-y-4 border-0 p-0 m-0">
            <div className="space-y-1.5">
              <label htmlFor={`${baseId}-current`} className="block text-xs font-semibold text-slate-700">
                {t.currentLabel}
              </label>
              <div className="relative">
                <input
                  id={`${baseId}-current`}
                  name="current_password"
                  type={showCurrent ? 'text' : 'password'}
                  autoComplete="current-password"
                  required
                  maxLength={255}
                  className={inputClass}
                />
                <button
                  type="button"
                  onClick={() => setShowCurrent((v) => !v)}
                  aria-label={showCurrent ? t.hideCurrent : t.showCurrent}
                  className="absolute inset-y-0 end-0 flex w-10 items-center justify-center text-slate-400 hover:text-slate-700"
                >
                  {showCurrent ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
              </div>
            </div>

            <div className="space-y-1.5">
              <label htmlFor={`${baseId}-new`} className="block text-xs font-semibold text-slate-700">
                {t.newLabel}
              </label>
              <div className="relative">
                <input
                  id={`${baseId}-new`}
                  name="password"
                  type={showNew ? 'text' : 'password'}
                  autoComplete="new-password"
                  required
                  maxLength={72}
                  className={inputClass}
                />
                <button
                  type="button"
                  onClick={() => setShowNew((v) => !v)}
                  aria-label={showNew ? t.hideNew : t.showNew}
                  className="absolute inset-y-0 end-0 flex w-10 items-center justify-center text-slate-400 hover:text-slate-700"
                >
                  {showNew ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
              </div>
            </div>

            <div className="space-y-1.5">
              <label htmlFor={`${baseId}-confirm`} className="block text-xs font-semibold text-slate-700">
                {t.confirmLabel}
              </label>
              <div className="relative">
                <input
                  id={`${baseId}-confirm`}
                  name="password_confirmation"
                  type={showConfirm ? 'text' : 'password'}
                  autoComplete="new-password"
                  required
                  maxLength={72}
                  className={inputClass}
                />
                <button
                  type="button"
                  onClick={() => setShowConfirm((v) => !v)}
                  aria-label={showConfirm ? t.hideConfirm : t.showConfirm}
                  className="absolute inset-y-0 end-0 flex w-10 items-center justify-center text-slate-400 hover:text-slate-700"
                >
                  {showConfirm ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
              </div>
            </div>

            <button
              type="submit"
              className="mt-2 inline-flex min-h-[44px] w-full items-center justify-center rounded-xl bg-[#111111] px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#27272a] focus:outline-none focus:ring-2 focus:ring-[#111111]/30 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {pending ? t.saving : t.save}
            </button>
          </fieldset>

          {message && (
            <p
              role={success ? 'status' : 'alert'}
              className={`rounded-xl border px-3.5 py-2.5 text-xs font-medium ${
                success
                  ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                  : 'border-rose-200 bg-rose-50 text-rose-800'
              }`}
            >
              {message}
            </p>
          )}
        </form>
      </div>
    </section>
  );
}
