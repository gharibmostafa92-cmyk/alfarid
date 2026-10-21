'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useAuthStore } from '@/store/auth.store';
import { logoutWeb } from '@/services/auth.service';

export function AccountMenu() {
  const token = useAuthStore((s) => s.token);
  const user = useAuthStore((s) => s.user);
  const home = useAuthStore((s) => s.home);
  const [hydrated, setHydrated] = useState(false);
  const [busy, setBusy] = useState(false);
  useEffect(() => setHydrated(true), []);

  if (!hydrated) return null;
  if (!token) return <Link href="/auth/login">تسجيل الدخول</Link>;
  return (
    <div className="flex items-center gap-4 text-sm">
      <Link href={home ?? '/'}>مرحبًا {String(user?.name ?? '')}</Link>
      <button type="button" disabled={busy} onClick={() => { setBusy(true); void logoutWeb(); }}>
        {busy ? 'جارٍ تسجيل الخروج…' : 'تسجيل الخروج'}
      </button>
    </div>
  );
}
