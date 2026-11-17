'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { useParams } from 'next/navigation';
import { supplierPortalApi, type SupplierPortalPO } from '@alfarid/api-client';
import { formatStorePrice } from '@alfarid/utils';

export default function SupplierOrderPage() {
  const { id } = useParams<{ id: string }>();
  const orderId = Number(id);
  const [order, setOrder] = useState<SupplierPortalPO | null>(null);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [refreshRequired, setRefreshRequired] = useState(false);
  const [notes, setNotes] = useState('');
  const [date, setDate] = useState('');
  const [quantities, setQuantities] = useState<Record<number, string>>({});
  const key = useRef<string | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    if (!Number.isSafeInteger(orderId) || orderId <= 0) {
      setError('رقم أمر الشراء غير صحيح.');
      return () => controller.abort();
    }
    supplierPortalApi.purchaseOrder(orderId, controller.signal)
      .then((result) => { if (!controller.signal.aborted) setOrder(result); })
      .catch(() => { if (!controller.signal.aborted) setError('تعذر تحميل أمر الشراء.'); });
    return () => controller.abort();
  }, [orderId]);

  const refreshAfterMutation = useCallback(async (successMessage: string) => {
    setRefreshRequired(true);
    setMessage(successMessage);
    try {
      setOrder(await supplierPortalApi.purchaseOrder(orderId));
      setRefreshRequired(false);
    } catch {
      // Keep follow-up writes disabled until the committed state is reloaded.
      setMessage(`${successMessage} تعذّر تحديث الحالة المعروضة؛ أعد تحميلها قبل أي إجراء آخر.`);
    }
  }, [orderId]);

  const reload = useCallback(async () => {
    setBusy(true);
    setError('');
    setMessage('');
    try {
      setOrder(await supplierPortalApi.purchaseOrder(orderId));
      setRefreshRequired(false);
    } catch {
      setError('تعذر تحديث حالة أمر الشراء. حاول مرة أخرى.');
    } finally {
      setBusy(false);
    }
  }, [orderId]);

  const mutate = async (
    action: () => Promise<unknown>,
    successMessage: string,
    failureMessage: string,
    afterCommit?: () => void,
  ) => {
    if (busy || refreshRequired) return;
    setBusy(true);
    setError('');
    setMessage('');
    let committed = false;
    try {
      await action();
      committed = true;
    } catch {
      setError(failureMessage);
    }
    if (committed) {
      afterCommit?.();
      await refreshAfterMutation(successMessage);
    }
    setBusy(false);
  };

  const deliver = async () => {
    const items = Object.entries(quantities)
      .filter(([, quantity]) => Number(quantity) > 0)
      .map(([itemId, quantity]) => ({ purchase_order_item_id: Number(itemId), shipped_qty: Number(quantity) }));
    if (!date || items.length === 0 || items.some((item) => !Number.isInteger(item.shipped_qty))) {
      setError('حدد تاريخ التوريد وكمية صحيحة لبند واحد على الأقل.');
      return;
    }

    key.current ??= crypto.randomUUID();
    await mutate(
      () => supplierPortalApi.createDelivery(orderId, { delivery_date: date, items }, key.current!),
      'تم تسجيل إشعار التوريد.',
      'تعذر تسجيل التوريد. تحقق من الكميات وحاول مرة أخرى.',
      () => {
        key.current = null;
        setQuantities({});
      },
    );
  };

  const canSubmitDelivery = ['sent_to_supplier', 'supplier_accepted', 'partially_received'].includes(order?.status ?? '');
  const canAct = order?.status === 'sent_to_supplier';

  return (
    <main id="main-content" className="mx-auto max-w-5xl px-5 py-8" dir="rtl">
      <h1 className="text-2xl font-semibold">أمر الشراء {order?.po_number}</h1>
      {error && <p role="alert" className="my-3 text-red-700">{error}</p>}
      {message && <p role="status" className="my-3">{message}</p>}
      {!order && !error && <p role="status">جارٍ التحميل…</p>}
      {refreshRequired && (
        <button type="button" className="my-3 rounded border px-4 py-2" disabled={busy} onClick={() => void reload()}>
          إعادة تحميل الحالة
        </button>
      )}
      {order && (
        <>
          <p className="my-4">الحالة: {order.status} · الإجمالي: {formatStorePrice(order.grand_total)}</p>
          <ul className="space-y-2">
            {order.items?.map((item) => (
              <li key={item.id} className="rounded bg-white p-3">
                {item.variant?.sku ?? item.product_variant_id} · المطلوب {item.ordered_qty} · المستلم {item.received_qty}
                {canSubmitDelivery && (
                  <label className="block">
                    كمية التوريد
                    <input
                      className="border"
                      type="number"
                      min="0"
                      step="1"
                      value={quantities[item.id] ?? ''}
                      disabled={busy || refreshRequired}
                      onChange={(event) => {
                        key.current = null;
                        setQuantities({ ...quantities, [item.id]: event.target.value });
                      }}
                    />
                  </label>
                )}
              </li>
            ))}
          </ul>
          {canAct && (
            <div className="my-4 space-y-3">
              <button
                type="button"
                disabled={busy || refreshRequired}
                className="rounded bg-black px-4 py-2 text-white"
                onClick={() => void mutate(() => supplierPortalApi.accept(orderId), 'تم تحديث أمر الشراء.', 'تعذر تنفيذ الطلب؛ راجع الحالة وحاول مرة أخرى.')}
              >
                قبول الأمر
              </button>
              <label className="block">
                ملاحظات التعديل
                <textarea className="block w-full border" value={notes} onChange={(event) => setNotes(event.target.value)} />
              </label>
              <button
                type="button"
                disabled={busy || refreshRequired || !notes.trim()}
                onClick={() => void mutate(() => supplierPortalApi.requestChange(orderId, notes), 'تم إرسال طلب التعديل.', 'تعذر تنفيذ الطلب؛ راجع الحالة وحاول مرة أخرى.')}
              >
                طلب تعديل
              </button>
            </div>
          )}
          {canSubmitDelivery && (
            <section className="mt-6">
              <h2 className="font-semibold">إشعار توريد</h2>
              <label>
                تاريخ التوريد
                <input
                  className="border"
                  type="date"
                  value={date}
                  disabled={busy || refreshRequired}
                  onChange={(event) => {
                    key.current = null;
                    setDate(event.target.value);
                  }}
                />
              </label>
              <button
                type="button"
                className="mx-3 rounded bg-black px-4 py-2 text-white"
                disabled={busy || refreshRequired}
                onClick={() => void deliver()}
              >
                إرسال التوريد
              </button>
            </section>
          )}
        </>
      )}
    </main>
  );
}
