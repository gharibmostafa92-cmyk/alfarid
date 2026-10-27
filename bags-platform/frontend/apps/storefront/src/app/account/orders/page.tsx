'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { accountEndpoints, type StoreOrder } from '@alfarid/api-client';
import { formatStorePrice } from '@alfarid/utils';

export default function AccountOrdersPage() {
  const [orders, setOrders] = useState<StoreOrder[] | null>(null);
  const [error, setError] = useState('');
  const [cancelling, setCancelling] = useState<number | null>(null);

  useEffect(() => {
    accountEndpoints.orders().then(setOrders).catch(() => setError('تعذر تحميل الطلبات.'));
  }, []);

  const cancel = async (id: number) => {
    setCancelling(id);
    try {
      const updated = await accountEndpoints.cancelOrder(id);
      setOrders((list) => (list ?? []).map((o) => (o.id === id ? updated : o)));
    } catch {
      setError('تعذر إلغاء الطلب في حالته الحالية.');
    } finally {
      setCancelling(null);
    }
  };

  return (
    <main className="min-h-screen bg-[#f3f5f7]">
      <section className="checkout-wrapper" aria-label="My orders">
        <div className="container" style={{ maxWidth: '720px', margin: '0 auto', padding: '40px 20px' }}>
          <h1 className="checkout-giant-title">MY ORDERS</h1>
          {error && <div className="checkout-banner is-error" role="alert"><span>{error}</span></div>}
          {orders === null ? (
            <div aria-hidden="true"><div className="mega-skel-link" /><div className="mega-skel-link" /></div>
          ) : orders.length === 0 ? (
            <div className="checkout-block"><p>لا توجد طلبات بعد. <Link href="/shop">تسوّق الآن</Link></p></div>
          ) : (
            orders.map((o) => (
              <div key={o.id} className="checkout-block">
                <div className="checkout-block-header">
                  <h2 className="checkout-section-heading">{o.order_number}</h2>
                  <span>{o.status}</span>
                </div>
                <div className="bag-totals-row"><span>الإجمالي</span><strong>{formatStorePrice(Number(o.grand_total))}</strong></div>
                <div className="checkout-success-actions">
                  <Link href={`/orders/${encodeURIComponent(o.order_number)}/track`}>تتبع</Link>
                  {(o.status === 'pending' || o.status === 'confirmed') && (
                    <button type="button" className="bag-remove-btn" disabled={cancelling === o.id} onClick={() => cancel(o.id)}>
                      {cancelling === o.id ? 'جاري الإلغاء...' : 'إلغاء الطلب'}
                    </button>
                  )}
                </div>
              </div>
            ))
          )}
        </div>
      </section>
    </main>
  );
}
