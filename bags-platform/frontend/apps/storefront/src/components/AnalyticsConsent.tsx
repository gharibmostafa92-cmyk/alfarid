'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useCspNonce } from '@/providers/csp-nonce';
import { analyticsConfigured, analyticsConsent, hasAnalyticsChoice, initializeAnalytics, setAnalyticsConsent } from '@/lib/analytics/ecommerce';
import { useStoreLang } from '@/providers/store-lang-provider';

export function AnalyticsConsent() {
  const [show, setShow] = useState(false);
  const nonce = useCspNonce();
  const { isAr } = useStoreLang();
  useEffect(() => {
    if (!analyticsConfigured) return;
    setShow(!hasAnalyticsChoice());
    const open = () => setShow(true);
    window.addEventListener('open-analytics-settings', open);
    if (analyticsConsent()) initializeAnalytics(nonce);
    return () => window.removeEventListener('open-analytics-settings', open);
  }, [nonce]);
  if (!show || !analyticsConfigured) return null;
  return <div role="dialog" aria-label={isAr ? 'اختيارات التحليلات' : 'Analytics choices'} className="fixed bottom-4 inset-x-4 z-[9999] mx-auto max-w-xl rounded-xl border border-neutral-300 bg-white p-5 text-neutral-900 shadow-lg" dir={isAr ? 'rtl' : 'ltr'}>
    <p>{isAr ? 'نستخدم Google Analytics اختياريًا لقياس استخدام المتجر والمشتريات. تقدر تغيّر اختيارك في أي وقت.' : 'We optionally use Google Analytics to measure store usage and purchases. You can change your choice at any time.'}</p>
    <p><Link href="/legal/cookies">{isAr ? 'سياسة ملفات الارتباط' : 'Cookie policy'}</Link></p>
    <div className="mt-3 flex flex-wrap gap-3">
      <button type="button" className="rounded bg-black px-4 py-2 text-white" onClick={() => setAnalyticsConsent(true)}>{isAr ? 'الموافقة على التحليلات' : 'Allow analytics'}</button>
      <button type="button" className="rounded border border-neutral-600 px-4 py-2" onClick={() => setAnalyticsConsent(false)}>{isAr ? 'رفض التحليلات' : 'Decline analytics'}</button>
    </div>
  </div>;
}
