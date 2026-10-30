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

const SUCCESS_RESPONSIVE_STYLES = `
  .checkout-success-shell {
    min-height: 100vh;
    min-height: 100dvh;
    background-color: #f3f5f7;
    display: flex;
    flex-direction: column;
    overflow-x: clip;
  }
  .checkout-success-shell .checkout-wrapper {
    flex: 1;
    padding-bottom: calc(96px + env(safe-area-inset-bottom, 0px));
  }
  .checkout-success-container {
    width: 100%;
    max-width: 720px;
    margin: 0 auto;
    padding: clamp(20px, 5vw, 48px) clamp(12px, 4vw, 24px);
    box-sizing: border-box;
  }
  .checkout-success-shell .order-modal-card {
    background: #ffffff;
    border-radius: 16px;
    padding: clamp(20px, 5vw, 36px);
    box-shadow: 0 8px 30px rgba(15, 23, 42, 0.06);
    border: 1px solid rgba(15, 23, 42, 0.07);
    box-sizing: border-box;
    width: 100%;
  }
  .checkout-success-shell .order-modal-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: #ecfdf5;
    color: #059669;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin: 0 auto 16px;
  }
  .checkout-success-shell .order-modal-title {
    font-size: clamp(1.35rem, 4vw, 1.75rem);
    font-weight: 700;
    color: #111827;
    text-align: center;
    margin: 0 0 8px;
    line-height: 1.35;
  }
  .checkout-success-shell .order-modal-desc {
    color: #4b5563;
    text-align: center;
    font-size: 0.975rem;
    line-height: 1.6;
    margin: 0 0 20px;
  }
  .order-number-pill-row {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: 10px;
    margin: 0 0 24px;
    padding: 12px 16px;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 12px;
  }
  .order-number-value {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: 0.03em;
  }
  .order-copy-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 40px;
    padding: 6px 14px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #1e293b;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 0.15s ease, border-color 0.15s ease;
    touch-action: manipulation;
  }
  .order-copy-btn:hover {
    background: #f1f5f9;
  }
  .order-copy-btn:focus-visible {
    outline: 2px solid #111827;
    outline-offset: 2px;
  }
  .order-copy-btn.is-copied {
    background: #ecfdf5;
    border-color: #a7f3d0;
    color: #047857;
  }
  .checkout-success-shell .bag-items-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 20px;
    border-top: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
    padding: 16px 0;
  }
  .checkout-success-shell .bag-item-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
  }
  .checkout-success-shell .bag-item-title {
    font-size: 0.975rem;
    font-weight: 600;
    color: #111827;
    margin: 0 0 4px;
    line-height: 1.4;
    word-break: break-word;
  }
  .checkout-success-shell .bag-item-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.875rem;
    color: #6b7280;
  }
  .checkout-success-shell .bag-totals-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 0;
    font-size: 0.975rem;
    color: #374151;
  }
  .checkout-success-shell .bag-totals-row.is-grand {
    border-top: 1px solid #e5e7eb;
    margin-top: 4px;
    padding-top: 14px;
    font-size: 1.1rem;
    color: #111827;
  }
  .checkout-success-actions {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 24px;
  }
  .checkout-success-actions .btn-place-order,
  .checkout-success-actions .back-to-shop-btn {
    width: 100%;
    min-height: 50px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 600;
    text-decoration: none;
    box-sizing: border-box;
    cursor: pointer;
    touch-action: manipulation;
  }
  .checkout-success-actions .back-to-shop-btn {
    background: #f8fafc;
    color: #1f2937;
    border: 1px solid #d1d5db;
  }
  @media (min-width: 540px) {
    .checkout-success-actions {
      flex-direction: row;
    }
    .checkout-success-actions .btn-place-order,
    .checkout-success-actions .back-to-shop-btn {
      flex: 1;
    }
  }
  @media (max-width: 539px) {
    .checkout-success-sticky-mobile {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      z-index: 40;
      background: rgba(255, 255, 255, 0.96);
      backdrop-filter: blur(10px);
      border-top: 1px solid rgba(15, 23, 42, 0.08);
      padding: 12px 16px calc(12px + env(safe-area-inset-bottom, 0px));
      display: flex;
      gap: 10px;
      box-shadow: 0 -4px 20px rgba(15, 23, 42, 0.06);
    }
    .checkout-success-sticky-mobile .btn-place-order,
    .checkout-success-sticky-mobile .back-to-shop-btn {
      min-height: 48px;
      border-radius: 10px;
      font-size: 0.95rem;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      touch-action: manipulation;
    }
    .checkout-success-sticky-mobile .btn-place-order {
      flex: 1.4;
    }
    .checkout-success-sticky-mobile .back-to-shop-btn {
      flex: 1;
      background: #f8fafc;
      color: #1f2937;
      border: 1px solid #d1d5db;
    }
  }
  @media (min-width: 540px) {
    .checkout-success-sticky-mobile {
      display: none;
    }
  }
`;

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
  const [copied, setCopied] = useState(false);
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
      .then((orders) => {
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

  const activeOrderNum = order?.order_number || orderNumber;

  return (
    <main id="main-content" tabIndex={-1} className="checkout-success-shell min-h-screen bg-[#f3f5f7]">
      <style dangerouslySetInnerHTML={{ __html: SUCCESS_RESPONSIVE_STYLES }} />
      <section className="checkout-wrapper" aria-label="Order success">
        <div className="container checkout-success-container">
          {loading ? (
            <div className="order-modal-card" aria-hidden="true" style={{ margin: '0 auto' }}>
              <div className="mega-skel-title" style={{ width: '45%', height: 28, margin: '0 auto 16px' }} />
              <div className="mega-skel-link" style={{ height: 48, marginBottom: 16 }} />
              <div className="mega-skel-link" style={{ height: 64, marginBottom: 12 }} />
              <div className="mega-skel-link" style={{ height: 48 }} />
            </div>
          ) : order ? (
            <div className="order-modal-card" style={{ margin: '0 auto' }} role="region" aria-live="polite">
              <div className="order-modal-icon">
                <i className="fa-solid fa-check" aria-hidden="true" />
              </div>
              <h1 className="order-modal-title">تم استلام طلبك بنجاح!</h1>
              <p className="order-modal-desc">
                شكراً لثقتك. يمكنك الاحتفاظ برقم الطلب لمتابعة حالة الشحنة في أي وقت.
              </p>

              <div className="order-number-pill-row">
                <span>رقم الطلب:</span>
                <strong className="order-number-value" dir="ltr">{order.order_number}</strong>
                <button
                  type="button"
                  className={`order-copy-btn${copied ? ' is-copied' : ''}`}
                  onClick={() => handleCopyOrderNumber(order.order_number)}
                  aria-label="نسخ رقم الطلب"
                >
                  <i className={`fa-regular ${copied ? 'fa-circle-check' : 'fa-copy'}`} aria-hidden="true" />
                  <span>{copied ? 'تم النسخ' : 'نسخ الرقم'}</span>
                </button>
              </div>

              <div className="bag-items-list" aria-label="المنتجات المطلوبة">
                {order.items.map((item) => (
                  <div key={item.id} className="bag-item-row">
                    <div className="bag-item-info">
                      <h4 className="bag-item-title">{item.product_name}</h4>
                      <div className="bag-item-meta">
                        <span className="meta-label">الكمية:</span>
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
                <span>طريقة الدفع</span>
                <strong>{paymentLabels[order.payment_method] ?? order.payment_method}</strong>
              </div>
              <div className="bag-totals-row is-grand">
                <span>الإجمالي</span>
                <strong>{formatStorePrice(Number(order.grand_total))}</strong>
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
              <div className="order-modal-icon" style={{ background: '#f1f5f9', color: '#334155' }}>
                <i className="fa-solid fa-box-open" aria-hidden="true" />
              </div>
              <h1 className="order-modal-title">
                {orderNumber ? `الطلب ${orderNumber}` : 'لا يوجد طلب لعرضه'}
              </h1>
              <p className="order-modal-desc">
                {orderNumber
                  ? 'تقدر تتحقق من تفاصيل طلبك برقمه ورقم الموبايل.'
                  : 'اطلب أولاً أو تابع طلبك برقمه ورقم الموبايل.'}
              </p>
              <div className="checkout-success-actions">
                {orderNumber ? (
                  <>
                    <Link
                      href={`/orders/${encodeURIComponent(orderNumber)}/track`}
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
                  </>
                ) : (
                  <Link href="/shop" className="btn-place-order">
                    تسوّق الآن
                  </Link>
                )}
              </div>
            </div>
          )}
        </div>
      </section>

      {/* One-handed mobile sticky action bar */}
      {!loading && (
        <div className="checkout-success-sticky-mobile" role="region" aria-label="إجراءات سريعة">
          {activeOrderNum ? (
            <>
              <Link
                href={`/orders/${encodeURIComponent(activeOrderNum)}/track`}
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
            </>
          ) : (
            <Link href="/shop" className="btn-place-order">
              تسوّق الآن
            </Link>
          )}
        </div>
      )}

      <SophieFooter />
    </main>
  );
}