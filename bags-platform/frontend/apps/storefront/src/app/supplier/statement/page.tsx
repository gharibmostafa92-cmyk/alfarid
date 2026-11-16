'use client';

import { FormEvent, useEffect, useState } from 'react';
import { supplierPortalApi, type SupplierStatement } from '@alfarid/api-client';
import { formatStorePrice } from '@alfarid/utils';

export default function SupplierStatementPage() {
  const [data, setData] = useState<SupplierStatement | null>(null);
  const [fromDate, setFromDate] = useState('');
  const [toDate, setToDate] = useState('');
  const [period, setPeriod] = useState<{ from_date?: string; to_date?: string }>({});
  const [error, setError] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    setData(null);
    setError('');
    supplierPortalApi.statement(period, controller.signal).then(setData)
      .catch(() => { if (!controller.signal.aborted) setError('تعذر تحميل كشف الحساب.'); });
    return () => controller.abort();
  }, [period]);

  const applyPeriod = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (fromDate && toDate && toDate < fromDate) {
      setError('تاريخ النهاية يجب أن يكون بعد تاريخ البداية.');
      return;
    }
    if (fromDate && toDate) {
      const days = (new Date(toDate).getTime() - new Date(fromDate).getTime()) / 86400000;
      if (days > 366) {
        setError('فترة كشف الحساب يجب ألا تتجاوز 366 يومًا.');
        return;
      }
    }
    setPeriod({ from_date: fromDate || undefined, to_date: toDate || undefined });
  };

  return (
    <main id="main-content" className="mx-auto max-w-5xl px-5 py-8" dir="rtl">
      <h1 className="text-2xl font-semibold">كشف الحساب</h1>
      <form onSubmit={applyPeriod} className="my-5 flex flex-wrap items-end gap-3">
        <label className="grid gap-1">من تاريخ<input className="rounded border px-3 py-2" type="date" value={fromDate} onChange={(event) => setFromDate(event.target.value)} /></label>
        <label className="grid gap-1">إلى تاريخ<input className="rounded border px-3 py-2" type="date" value={toDate} onChange={(event) => setToDate(event.target.value)} /></label>
        <button className="rounded bg-black px-4 py-2 text-white" type="submit">عرض الكشف</button>
        <button className="rounded border px-4 py-2" type="button" onClick={() => { setFromDate(''); setToDate(''); setPeriod({}); }}>آخر 365 يومًا</button>
      </form>
      {error && <p role="alert">{error}</p>}
      {!data && !error && <p role="status">جارٍ التحميل…</p>}
      {data && <>
        <p className="my-4">الرصيد الافتتاحي: {formatStorePrice(data.opening_balance)} · الرصيد النهائي: {formatStorePrice(data.closing_balance)}</p>
        <div className="overflow-x-auto"><table className="w-full text-right"><thead><tr><th>التاريخ</th><th>المرجع</th><th>البيان</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead><tbody>
          {data.entries.map((entry, index) => <tr key={`${entry.type}-${entry.reference}-${index}`}><td>{entry.date}</td><td>{entry.reference}</td><td>{entry.description}</td><td>{formatStorePrice(entry.debit)}</td><td>{formatStorePrice(entry.credit)}</td><td>{formatStorePrice(entry.running_balance)}</td></tr>)}
        </tbody></table></div>
      </>}
    </main>
  );
}
