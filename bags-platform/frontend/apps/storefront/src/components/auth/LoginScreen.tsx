'use client';
import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import '../../styles/luxuryAuth.css';
import { BIRKIN_IMG_DATA } from '../../assets/birkinHalftone';
import { useAuthStore } from '@/store/auth.store';
import { loginUnified } from '@/services/auth.service';
import { AccountForm, type AuthView } from './AccountForm';

export const LoginScreen: React.FC<{ initialView?: AuthView }> = ({ initialView = 'login' }) => {
  const [view, setView] = useState<AuthView>(initialView);
  const formRef = useRef<HTMLDivElement>(null);
  const router = useRouter();
  const params = useSearchParams();
  const [lang, setLang] = useState<'en' | 'ar'>('ar');
  const gridStep = 7;
  const [showPassword, setShowPassword] = useState(false);

  // Form states
  const [login, setLogin] = useState('');
  const [password, setPassword] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isReady, setIsReady] = useState(false);
  const [staffCheckPending, setStaffCheckPending] = useState(initialView === 'login');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState('');

  useEffect(() => {
    setIsReady(true);
    formRef.current?.querySelector('h1')?.focus({ preventScroll: true });
    setErrors({}); setFormError('');
  }, [view]);

  const home = useAuthStore((s) => s.home);
  const token = useAuthStore((s) => s.token);

  useEffect(() => {
    if (view !== 'login' || token) { setStaffCheckPending(false); return; }
    const office = new URL(process.env.NEXT_PUBLIC_BACKOFFICE_URL ?? 'http://localhost:5173');
    const nonce = crypto.randomUUID();
    const frame = document.createElement('iframe');
    frame.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none;border:0';
    frame.setAttribute('aria-hidden', 'true');
    frame.tabIndex = -1;
    frame.title = 'Session verification';
    frame.src = new URL(`/auth/session#nonce=${encodeURIComponent(nonce)}`, office).href;
    setStaffCheckPending(true);
    const receive = (event: MessageEvent) => {
      if (event.origin !== office.origin || event.source !== frame.contentWindow
        || event.data?.type !== 'alfarid:staff-session' || event.data?.nonce !== nonce) return;
      if (event.data.authenticated === true) window.location.replace(new URL('/dashboard', office).href);
      else setStaffCheckPending(false);
    };
    window.addEventListener('message', receive);
    document.body.appendChild(frame);
    const timer = window.setTimeout(() => setStaffCheckPending(false), 5000);
    return () => {
      window.clearTimeout(timer);
      window.removeEventListener('message', receive);
      frame.remove();
    };
  }, [view, token]);

  // لو المستخدم داخل بالفعل، روح لصفحته الرئيسية
  useEffect(() => {
    if (token && home) router.replace(home);
  }, [token, home, router]);

  // Canvas refs
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const birkinPixelsRef = useRef<Uint8ClampedArray | null>(null);
  const renderCanvasRef = useRef<() => void>(() => {});
  const mousePosRef = useRef({ x: -1000, y: -1000, isHovering: false });

  const W = 560;
  const H = 640;


  // Translations
  const t = {
    en: {
      signInTitle: 'Sign In',
      signInSub: 'Sign in to your Alfarid account',
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
      signInSub: 'سجّل الدخول إلى حسابك في الفريد',
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

  // Canvas drawing routine
  const renderCanvas = useCallback(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, W, H);

    const activePixels = birkinPixelsRef.current;
    if (!activePixels) return;

    const step = gridStep;
    const half = step / 2;
    const { x: mx, y: my, isHovering: isHover } = mousePosRef.current;

    ctx.fillStyle = '#09090b';

    for (let y = Math.floor(half); y < H; y += step) {
      for (let x = Math.floor(half); x < W; x += step) {
        const idx = (y * W + x) * 4;
        const luma = activePixels[idx] / 255.0;
        const dark = 1.0 - luma;
        if (dark < 0.06) continue;

        let drawX = x;
        let drawY = y;
        let scaleBoost = 1.0;

        if (isHover) {
          const dx = x - mx;
          const dy = y - my;
          const dist = Math.sqrt(dx * dx + dy * dy);
          if (dist < 110) {
            const factor = 1.0 - dist / 110.0;
            drawX += (dx / (dist + 1)) * factor * 2.2;
            drawY += (dy / (dist + 1)) * factor * 2.2;
            scaleBoost = 1.0 + factor * 0.16;
          }
        }

        const r = Math.pow(dark, 0.92) * (step * 0.68) * scaleBoost;
        if (r < 0.45) continue;

        // Diamond dot
        ctx.beginPath();
        ctx.moveTo(drawX, drawY - r);
        ctx.lineTo(drawX + r, drawY);
        ctx.lineTo(drawX, drawY + r);
        ctx.lineTo(drawX - r, drawY);
        ctx.closePath();
        ctx.fill();

        // White micro center dot
        if (dark > 0.76 && r > 2.4) {
          const ir = (dark - 0.74) * (step * 0.3);
          if (ir >= 0.7) {
            ctx.save();
            ctx.fillStyle = '#ffffff';
            ctx.beginPath();
            ctx.arc(drawX, drawY, ir, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();
          }
        }
      }
    }

  }, []);

  useEffect(() => {
    renderCanvasRef.current = renderCanvas;
    renderCanvas();
  }, [renderCanvas]);

  // Extract pixel buffers on mount
  useEffect(() => {
    const offCanvas = document.createElement('canvas');
    offCanvas.width = W;
    offCanvas.height = H;
    const offCtx = offCanvas.getContext('2d', { willReadFrequently: true });
    if (!offCtx) return;

    const extract = (img: HTMLImageElement) => {
      offCtx.fillStyle = '#ffffff';
      offCtx.fillRect(0, 0, W, H);
      offCtx.drawImage(img, 0, 0, W, H);
      return offCtx.getImageData(0, 0, W, H).data;
    };

    const bImg = new Image();
    bImg.src = BIRKIN_IMG_DATA;
    bImg.onload = () => {
      birkinPixelsRef.current = extract(bImg);
      renderCanvasRef.current();
    };

    return () => { bImg.onload = null; };
  }, []);

  // Pointer tracking
  const handlePointerMove = (clientX: number, clientY: number) => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const rect = canvas.getBoundingClientRect();
    mousePosRef.current = {
      x: ((clientX - rect.left) / rect.width) * W,
      y: ((clientY - rect.top) / rect.height) * H,
      isHovering: true,
    };
    requestAnimationFrame(renderCanvas);
  };

  const handlePointerLeave = () => {
    mousePosRef.current.isHovering = false;
    requestAnimationFrame(renderCanvas);
  };

  // Login handler
  const handleSignIn = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isReady || staffCheckPending || isSubmitting) return;
    setIsSubmitting(true); setErrors({}); setFormError('');
    try {
      const path = await loginUnified(login, password, params.get('redirect'));
      if (path) router.replace(path);
    } catch (err: any) {
      const status = err?.response?.status;
      const msg = err?.response?.data?.message;
      if (status === 422) setErrors(err.response.data.errors ?? {});
      else if (status === 401) setFormError(lang === 'ar' ? 'بيانات الدخول غير صحيحة.' : 'Invalid credentials.');
      else if (status === 403) setFormError(msg ?? 'غير مسموح.');
      else if (status === 429) {
        const s = err.response.headers['retry-after'] ?? 60;
        setFormError(lang === 'ar' ? `محاولات كثيرة، حاول بعد ${s} ثانية.` : `Too many attempts, retry in ${s}s.`);
      } else setFormError(lang === 'ar' ? 'تعذر الاتصال بالخادم.' : 'Server unreachable.');
    } finally {
      setIsSubmitting(false);
    }
  };

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
                title="Alfarid — Luxury Handbags"
              >
                <svg
                  width="22"
                  height="22"
                  viewBox="0 0 24 24"
                  fill="none"
                  xmlns="http://www.w3.org/2000/svg"
                >
                  <polygon points="4,10 10,4 10,13 4,19" fill="#09090b" />
                  <polygon points="11,11 17,5 17,14 11,20" fill="#09090b" />
                </svg>
                <span className="luxury-brand-tag">
                  ALFARID · Atelier
                </span>
              </button>

              <button
                type="button"
                onClick={() => setLang(lang === 'en' ? 'ar' : 'en')}
                className="luxury-lang-btn"
              >
                {lang === 'en' ? 'العربية' : 'English'}
              </button>
            </div>

            {/* SIGN IN FORM */}
              <div className="luxury-form-wrap" ref={formRef}>
                {view === 'login' ? <>
                <h1 className="luxury-title" tabIndex={-1}>
                  {t.signInTitle}
                </h1>
                <p className="luxury-sub">
                  {t.signInSub}
                </p>

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
                    </label>
                    <input
                      id="login"
                      name="login"
                      type="text"
                      inputMode="email"
                      autoComplete="username"
                      required
                      value={login}
                      onChange={(e) => setLogin(e.target.value)}
                      placeholder={t.loginPlaceholder}
                      className="pill-input"
                      dir="ltr"
                    />
                    {errors.login?.[0] && (
                      <p className="luxury-field-error" role="alert">{errors.login[0]}</p>
                    )}
                  </div>

                  <div>
                    <label className="luxury-field-label" htmlFor="password">
                      {t.passwordLabel}
                    </label>
                    <div className="luxury-pwd-wrap">
                      <input
                        id="password"
                        name="password"
                        type={showPassword ? 'text' : 'password'}
                        autoComplete="current-password"
                        required
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        placeholder={t.passwordPlaceholder}
                        className="pill-input pr-pwd"
                      />
                      <button
                        type="button"
                        onClick={() => setShowPassword(!showPassword)}
                        className="luxury-eye-btn"
                        aria-label={lang === 'ar' ? (showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور') : (showPassword ? 'Hide password' : 'Show password')}
                        aria-pressed={showPassword}
                      >
                        <svg
                          width="14"
                          height="14"
                          viewBox="0 0 24 24"
                          fill="none"
                          stroke="currentColor"
                          strokeWidth="1.8"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                        >
                          <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                          <circle cx="12" cy="12" r="3" />
                        </svg>
                      </button>
                    </div>
                    {errors.password?.[0] && (
                      <p className="luxury-field-error" role="alert">{errors.password[0]}</p>
                    )}
                  </div>

                  {formError && (
                    <p className="luxury-form-error" role="alert">{formError}</p>
                  )}

                  <div style={{ paddingTop: '4px' }}>
                    <button
                      type="submit"
                      disabled={!isReady || staffCheckPending || isSubmitting}
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

                {/* Bottom links */}
                <div className="luxury-bottom-switch">
                  <button type="button" disabled={isSubmitting} onClick={() => setView('forgot-password')} className="luxury-switch-link">
                    {t.forgotPassword}
                  </button>
                  <span className="luxury-link-sep">·</span>
                  <button type="button" disabled={isSubmitting} onClick={() => setView('register')} className="luxury-switch-link">
                    {t.createAccount}
                  </button>
                  <span className="luxury-link-sep">·</span>
                  <button type="button" disabled={isSubmitting} onClick={() => setView('wholesale-register')} className="luxury-switch-link">
                    {t.wholesaleRegister}
                  </button>
                </div>
                </> : <AccountForm key={view} view={view} lang={lang} onChangeView={setView}
                  initialIdentifier={params.get('email') ?? params.get('phone') ?? login}
                  initialToken={params.get('token') ?? ''} />}
              </div>

          </section>

          {/* ================= RIGHT COLUMN: LUXURY HANDBAG HALFTONE ARTWORK ================= */}
          <section className="luxury-right-col" aria-label={lang === 'ar' ? 'حقيبة بيركن بتقنية الهالفتون' : 'Birkin Halftone artwork'}>
            <canvas
              ref={canvasRef}
              width={W}
              height={H}
              aria-hidden="true"
              onMouseMove={(e) => handlePointerMove(e.clientX, e.clientY)}
              onMouseLeave={handlePointerLeave}
              onTouchMove={(e) => {
                if (e.touches && e.touches[0]) {
                  handlePointerMove(e.touches[0].clientX, e.touches[0].clientY);
                }
              }}
            />

          </section>
        </div>
      </main>

    </div>
  );
};

export default LoginScreen;
