'use client';

import { useEffect, useRef, useState } from 'react';

type Turnstile = {
  render: (container: HTMLElement, options: Record<string, unknown>) => string;
  remove: (id: string) => void;
};
const api = () => (window as Window & { turnstile?: Turnstile }).turnstile;
let loading: Promise<void> | undefined;

function loadScript() {
  if (api()) return Promise.resolve();
  if (!loading) {
    loading = new Promise<void>((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
      script.async = true;
      script.nonce = document.querySelector<HTMLScriptElement>('script[nonce]')?.nonce ?? '';
      const fail = () => {
        clearTimeout(timer);
        script.remove();
        loading = undefined;
        reject(new Error('Human verification could not load.'));
      };
      const timer = setTimeout(fail, 15000);
      script.onerror = fail;
      script.onload = () => { clearTimeout(timer); api() ? resolve() : fail(); };
      document.head.appendChild(script);
    });
  }
  return loading;
}

export function HumanVerification({ siteKey, lang, onChange }: {
  siteKey: string; lang: 'ar' | 'en'; onChange: (token: string) => void;
}) {
  const container = useRef<HTMLDivElement>(null);
  const [attempt, setAttempt] = useState(0);
  const [failed, setFailed] = useState(false);
  useEffect(() => {
    let cancelled = false;
    let widget: string | undefined;
    const element = container.current;
    const invalidate = () => { if (!cancelled) { onChange(''); setFailed(true); } };
    onChange('');
    setFailed(false);
    loadScript().then(() => {
      if (cancelled || !element) return;
      widget = api()!.render(element, {
        sitekey: siteKey, language: lang, size: 'compact', 'response-field': false,
        callback: (token: string) => { if (!cancelled) { onChange(token); setFailed(false); } },
        'expired-callback': invalidate, 'error-callback': invalidate, 'timeout-callback': invalidate,
      });
    }).catch(invalidate);
    return () => {
      cancelled = true;
      if (widget !== undefined) api()?.remove(widget);
      element?.replaceChildren();
    };
  }, [siteKey, lang, attempt, onChange]);

  return <div>
    <div ref={container} aria-label={lang === 'ar' ? 'التحقق البشري' : 'Human verification'} />
    {failed && <div role="alert">
      <p className="luxury-field-error">{lang === 'ar' ? 'أعد التحقق البشري للمتابعة.' : 'Complete human verification again to continue.'}</p>
      <button type="button" className="luxury-switch-link" onClick={() => setAttempt(value => value + 1)}>
        {lang === 'ar' ? 'إعادة التحقق البشري' : 'Retry human verification'}
      </button>
    </div>}
  </div>;
}
