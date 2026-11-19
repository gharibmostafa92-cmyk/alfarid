'use client';
import { resolveServiceUrl } from '@alfarid/api-client';
import { useBranding } from '@alfarid/ui/branding';
import React, { useState, useEffect, useRef } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import '../../styles/luxuryAuth.css';
import { AuthHalftone } from '@alfarid/ui';
import { useAuthStore } from '@/store/auth.store';
import { loginUnified, getRegistrationOptions } from '@/services/auth.service';
import { HumanVerification } from './HumanVerification';
import { AccountForm, type AuthView } from './AccountForm';
import { createSessionNonce } from './session-nonce';
import { StorefrontLogo } from '@/components/StorefrontLogo';
import { useAuthMe } from '@/hooks/use-auth-me';
import { safeReturnPath } from '@/lib/orders/safe-return-path';

/**
 * Recovery token from the URL. SMS links carry the raw secret in the
 * fragment (#token=...) so it never leaves the browser via HTTP, logs, or
 * Referer; mailed links still use the query string. Fragment wins, query is
 * the backward-compatible fallback.
 */
function resetTokenFromUrl(params: ReturnType<typeof useSearchParams>): string {
  if (typeof window !== 'undefined') {
    const hashToken = new URLSearchParams(window.location.hash.replace(/^#/, '')).get('token');
    if (hashToken) return hashToken;
  }
  return params.get('token') ?? '';
}

export const LoginScreen: React.FC<{ initialView?: AuthView }> = ({ initialView = 'login' }) => {
  const { branding } = useBranding();
  const [view, setView] = useState<AuthView>(initialView);
  const formRef = useRef<HTMLDivElement>(null);
  const router = useRouter();
  const params = useSearchParams();
  const [recoveryToken, setRecoveryToken] = useState('');
  useEffect(() => {
    if (view !== 'reset-password') return;
    const readToken = () => setRecoveryToken(resetTokenFromUrl(params));
    readToken();
    window.addEventListener('hashchange', readToken);
    return () => window.removeEventListener('hashchange', readToken);
  }, [view, params]);
  const [lang, setLang] = useState<'en' | 'ar'>('ar');
  const [showPassword, setShowPassword] = useState(false);

  // Form states
  const [login, setLogin] = useState('');
  const [password, setPassword] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [navigating, setNavigating] = useState(false);
  const hasRecoveryToken = view === 'reset-password' && Boolean(recoveryToken || resetTokenFromUrl(params));
  const guardSession = !hasRecoveryToken;
  const [probing, setProbing] = useState(guardSession);
  const [isReady, setIsReady] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState('');
  const [challengeRequired, setChallengeRequired] = useState(false);
  const [siteKey, setSiteKey] = useState<string | null>(null);
  const [siteKeyError, setSiteKeyError] = useState(false);
  const [turnstileToken, setTurnstileToken] = useState('');

  const loadTurnstileOptions = () => {
    setSiteKeyError(false);
    getRegistrationOptions()
      .then((options) => {
        if (options.turnstile_site_key) {
          setSiteKey(options.turnstile_site_key);
        } else {
          setSiteKey(null);
          setSiteKeyError(true);
        }
      })
      .catch(() => {
        setSiteKey(null);
        setSiteKeyError(true);
      });
  };
  // Bumped whenever the solved widget becomes stale so HumanVerification
  // remounts with a fresh single-use Turnstile token.
  const [challengeAttempt, setChallengeAttempt] = useState(0);

  useEffect(() => {
    setIsReady(true);
    formRef.current?.querySelector('h1')?.focus({ preventScroll: true });
    setErrors({});
    setFormError('');
  }, [view]);

  const token = useAuthStore((state) => state.token);
  const checkingWeb = guardSession && Boolean(token) && !isSubmitting && !navigating;
  const me = useAuthMe(token, checkingWeb);

  useEffect(() => {
    if (!guardSession || token) {
      setProbing(false);
      return;
    }
    setProbing(true);
    const complete = () => setProbing(false);
    const configuredOffice = process.env.NEXT_PUBLIC_BACKOFFICE_URL?.trim() ?? '';
    let office: URL;
    try {
      office = new URL(resolveServiceUrl(configuredOffice || 'http://localhost:5173'));
    } catch {
      complete();
      return;
    }
    const pageLoopback = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1' || window.location.hostname === '::1';
    const officeLoopback = office.hostname === 'localhost' || office.hostname === '127.0.0.1' || office.hostname === '::1';
    if (process.env.NODE_ENV === 'production' && !pageLoopback && (!configuredOffice || officeLoopback)) return complete();
    const nonce = createSessionNonce();
    if (!nonce) return complete();
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none;border:0';
    frame.setAttribute('aria-hidden', 'true');
    frame.tabIndex = -1;
    frame.title = 'Session verification';
    frame.referrerPolicy = 'origin';
    frame.src = new URL(`/auth/session-probe.html#nonce=${encodeURIComponent(nonce)}`, office).href;
    let cleaned = false;
    const cleanup = () => {
      if (cleaned) return;
      cleaned = true;
      window.removeEventListener('message', receive);
      frame.remove();
    };
    const receive = (event: MessageEvent) => {
      if (
        event.origin !== office.origin ||
        event.source !== frame.contentWindow ||
        event.data?.type !== 'alfarid:staff-session' ||
        event.data?.nonce !== nonce
      )
        return;
      window.clearTimeout(timer);
      cleanup();
      const path = event.data.authenticated === true ? event.data.path : null;
      if (path === '/dashboard' || path === '/pos') {
        window.location.replace(new URL(path, office).href);
      } else complete();
    };
    const timer = window.setTimeout(() => {
      cleanup();
      complete();
    }, 5000);
    window.addEventListener('message', receive);
    document.body.appendChild(frame);
    return () => {
      window.clearTimeout(timer);
      cleanup();
    };
  }, [guardSession, token]);

  useEffect(() => {
    if (!checkingWeb || useAuthStore.getState().token !== token) return;
    const status = me.error?.response?.status;
    if (status === 401 || status === 403) {
      useAuthStore.getState().clear();
      return;
    }
    const destination = me.data?.redirect?.destination;
    const home = safeReturnPath(me.data?.redirect?.path ?? null, ['/wholesale', '/supplier', '/account']);
    if (!home) return;
    const allowed = destination === 'wholesale' ? ['/wholesale'] : destination === 'supplier' ? ['/supplier'] : [];
    router.replace(safeReturnPath(params.get('redirect'), allowed) ?? home);
  }, [checkingWeb, me.data, me.error, params, router, token]);

  // Translations
  const t = {
    en: {
      signInTitle: 'Sign In',
      signInSub: `Sign in to your ${branding.name} account`,
      loginLabel: 'Email or mobile number',
      loginPlaceholder: 'Enter your email or mobile number',
      wholesaleRegister: 'Register as a wholesale trader',
      handoffExpired: 'The login link has expired, please sign in again.',
      passwordLabel: 'Password',
      passwordPlaceholder: 'Enter your password',
      forgotPassword: 'Forgot Password?',
      signInBtn: 'Sign In',
      createAccount: 'Create an Account',
    },
    ar: {
      signInTitle: 'تسجيل الدخول',
      signInSub: `سجّل الدخول إلى حسابك في ${branding.name}`,
      loginLabel: 'البريد الإلكتروني أو رقم الموبايل',
      loginPlaceholder: 'أدخل بريدك الإلكتروني أو رقم موبايلك',
      wholesaleRegister: 'تسجيل كتاجر جملة',
      handoffExpired: 'انتهت صلاحية رابط الدخول، سجّل الدخول مرة أخرى',
      passwordLabel: 'كلمة المرور',
      passwordPlaceholder: 'أدخل كلمة المرور',
      forgotPassword: 'نسيت كلمة المرور؟',
      signInBtn: 'تسجيل الدخول',
      createAccount: 'إنشاء حساب جديد',
    },
  }[lang];

  // Login handler
  const handleSignIn = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isReady || isSubmitting) return;
    setIsSubmitting(true);
    setErrors({});
    setFormError('');
    try {
      const path = await loginUnified(login, password, params.get('redirect'), turnstileToken || undefined, lang);
      setNavigating(true);
      if (path) {
        sessionStorage.setItem('alfarid_login_notice', lang);
        router.replace(path);
      }
    } catch (err: any) {
      const status = err?.response?.status;
      const code = err?.response?.data?.code;
      const ar = lang === 'ar';
      if (challengeRequired) {
        // Turnstile tokens are single-use and the server consumes them
        // before checking credentials: any failed attempt invalidates the
        // solved widget, so reset it and force a fresh challenge.
        setTurnstileToken('');
        setChallengeAttempt((v) => v + 1);
      }
      if (status === 422) {
        const fields = err.response.data.errors ?? {};
        setErrors(Object.fromEntries(Object.keys(fields).map((field) => [
          field, [ar ? 'راجع القيمة المدخلة.' : 'Check the value you entered.'],
        ])));
        setFormError(ar ? 'راجع بيانات الدخول وحاول مرة أخرى.' : 'Check your sign-in details and try again.');
      }
      else if (status === 401) setFormError(lang === 'ar' ? 'بيانات الدخول غير صحيحة.' : 'Invalid credentials.');
      else if (status === 403) {
        if (code === 'pos_branch_unavailable') setFormError(ar ? 'حسابك غير مرتبط بفرع نشط.' : 'Your account is not assigned to an active branch.');
        else if (code === 'phone_verification_required') setFormError(ar ? 'وثّق رقم الهاتف أولًا.' : 'Verify your phone number first.');
        else if (code === 'account_inactive') setFormError(ar ? 'الحساب موقوف أو غير نشط.' : 'This account is suspended or inactive.');
        else setFormError(ar ? 'تعذر السماح بالدخول لهذا الحساب.' : 'This account cannot sign in.');
      }
      else if (status === 429 && err?.response?.data?.code === 'challenge_required') {
        setChallengeRequired(true);
        setTurnstileToken('');
        setFormError(lang === 'ar' ? 'أكمل التحقق البشري ثم سجّل الدخول مرة أخرى.' : 'Complete human verification, then sign in again.');
        loadTurnstileOptions();
      }
      else if (status === 429) {
        const s = err.response.headers['retry-after'] ?? 60;
        setFormError(lang === 'ar' ? `محاولات كثيرة، حاول بعد ${s} ثانية.` : `Too many attempts, retry in ${s}s.`);
      } else setFormError(lang === 'ar' ? 'تعذر الاتصال بالخادم.' : 'Server unreachable.');
    } finally {
      setIsSubmitting(false);
    }
  };

  if (guardSession && (checkingWeb || probing || navigating)) {
    return (
      <div className="luxury-auth-page" dir={lang === 'ar' ? 'rtl' : 'ltr'} data-auth-view={view}>
        <main className="luxury-auth-loading">
          {me.isError && checkingWeb ? (
            <div className="luxury-auth-loading-actions">
              <button type="button" className="pill-btn-primary" onClick={() => void me.refetch()}>
                {lang === 'ar' ? 'إعادة المحاولة' : 'Try again'}
              </button>
            </div>
          ) : (
            <div role="status" aria-label={lang === 'ar' ? 'جارٍ التحميل' : 'Loading'}>
              <span className="luxury-auth-spinner" aria-hidden="true" />
            </div>
          )}
        </main>
      </div>
    );
  }

  return (
    <div className="luxury-auth-page" dir="ltr" data-auth-view={view}>
      <main className="luxury-auth-main">
        <div className="auth-card">
          {/* ================= LEFT COLUMN: AUTHENTICATION ================= */}
          <section
            id="leftColumn"
            dir={lang === 'ar' ? 'rtl' : 'ltr'}
            className="luxury-left-col"
          >
            {/* Top Brand Mark + Language Toggle */}
            <div className="luxury-top-bar">
              <button
                type="button"
                className="luxury-brand-btn"
                title={branding.name}
                disabled={!isReady || isSubmitting}
                onClick={() => setView('login')}
              >
                <StorefrontLogo className="w-[100px] h-10" />
                <span className="luxury-brand-tag">
                  {branding.name}
                </span>
              </button>

              <button
                type="button"
                onClick={() => { setLang(lang === 'en' ? 'ar' : 'en'); setErrors({}); setFormError(''); }}
                className="luxury-lang-btn"
                disabled={!isReady || isSubmitting}
                lang={lang === 'en' ? 'ar' : 'en'}
              >
                {lang === 'en' ? 'العربية' : 'English'}
              </button>
            </div>

            {/* SIGN IN FORM */}
            <div className="luxury-form-wrap" ref={formRef}>
              {view === 'login' ? (
                <>
                  <h1 className="luxury-title" tabIndex={-1}>
                    {t.signInTitle}
                  </h1>
                  <p className="luxury-sub">
                    {t.signInSub}
                  </p>

                  {params.get('error') === 'branch' && (
                    <p className="luxury-form-error" role="alert">
                      {lang === 'ar' ? 'تم تسجيل خروجك لأن حسابك غير مرتبط بفرع نشط.' : 'You were signed out because your account has no active branch.'}
                    </p>
                  )}
                  {params.get('error') === 'handoff' && (
                    <p className="luxury-form-error" role="alert">
                      {t.handoffExpired}
                    </p>
                  )}

                  {/* Form */}
                  <form method="post" onSubmit={handleSignIn} className="luxury-form">
                    <div>
                      <label className="luxury-field-label" htmlFor="login">
                        {t.loginLabel}
                        <span className="luxury-req-mark" aria-hidden="true">*</span>
                      </label>
                      <input
                        id="login"
                        name="login"
                        type="text"
                        inputMode="email"
                        autoComplete="username"
                        enterKeyHint="next"
                        required
                        value={login}
                        onChange={(e) => setLogin(e.target.value)}
                        placeholder={t.loginPlaceholder}
                        className="pill-input"
                        dir="ltr"
                        aria-invalid={!!errors.login?.[0]}
                        aria-describedby={errors.login?.[0] ? 'login-error' : undefined}
                      />
                      {errors.login?.[0] && (
                        <p id="login-error" className="luxury-field-error" role="alert">
                          {errors.login[0]}
                        </p>
                      )}
                    </div>

                    <div>
                      <label className="luxury-field-label" htmlFor="password">
                        {t.passwordLabel}
                        <span className="luxury-req-mark" aria-hidden="true">*</span>
                      </label>
                      <div className="luxury-pwd-wrap">
                        <input
                          id="password"
                          name="password"
                          type={showPassword ? 'text' : 'password'}
                          autoComplete="current-password"
                          enterKeyHint="done"
                          required
                          value={password}
                          onChange={(e) => setPassword(e.target.value)}
                          placeholder={t.passwordPlaceholder}
                          className="pill-input pr-pwd"
                          aria-invalid={!!errors.password?.[0]}
                          aria-describedby={errors.password?.[0] ? 'password-error' : undefined}
                        />
                        <button
                          type="button"
                          onClick={() => setShowPassword(!showPassword)}
                          className="luxury-eye-btn"
                          aria-label={
                            lang === 'ar'
                              ? showPassword
                                ? 'إخفاء كلمة المرور'
                                : 'إظهار كلمة المرور'
                              : showPassword
                                ? 'Hide password'
                                : 'Show password'
                          }
                          aria-pressed={showPassword}
                        >
                          <svg
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="1.8"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                          >
                            {showPassword ? (
                              <>
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
                                <line x1="1" y1="1" x2="23" y2="23" />
                              </>
                            ) : (
                              <>
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                <circle cx="12" cy="12" r="3" />
                              </>
                            )}
                          </svg>
                        </button>
                      </div>
                      {errors.password?.[0] && (
                        <p id="password-error" className="luxury-field-error" role="alert">
                          {errors.password[0]}
                        </p>
                      )}
                    </div>

                    {formError && (
                      <p className="luxury-form-error" role="alert">
                        {formError}
                      </p>
                    )}

                    {challengeRequired && siteKey && (
                      <HumanVerification key={challengeAttempt} siteKey={siteKey} lang={lang} onChange={setTurnstileToken} />
                    )}
                    {challengeRequired && siteKeyError && !siteKey && (
                      <div className="luxury-form-error" role="alert">
                        <span>{lang === 'ar' ? 'تعذر تحميل التحقق البشري.' : 'Could not load human verification.'}</span>{' '}
                        <button type="button" onClick={loadTurnstileOptions} className="luxury-switch-link">
                          {lang === 'ar' ? 'إعادة المحاولة' : 'Retry'}
                        </button>
                      </div>
                    )}

                    <div style={{ paddingTop: '4px' }}>
                      <button
                        type="submit"
                        disabled={!isReady || isSubmitting || (challengeRequired && !turnstileToken)}
                        className="pill-btn-primary"
                      >
                        {isSubmitting ? (
                          <span>{lang === 'ar' ? 'جاري التحقق...' : 'Signing in...'}</span>
                        ) : (
                          <span>{t.signInBtn}</span>
                        )}
                      </button>
                    </div>
                  </form>

                  {/* Bottom links in Thumb Reach Zone */}
                  <div className="luxury-bottom-switch">
                    <button
                      type="button"
                      disabled={!isReady || isSubmitting}
                      onClick={() => setView('forgot-password')}
                      className="luxury-switch-link"
                    >
                      {t.forgotPassword}
                    </button>
                    <span className="luxury-link-sep">·</span>
                    <button
                      type="button"
                      disabled={!isReady || isSubmitting}
                      onClick={() => setView('register')}
                      className="luxury-switch-link"
                    >
                      {t.createAccount}
                    </button>
                    <span className="luxury-link-sep">·</span>
                    <button
                      type="button"
                      disabled={!isReady || isSubmitting}
                      onClick={() => setView('wholesale-register')}
                      className="luxury-switch-link"
                    >
                      {t.wholesaleRegister}
                    </button>
                  </div>
                </>
              ) : (
                <AccountForm
                  key={view}
                  view={view}
                  lang={lang}
                  onChangeView={setView}
                  initialIdentifier={params.get('email') ?? params.get('phone') ?? login}
                  initialToken={recoveryToken}
                />
              )}
            </div>
          </section>

          {/* ================= RIGHT COLUMN: LUXURY HANDBAG HALFTONE ARTWORK ================= */}
          <section
            className="luxury-right-col"
            aria-label={lang === 'ar' ? 'حقيبة بيركن بتقنية الهالفتون' : 'Birkin Halftone artwork'}
          >
            <AuthHalftone />
          </section>
        </div>
      </main>
    </div>
  );
};

export default LoginScreen;
