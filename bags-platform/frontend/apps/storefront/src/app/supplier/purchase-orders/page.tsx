'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { Suspense, useEffect, useState } from 'react';
import { supplierPortalApi, type SupplierPortalPO, type SupplierPortalPOStatus } from '@alfarid/api-client';
import { formatStorePrice } from '@alfarid/utils';

const statuses: { value?: SupplierPortalPOStatus; label: string }[] = [
  { label: 'الكل' },
  { value: 'sent_to_supplier', label: 'جديدة' },
  { value: 'supplier_accepted', label: 'مقبولة' },
  { value: 'change_requested', label: 'تعديل مطلوب' },
  { value: 'partially_received', label: 'مستلمة جزئيًا' },
  { value: 'completed', label: 'مكتملة' },
];

const statusLabel: Record<SupplierPortalPOStatus, string> = {
  sent_to_supplier: 'جديدة',
  supplier_accepted: 'مقبولة',
  change_requested: 'تعديل مطلوب',
  partially_received: 'مستلمة جزئيًا',
  completed: 'مكتملة',
  cancelled: 'ملغاة',
};

const statusValues = new Set<SupplierPortalPOStatus>(statuses.flatMap((item) => item.value ? [item.value] : []));

export default function SupplierPurchaseOrdersPage() {
  return (
    <Suspense fallback={<main id="main-content" className="mx-auto max-w-5xl px-5 py-8"><p role="status">جارٍ التحميل…</p></main>}>
      <SupplierPurchaseOrdersContent />
    </Suspense>
  );
}

function SupplierPurchaseOrdersContent() {
  const searchParams = useSearchParams();
  const requestedStatus = searchParams.get('status') as SupplierPortalPOStatus | null;
  const [status, setStatus] = useState<SupplierPortalPOStatus | undefined>(requestedStatus && statusValues.has(requestedStatus) ? requestedStatus : undefined);
  const [orders, setOrders] = useState<SupplierPortalPO[] | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    setOrders(null);
    setError('');
    supplierPortalApi.purchaseOrders({ status }, controller.signal)
      .then((page) => setOrders(page.data))
      .catch(() => { if (!controller.signal.aborted) setError('تعذر تحميل أوامر الشراء.'); });
    return () => controller.abort();
  }, [status]);

  return (
    <main id="main-content" tabIndex={-1} className="mx-auto max-w-5xl px-5 py-8">
      <h1 className="mb-4 text-2xl font-semibold">أوامر الشراء</h1>
      <div className="mb-5 flex flex-wrap gap-2">
        {statuses.map((item) => (
          <button key={item.label} type="button" onClick={() => setStatus(item.value)}
            className={`rounded-full px-3 py-1 text-sm ${status === item.value ? 'bg-black text-white' : 'bg-white text-black/70'}`}>
            {item.label}
          </button>
        ))}
      </div>
      {error ? <p role="alert" className="text-red-700">{error}</p> : null}
      {!orders && !error ? <p role="status">جارٍ التحميل…</p> : null}
      {orders?.length === 0 ? <p className="text-black/60">لا توجد أوامر شراء مطابقة.</p> : null}
      <div className="space-y-3">
        {orders?.map((order) => (
          <Link key={order.id} href={`/supplier/purchase-orders/${order.id}`}
            className="block rounded-xl bg-white p-5 shadow-sm transition hover:shadow-md">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <strong>{order.po_number}</strong>
              <span className="text-sm text-black/60">{statusLabel[order.status]}</span>
            </div>
            <div className="mt-2 flex flex-wrap justify-between gap-2 text-sm text-black/60">
              <span>{order.branch?.name ?? '—'}</span>
              <span>التسليم: {order.expected_delivery_date ?? 'غير محدد'}</span>
              <strong className="text-black">{formatStorePrice(order.grand_total)}</strong>
            </div>
          </Link>
        ))}
      </div>
    </main>
  );
}
