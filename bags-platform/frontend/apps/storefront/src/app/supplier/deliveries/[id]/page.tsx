'use client';

import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import { openProtectedFile, supplierPortalApi, type SupplierPortalDelivery } from '@alfarid/api-client';

const statusLabels: Record<string, string> = {
  pending_receipt: 'بانتظار الاستلام',
  received: 'تم الاستلام',
  rejected: 'مرفوض',
  closed_short: 'مغلق بنقص',
};

export default function SupplierDeliveryPage() {
  const { id } = useParams<{ id: string }>();
  const deliveryId = Number(id);
  const [delivery, setDelivery] = useState<SupplierPortalDelivery | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    if (!Number.isSafeInteger(deliveryId) || deliveryId <= 0) {
      setError('رقم التوريد غير صحيح.');
      return () => controller.abort();
    }
    supplierPortalApi.delivery(deliveryId, controller.signal).then(setDelivery)
      .catch(() => { if (!controller.signal.aborted) setError('تعذر تحميل تفاصيل التوريد.'); });
    return () => controller.abort();
  }, [deliveryId]);

  return (
    <main id="main-content" className="mx-auto max-w-5xl px-5 py-8" dir="rtl">
      <Link href="/supplier/deliveries" className="underline">العودة إلى التوريدات</Link>
      <h1 className="my-4 text-2xl font-semibold">تفاصيل التوريد {delivery?.delivery_number}</h1>
      {error && <p role="alert">{error}</p>}
      {!delivery && !error && <p role="status">جارٍ التحميل…</p>}
      {delivery && <>
        <p>الحالة: {statusLabels[delivery.status] ?? 'حالة غير معروفة'} · التاريخ: {delivery.delivery_date ?? '—'}</p>
        <p>رقم فاتورة المورد: {delivery.supplier_invoice_no ?? '—'}</p>
        {delivery.notes && <p className="my-3">ملاحظات: {delivery.notes}</p>}
        <Link href={`/supplier/purchase-orders/${delivery.purchase_order_id}`} className="my-3 inline-block underline">عرض أمر الشراء المرتبط</Link>
        {delivery.invoice_file_url && <p><button type="button" className="underline" onClick={() => {
          void openProtectedFile(delivery.invoice_file_url!).catch(() => setError('تعذر فتح الفاتورة المرفقة.'));
        }}>عرض أو تنزيل الفاتورة المرفقة</button></p>}
        <div className="mt-5 overflow-x-auto"><table className="w-full text-right"><thead><tr><th>الصنف</th><th>الكمية المشحونة</th><th>الكمية المستلمة</th></tr></thead><tbody>
          {delivery.items?.map((item) => <tr key={item.id}><td>{item.variant?.sku ?? item.product_variant_id}</td><td>{item.shipped_qty}</td><td>{item.received_qty}</td></tr>)}
        </tbody></table></div>
      </>}
    </main>
  );
}
