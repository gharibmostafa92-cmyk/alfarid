'use client';

import React, { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { accountEndpoints } from '@alfarid/api-client';

type ReturnItem = {
  id: number;
  return_number?: string | null;
  order_number?: string | null;
  status: string;
  created_at: string;
};

type ReturnPage = { current_page: number; last_page: number; total: number };

const STATUS_LABELS: Record<string, string> = {
  pending: 'قيد المراجعة',
  approved: 'تمت الموافقة',
  rejected: 'مرفوض',
  received: 'تم استلام المنتجات',
  refunded: 'تم رد المبلغ',
  cancelled: 'ملغي',
};

function statusLabel(status: string): string {
  return STATUS_LABELS[status.toLowerCase()] ?? 'الحالة قيد التحديث';
}

function dateLabel(value: string): string {
  const timestamp = Date.parse(value);
  return Number.isFinite(timestamp)
    ? new Intl.DateTimeFormat('ar-EG', { dateStyle: 'medium' }).format(timestamp)
    : '—';
}

export default function AccountReturnsPage() {
  const [items, setItems] = useState<ReturnItem[]>([]);
  const [pageInfo, setPageInfo] = useState<ReturnPage>({ current_page: 1, last_page: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const requestVersion = useRef(0);

  const load = useCallback(async (requestedPage: number) => {
    const version = ++requestVersion.current;
    setLoading(true);
    setError('');
    try {
      const response = await accountEndpoints.returns(requestedPage);
      if (version !== requestVersion.current) return;
      setItems(response.data as ReturnItem[]);
      setPageInfo(response.meta);
      setPage(response.meta.current_page || requestedPage);
    } catch {
      if (version === requestVersion.current) setError('تعذر تحميل المرتجعات. حاول مرة أخرى.');
    } finally {
      if (version === requestVersion.current) setLoading(false);
    }
  }, []);

  useEffect(() => { void load(page); }, [load, page]);

  return (
    <main id="main-content" tabIndex={-1} className="min-h-screen bg-[#f3f5f7]">
      <section className="checkout-wrapper" aria-label="طلبات الإرجاع">
        <div className="container" style={{ maxWidth: '720px', margin: '0 auto', padding: '40px 20px' }}>
          <h1 className="checkout-giant-title">طلبات الإرجاع</h1>
          {error && (
            <div className="checkout-banner is-error" role="alert">
              <span>{error}</span>
              <button type="button" className="btn-apply-promo" onClick={() => { void load(page); }}>
                إعادة المحاولة
              </button>
            </div>
          )}
          {loading && <div role="status" aria-label="جارٍ تحميل المرتجعات"><div className="mega-skel-link" /></div>}
          {!loading && !error && items.length === 0 && (
            <div className="checkout-block">
              <p>لا توجد طلبات إرجاع حتى الآن. <Link href="/account/orders">عرض طلباتي</Link></p>
            </div>
          )}
          {!loading && !error && items.map((item) => (
            <article key={item.id} className="checkout-block">
              <div className="checkout-block-header">
                <h2 className="checkout-section-heading">طلب إرجاع {item.return_number || `#${item.id}`}</h2>
                <span>{statusLabel(item.status)}</span>
              </div>
              <p>تاريخ الطلب: {dateLabel(item.created_at)}</p>
              {item.order_number && <p>رقم الطلب: {item.order_number}</p>}
            </article>
          ))}
          {pageInfo.last_page > 1 && (
            <nav className="flex items-center justify-between gap-4 py-5" aria-label="التنقل بين صفحات المرتجعات">
              <button
                type="button"
                className="btn-apply-promo"
                disabled={loading || pageInfo.current_page <= 1}
                onClick={() => setPage((current) => Math.max(1, current - 1))}
              >
                السابق
              </button>
              <span aria-live="polite">صفحة {pageInfo.current_page} من {pageInfo.last_page} · {pageInfo.total} طلب</span>
              <button
                type="button"
                className="btn-apply-promo"
                disabled={loading || pageInfo.current_page >= pageInfo.last_page}
                onClick={() => setPage((current) => Math.min(pageInfo.last_page, current + 1))}
              >
                التالي
              </button>
            </nav>
          )}
        </div>
      </section>
    </main>
  );
}
