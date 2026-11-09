'use client';

import React, { Suspense, useEffect, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { accountEndpoints, checkoutEndpoints, type StoreOrder, type TrackOrderResponse } from '@alfarid/api-client';
import { useBranding } from '@alfarid/ui/branding';
import { useAuthStore } from '@/store/auth.store';
import { useAuthHydrated } from '@/hooks/use-auth-hydrated';
import { formatStorePrice, getStatusLabel, OrderStatusLabelsAr, PaymentMethodLabelsAr, PaymentStatusLabelsAr } from '@alfarid/utils';
import { SophieFooter } from '@/components/sophie/layout/SophieFooter';
import { InstaPayInstructions } from '@/components/sophie/checkout/InstaPayInstructions';
import { StoreImage } from '@/components/StoreImage';

export default function CheckoutSuccessPage() {
  return (
    <Suspense fallback={null}>
      <CheckoutSuccessContent />
    </Suspense>
  );
}
function CheckoutSuccessContent() {
  const params = useSearchParams();
  const orderNumber = params.get('order') ?? '';
  // sessionStorage survives refresh in the same tab. A new tab without it
  // falls back to the phone-verified track page (track requires the order phone).
  const [order, setOrder] = useState<StoreOrder | null>(null);
  const [loading, setLoading] = useState(true);
  const [copied, setCopied] = useState(false);
  const [transfer, setTransfer] = useState<{ orderNumber: string; details: NonNullable<TrackOrderResponse['instapay']> } | null>(null);
  const token = useAuthStore((s) => s.token);
  const hydrated = useAuthHydrated();

  useEffect(() => {
    if (!hydrated) return;
    let active = true;
    setLoading(true);
    let receipt: StoreOrder | null = null;
    try {
      const raw = sessionStorage.getItem('last_order');
      const parsed = raw ? (JSON.parse(raw) as StoreOrder) : null;
      if (
        parsed &&
        parsed.order_number === orderNumber &&
        Array.isArray(parsed.items) &&
        Number.isFinite(Number(parsed.grand_total))
      ) {
        receipt = parsed;
      }
    } catch {
      /* Without storage, signed-in shoppers can recover their own receipt. */
    }
    setOrder(receipt);
    if (receipt || !token || !orderNumber) {
      setLoading(false);
      return;
    }
    accountEndpoints
      .orders()
      .then(({ data: orders }) => {
        if (active) setOrder(orders.find((o) => o.order_number === orderNumber) ?? null);
      })
      .catch(() => {})
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [orderNumber, token, hydrated]);

  const handleCopyOrderNumber = async (num: string) => {
    if (!num) return;
    try {
      await navigator.clipboard.writeText(num);
      setCopied(true);
      setTimeout(() => setCopied(false), 2200);
    } catch {
      /* Ignore clipboard permission errors on restricted webviews */
    }
  };

  useEffect(() => {
    if (!order || order.order_number !== orderNumber || order.payment_method !== 'bank_transfer' || !order.recipient_phone) return;
    let active = true;
    checkoutEndpoints.track(orderNumber, order.recipient_phone).then(({ instapay }) => {
      if (active) setTransfer(instapay ? { orderNumber, details: instapay } : null);
    }).catch(() => {});
    return () => {
      active = false;
    };
  }, [order, orderNumber]);
  const instapay = transfer?.orderNumber === orderNumber ? transfer.details : null;

  const visibleOrder = order?.order_number === orderNumber ? order : null;
  const [failedImage, setFailedImage] = useState<string | null>(null);
  const { branding } = useBranding();
  const photographed = (visibleOrder?.items ?? []).filter((item) => item.image).sort((a, b) => a.id - b.id);
  // Seed the selection with the order number so the photo stays the same on reload.
  const seed = Array.from(orderNumber).reduce((hash, char) => Math.imul(hash ^ char.charCodeAt(0), 16777619), 2166136261) >>> 0;
  const featured = photographed.length ? photographed[seed % photographed.length] : undefined;
  const image = featured?.image;
  const showImage = image && image !== failedImage;
  const status = visibleOrder?.status ?? '';
  const stage = ['pending', 'confirmed', 'processing', 'shipped', 'delivered'].indexOf(status);
  const payment = visibleOrder?.payment_status ?? '';
  const paymentLabel = payment === 'unpaid' && visibleOrder?.payment_method === 'cod'
    ? 'عند الاستلام'
    : getStatusLabel(PaymentStatusLabelsAr, payment);
  const trackingUrl = orderNumber ? `/orders/${encodeURIComponent(orderNumber)}/track` : null;

  return (
    <main id="main-content" tabIndex={-1} className="min-h-screen bg-[radial-gradient(ellipse_at_top,#e8e6e3,#cfcdca)]">
      <div className="px-4 py-8 sm:px-6 sm:py-12 lg:py-16">
        {loading || !visibleOrder ? (
          <section dir="rtl" className="mx-auto max-w-lg rounded-2xl bg-[#f4f4f2] p-8 text-center text-neutral-900" aria-busy={loading}>
            {loading ? <p role="status">بنحمّل تفاصيل الطلب…</p> : (
              <>
                <h1 className="text-xl font-bold">{orderNumber ? `الطلب ${orderNumber}` : 'لا يوجد طلب لعرضه'}</h1>
                {trackingUrl && <p className="mt-2 text-sm text-neutral-600">تقدر تتحقق من طلبك برقمه ورقم الموبايل.</p>}
                <Link className="mt-5 inline-flex rounded-lg bg-neutral-900 px-5 py-3 text-sm font-semibold text-white" href={trackingUrl ?? '/shop'}>
                  {trackingUrl ? 'تتبع طلبك' : 'تسوّق الآن'}
                </Link>
              </>
            )}
          </section>
        ) : (
          <article className="mx-auto grid max-w-[920px] overflow-hidden rounded-[18px] bg-[#f4f4f2] shadow-[0_24px_65px_-18px_rgba(43,33,24,0.38)] md:grid-cols-2" dir="ltr">
            <figure className="relative isolate flex min-h-[240px] flex-col justify-between overflow-hidden bg-[#d8c3a5] p-5 sm:min-h-[320px] sm:p-7 md:min-h-[610px]">
              {showImage && (
                <StoreImage src={image} alt={featured.product_name}
                  className="absolute inset-0 -z-10 h-full w-full object-cover"
                  onError={() => setFailedImage(image)} />
              )}
              <div className="self-start rounded-full bg-[#f6f3ef]/90 px-4 py-2 font-serif text-xl font-semibold text-[#2b2118]">
                {branding.name}
              </div>
              {showImage && (
                <figcaption dir="auto" className="self-start rounded-2xl bg-[#f6f3ef]/90 px-4 py-2 text-sm font-medium text-[#2b2118]">
                  {featured.product_name}
                </figcaption>
              )}
            </figure>

            <section dir="rtl" className="min-w-0 p-5 text-neutral-900 sm:p-8">
              <header className="mb-5 text-center">
                <span aria-hidden="true" className="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-emerald-100 text-xl text-emerald-800">✓</span>
                <h1 className="text-2xl font-bold">تم استلام طلبك بنجاح!</h1>
                {visibleOrder.recipient_name && <p className="mt-1 text-sm text-neutral-600">شكرًا، {visibleOrder.recipient_name}.</p>}
              </header>

              <div className="rounded-2xl bg-white p-4 shadow-sm sm:p-5">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 pb-3">
                  <div className="min-w-0">
                    <p className="text-xs text-neutral-600">رقم الطلب</p>
                    <strong dir="ltr" className="block break-all text-sm">{visibleOrder.order_number}</strong>
                  </div>
                  <button type="button" onClick={() => void handleCopyOrderNumber(visibleOrder.order_number)}
                    className="rounded-lg bg-neutral-100 px-3 py-2 text-xs font-semibold hover:bg-neutral-200" aria-label="نسخ رقم الطلب">
                    {copied ? 'تم النسخ' : 'نسخ الرقم'}
                  </button>
                </div>

                <dl className="my-4 grid grid-cols-2 gap-3 text-sm">
                  <div><dt className="text-xs text-neutral-600">الإجمالي</dt><dd className="font-semibold">{formatStorePrice(Number(visibleOrder.grand_total))}</dd></div>
                  <div><dt className="text-xs text-neutral-600">حالة الدفع</dt><dd className="font-semibold">{paymentLabel}</dd></div>
                  <div><dt className="text-xs text-neutral-600">طريقة الدفع</dt><dd>{getStatusLabel(PaymentMethodLabelsAr, visibleOrder.payment_method)}</dd></div>
                  <div><dt className="text-xs text-neutral-600">حالة الطلب</dt><dd>{getStatusLabel(OrderStatusLabelsAr, status)}</dd></div>
                </dl>

                {stage >= 0 && (
                  <ol aria-label="مراحل الطلب" className="mb-4 space-y-2 border-y border-neutral-100 py-3 text-sm">
                    {['استلام الطلب', 'التجهيز', 'الشحن', 'التسليم'].map((label, index) => {
                      const current = stage < 2 ? 0 : stage - 1;
                      return (
                        <li key={label} aria-current={index === current ? 'step' : undefined} className="flex items-center gap-2">
                          <span aria-hidden="true" className={`h-2.5 w-2.5 rounded-full border ${index <= current ? 'border-emerald-700 bg-emerald-700' : 'border-neutral-400'}`} />
                          <span className={index === current ? 'font-semibold' : 'text-neutral-600'}>{label}</span>
                        </li>
                      );
                    })}
                  </ol>
                )}

                <details open>
                  <summary className="cursor-pointer text-sm font-semibold">المنتجات المطلوبة ({visibleOrder.items.length})</summary>
                  <ul className="mt-2 divide-y divide-neutral-100">
                    {visibleOrder.items.map((item) => (
                      <li key={item.id} className="flex items-start justify-between gap-3 py-2 text-sm">
                        <div className="min-w-0"><p className="break-words">{item.product_name}</p><p className="text-xs text-neutral-600">الكمية {Number(item.qty)}</p></div>
                        <span className="shrink-0">{formatStorePrice(Number(item.line_total))}</span>
                      </li>
                    ))}
                  </ul>
                </details>
              </div>

              {instapay && <InstaPayInstructions key={instapay.expires_at} details={instapay} className="mt-4 space-y-2 rounded-xl bg-[#e9e9e6] p-4 text-sm" />}

              <div className="mt-4 grid gap-2 text-center text-sm font-semibold">
                {trackingUrl && <Link href={trackingUrl} className="rounded-lg bg-neutral-900 px-4 py-3 text-white hover:bg-neutral-800">تتبع طلبك</Link>}
                <Link href="/shop" className="rounded-lg border border-neutral-300 px-4 py-3 hover:bg-white">كمّل تسوق</Link>
              </div>
            </section>
          </article>
        )}
      </div>
      <SophieFooter />
    </main>
  );
}
