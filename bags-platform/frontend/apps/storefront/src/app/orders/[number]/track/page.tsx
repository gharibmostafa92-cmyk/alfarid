'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { checkoutEndpoints, type TrackOrderResponse } from '@alfarid/api-client';
import { useAuthStore } from '@/store/auth.store';
import { SophieFooter } from '@/components/sophie/SophieFooter';

const STATUS_LABELS: Record<string, string> = {
  pending: 'قيد المراجعة',
  confirmed: 'تم تأكيد الطلب',
  processing: 'جاري التجهيز',
  shipped: 'خرج للتوصيل',
  out_for_delivery: 'مع المندوب',
  delivered: 'تم التوصيل',
  cancelled: 'ملغي',
  returned: 'مرتجع',
};

const TRACK_PROGRESS_STEPS = [
  { key: 'pending', label: 'استلام الطلب' },
  { key: 'processing', label: 'التجهيز' },
  { key: 'shipped', label: 'الشحن' },
  { key: 'delivered', label: 'التوصيل' },
];

function getStepIndex(status: string): number {
  const normalized = status.toLowerCase().trim();
  if (normalized === 'delivered' || normalized === 'completed') return 3;
  if (normalized === 'shipped' || normalized === 'out_for_delivery') return 2;
  if (normalized === 'confirmed' || normalized === 'processing' || normalized === 'preparing') return 1;
  if (normalized === 'cancelled' || normalized === 'returned') return -1;
  return 0;
}

const TRACK_PAGE_STYLES = `
  .track-order-shell {
    min-height: 100vh;
    min-height: 100dvh;
    background-color: #f3f5f7;
    display: flex;
    flex-direction: column;
    overflow-x: clip;
  }
  .track-order-shell .checkout-wrapper {
    flex: 1;
    padding-bottom: calc(32px + env(safe-area-inset-bottom, 0px));
  }
  .track-order-container {
    width: 100%;
    max-width: 720px;
    margin: 0 auto;
    padding: clamp(20px, 5vw, 44px) clamp(12px, 4vw, 24px);
    box-sizing: border-box;
  }
  .track-top-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
  }
  .track-title-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }
  .track-subtitle {
    margin: 0;
    font-size: 0.925rem;
    color: #4b5563;
    line-height: 1.5;
  }
  .track-order-shell .checkout-block {
    background: #ffffff;
    border-radius: 16px;
    padding: clamp(18px, 4vw, 28px);
    margin-bottom: 20px;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
    border: 1px solid rgba(15, 23, 42, 0.06);
    box-sizing: border-box;
  }
  .track-order-shell .checkout-fields-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
  }
  @media (min-width: 560px) {
    .track-order-shell .checkout-fields-grid {
      grid-template-columns: 1fr 1fr;
    }
  }
  .track-field-label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 6px;
  }
  .track-order-shell .line-input {
    width: 100%;
    min-height: 48px;
    font-size: max(16px, 1rem);
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background: #f9fafb;
    color: #111827;
    box-sizing: border-box;
    transition: border-color 0.15s ease, background-color 0.15s ease, box-shadow 0.15s ease;
  }
  .track-order-shell .line-input:focus {
    outline: none;
    background: #ffffff;
    border-color: #111827;
    box-shadow: 0 0 0 3px rgba(17, 24, 39, 0.08);
  }
  .track-submit-btn {
    width: 100%;
    min-height: 50px;
    margin-top: 18px;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
    touch-action: manipulation;
  }
  .track-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 0.85rem;
    font-weight: 600;
    background: #eff6ff;
    color: #1d4ed8;
  }
  .track-status-badge.is-delivered {
    background: #ecfdf5;
    color: #047857;
  }
  .track-status-badge.is-cancelled {
    background: #fef2f2;
    color: #b91c1c;
  }
  .track-stepper {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin: 20px 0 24px;
    padding: 16px 12px;
    background: #f8fafc;
    border-radius: 12px;
    list-style: none;
  }
  .track-step-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 6px;
    font-size: 0.78rem;
    color: #6b7280;
    font-weight: 500;
  }
  .track-step-dot {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    font-weight: 700;
    background: #e2e8f0;
    color: #475569;
  }
  .track-step-item.is-done {
    color: #047857;
  }
  .track-step-item.is-done .track-step-dot {
    background: #10b981;
    color: #ffffff;
  }
  .track-step-item.is-current {
    color: #111827;
    font-weight: 700;
  }
  .track-step-item.is-current .track-step-dot {
    background: #111827;
    color: #ffffff;
    box-shadow: 0 0 0 4px rgba(17, 24, 39, 0.12);
  }
  .track-sub-heading {
    font-size: 0.9rem;
    font-weight: 700;
    color: #374151;
    margin: 20px 0 10px;
    padding-bottom: 6px;
    border-bottom: 1px solid #e5e7eb;
  }
  .track-timeline-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .track-order-shell .bag-totals-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.95rem;
  }
  .track-order-shell .bag-totals-row:last-child {
    border-bottom: none;
  }
`;

export default function TrackOrderPage() {
  const raw = useParams()?.number;
  const initialRaw = Array.isArray(raw) ? raw[0] : raw ?? '';
  const initial = (() => {
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

  // Pre-fill phone from signed-in user or recent sessionStorage order receipt for one-handed convenience
  useEffect(() => {
    if (phone) return;
    if (user?.phone && typeof user.phone === 'string') {
      setPhone(user.phone);
      return;
    }
    try {
      const pendingRaw = sessionStorage.getItem('pending_checkout');
      if (pendingRaw) {
        const parsedPending = JSON.parse(pendingRaw);
        if (parsedPending?.body?.recipient_phone) {
          setPhone(parsedPending.body.recipient_phone);
        }
      }
    } catch {
      /* optional storage */
    }
  }, [user, phone]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!number.trim() || !phone.trim() || busy) return;
    setBusy(true);
    setError('');
    try {
      setResult(await checkoutEndpoints.track(number.trim(), phone.trim()));
    } catch {
      setError('لم نلاقي طلب بالرقم ورقم الموبايل دول.');
      setResult(null);
    } finally {
      setBusy(false);
    }
  };

  const activeStepIdx = result ? getStepIndex(result.status) : 0;
  const localizedStatus = result ? (STATUS_LABELS[result.status.toLowerCase()] ?? result.status) : '';

  return (
    <main id="main-content" tabIndex={-1} className="track-order-shell min-h-screen bg-[#f3f5f7]">
      <style dangerouslySetInnerHTML={{ __html: TRACK_PAGE_STYLES }} />
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
                        <span>{STATUS_LABELS[h.status.toLowerCase()] ?? h.status}</span>
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