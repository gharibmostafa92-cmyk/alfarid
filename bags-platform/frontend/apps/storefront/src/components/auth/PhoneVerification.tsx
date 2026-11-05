'use client';

import { useEffect, useState } from 'react';
import { getRegistrationOptions, sendPhoneVerification, verifyPhone } from '@/services/auth.service';
import { HumanVerification } from './HumanVerification';

export type PhoneProof = { token: string; ready: boolean };

function normalizeCode(value: string) {
  return value.replace(/[٠-٩]/g, digit => String(digit.charCodeAt(0) - 1632))
    .replace(/[۰-۹]/g, digit => String(digit.charCodeAt(0) - 1776))
    .replace(/[^0-9]/g, '');
}

export function PhoneVerification({ phone, lang, disabled, onChange, onBusyChange }: {
  phone: string; lang: 'ar' | 'en'; disabled: boolean;
  onChange: (proof: PhoneProof) => void; onBusyChange: (busy: boolean) => void;
}) {
  const [options, setOptions] = useState<Awaited<ReturnType<typeof getRegistrationOptions>>>();
  const [configError, setConfigError] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const [challenge, setChallenge] = useState('');
  const [challengeAttempt, setChallengeAttempt] = useState(0);
  const [proof, setProof] = useState<{ phone: string; token: string }>();
  const [code, setCode] = useState('');
  const [sent, setSent] = useState(false);
  const [cooldown, setCooldown] = useState(0);
  const [resendAt, setResendAt] = useState(0);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const text = (ar: string, en: string) => lang === 'ar' ? ar : en;
  const validProof = proof?.phone === phone ? proof.token : '';

  useEffect(() => {
    let cancelled = false;
    setConfigError(false);
    getRegistrationOptions().then(value => { if (!cancelled) setOptions(value); })
      .catch(() => { if (!cancelled) setConfigError(true); });
    return () => { cancelled = true; };
  }, [attempt]);

  useEffect(() => {
    onChange({ token: validProof, ready: !!options && (!options.phone_verification_required || !!validProof) });
  }, [options, validProof, phone, onChange]);

  useEffect(() => {
    setProof(undefined); setCode(''); setSent(false); setError(''); setNotice('');
    setChallenge(''); setChallengeAttempt(value => value + 1);
  }, [phone]);

  useEffect(() => {
    if (!proof) return;
    const timer = setTimeout(() => setProof(undefined), 10 * 60 * 1000);
    return () => clearTimeout(timer);
  }, [proof]);

  useEffect(() => {
    const update = () => setCooldown(Math.max(0, Math.ceil((resendAt - Date.now()) / 1000)));
    update();
    if (resendAt <= Date.now()) return;
    const timer = setInterval(update, 1000);
    return () => clearInterval(timer);
  }, [resendAt]);

  async function run(send: boolean) {
    if (disabled) return;
    onBusyChange(true); setError(''); setNotice('');
    try {
      if (send) {
        const result = await sendPhoneVerification(phone.trim(), challenge);
        setNotice(result.message); setSent(true); setResendAt(Date.now() + 60000);
      } else {
        const token = await verifyPhone(phone.trim(), code);
        setProof({ phone, token }); setCode('');
      }
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> }; status?: number; headers?: Record<string, string> } }).response;
      setError(Object.values(response?.data?.errors ?? {}).flat()[0] ?? response?.data?.message
        ?? text('تعذر إتمام التحقق، حاول مجددًا.', 'Verification failed. Please try again.'));
      if (response?.status === 429) {
        setResendAt(Date.now() + Math.max(1, Math.min(3600, Number(response.headers?.['retry-after']) || 60)) * 1000);
      }
    } finally {
      // Siteverify consumes the challenge even when a later API step fails.
      if (send) { setChallenge(''); setChallengeAttempt(value => value + 1); }
      onBusyChange(false);
    }
  }

  if (configError) return <div role="alert">
    <p className="luxury-field-error">{text('تعذر تحميل إعدادات التحقق.', 'Could not load verification settings.')}</p>
    <button type="button" className="luxury-switch-link" onClick={() => setAttempt(value => value + 1)}>{text('إعادة المحاولة', 'Try again')}</button>
  </div>;
  if (!options) return <p role="status" className="luxury-field-hint">{text('جارٍ تحميل إعدادات التحقق…', 'Loading verification settings…')}</p>;
  if (!options.phone_verification_required) return null;
  if (validProof) return <p role="status" className="luxury-success">{text('رقم الموبايل مؤكّد', 'Mobile number verified')}</p>;
  if (!options.turnstile_site_key) return <p role="alert" className="luxury-form-error">{text('التحقق من الموبايل غير متاح حاليًا؛ تواصل مع الدعم.', 'Phone verification is unavailable. Please contact support.')}</p>;

  return <section aria-label={text('تأكيد رقم الموبايل', 'Verify mobile number')} className="luxury-auth-fields">
    <p className="luxury-field-hint">{text('أكّد ملكية رقم الموبايل قبل التسجيل.', 'Verify your mobile number before registering.')}</p>
    <HumanVerification key={challengeAttempt} siteKey={options.turnstile_site_key} lang={lang} onChange={setChallenge} />
    <button type="button" className="pill-btn-primary" disabled={disabled || !phone.trim() || !challenge || cooldown > 0} onClick={() => run(true)}>
      {cooldown > 0 ? text(`إعادة الإرسال بعد ${cooldown} ثانية`, `Resend in ${cooldown}s`) : text('إرسال رمز التحقق', 'Send verification code')}
    </button>
    {sent && <div className="luxury-phone-verification">
      <div>
        <label htmlFor="phone-code" className="luxury-field-label">{text('رمز التحقق', 'Verification code')}</label>
        <input
          id="phone-code" name="code" className="pill-input" inputMode="numeric"
          autoComplete="one-time-code" dir="ltr" maxLength={6} value={code} disabled={disabled}
          onChange={event => setCode(normalizeCode(event.target.value))}
        />
      </div>
      <button type="button" className="luxury-switch-link" disabled={disabled || code.length !== 6} onClick={() => run(false)}>{text('تأكيد الرقم', 'Verify number')}</button>
    </div>}
    {notice && <p role="status" className="luxury-field-hint">{notice}</p>}
    {error && <p role="alert" className="luxury-form-error">{error}</p>}
  </section>;
}
