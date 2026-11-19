'use client';
import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { authEndpoints, defaultApiClient } from '@alfarid/api-client';
import { useBranding } from '@alfarid/ui/branding';
import { WholesalePortal } from '@alfarid/ui';
import { useAuthStore } from '@/store/auth.store';
import type { Destination } from '@/types/auth';
import { logoutWeb, requestPasswordReset, resetWebPassword } from '@/services/auth.service';
import { RequireActor } from '@/components/auth/RequireActor';

interface ApplicationStatus {
  status: string;
  rejection_reason: string | null;
}

const statusLabels: Record<string, string> = {
  pending: 'قيد المراجعة',
  rejected: 'مرفوض',
  approved: 'معتمد',
  suspended: 'موقوف',
};

function ApplicationStatusContent({ brandName }: { brandName: string }) {
  const [data, setData] = useState<ApplicationStatus | null>(null);
  const [error, setError] = useState(false);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    setError(false);
    defaultApiClient
      .get('/api/v1/wholesale/auth/application-status', { signal: controller.signal })
      .then((r) => setData(r.data.data))
      .catch(() => { if (!controller.signal.aborted) setError(true); });
    return () => controller.abort();
  }, [attempt]);

  return (
    <main dir="rtl" className="mx-auto max-w-xl px-4 py-8 sm:py-14">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 sm:p-8">
        <p className="mb-3 text-sm font-semibold text-slate-600" dir="auto">{brandName}</p>
        <h1 className="text-2xl font-bold">حالة طلب الاعتماد</h1>
        {error ? (
          <div role="alert">
            <p>تعذر تحميل حالة الطلب. حاول مرة أخرى.</p>
            <button type="button" onClick={() => setAttempt((n) => n + 1)}>إعادة المحاولة</button>
          </div>
        ) : (
          <>
            <p className="mt-3 text-sm" role="status">{data ? statusLabels[data.status] ?? 'الحالة غير متاحة' : 'جارٍ التحميل…'}</p>
            {data?.rejection_reason && <p className="mt-2 text-sm text-red-600">سبب الرفض: {data.rejection_reason}</p>}
          </>
        )}
      </section>
    </main>
  );
}

export default function ApplicationStatusPage() {
  const router = useRouter();
  const { branding } = useBranding();
  const destination = useAuthStore((state) => state.destination);
  const status = useAuthStore((state) => (state.user as { status?: string } | null)?.status);
  const isPendingOrRejected = destination === 'wholesale_pending' || status === 'pending' || status === 'rejected';
  const onEnter = async () => {
    const me = await authEndpoints.me() as {
      actor?: { type?: string; subtype?: Destination };
      data?: Record<string, unknown>;
      redirect?: { path?: string };
    };
    const current = useAuthStore.getState();
    const dest = me.actor?.subtype;
    const path = me.redirect?.path;
    if (!current.token || !dest || typeof path !== 'string') return;
    current.setSession({
      token: current.token,
      actorType: me.actor?.type === 'supplier' ? 'supplier' : 'customer',
      destination: dest,
      home: path,
      user: me.data ?? {},
    });
    router.replace('/wholesale');
  };
  return (
    <RequireActor allow={['wholesale_pending', 'wholesale']}>
        <a href="/security/password">تغيير كلمة المرور</a>
      {isPendingOrRejected ? (
        <ApplicationStatusContent brandName={branding.name} />
      ) : (
        <WholesalePortal brandName={branding.name}
          initialScreen="status"
          onLogout={logoutWeb}
          onEnter={onEnter}
          onForgotSubmit={requestPasswordReset}
          onResetSubmit={resetWebPassword}
        />
      )}
    </RequireActor>
  );
}
