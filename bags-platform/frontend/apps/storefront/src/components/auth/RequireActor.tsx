'use client';
import { useEffect, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useAuthMe } from '@/hooks/use-auth-me';
import { useAuthStore } from '@/store/auth.store';
import { unauthorizedLoginRedirect } from '@/store/auth-redirect';
import type { Destination } from '@/types/auth';

export function RequireActor({ allow, children }: { allow: Destination[]; children: React.ReactNode }) {
  const { token, destination, home } = useAuthStore();
  const router = useRouter(); const pathname = usePathname();
  const [hydrated, setHydrated] = useState(false);
  const [verifiedToken, setVerifiedToken] = useState<string | null>(null);
  const [verificationFailed, setVerificationFailed] = useState(false);
  useEffect(() => setHydrated(true), []);
  const { data: meData, error: meError, isError, refetch } = useAuthMe(token, hydrated);
  // Re-verify the session via shared React Query cache to refresh redirect destination if approval or status changed.
  useEffect(() => {
    if (!hydrated || !token) return;
    setVerifiedToken(null);
    setVerificationFailed(false);
    if (isError) {
      if (useAuthStore.getState().token !== token) return;
      // Clear local session on 401/403 (e.g. suspended account) and redirect to login to avoid deadlocked navigation.
      // Uses the same redirect builder as the global 401 handler so both
      // navigation paths converge on one URL (query strings preserved).
      if (meError?.response?.status === 401 || meError?.response?.status === 403) {
        const search = typeof window === 'undefined' ? '' : window.location.search;
        const redirect = unauthorizedLoginRedirect(pathname, search, true);
        useAuthStore.getState().clear();
        if (redirect) router.replace(redirect);
      } else {
        setVerificationFailed(true);
      }
      return;
    }
    if (!meData || useAuthStore.getState().token !== token) return;
    const dest = meData.redirect?.destination;
    const path = meData.redirect?.path;
    if (typeof dest === 'string' && typeof path === 'string') {
      const nextActorType = (meData.actor?.type ?? 'customer') as 'customer' | 'supplier';
      const nextUser = (meData.data ?? {}) as Record<string, unknown>;
      const current = useAuthStore.getState();
      if (
        current.token !== token
        || current.actorType !== nextActorType
        || current.destination !== dest
        || current.home !== path
        || JSON.stringify(current.user) !== JSON.stringify(nextUser)
      ) {
        current.setSession({
          token, actorType: nextActorType,
          destination: dest, home: path, user: nextUser,
        });
      }
      setVerifiedToken(token);
    } else {
      setVerificationFailed(true);
    }
  }, [hydrated, token, meData, isError, meError, pathname, router]);
  const allowKey = allow.join(',');
  useEffect(() => {
    if (!hydrated) return;
    // Unauthenticated visits reuse the shared builder (query preserved,
    // public pages stay put) instead of a divergent hand-rolled URL.
    if (!token) {
      const search = typeof window === 'undefined' ? '' : window.location.search;
      const redirect = unauthorizedLoginRedirect(pathname, search, false);
      if (redirect) router.replace(redirect);
    } else if (verifiedToken === token && (!destination || !allow.includes(destination))) router.replace(home ?? '/');
  }, [hydrated, token, verifiedToken, destination, home, allowKey, pathname, router]);
  if (hydrated && token && verificationFailed) return <div role="alert">تعذر التحقق من الجلسة. <button type="button" onClick={() => { void refetch(); }}>إعادة المحاولة</button></div>;
  if (!hydrated || !token || verifiedToken !== token || !destination || !allow.includes(destination)) return null;
  return <>{children}</>;
}
