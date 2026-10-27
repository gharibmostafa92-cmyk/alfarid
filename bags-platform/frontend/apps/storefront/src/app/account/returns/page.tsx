'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { accountEndpoints } from '@alfarid/api-client';

export default function AccountReturnsPage() {
  const [items, setItems] = useState<Array<{ id: number; status: string; created_at: string }> | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    accountEndpoints.returns().then(setItems).catch(() => setError('تعذر تحميل المرتجعات.'));
  }, []);

  return (
    <main className="min-h-screen bg-[#f3f5f7]">
      <section className="checkout-wrapper" aria-label="Returns">
        <div className="container" style={{ maxWidth: '720px', margin: '0 auto', padding: '40px 20px' }}>
          <h1 className="checkout-giant-title">RETURNS</h1>
          {error && <div className="checkout-banner is-error" role="alert"><span>{error}</span></div>}
          {items === null ? (
            <div aria-hidden="true"><div className="mega-skel-link" /></div>
          ) : items.length === 0 ? (
            <div className="checkout-block"><p>لا توجد طلبات إرجاع. <Link href="/account/orders">طلباتي</Link></p></div>
          ) : (
            items.map((r) => (
              <div key={r.id} className="checkout-block">
                <div className="checkout-block-header">
                  <h2 className="checkout-section-heading">طلب إرجاع #{r.id}</h2>
                  <span>{r.status}</span>
                </div>
              </div>
            ))
          )}
        </div>
      </section>
    </main>
  );
}
