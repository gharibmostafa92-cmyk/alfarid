'use client';
import { useEffect, useState } from 'react';
import { supplierPortalApi, type SupplierPortalDelivery } from '@alfarid/api-client';

export default function SupplierDeliveriesPage() {
  const [rows, setRows] = useState<SupplierPortalDelivery[] | null>(null); const [error, setError] = useState('');
  useEffect(() => { const c = new AbortController(); supplierPortalApi.deliveries(undefined, c.signal).then(p => setRows(p.data)).catch(() => { if (!c.signal.aborted) setError('تعذر تحميل التوريدات.'); }); return () => c.abort(); }, []);
  return <main id="main-content" className="mx-auto max-w-5xl px-5 py-8" dir="rtl"><h1 className="mb-5 text-2xl font-semibold">التوريدات</h1>{error && <p role="alert">{error}</p>}{!rows && !error && <p role="status">جارٍ التحميل…</p>}{rows?.length === 0 && <p>لا توجد توريدات.</p>}<ul className="space-y-3">{rows?.map(row => <li className="rounded-xl bg-white p-4" key={row.id}><strong>{row.delivery_number}</strong><p>أمر شراء #{row.purchase_order_id} · {row.delivery_date ?? '—'} · {row.status}</p></li>)}</ul></main>;
}
