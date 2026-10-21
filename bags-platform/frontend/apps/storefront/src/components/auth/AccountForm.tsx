'use client';

import { useState, type FormEvent, type InputHTMLAttributes } from 'react';
import { useRouter } from 'next/navigation';
import { registerWeb, requestPasswordReset, resetWebPassword, sendPhoneVerification, verifyPhone } from '@/services/auth.service';

export type AuthView = 'login' | 'register' | 'wholesale-register' | 'forgot-password' | 'reset-password';
type AccountView = Exclude<AuthView, 'login'>;
type Errors = Record<string, string[]>;

function AuthInput({ label, error, ...props }: InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string }) {
  const id = `auth-${props.name}`;
  return <div>
    <label className="luxury-field-label" htmlFor={id}>{label}</label>
    <input {...props} id={id} className="pill-input" aria-invalid={!!error} aria-describedby={error ? `${id}-error` : undefined} />
    {error && <p id={`${id}-error`} className="luxury-field-error" role="alert">{error}</p>}
  </div>;
}

export function AccountForm({ view, lang, onChangeView, initialIdentifier = '', initialToken = '' }: {
  view: AccountView;
  lang: 'ar' | 'en';
  onChangeView: (view: AuthView) => void;
  initialIdentifier?: string;
  initialToken?: string;
}) {
  const router = useRouter();
  const ar = lang === 'ar';
  const wholesale = view === 'wholesale-register';
  const recovery = view === 'forgot-password' || view === 'reset-password';
  const [businessStep, setBusinessStep] = useState(false);
  const [resetStep, setResetStep] = useState(view === 'reset-password');
  const [recoverySent, setRecoverySent] = useState(false);
  const [finished, setFinished] = useState(false);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  const [formError, setFormError] = useState('');
  const [notice, setNotice] = useState('');
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [identifier, setIdentifier] = useState(initialIdentifier);
  const [resetToken, setResetToken] = useState(initialToken);
  const [company, setCompany] = useState('');
  const [taxNumber, setTaxNumber] = useState('');
  const [commercialRegister, setCommercialRegister] = useState('');
  const [documents, setDocuments] = useState<File[]>([]);
  const [codeSent, setCodeSent] = useState(false);
  const [code, setCode] = useState('');
  const [phoneProof, setPhoneProof] = useState('');
  const [resendAt, setResendAt] = useState(0);

  const text = (arabic: string, english: string) => ar ? arabic : english;
  const title = recovery
    ? resetStep ? text('كلمة مرور جديدة', 'New password') : text('نسيت كلمة المرور؟', 'Forgot password?')
    : wholesale ? text('تسجيل كتاجر جملة', 'Wholesale registration') : text('إنشاء حساب', 'Create an account');
  const subtitle = recovery
    ? resetStep ? text('أدخل رمز الاستعادة وكلمة المرور الجديدة.', 'Enter your recovery code and a new password.')
      : text('أدخل بريدك الإلكتروني أو رقم موبايلك لاستعادة حسابك.', 'Enter your email or mobile number to recover your account.')
    : wholesale ? businessStep ? text('بيانات شركتك ومستنداتها لمراجعة طلب الجملة.', 'Your business details and documents for application review.')
      : text('ابدأ ببياناتك، ثم أكمل بيانات نشاطك التجاري.', 'Start with your details, then tell us about your business.')
      : text('أنشئ حسابك في الفريد وتابع طلباتك بسهولة.', 'Create your Alfarid account and keep track of your orders.');

  function handleError(err: unknown) {
    const response = (err as { response?: { status?: number; data?: { message?: string; errors?: Errors }; headers?: Record<string, string> } }).response;
    if (response?.status === 422) {
      const fields = response.data?.errors ?? {};
      setErrors(fields);
      if (wholesale && ['name', 'phone', 'email', 'password', 'phone_verification_token'].some((key) => fields[key])) setBusinessStep(false);
      if (fields.phone_verification_token) {
        setPhoneProof('');
        setCodeSent(false);
        setCode('');
      }
      if (Object.keys(fields).length === 0) setFormError(response.data?.message ?? text('راجع البيانات وحاول مرة أخرى.', 'Check your details and try again.'));
    } else if (response?.status === 429) {
      const seconds = response.headers?.['retry-after'] ?? '60';
      setFormError(text(`محاولات كثيرة، حاول بعد ${seconds} ثانية.`, `Too many attempts. Try again in ${seconds}s.`));
    } else {
      setFormError(response?.data?.message ?? text('تعذر الاتصال بالخادم. حاول مرة أخرى.', 'Could not reach the server. Please try again.'));
    }
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    setErrors({}); setFormError(''); setNotice('');
    if ((!recovery || resetStep) && password !== confirmation) {
      setErrors({ password_confirmation: [text('كلمتا المرور غير متطابقتين.', 'The passwords do not match.')] });
      return;
    }
    if (wholesale && !businessStep) { setBusinessStep(true); return; }
    setBusy(true);
    try {
      if (recovery) {
        if (resetStep) {
          await resetWebPassword(identifier, resetToken, password, confirmation);
          setFinished(true);
          setPassword(''); setConfirmation(''); setResetToken('');
        } else {
          const response = await requestPasswordReset(identifier);
          setNotice(response.message);
          setRecoverySent(true);
        }
      } else {
        const home = await registerWeb({
          name: name.trim(), phone: phone.trim(), email: email.trim() || undefined, password,
          phone_verification_token: phoneProof || undefined,
          ...(wholesale ? { company_name: company.trim(), tax_number: taxNumber.trim(), commercial_register: commercialRegister.trim() } : {}),
        }, wholesale, documents);
        router.replace(home);
      }
    } catch (err) { handleError(err); }
    finally { setBusy(false); }
  }

  async function sendCode() {
    if (busy) return;
    setFormError(''); setErrors({}); setNotice('');
    if (!phone.trim()) { setErrors({ phone: [text('أدخل رقم الموبايل أولًا.', 'Enter your mobile number first.')] }); return; }
    if (Date.now() < resendAt) {
      setNotice(text('انتظر دقيقة قبل طلب رمز آخر.', 'Wait one minute before requesting another code.'));
      return;
    }
    setBusy(true);
    try {
      const response = await sendPhoneVerification(phone.trim());
      setCodeSent(true); setNotice(response.message); setResendAt(Date.now() + 60000);
    } catch (err) { handleError(err); }
    finally { setBusy(false); }
  }

  async function confirmPhone() {
    if (busy) return;
    setErrors({}); setFormError(''); setNotice('');
    if (!/^\d{6}$/.test(code)) { setErrors({ code: [text('أدخل رمز التحقق المكون من 6 أرقام.', 'Enter the 6-digit verification code.')] }); return; }
    setBusy(true);
    try {
      setPhoneProof(await verifyPhone(phone.trim(), code));
      setNotice(text('تم تأكيد رقم الموبايل.', 'Your mobile number is verified.'));
    } catch (err) { handleError(err); }
    finally { setBusy(false); }
  }

  const passwordFields = <>
    <AuthInput name="password" label={text(resetStep ? 'كلمة المرور الجديدة' : 'كلمة المرور', resetStep ? 'New password' : 'Password')} type="password" autoComplete="new-password" required minLength={8} maxLength={255} value={password} onChange={(e) => setPassword(e.target.value)} error={errors.password?.[0]} />
    <AuthInput name="password_confirmation" label={text('تأكيد كلمة المرور', 'Confirm password')} type="password" autoComplete="new-password" required minLength={8} maxLength={255} value={confirmation} onChange={(e) => setConfirmation(e.target.value)} error={errors.password_confirmation?.[0]} />
  </>;

  return <>
    <h1 className="luxury-title" tabIndex={-1}>{title}</h1>
    <p className="luxury-sub">{subtitle}</p>
    {finished ? <div className="luxury-success" role="status">{text('تم تغيير كلمة المرور. يمكنك تسجيل الدخول الآن.', 'Password changed. You can sign in now.')}</div> : <form className="luxury-form" onSubmit={submit}>
      <fieldset disabled={busy} className="luxury-auth-fields">
        {recovery ? <>
          <AuthInput name="identifier" label={text('البريد الإلكتروني أو رقم الموبايل', 'Email or mobile number')} type="text" autoComplete="username" required maxLength={255} dir="ltr" value={identifier} onChange={(e) => { setIdentifier(e.target.value); setRecoverySent(false); }} error={errors.email?.[0] ?? errors.phone?.[0]} />
          {resetStep && <>
            <AuthInput name="token" label={text('رمز الاستعادة', 'Recovery code')} type="text" autoComplete="one-time-code" required minLength={64} maxLength={64} dir="ltr" value={resetToken} onChange={(e) => setResetToken(e.target.value)} error={errors.token?.[0]} />
            {passwordFields}
          </>}
        </> : wholesale && businessStep ? <>
          <AuthInput name="company_name" label={text('اسم الشركة أو النشاط التجاري', 'Business name')} autoComplete="organization" required maxLength={255} value={company} onChange={(e) => setCompany(e.target.value)} error={errors.company_name?.[0]} />
          <AuthInput name="tax_number" label={text('الرقم الضريبي', 'Tax number')} required maxLength={50} value={taxNumber} onChange={(e) => setTaxNumber(e.target.value)} error={errors.tax_number?.[0]} />
          <AuthInput name="commercial_register" label={text('رقم السجل التجاري', 'Commercial register number')} required maxLength={50} value={commercialRegister} onChange={(e) => setCommercialRegister(e.target.value)} error={errors.commercial_register?.[0]} />
          <div>
            <label className="luxury-field-label" htmlFor="auth-documents">{text('مستندات الشركة', 'Business documents')}</label>
            <p className="luxury-field-hint" id="documents-hint">{text('من 1 إلى 5 ملفات PDF أو JPG أو PNG، حتى 5 ميجابايت للملف.', '1–5 PDF, JPG or PNG files, up to 5 MB each.')}</p>
            <input id="auth-documents" className="luxury-file-input" name="documents" type="file" accept=".pdf,.jpg,.jpeg,.png" multiple required={documents.length === 0} aria-describedby="documents-hint documents-error" onChange={(e) => {
              const selected = Array.from(e.target.files ?? []);
              if (selected.length > 5 || selected.some((f) => f.size > 5 * 1024 * 1024 || !/\.(pdf|jpe?g|png)$/i.test(f.name))) {
                setErrors({ documents: [text('اختر حتى 5 ملفات PDF أو صور، بحجم 5 ميجابايت أو أقل لكل ملف.', 'Choose up to 5 PDF or image files, no larger than 5 MB each.')] });
                setDocuments([]); e.target.value = ''; return;
              }
              setDocuments(selected); setErrors({});
            }} />
            {documents.length > 0 && <ul className="luxury-file-list">{documents.map((file, index) => <li key={`${file.name}-${index}`}>{file.name}</li>)}</ul>}
            <div id="documents-error">{Object.entries(errors).filter(([key]) => key === 'documents' || key.startsWith('documents.')).map(([key, messages]) => <p key={key} className="luxury-field-error" role="alert">{messages[0]}</p>)}</div>
          </div>
        </> : <>
          <AuthInput name="name" label={text('الاسم الكامل', 'Full name')} autoComplete="name" required maxLength={255} value={name} onChange={(e) => setName(e.target.value)} error={errors.name?.[0]} />
          <div>
            <AuthInput name="phone" label={text('رقم الموبايل', 'Mobile number')} type="tel" inputMode="tel" autoComplete="tel" required maxLength={30} dir="ltr" value={phone} onChange={(e) => { setPhone(e.target.value); setPhoneProof(''); setCodeSent(false); setCode(''); setNotice(''); setResendAt(0); }} error={errors.phone?.[0] ?? errors.phone_verification_token?.[0]} />
            {phoneProof ? <p className="luxury-field-hint" role="status">{text('رقم الموبايل مؤكّد', 'Mobile number verified')}</p>
              : <button type="button" className="luxury-inline-action" onClick={sendCode}>{codeSent ? text('إعادة إرسال رمز التحقق', 'Resend verification code') : text('إرسال رمز التحقق', 'Send verification code')}</button>}
            {codeSent && !phoneProof && <div className="luxury-phone-verification">
              <AuthInput name="code" label={text('رمز التحقق', 'Verification code')} inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} error={errors.code?.[0]} />
              <button type="button" className="luxury-secondary-button" onClick={confirmPhone}>{text('تأكيد الرقم', 'Verify number')}</button>
            </div>}
          </div>
          <AuthInput name="email" label={text('البريد الإلكتروني (اختياري)', 'Email (optional)')} type="email" autoComplete="email" maxLength={255} dir="ltr" value={email} onChange={(e) => setEmail(e.target.value)} error={errors.email?.[0]} />
          {passwordFields}
        </>}
      </fieldset>
      {notice && <div className="luxury-success" role="status">{notice}</div>}
      {formError && <p className="luxury-form-error" role="alert">{formError}</p>}
      <button type="submit" className="pill-btn-primary" disabled={busy}>
        {busy ? text('جارٍ التنفيذ…', 'Please wait…') : recovery ? resetStep ? text('تعيين كلمة المرور', 'Set new password') : text('إرسال رمز الاستعادة', 'Send recovery code')
          : wholesale ? businessStep ? text('إرسال طلب التسجيل', 'Submit application') : text('التالي: بيانات النشاط', 'Next: business details') : text('إنشاء حساب', 'Create account')}
      </button>
      {recoverySent && !resetStep && <button type="button" className="luxury-secondary-button" disabled={busy} onClick={() => { setResetStep(true); setNotice(''); setErrors({}); setFormError(''); }}>{text('لدي رمز الاستعادة', 'I have a recovery code')}</button>}
      {wholesale && businessStep && <button type="button" className="luxury-inline-action" disabled={busy} onClick={() => { setBusinessStep(false); setErrors({}); setFormError(''); }}>{text('الخطوة السابقة', 'Previous step')}</button>}
    </form>}
    <div className="luxury-bottom-switch">
      <button type="button" className="luxury-switch-link" disabled={busy} onClick={() => onChangeView('login')}>{text('العودة لتسجيل الدخول', 'Back to sign in')}</button>
      {!recovery && <>
        <span className="luxury-link-sep">·</span>
        <button type="button" className="luxury-switch-link" disabled={busy} onClick={() => onChangeView(wholesale ? 'register' : 'wholesale-register')}>{wholesale ? text('حساب قطاعي', 'Retail account') : text('تسجيل كتاجر جملة', 'Wholesale registration')}</button>
      </>}
    </div>
  </>;
}
