'use client';
import { useParams } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { supplierPortalApi, type SupplierPortalPO } from '@alfarid/api-client';
import { formatStorePrice } from '@alfarid/utils';

export default function SupplierOrderPage() {
  const { id } = useParams<{ id: string }>();
  const orderId = Number(id);
  const [order, setOrder] = useState<SupplierPortalPO | null>(null);
  const [error, setError] = useState(''); const [message, setMessage] = useState(''); const [busy, setBusy] = useState(false);
  const [notes, setNotes] = useState(''); const [date, setDate] = useState(''); const [quantities, setQuantities] = useState<Record<number, string>>({});
  const key = useRef<string | null>(null);
  useEffect(() => { const c = new AbortController(); if (!Number.isSafeInteger(orderId) || orderId <= 0) { setError('رقم أمر الشراء غير صحيح.'); return () => c.abort(); } supplierPortalApi.purchaseOrder(orderId, c.signal).then(setOrder).catch(() => { if (!c.signal.aborted) setError('تعذر تحميل أمر الشراء.'); }); return () => c.abort(); }, [orderId]);
  const act = async (action: () => Promise<SupplierPortalPO>) => { setBusy(true); setError(''); try { await action(); setOrder(await supplierPortalApi.purchaseOrder(orderId)); setMessage('تم تحديث أمر الشراء.'); } catch { setError('تعذر تنفيذ الطلب؛ راجع الحالة وحاول مرة أخرى.'); } finally { setBusy(false); } };
  const deliver = async () => {
    const items = Object.entries(quantities).filter(([, q]) => Number(q) > 0).map(([item, q]) => ({ purchase_order_item_id: Number(item), shipped_qty: Number(q) }));
    if (!date || !items.length || items.some(item => !Number.isInteger(item.shipped_qty))) { setError('حدد تاريخ التوريد وكمية صحيحة لبند واحد على الأقل.'); return; }
    setBusy(true); setError(''); key.current ??= crypto.randomUUID();
    try { await supplierPortalApi.createDelivery(orderId, { delivery_date: date, items }, key.current); key.current = null; setQuantities({}); setMessage('تم تسجيل إشعار التوريد.'); setOrder(await supplierPortalApi.purchaseOrder(orderId)); }
    catch { setError('تعذر تسجيل التوريد. تحقق من الكميات وحاول مرة أخرى.'); } finally { setBusy(false); }
  };
  return <main id="main-content" className="mx-auto max-w-5xl px-5 py-8" dir="rtl"><h1 className="text-2xl font-semibold">أمر الشراء {order?.po_number}</h1>{error && <p role="alert" className="my-3 text-red-700">{error}</p>}{message && <p role="status" className="my-3">{message}</p>}{!order && !error && <p role="status">جارٍ التحميل…</p>}{order && <><p className="my-4">الحالة: {order.status} · الإجمالي: {formatStorePrice(order.grand_total)}</p><ul className="space-y-2">{order.items?.map(item => <li key={item.id} className="rounded bg-white p-3">{item.variant?.sku ?? item.product_variant_id} · المطلوب {item.ordered_qty} · المستلم {item.received_qty}{['sent_to_supplier', 'supplier_accepted', 'partially_received'].includes(order.status) && <label className="block">كمية التوريد <input className="border" type="number" min="0" step="1" value={quantities[item.id] ?? ''} onChange={e => { key.current = null; setQuantities({ ...quantities, [item.id]: e.target.value }); }} /></label>}</li>)}</ul>{order.status === 'sent_to_supplier' && <div className="my-4 space-y-3"><button disabled={busy} className="rounded bg-black px-4 py-2 text-white" onClick={() => void act(() => supplierPortalApi.accept(orderId))}>قبول الأمر</button><label className="block">ملاحظات التعديل <textarea className="block w-full border" value={notes} onChange={e => setNotes(e.target.value)} /></label><button disabled={busy || !notes.trim()} onClick={() => void act(() => supplierPortalApi.requestChange(orderId, notes))}>طلب تعديل</button></div>}{['sent_to_supplier', 'supplier_accepted', 'partially_received'].includes(order.status) && <section className="mt-6"><h2 className="font-semibold">إشعار توريد</h2><label>تاريخ التوريد <input className="border" type="date" value={date} onChange={e => { key.current = null; setDate(e.target.value); }} /></label><button className="mx-3 rounded bg-black px-4 py-2 text-white" disabled={busy} onClick={() => void deliver()}>إرسال التوريد</button></section>}</>}</main>;
}
