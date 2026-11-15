'use client';
import { useEffect, useState } from 'react';
import { supplierPortalApi, type SupplierStatement } from '@alfarid/api-client';
import { formatStorePrice } from '@alfarid/utils';

export default function SupplierStatementPage() {
  const [data, setData] = useState<SupplierStatement | null>(null);
  const [error, setError] = useState('');
  useEffect(() => { const c = new AbortController(); supplierPortalApi.statement(undefined, c.signal).then(setData).catch(() => { if (!c.signal.aborted) setError('تعذر تحميل كشف الحساب.'); }); return () => c.abort(); }, []);
  return <main id="main-content" className="mx-auto max-w-5xl px-5 py-8" dir="rtl"><h1 className="text-2xl font-semibold">كشف الحساب</h1>{error && <p role="alert">{error}</p>}{!data && !error && <p role="status">جارٍ التحميل…</p>}{data && <><p className="my-4">الرصيد الافتتاحي: {formatStorePrice(data.opening_balance)} · الرصيد النهائي: {formatStorePrice(data.closing_balance)}</p><div className="overflow-x-auto"><table className="w-full text-right"><thead><tr><th>التاريخ</th><th>المرجع</th><th>البيان</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead><tbody>{data.entries.map((e, i) => <tr key={`${e.type}-${e.reference}-${i}`}><td>{e.date}</td><td>{e.reference}</td><td>{e.description}</td><td>{formatStorePrice(e.debit)}</td><td>{formatStorePrice(e.credit)}</td><td>{formatStorePrice(e.running_balance)}</td></tr>)}</tbody></table></div></>}</main>;
}
