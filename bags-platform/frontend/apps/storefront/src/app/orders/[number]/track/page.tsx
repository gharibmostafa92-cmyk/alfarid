'use client';

import '@/styles/track-order.css';
import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { checkoutEndpoints, type TrackOrderResponse } from '@alfarid/api-client';
import { useAuthStore } from '@/store/auth.store';
import { getStatusLabel, OrderStatusLabelsAr } from '@alfarid/utils';
import { SophieFooter } from '@/components/sophie/layout/SophieFooter';
import { InstaPayInstructions } from '@/components/sophie/checkout/InstaPayInstructions';



const TRACK_PROGRESS_STEPS = [
  { key: 'pending', label: 'استلام الطلب' },
  { key: 'processing', label: 'التجهيز' },
  { key: 'shipped', label: 'الشحن' },
  { key: 'delivered', label: 'التوصيل' },
];

/**
 * Maps order status to 0-indexed tracking stage.
 * Returns -1 for cancelled or returned orders to suppress forward progress indicators.
 */
function getStepIndex(status: string): number {
  const normalized = status.toLowerCase().trim();
  if (normalized === 'delivered' || normalized === 'completed') return 3;
  if (normalized === 'shipped' || normalized === 'out_for_delivery') return 2;
  if (normalized === 'confirmed' || normalized === 'processing' || normalized === 'preparing') return 1;
  if (normalized === 'cancelled' || normalized === 'returned') return -1;
  return 0;
}


/**
 * Public order tracking page allowing customers and guests to view live order progress.
 * Requires matching order number and phone to prevent unauthorized enumeration of customer PII.
 */
