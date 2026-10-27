'use client';

import React, { Suspense, useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { accountEndpoints, type StoreOrder } from '@alfarid/api-client';
import { useAuthStore } from '@/store/auth.store';
import { useAuthHydrated } from '@/hooks/use-auth-hydrated';
import { formatStorePrice } from '@alfarid/utils';
import { SophieFooter } from '@/components/sophie/SophieFooter';

const paymentLabels: Record<string, string> = {
  cod: 'الدفع عند الاستلام',
  wallet: 'المحفظة',
  credit: 'آجل',
};

export default function CheckoutSuccessPage() {
  return (
    <Suspense fallback={null}>
      <CheckoutSuccessContent />
    </Suspense>
  );
}

function CheckoutSuccessContent() {
  const router = useRouter();
  const params = useSearchParams();
  const orderNumber = params.get('order') ?? '';
  // sessionStorage survives refresh in the same tab. A new tab without it
  // falls back to the phone-verified track page (track requires the order phone).
  const [order, setOrder] = useState<StoreOrder | null>(null);
  const [loading, setLoading] = useState(true);
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
      if (parsed && parsed.order_number === orderNumber && Array.isArray(parsed.items)
        && Number.isFinite(Number(parsed.grand_total))) receipt = parsed;
    } catch { /* Without storage, signed-in shoppers can recover their own receipt. */ }
    setOrder(receipt);
    if (receipt || !token || !orderNumber) { setLoading(false); return; }
    accountEndpoints.orders().then((orders) => {
      if (active) setOrder(orders.find((o) => o.order_number === orderNumber) ?? null);
    }).catch(() => {}).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [orderNumber, token, hydrated]);

  return (
    <main className="min-h-screen bg-[#f3f5f7]">
      <section className="checkout-wrapper" aria-label="Order success">
        <div className="container" style={{ maxWidth: '720px', margin: '0 auto', padding: '40px 20px' }}>
          {loading ? (
            <div aria-hidden="true">
              <div className="mega-skel-title" style={{ width: '40%' }} />
              <div className="mega-skel-link" />
              <div className="mega-skel-link" />
            </div>
          ) : order ? (
            <div className="order-modal-card" style={{ margin: '0 auto' }}>
              <div className="order-modal-icon">
                <i className="fa-solid fa-check" aria-hidden="true" />
              </div>
              <h1 className="order-modal-title">تم استلام طلبك!</h1>
              <p className="order-modal-desc">
                رقم الطلب <strong>{order.order_number}</strong>
              </p>
              <div className="bag-items-list">
                {order.items.map((item) => (
                  <div key={item.id} className="bag-item-row">
                    <div className="bag-item-info">
                      <h4 className="bag-item-title">{item.product_name}</h4>
                      <div className="bag-item-meta">
                        <span className="meta-label">Quantity:</span>
                        <span className="meta-val">{item.qty}</span>
                      </div>
                    </div>
                    <div className="bag-item-right">
                      <span className="bag-item-price">{formatStorePrice(Number(item.line_total))}</span>
                    </div>
                  </div>
                ))}
              </div>
              <div className="bag-totals-row">
                <span>الإجمالي</span>
                <strong>{formatStorePrice(Number(order.grand_total))}</strong>
              </div>
              <div className="bag-totals-row">
                <span>طريقة الدفع</span>
                <strong>{paymentLabels[order.payment_method] ?? order.payment_method}</strong>
              </div>
              <div className="checkout-success-actions">
                <Link
                  href={`/orders/${encodeURIComponent(order.order_number)}/track`}
                  className="btn-place-order"
                >
                  تتبع طلبك
                </Link>
                <button
                  type="button"
                  className="back-to-shop-btn"
                  onClick={() => router.push('/shop')}
                >
                  كمّل تسوق
                </button>
              </div>
            </div>
          ) : (
            <div className="order-modal-card" style={{ margin: '0 auto', textAlign: 'center' }}>
              <h1 className="order-modal-title">{orderNumber ? `الطلب ${orderNumber}` : 'لا يوجد طلب لعرضه'}</h1>
              <p className="order-modal-desc">{orderNumber ? 'تقدر تتحقق من تفاصيل طلبك برقمه ورقم الموبايل.' : 'اطلب أولاً أو تابع طلبك برقمه ورقم الموبايل.'}</p>
              <div className="checkout-success-actions">
                {orderNumber ? (
                  <Link href={`/orders/${encodeURIComponent(orderNumber)}/track`} className="btn-place-order">تتبع طلبك</Link>
                ) : (
                  <Link href="/shop" className="btn-place-order">تسوّق الآن</Link>
                )}
              </div>
            </div>
          )}
        </div>
      </section>
      <SophieFooter />
    </main>
  );
}
