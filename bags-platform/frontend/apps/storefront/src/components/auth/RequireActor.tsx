'use client';
import { useEffect, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { authEndpoints } from '@alfarid/api-client';
import { useAuthStore } from '@/store/auth.store';
import type { Destination } from '@/types/auth';

export function RequireActor({ allow, children }: { allow: Destination[]; children: React.ReactNode }) {
  const { token, destination, home } = useAuthStore();
  const router = useRouter(); const pathname = usePathname();
  const [hydrated, setHydrated] = useState(false);
  const [verifiedToken, setVerifiedToken] = useState<string | null>(null);
  const [verificationFailed, setVerificationFailed] = useState(false);
  const [retry, setRetry] = useState(0);
  useEffect(() => setHydrated(true), []);
  // M2: الوجهة المحفوظة لحظة الدخول قد تقدم (اعتماد/إيقاف) — أعد التحقق
  // من الجلسة عند الـ hydration وحدّث الوجهة من redirect الحالي.
  useEffect(() => {
    if (!hydrated || !token) return;
    let cancelled = false;
    setVerifiedToken(null);
    setVerificationFailed(false);
    authEndpoints.me().then((res: {
      actor?: { type?: string }; redirect?: { destination?: Destination; path?: string };
      data?: Record<string, unknown>;
    }) => {
      if (cancelled || useAuthStore.getState().token !== token) return;
      const dest = res?.redirect?.destination;
      const path = res?.redirect?.path;
      if (typeof dest === 'string' && typeof path === 'string') {
        useAuthStore.getState().setSession({
          token, actorType: (res?.actor?.type ?? 'customer') as 'customer' | 'supplier',
          destination: dest, home: path, user: (res?.data ?? {}) as Record<string, unknown>,
        });
        setVerifiedToken(token);
      } else {
        setVerificationFailed(true);
      }
    }).catch((error: { response?: { status?: number } }) => {
      if (cancelled || useAuthStore.getState().token !== token) return;
      // L1: الـ 403 (موقوف/معطّل) لا يمسحها handler الـ 401 العام — امسح
      // الجلسة المحلية القديمة وحوّل للدخول بدل حبس المستخدم في بوابة ميتة.
      if (error?.response?.status === 401 || error?.response?.status === 403) {
        useAuthStore.getState().clear();
        router.replace(`/auth/login?redirect=${encodeURIComponent(pathname)}`);
      } else {
        setVerificationFailed(true);
      }
      /* الـ 401 يمسحها الـ handler العام */
    });
    return () => { cancelled = true; };
  }, [hydrated, token, pathname, router, retry]);
  useEffect(() => {
    if (!hydrated) return;
    if (!token) router.replace(`/auth/login?redirect=${encodeURIComponent(pathname)}`);
    else if (verifiedToken === token && (!destination || !allow.includes(destination))) router.replace(home ?? '/');
  }, [hydrated, token, verifiedToken, destination, home, allow, pathname, router]);
  if (hydrated && token && verificationFailed) return <div role="alert">تعذر التحقق من الجلسة. <button type="button" onClick={() => setRetry((value) => value + 1)}>إعادة المحاولة</button></div>;
  if (!hydrated || !token || verifiedToken !== token || !destination || !allow.includes(destination)) return null;
  return <>{children}</>;
}
