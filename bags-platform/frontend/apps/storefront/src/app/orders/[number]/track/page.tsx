'use client';

import React, { useState } from 'react';
import { useParams } from 'next/navigation';
import { checkoutEndpoints, type TrackOrderResponse } from '@alfarid/api-client';

export default function TrackOrderPage() {
  const raw = useParams()?.number;
  const initial = Array.isArray(raw) ? raw[0] : raw ?? '';
  const [number, setNumber] = useState(initial);
  const [phone, setPhone] = useState('');
  const [result, setResult] = useState<TrackOrderResponse | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

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

  return (
    <main className="min-h-screen bg-[#f3f5f7]">
      <section className="checkout-wrapper" aria-label="Track order">
        <div className="container" style={{ maxWidth: '720px', margin: '0 auto', padding: '40px 20px' }}>
          <h1 className="checkout-giant-title">TRACK ORDER</h1>
          <form onSubmit={submit} className="checkout-block">
            <div className="checkout-fields-grid">
              <div className="line-input-wrap">
                <input className="line-input" placeholder="رقم الطلب" value={number} onChange={(e) => setNumber(e.target.value)} required />
              </div>
              <div className="line-input-wrap">
                <input className="line-input" placeholder="رقم موبايل الطلب" inputMode="tel" autoComplete="tel" dir="ltr" value={phone} onChange={(e) => setPhone(e.target.value)} required />
              </div>
            </div>
            <button type="submit" className="btn-place-order" disabled={busy} style={{ marginTop: 16 }}>
              {busy ? 'جاري البحث...' : 'تتبع الطلب'}
            </button>
          </form>
          {error && <div className="checkout-banner is-error" role="alert"><span>{error}</span></div>}
          {result && (
            <div className="checkout-block">
              <div className="checkout-block-header">
                <h2 className="checkout-section-heading">{result.order_number}</h2>
                <span>{result.status}</span>
              </div>
              {result.items.map((item, i) => (
                <div key={i} className="bag-totals-row">
                  <span>{item.product_name}</span>
                  <strong>× {item.qty}</strong>
                </div>
              ))}
              {result.status_history.map((h, i) => (
                <div key={i} className="bag-totals-row">
                  <span>{h.status}</span>
                  <span>{h.at ? new Date(h.at).toLocaleDateString('ar-EG') : ''}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      </section>
    </main>
  );
}