export default function TrackOrderPage() {
  const raw = useParams()?.number;
  const initialRaw = Array.isArray(raw) ? raw[0] : raw ?? '';
  const initial = (() => {
    // External SMS/WhatsApp links may contain unencoded or malformed percent sequences.
    try {
      return decodeURIComponent(initialRaw);
    } catch {
      return initialRaw;
    }
  })();

  const user = useAuthStore((s) => s.user);
  const [number, setNumber] = useState(initial);
  const [phone, setPhone] = useState('');
  const [result, setResult] = useState<TrackOrderResponse | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  // Pre-fill phone from signed-in user or the receipt saved after a successful guest checkout.
  useEffect(() => {
    if (phone) return;
    if (user?.phone && typeof user.phone === 'string') {
      setPhone(user.phone);
      return;
    }
    try {
      const raw = sessionStorage.getItem('last_order');
      const parsed = raw ? (JSON.parse(raw) as { order_number?: string; recipient_phone?: string; expiresAt?: number }) : null;
      if (parsed && !(typeof parsed.expiresAt === 'number' && parsed.expiresAt > Date.now())) {
        sessionStorage.removeItem('last_order');
        return;
      }
      if (parsed?.order_number === initial && typeof parsed.recipient_phone === 'string') {
        setPhone(parsed.recipient_phone);
      }
    } catch {
      /* optional storage */
    }
  }, [user, phone, initial]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!number.trim() || !phone.trim() || busy) return;
    setBusy(true);
    setError('');
    try {
      setResult(await checkoutEndpoints.track(number.trim(), phone.trim()));
    } catch {
      // Uniform error message masks whether the order number or phone mismatched to prevent enumeration.
      setError('لم نلاقي طلب بالرقم ورقم الموبايل دول.');
      setResult(null);
    } finally {
      setBusy(false);
    }
  };

  const activeStepIdx = result ? getStepIndex(result.status) : 0;
  const localizedStatus = result ? getStatusLabel(OrderStatusLabelsAr, result.status, '') : '';

  return (
    <main id="main-content" tabIndex={-1} className="track-order-shell min-h-screen bg-[#f3f5f7]">
      <section className="checkout-wrapper" aria-label="Track order">
        <div className="container track-order-container">
          <div className="track-top-bar">
            <div className="track-title-group">
              <h1 className="checkout-giant-title" style={{ margin: 0 }}>TRACK ORDER</h1>
              <p className="track-subtitle">تابع حالة طلبك خطوة بخطوة برقم الطلب ورقم الموبايل</p>
            </div>
            <Link href="/shop" className="back-to-shop-btn" style={{ minHeight: 44, display: 'inline-flex', alignItems: 'center', gap: 8 }}>
              <i className="fa-solid fa-arrow-left" aria-hidden="true" />
              <span>العودة للتسوق</span>
            </Link>
          </div>

          <form onSubmit={submit} className="checkout-block" noValidate>
            <div className="checkout-fields-grid">
              <div className="line-input-wrap">
                <label htmlFor="track-order-number" className="track-field-label">
                  رقم الطلب
                </label>
                <input
                  id="track-order-number"
                  className="line-input"
                  placeholder="رقم الطلب (مثال: ORD-1024)"
                  dir="ltr"
                  autoComplete="off"
                  enterKeyHint="next"
                  value={number}
                  onChange={(e) => setNumber(e.target.value)}
                  required
                />
              </div>
              <div className="line-input-wrap">
                <label htmlFor="track-order-phone" className="track-field-label">
                  رقم موبايل الطلب
                </label>
                <input
                  id="track-order-phone"
                  className="line-input"
                  placeholder="رقم موبايل الطلب"
                  type="tel"
                  inputMode="tel"
                  autoComplete="tel"
                  enterKeyHint="search"
                  dir="ltr"
                  value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  required
                />
              </div>
            </div>
            <button
              type="submit"
              className="btn-place-order track-submit-btn"
              disabled={busy || !number.trim() || !phone.trim()}
            >
              {busy ? <span className="mega-btn-spinner" aria-hidden="true" /> : null}
              {busy ? 'جاري البحث...' : 'تتبع الطلب'}
            </button>
          </form>

          {error && (
            <div className="checkout-banner is-error" role="alert" style={{ marginBottom: 20 }}>
              <span>{error}</span>
            </div>
          )}

          {result && (
            <div className="checkout-block" role="region" aria-live="polite" aria-label="تفاصيل تتبع الطلب">
              <div className="checkout-block-header" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 10 }}>
                <h2 className="checkout-section-heading" style={{ margin: 0 }} dir="ltr">
                  {result.order_number}
                </h2>
                <span
                  className={`track-status-badge${
                    activeStepIdx === 3 ? ' is-delivered' : activeStepIdx === -1 ? ' is-cancelled' : ''
                  }`}
                >
                  {localizedStatus}
                </span>
              </div>

              {result.instapay && (
                <InstaPayInstructions key={result.order_number} details={result.instapay} />
              )}

              {activeStepIdx >= 0 && (
                <ol className="track-stepper" aria-label="مراحل توصيل الطلب">
                  {TRACK_PROGRESS_STEPS.map((st, idx) => {
                    const isDone = idx < activeStepIdx;
                    const isCurrent = idx === activeStepIdx;
                    return (
                      <li
                        key={st.key}
                        className={`track-step-item${isDone ? ' is-done' : ''}${isCurrent ? ' is-current' : ''}`}
                        aria-current={isCurrent ? 'step' : undefined}
                      >
                        <span className="track-step-dot" aria-hidden="true">
                          {isDone ? '✓' : idx + 1}
                        </span>
                        <span>{st.label}</span>
                      </li>
                    );
                  })}
                </ol>
              )}

              <h3 className="track-sub-heading">المنتجات ({result.items.length})</h3>
              <div className="track-timeline-list">
                {result.items.map((item, i) => (
                  <div key={i} className="bag-totals-row">
                    <span>{item.product_name}</span>
                    <strong dir="ltr">× {item.qty}</strong>
                  </div>
                ))}
              </div>

              {result.status_history.length > 0 && (
                <>
                  <h3 className="track-sub-heading">سجل حالة الطلب</h3>
                  <div className="track-timeline-list">
                    {result.status_history.map((h, i) => (
                      <div key={i} className="bag-totals-row">
                        <span>{getStatusLabel(OrderStatusLabelsAr, h.status)}</span>
                        <span style={{ color: '#6b7280', fontSize: '0.875rem' }}>
                          {h.at ? new Date(h.at).toLocaleDateString('ar-EG', { year: 'numeric', month: 'short', day: 'numeric' }) : ''}
                        </span>
                      </div>
                    ))}
                  </div>
                </>
              )}
            </div>
          )}
        </div>
      </section>
      <SophieFooter />
    </main>
  );
}
