'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { supplierPortalApi, type SupplierPortalDelivery } from '@alfarid/api-client';

const statusLabels: Record<string, string> = {
  pending_receipt: 'بانتظار الاستلام',
  received: 'تم الاستلام',
  rejected: 'مرفوض',
  closed_short: 'مغلق بنقص',
};

export default function SupplierDeliveriesPage() {
  const [rows, setRows] = useState<SupplierPortalDelivery[] | null>(null);
  const [error, setError] = useState('');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);

  useEffect(() => {
    const controller = new AbortController();
    setRows(null);
    setError('');
    supplierPortalApi.deliveries({ page }, controller.signal)
      .then((result) => { setRows(result.data); setLastPage(result.meta.last_page); })
      .catch(() => { if (!controller.signal.aborted) setError('تعذر تحميل التوريدات.'); });
    return () => controller.abort();
  }, [page]);

  return (
    <main id="main-content" dir="rtl" className="mx-auto max-w-5xl px-5 py-8">
      <h1 className="mb-5 text-2xl font-semibold">التوريدات</h1>
      {error && <p role="alert">{error}</p>}
      {!rows && !error && <p role="status">جارٍ التحميل…</p>}
      {rows?.length === 0 && <p>لا توجد توريدات.</p>}
      <ul className="space-y-3">
        {rows?.map((row) => (
          <li className="rounded-xl bg-white p-4" key={row.id}>
            <Link className="font-semibold underline" href={`/supplier/deliveries/${row.id}`}>{row.delivery_number}</Link>
            <p>أمر شراء #{row.purchase_order_id} · {row.delivery_date ?? '—'} · {statusLabels[row.status] ?? 'حالة غير معروفة'}</p>
          </li>
        ))}
      </ul>
      <nav aria-label="صفحات التوريدات" className="mt-6 flex items-center justify-between">
        <button type="button" disabled={page <= 1 || !rows} onClick={() => setPage((current) => Math.max(1, current - 1))}
          className="rounded border px-4 py-2 disabled:opacity-50">السابق</button>
        <span aria-live="polite">صفحة {page} من {lastPage}</span>
        <button type="button" disabled={page >= lastPage || !rows} onClick={() => setPage((current) => Math.min(lastPage, current + 1))}
          className="rounded border px-4 py-2 disabled:opacity-50">التالي</button>
      </nav>
    </main>
  );
}
