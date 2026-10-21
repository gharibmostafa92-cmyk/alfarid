'use client';
import { useEffect, useState } from 'react';
import { defaultApiClient } from '@alfarid/api-client';
import { FinancialDashboard } from '@alfarid/ui';
import { useAuthStore } from '@/store/auth.store';
import { logoutWeb } from '@/services/auth.service';
import { RequireActor } from '@/components/auth/RequireActor';

interface ApplicationStatus {
  status: string;
  rejection_reason: string | null;
}

function ApplicationStatusContent() {
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
    <div className="container mx-auto px-4 py-8">
      <h1 className="text-2xl font-bold">حالة طلب الاعتماد</h1>
      {error ? <div role="alert">
        <p>تعذر تحميل حالة الطلب. حاول مرة أخرى.</p>
        <button type="button" onClick={() => setAttempt((n) => n + 1)}>إعادة المحاولة</button>
      </div> : <>
        <p className="mt-2 text-sm" role="status">الحالة: {data?.status ?? 'جارٍ التحميل…'}</p>
        {data?.rejection_reason && <p className="mt-2 text-sm text-red-600">سبب الرفض: {data.rejection_reason}</p>}
      </>}
    </div>
  );
}

export default function ApplicationStatusPage() {
  const customerType = useAuthStore((state) => state.user?.type);
  return <RequireActor allow={['wholesale_pending', 'wholesale']}>
    {customerType === 'retail' ? <ApplicationStatusContent /> : <FinancialDashboard onLogout={logoutWeb} />}
  </RequireActor>;
}
