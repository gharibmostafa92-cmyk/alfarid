'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { ChevronDown } from 'lucide-react';
import { accountEndpoints, type StoreOrder } from '@alfarid/api-client';
import { formatStorePrice, getStatusLabel, OrderStatusLabelsAr } from '@alfarid/utils';
import { useStoreLang } from '@/providers/store-lang-provider';

const ORDER_STATUS_EN: Record<string, string> = {
  pending: 'Under review', confirmed: 'Confirmed', processing: 'Preparing',
  shipped: 'Shipped', delivered: 'Delivered', cancelled: 'Cancelled', returned: 'Returned',
};

const SHIPMENT_STATUS: Record<string, [string, string]> = {
  created: ['بانتظار الاستلام من شركة الشحن', 'Awaiting carrier pickup'],
  picked_up: ['استلمتها شركة الشحن', 'Picked up by carrier'],
  in_transit: ['الشحنة في الطريق', 'In transit'],
  out_for_delivery: ['مع مندوب التوصيل', 'Out for delivery'],
  delivered: ['تم التوصيل', 'Delivered'],
  failed: ['تعذر التوصيل', 'Delivery unsuccessful'],
  returned: ['أُعيدت الشحنة إلى المتجر', 'Returned to store'],
};

export default function AccountOrdersPage() {
  const { isAr } = useStoreLang();
  const [orders, setOrders] = useState<StoreOrder[] | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState('');
  const [cancelling, setCancelling] = useState<number | null>(null);
  const [confirmTarget, setConfirmTarget] = useState<StoreOrder | null>(null);

  const load = useCallback(async () => {
    setError('');
    try {
      const result = await accountEndpoints.orders(1);
      setOrders(result.data);
      setPage(result.currentPage);
      setLastPage(result.lastPage);
    } catch { setError(isAr ? 'تعذر تحميل الطلبات.' : 'Could not load orders.'); }
  }, [isAr]);

  const loadMore = async () => {
    const next = page + 1;
    setLoadingMore(true);
    setError('');
    try {
      const result = await accountEndpoints.orders(next);
      setOrders((list) => [...(list ?? []), ...result.data]);
      setPage(result.currentPage);
      setLastPage(result.lastPage);
    } catch { setError(isAr ? 'تعذر تحميل المزيد من الطلبات.' : 'Could not load more orders.'); }
    finally { setLoadingMore(false); }
  };

  useEffect(() => { void load(); }, [load]);
  useEffect(() => {
    if (!confirmTarget) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && cancelling === null) setConfirmTarget(null);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [confirmTarget, cancelling]);

  const confirmCancel = async () => {
    if (!confirmTarget) return;
    const id = confirmTarget.id;
    setCancelling(id);
    try {
      const updated = await accountEndpoints.cancelOrder(id);
      setOrders((list) => (list ?? []).map((o) => (o.id === id ? updated : o)));
      setConfirmTarget(null);
    } catch {
      setError(isAr ? 'تعذر إلغاء الطلب في حالته الحالية.' : 'Could not cancel this order in its current status.');
      setConfirmTarget(null);
    } finally {
      setCancelling(null);
    }
  };

  return (
    <main id="main-content" tabIndex={-1} className="min-h-screen bg-[#f3f5f7]">
      <section className="checkout-wrapper" aria-label={isAr ? 'طلباتي' : 'My orders'}>
        <div className="container" style={{ maxWidth: '720px', margin: '0 auto', padding: '40px 20px' }}>
          <h1 className="checkout-giant-title">{isAr ? 'طلباتي' : 'MY ORDERS'}</h1>
          {error && (
            <div className="checkout-banner is-error" role="alert">
              <span>{error}</span>
              <button type="button" className="btn-apply-promo" onClick={() => { void load(); }}>
                {isAr ? 'إعادة المحاولة' : 'Retry'}
              </button>
            </div>
          )}
          {orders === null ? (error ? null : (
            <div role="status" aria-label={isAr ? 'جاري تحميل الطلبات' : 'Loading orders'}><div className="mega-skel-link" /><div className="mega-skel-link" /></div>
          )) : orders.length === 0 ? (
            <div className="checkout-block">
              <p>
                {isAr ? 'لا توجد طلبات بعد. ' : 'No orders yet. '}
                <Link href="/shop">{isAr ? 'تسوّق الآن' : 'Shop now'}</Link>
              </p>
            </div>
          ) : (
            <>
            {orders.map((o) => (
              <details key={o.id} className="checkout-block account-detail-card group">
                <summary className="flex min-h-11 cursor-pointer list-none items-center gap-3 rounded-sm active:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-slate-700 [&::-webkit-details-marker]:hidden">
                  <span className="min-w-0 flex-1">
                    <span className="block text-sm text-slate-600">{isAr ? 'رقم الطلب' : 'Order number'}</span>
                    <span className="block font-semibold"><bdi>{o.order_number}</bdi></span>
                  </span>
                  <span className="min-w-0 text-end text-sm">
                    <span className="block">{getStatusLabel(isAr ? OrderStatusLabelsAr : ORDER_STATUS_EN, o.status, isAr ? 'قيد التحديث' : 'Updating')}</span>
                    <strong className="block tabular-nums">{formatStorePrice(Number(o.grand_total))}</strong>
                  </span>
                  <ChevronDown aria-hidden="true" className="size-5 shrink-0 group-open:rotate-180 motion-safe:transition-transform" />
                </summary>
                <div className="mt-4 space-y-4 border-t border-slate-200 pt-4">
                  <section>
                    <h2 className="text-sm font-semibold">{isAr ? 'المنتجات' : 'Products'}</h2>
                    {o.items.length ? (
                      <ul className="mt-1 divide-y divide-slate-100">
                        {o.items.map((item) => (
                          <li key={item.id} className="flex items-start justify-between gap-3 py-2 text-sm">
                            <span dir="auto" className="min-w-0">{item.product_name || item.sku || (isAr ? 'منتج' : 'Product')}</span>
                            <span dir="ltr" className="shrink-0 tabular-nums">× {Number(item.qty).toLocaleString(isAr ? 'ar-EG' : 'en-EG')}</span>
                          </li>
                        ))}
                      </ul>
                    ) : <p className="text-sm text-slate-600">{isAr ? 'تفاصيل المنتجات غير متاحة.' : 'Product details are unavailable.'}</p>}
                  </section>
                  <dl className="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                      <dt className="font-semibold">{isAr ? 'عنوان التوصيل' : 'Delivery address'}</dt>
                      <dd className="mt-1 text-slate-600" dir="auto">
                        {[o.shipping_address_line, isAr
                          ? o.shipping_city?.name_ar
                          : o.shipping_city?.name_en || o.shipping_city?.name_ar].filter(Boolean).join(isAr ? '، ' : ', ')
                          || (isAr ? 'العنوان غير متاح.' : 'Address unavailable.')}
                      </dd>
                    </div>
                    <div>
                      <dt className="font-semibold">{isAr ? 'حالة الشحنة' : 'Shipment status'}</dt>
                      <dd className="mt-1 text-slate-600">
                        {o.shipments?.length ? (
                          <ul className="space-y-1">
                            {o.shipments.map((shipment) => (
                              <li key={shipment.id}>{SHIPMENT_STATUS[shipment.status]?.[isAr ? 0 : 1] ?? (isAr ? 'الحالة قيد التحديث' : 'Status updating')}</li>
                            ))}
                          </ul>
                        ) : (isAr ? 'لا توجد بيانات شحن لهذا الطلب.' : 'Shipping details are unavailable.')}
                      </dd>
                    </div>
                  </dl>
                  <div className="checkout-success-actions">
                    <Link href={`/orders/${encodeURIComponent(o.order_number)}/track`}>
                      {isAr ? 'تتبع' : 'Track'}
                    </Link>
                    {o.status === 'delivered' && o.channel !== 'pos' && (
                      <Link href={`/account/returns/new/${encodeURIComponent(o.order_number)}`}>
                        {isAr ? 'طلب إرجاع' : 'Request a return'}
                      </Link>
                    )}
                    {(o.status === 'pending' || o.status === 'confirmed') && (
                      <button
                        type="button"
                        className="bag-remove-btn"
                        disabled={cancelling === o.id}
                        onClick={() => setConfirmTarget(o)}
                      >
                        {cancelling === o.id
                          ? (isAr ? 'جاري الإلغاء...' : 'Cancelling...')
                          : (isAr ? 'إلغاء الطلب' : 'Cancel Order')}
                      </button>
                    )}
                  </div>
                </div>
              </details>
            ))}
            {page < lastPage && (
              <button type="button" className="btn-apply-promo" disabled={loadingMore} onClick={() => { void loadMore(); }}>
                {loadingMore ? (isAr ? 'جاري التحميل...' : 'Loading...') : (isAr ? 'عرض المزيد' : 'Load more')}
              </button>
            )}
            </>
          )}
        </div>
      </section>

      {confirmTarget && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-[2px]"
          onClick={() => { if (cancelling === null) setConfirmTarget(null); }}
        >
          <div
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="cancel-order-dialog-title"
            aria-describedby="cancel-order-dialog-desc"
            className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl border border-slate-200"
            onClick={(e) => e.stopPropagation()}
          >
            <h2 id="cancel-order-dialog-title" className="text-lg font-bold text-slate-900">
              {isAr ? 'تأكيد إلغاء الطلب' : 'Confirm Order Cancellation'}
            </h2>
            <p id="cancel-order-dialog-desc" className="mt-2 text-sm leading-relaxed text-slate-600">
              {isAr
                ? `هل أنت متأكد من إلغاء الطلب ${confirmTarget.order_number}؟ لا يمكن التراجع عن هذا الإجراء.`
                : `Are you sure you want to cancel order ${confirmTarget.order_number}? This action cannot be undone.`}
            </p>
            <div className="mt-6 flex flex-wrap items-center justify-end gap-3">
              <button
                type="button"
                disabled={cancelling !== null}
                onClick={() => setConfirmTarget(null)}
                className="min-h-[42px] rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50"
              >
                {isAr ? 'تراجع' : 'Keep Order'}
              </button>
              <button
                type="button"
                disabled={cancelling !== null}
                onClick={() => { void confirmCancel(); }}
                className="min-h-[42px] rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 transition-colors disabled:opacity-50"
              >
                {cancelling !== null
                  ? (isAr ? 'جاري الإلغاء...' : 'Cancelling...')
                  : (isAr ? 'تأكيد الإلغاء' : 'Yes, Cancel Order')}
              </button>
            </div>
          </div>
        </div>
      )}
    </main>
  );
}
