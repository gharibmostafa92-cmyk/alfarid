'use client';

import React, { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { accountEndpoints } from '@alfarid/api-client';
import { formatStorePrice } from '@alfarid/utils';

type WalletTx = {
  id: number;
  type: string;
  type_label?: string | null;
  amount: number | string;
  balance_after?: number | string | null;
  reference_type?: string | null;
  reference_id?: number | null;
  reference_label?: string | null;
  created_at: string;
};

type WalletData = {
  balance: number | string;
  transactions: { data: WalletTx[]; current_page?: number; last_page?: number } | WalletTx[];
};

export default function AccountWalletPage() {
  const [data, setData] = useState<WalletData | null>(null);
  const [page, setPage] = useState(1);
  const [error, setError] = useState('');

  const load = useCallback(async (p: number) => {
    setError('');
    try { setData(await accountEndpoints.wallet(p) as unknown as WalletData); }
    catch { setError('تعذر تحميل المحفظة.'); }
  }, []);

  useEffect(() => { void load(page); }, [load, page]);

  const list = data === null ? [] : Array.isArray(data.transactions) ? data.transactions : data.transactions.data;
  const lastPage = data !== null && !Array.isArray(data.transactions) ? (data.transactions.last_page ?? 1) : 1;

  return (
    <main id="main-content" tabIndex={-1} className="min-h-screen bg-[#f3f5f7]">
      <section className="checkout-wrapper" aria-label="Wallet">
        <div className="container" style={{ maxWidth: '720px', margin: '0 auto', padding: '40px 20px' }}>
          <h1 className="checkout-giant-title">WALLET</h1>
          {error && <div className="checkout-banner is-error" role="alert"><span>{error}</span><button type="button" className="btn-apply-promo" onClick={() => { void load(page); }}>إعادة المحاولة</button></div>}
          {data === null ? (error ? null : (
            <div role="status" aria-label="Loading wallet"><div className="mega-skel-link" /><div className="mega-skel-link" /></div>
          )) : (
            <>
              <div className="checkout-block">
                <div className="checkout-block-header">
                  <h2 className="checkout-section-heading">الرصيد</h2>
                  <strong>{formatStorePrice(Number(data.balance ?? 0))}</strong>
                </div>
              </div>
              <div className="checkout-block">
                <div className="checkout-block-header">
                  <h2 className="checkout-section-heading">الحركة</h2>
                </div>
                {list.length === 0 ? (
                  <p role="status">لا توجد حركات في المحفظة بعد.</p>
                ) : list.map((t) => {
                  const value = Number(t.amount ?? 0);
                  const tone = value < 0 ? 'wallet-debit' : value > 0 ? 'wallet-credit' : '';
                  return (
                    <div key={t.id} className="bag-totals-row">
                      <span>
                        {t.type_label || t.type} — {new Date(t.created_at).toLocaleDateString('ar-EG')}
                        {t.reference_label && (
                          t.reference_type === 'order' && t.reference_id ? (
                            <> · <Link href="/account/orders">{t.reference_label}</Link></>
                          ) : (
                            <> · {t.reference_label}</>
                          )
                        )}
                      </span>
                      <strong className={tone}>{formatStorePrice(value)}</strong>
                    </div>
                  );
                })}
                {lastPage > 1 && (
                  <nav className="checkout-success-actions" aria-label="ترقيم صفحات المحفظة">
                    <button type="button" className="btn-apply-promo" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>السابق</button>
                    <span role="status">صفحة {page} من {lastPage}</span>
                    <button type="button" className="btn-apply-promo" disabled={page >= lastPage} onClick={() => setPage((p) => Math.min(lastPage, p + 1))}>التالي</button>
                  </nav>
                )}
              </div>
            </>
          )}
        </div>
      </section>
    </main>
  );
}
