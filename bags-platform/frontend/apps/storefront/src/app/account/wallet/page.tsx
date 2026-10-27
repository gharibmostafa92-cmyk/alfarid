'use client';

import React, { useEffect, useState } from 'react';
import { accountEndpoints } from '@alfarid/api-client';
import { formatStorePrice } from '@alfarid/utils';

export default function AccountWalletPage() {
  const [data, setData] = useState<{ balance: number | string; transactions: Array<{ id: number; type: string; amount: number | string; created_at: string }> } | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    accountEndpoints.wallet().then(setData).catch(() => setError('تعذر تحميل المحفظة.'));
  }, []);

  return (
    <main className="min-h-screen bg-[#f3f5f7]">
      <section className="checkout-wrapper" aria-label="Wallet">
        <div className="container" style={{ maxWidth: '720px', margin: '0 auto', padding: '40px 20px' }}>
          <h1 className="checkout-giant-title">WALLET</h1>
          {error && <div className="checkout-banner is-error" role="alert"><span>{error}</span></div>}
          {data === null ? (
            <div aria-hidden="true"><div className="mega-skel-link" /></div>
          ) : (
            <div className="checkout-block">
              <div className="checkout-block-header">
                <h2 className="checkout-section-heading">الرصيد</h2>
                <strong>{formatStorePrice(Number(data.balance ?? 0))}</strong>
              </div>
              {data.transactions.map((t) => (
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
