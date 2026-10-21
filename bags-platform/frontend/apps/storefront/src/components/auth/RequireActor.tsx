'use client';
import { useEffect, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { useAuthStore } from '@/store/auth.store';
import type { Destination } from '@/types/auth';

export function RequireActor({ allow, children }: { allow: Destination[]; children: React.ReactNode }) {
  const { token, destination, home } = useAuthStore();
  const router = useRouter(); const pathname = usePathname();
  const [hydrated, setHydrated] = useState(false);
  useEffect(() => setHydrated(true), []);
  useEffect(() => {
    if (!hydrated) return;
    if (!token) router.replace(`/auth/login?redirect=${encodeURIComponent(pathname)}`);
    else if (!destination || !allow.includes(destination)) router.replace(home ?? '/');
  }, [hydrated, token, destination, home, allow, pathname, router]);
  if (!hydrated || !token || !destination || !allow.includes(destination)) return null;
  return <>{children}</>;
}
