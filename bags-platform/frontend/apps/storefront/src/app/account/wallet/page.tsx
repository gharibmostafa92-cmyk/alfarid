'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { accountEndpoints } from '@alfarid/api-client';
import { formatStorePrice } from '@alfarid/utils';

export default function AccountWalletPage() {
  const [data, setData] = useState<{ balance: number | string; transactions: { data: Array<{ id: number; type: string; amount: number | string; created_at: string }> } } | null>(null);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setError('');
    try { setData(await accountEndpoints.wallet().then(r => r as unknown as { balance: number | string; transactions: { data: Array<{ id: number; type: string; amount: number | string; created_at: string }> } })); }
    catch { setError('تعذر تحميل المحفظة.'); }
  }, []);

  useEffect(() => { void load(); }, [load]);
  return (
    <main id="main-content" tabIndex={-1} className="min-h-screen bg-[#f3f5f7]">
      <section className="checkout-wrapper" aria-label="Wallet">
        <div className="container" style={{ maxWidth: '720px', margin: '0 auto', padding: '40px 20px' }}>
          <h1 className="checkout-giant-title">WALLET</h1>
          {error && <div className="checkout-banner is-error" role="alert"><span>{error}</span><button type="button" className="btn-apply-promo" onClick={() => { void load(); }}>إعادة المحاولة</button></div>}
          {data === null ? (error ? null : (
            <div role="status" aria-label="Loading wallet"><div className="mega-skel-link" /></div>
          )) : (
            <div className="checkout-block">
              <div className="checkout-block-header">
                <h2 className="checkout-section-heading">الرصيد</h2>
                <strong>{formatStorePrice(Number(data.balance ?? 0))}</strong>
              </div>
              {(Array.isArray(data.transactions) ? data.transactions : data.transactions.data).map((t) => (
                <div key={t.id} className="bag-totals-row">
                  <span>{t.type} — {new Date(t.created_at).toLocaleDateString('ar-EG')}</span>
                  <strong>{formatStorePrice(Number(t.amount ?? 0))}</strong>
                </div>
              ))}
            </div>
          )}
        </div>
      </section>
    </main>
  );
}
