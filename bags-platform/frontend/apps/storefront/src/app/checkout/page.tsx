'use client';

import React, { Suspense } from 'react';
import { useRouter } from 'next/navigation';
import { SophieCheckoutPage } from '@/components/sophie/checkout/SophieCheckoutPage';
import { SophieFooter } from '@/components/sophie/SophieFooter';

export default function CheckoutPage() {
  const router = useRouter();

  const handleContinueShopping = () => {
    router.push('/shop');
  };

  return (
    <main id="main-content" tabIndex={-1} className="min-h-screen bg-[#f3f5f7] overflow-x-clip">
      <Suspense
        fallback={
          <div className="container" style={{ maxWidth: '1140px', margin: '0 auto', padding: '24px 16px' }} aria-hidden="true">
            <div className="mega-skel-title" style={{ width: '180px', height: '32px', marginBottom: '20px' }} />
            <div className="mega-skel-link" style={{ height: '160px', marginBottom: '16px' }} />
            <div className="mega-skel-link" style={{ height: '160px' }} />
          </div>
        }
      >
        <SophieCheckoutPage onContinueShopping={handleContinueShopping} />
      </Suspense>
      <SophieFooter />
    </main>
  );
}