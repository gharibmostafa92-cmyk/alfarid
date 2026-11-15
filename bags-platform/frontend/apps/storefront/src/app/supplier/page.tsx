'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { supplierPortalApi, type SupplierDashboard } from '@alfarid/api-client';
import { formatStorePrice } from '@alfarid/utils';

export default function SupplierDashboardPage() {
  const [data, setData] = useState<SupplierDashboard | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    supplierPortalApi.dashboard(controller.signal)
      .then(setData)
      .catch(() => { if (!controller.signal.aborted) setError('تعذر تحميل بيانات لوحة التحكم.'); });
    return () => controller.abort();
  }, []);

  return (
    <main id="main-content" tabIndex={-1} className="mx-auto max-w-5xl px-5 py-8">
      <h1 className="mb-6 text-2xl font-semibold">لوحة تحكم المورد</h1>
      {error ? <p role="alert" className="mb-4 text-red-700">{error}</p> : null}
      {!data && !error ? <p role="status">جارٍ التحميل…</p> : null}
      {data ? (
        <>
          <div className="grid gap-4 sm:grid-cols-3">
            <Link href="/supplier/purchase-orders?status=sent_to_supplier" className="rounded-xl bg-white p-5 shadow-sm">
              <p className="text-sm text-black/60">أوامر شراء جديدة</p>
              <p className="mt-2 text-2xl font-semibold">{data.new_orders_count}</p>
            </Link>
            <Link href="/supplier/purchase-orders" className="rounded-xl bg-white p-5 shadow-sm">
              <p className="text-sm text-black/60">أوامر قيد التنفيذ</p>
              <p className="mt-2 text-2xl font-semibold">{data.in_progress_count}</p>
            </Link>
            <Link href="/supplier/statement" className="rounded-xl bg-white p-5 shadow-sm">
              <p className="text-sm text-black/60">الرصيد المستحق</p>
              <p className="mt-2 text-2xl font-semibold">{formatStorePrice(data.current_balance)}</p>
            </Link>
          </div>
          <section className="mt-8 rounded-xl bg-white p-5 shadow-sm">
            <div className="mb-3 flex items-center justify-between">
              <h2 className="text-lg font-semibold">أحدث المدفوعات</h2>
              <Link href="/supplier/statement" className="text-sm underline">كشف الحساب</Link>
            </div>
            {data.last_payments.length === 0 ? <p className="text-sm text-black/60">لا توجد مدفوعات بعد.</p> : (
              <ul className="divide-y divide-black/10">
                {data.last_payments.map((payment) => (
                  <li key={payment.id} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                    <span>{payment.payment_number}{payment.invoice ? ` — ${payment.invoice.invoice_number}` : ''}</span>
                    <span>{payment.payment_date}</span>
                    <strong>{formatStorePrice(payment.amount)}</strong>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      ) : null}
    </main>
  );
}
