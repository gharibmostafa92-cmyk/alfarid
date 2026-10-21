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
  useEffect(() => setHydrated(true), []);
  // M2: الوجهة المحفوظة لحظة الدخول قد تقدم (اعتماد/إيقاف) — أعد التحقق
  // من الجلسة عند الـ hydration وحدّث الوجهة من redirect الحالي.
  useEffect(() => {
    if (!hydrated || !token) return;
    let cancelled = false;
    authEndpoints.me().then((res: {
      actor?: { type?: string }; redirect?: { destination?: Destination; path?: string };
      data?: Record<string, unknown>;
    }) => {
      if (cancelled) return;
      const dest = res?.redirect?.destination;
      const path = res?.redirect?.path;
      if (typeof dest === 'string' && typeof path === 'string') {
        useAuthStore.getState().setSession({
          token, actorType: (res?.actor?.type ?? 'customer') as 'customer' | 'supplier',
          destination: dest, home: path, user: (res?.data ?? {}) as Record<string, unknown>,
        });
      }
    }).catch(() => { /* الـ 401 يمسحها الـ handler العام */ });
    return () => { cancelled = true; };
  }, [hydrated, token]);
  useEffect(() => {
    if (!hydrated) return;
    if (!token) router.replace(`/auth/login?redirect=${encodeURIComponent(pathname)}`);
    else if (!destination || !allow.includes(destination)) router.replace(home ?? '/');
  }, [hydrated, token, destination, home, allow, pathname, router]);
  if (!hydrated || !token || !destination || !allow.includes(destination)) return null;
  return <>{children}</>;
}
