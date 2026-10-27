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
    <main className="min-h-screen bg-[#f3f5f7]">
      <Suspense fallback={null}>
        <SophieCheckoutPage onContinueShopping={handleContinueShopping} />
      </Suspense>
      <SophieFooter />
    </main>
  );
}
