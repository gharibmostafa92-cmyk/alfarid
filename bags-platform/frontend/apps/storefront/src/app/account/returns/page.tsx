'use client';

import React, { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { accountEndpoints, type StoreReturnRequest } from '@alfarid/api-client';
import { formatStorePrice } from '@alfarid/utils';
import { ChevronDown } from 'lucide-react';
import { useStoreLang } from '@/providers/store-lang-provider';

type ReturnPage = { current_page: number; last_page: number; total: number };

const STATUS_LABELS: Record<string, [string, string]> = {
  pending: ['قيد المراجعة', 'Under review'],
  approved: ['تمت الموافقة', 'Approved'],
  rejected: ['مرفوض', 'Declined'],
  received: ['تم استلام المنتجات', 'Items received'],
  refunded: ['تم رد المبلغ', 'Refunded'],
  cancelled: ['ملغي', 'Cancelled'],
};

function dateLabel(value: string | null, isAr: boolean): string | null {
  const timestamp = value ? Date.parse(value) : NaN;
  return Number.isFinite(timestamp)
    ? new Intl.DateTimeFormat(isAr ? 'ar-EG' : 'en-EG', { dateStyle: 'medium', timeZone: 'Africa/Cairo' }).format(timestamp)
    : null;
}

export default function AccountReturnsPage() {
  const { isAr } = useStoreLang();
  const [items, setItems] = useState<StoreReturnRequest[]>([]);
  const [pageInfo, setPageInfo] = useState<ReturnPage>({ current_page: 1, last_page: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const requestVersion = useRef(0);

  const load = useCallback(async (requestedPage: number) => {
    const version = ++requestVersion.current;
    setLoading(true);
    setError(false);
    try {
      const response = await accountEndpoints.returns(requestedPage);
      if (version !== requestVersion.current) return;
      setItems(response.data);
      setPageInfo(response.meta);
      setPage(response.meta.current_page || requestedPage);
    } catch {
      if (version === requestVersion.current) setError(true);
    } finally {
      if (version === requestVersion.current) setLoading(false);
    }
  }, []);

  useEffect(() => { void load(page); }, [load, page]);

  return (
    <main id="main-content" tabIndex={-1} className="min-h-screen bg-[#f3f5f7]">
      <section className="checkout-wrapper" aria-labelledby="returns-heading">
        <div className="container" style={{ maxWidth: '720px', margin: '0 auto', padding: '40px 20px' }}>
          <h1 id="returns-heading" className="checkout-giant-title">{isAr ? 'طلبات الإرجاع' : 'Return requests'}</h1>
          <p className="mb-5 text-sm text-slate-700">
            {isAr ? 'لتقديم طلب إرجاع، افتح تفاصيل الطلب المُسلّم من صفحة طلباتي.' : 'To start a return, open a delivered order in My orders.'}{' '}
            <Link href="/account/orders" className="inline-flex min-h-11 items-center underline underline-offset-4">{isAr ? 'عرض طلباتي' : 'View my orders'}</Link>
          </p>
          {error && (
            <div className="checkout-banner is-error" role="alert">
              <span>{isAr ? 'تعذر تحميل طلبات الإرجاع. يرجى المحاولة مرة أخرى.' : 'We could not load your return requests. Please try again.'}</span>
              <button type="button" className="btn-apply-promo" onClick={() => { void load(page); }}>
                {isAr ? 'إعادة المحاولة' : 'Try again'}
              </button>
            </div>
          )}
          {loading && <div role="status" aria-label={isAr ? 'جارٍ تحميل طلبات الإرجاع' : 'Loading return requests'}><div className="mega-skel-link" /></div>}
          {!loading && !error && items.length === 0 && (
            <div className="checkout-block">
              <p>{isAr ? 'لا توجد طلبات إرجاع حتى الآن.' : 'You haven’t submitted any return requests yet.'}</p>
            </div>
          )}
          {!loading && !error && items.map((item) => {
            const date = dateLabel(item.created_at, isAr);
            return (
              <details key={item.id} className="checkout-block account-detail-card group">
                <summary className="flex min-h-11 cursor-pointer list-none items-center gap-3 rounded-sm active:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-slate-700 [&::-webkit-details-marker]:hidden">
                  <span className="min-w-0 flex-1">
                    <span className="block font-semibold"><bdi>{item.return_number || `#${item.id}`}</bdi></span>
                    {date && <time dateTime={item.created_at ?? undefined} className="block text-sm text-slate-600">{date}</time>}
                  </span>
                  <span className="min-w-0 text-end text-sm">
                    {STATUS_LABELS[item.status]?.[isAr ? 0 : 1] ?? (isAr ? 'الحالة قيد التحديث' : 'Status updating')}
                  </span>
                  <ChevronDown aria-hidden="true" className="size-5 shrink-0 group-open:rotate-180 motion-safe:transition-transform" />
                </summary>
                <div className="mt-4 space-y-3 border-t border-slate-200 pt-4 text-sm">
                  {item.order_number && <p>{isAr ? 'رقم الطلب' : 'Order number'} <bdi className="font-semibold">{item.order_number}</bdi></p>}
                  <ul className="divide-y divide-slate-100">
                    {item.items.map((line) => (
                      <li key={line.id} className="flex items-start justify-between gap-3 py-2">
                        <bdi className="min-w-0">{line.product_name || line.sku || (isAr ? 'منتج' : 'Product')}</bdi>
                        <span className="shrink-0 tabular-nums" dir="ltr">× {Number(line.requested_qty).toLocaleString(isAr ? 'ar-EG' : 'en-EG')}</span>
                      </li>
                    ))}
                  </ul>
                  {item.reason_type && <p>{isAr ? 'نوع الطلب' : 'Request type'} <strong>{item.reason_type === 'defect_or_mismatch' ? (isAr ? 'عيب أو عدم مطابقة' : 'Defect or mismatch') : (isAr ? 'العدول عن الشراء' : 'Change of mind')}</strong></p>}
                  {item.reason && <p dir="auto" className="whitespace-pre-wrap text-slate-700">{item.reason}</p>}
                  {item.status === 'refunded' && (
                    <p>{isAr ? 'المبلغ المسترد' : 'Refund amount'} <strong>{formatStorePrice(Number(item.total_refund_amount))}</strong></p>
                  )}
                </div>
              </details>
            );
          })}
          {pageInfo.last_page > 1 && (
            <nav className="flex flex-wrap items-center justify-between gap-3 py-5" aria-label={isAr ? 'صفحات طلبات الإرجاع' : 'Return request pages'}>
              <button
                type="button"
                className="btn-apply-promo"
                disabled={loading || pageInfo.current_page <= 1}
                onClick={() => setPage((current) => Math.max(1, current - 1))}
              >
                {isAr ? 'السابق' : 'Previous'}
              </button>
              <span aria-live="polite">{isAr ? `صفحة ${pageInfo.current_page} من ${pageInfo.last_page}` : `Page ${pageInfo.current_page} of ${pageInfo.last_page}`}</span>
              <button
                type="button"
                className="btn-apply-promo"
                disabled={loading || pageInfo.current_page >= pageInfo.last_page}
                onClick={() => setPage((current) => Math.min(pageInfo.last_page, current + 1))}
              >
                {isAr ? 'التالي' : 'Next'}
              </button>
            </nav>
          )}
        </div>
      </section>
    </main>
  );
}
