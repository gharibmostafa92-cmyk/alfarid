'use client';

import React from 'react';
import Link from 'next/link';
import { useAuthStore } from '@/store/auth.store';

export default function AccountPage() {
  const user = useAuthStore((s) => s.user);
  const destination = useAuthStore((s) => s.destination);
  const name = (user?.name as string) || 'My account';
  const email = (user?.email as string) || (user?.phone as string) || '';

  return (
    <main id="main-content" tabIndex={-1} className="min-h-screen bg-[#f3f5f7]">
      <section className="checkout-wrapper" aria-label="My profile">
        <div className="container" style={{ maxWidth: '720px', margin: '0 auto', padding: '40px 20px' }}>
          <h1 className="checkout-giant-title">MY PROFILE</h1>
          <div className="checkout-block">
            <div className="profile-identity" style={{ border: 'none', margin: 0, padding: '6px 0 0' }}>
              <p className="profile-identity-name">{name}</p>
              {email ? <p className="profile-identity-email">{email}</p> : null}
              <p className="profile-identity-wallet">{destination === 'wholesale' ? 'Wholesale account' : 'Member'}</p>
            </div>
          </div>
          <div className="checkout-block">
            <Link href="/account/orders" className="block py-2">My orders →</Link>
            <Link href="/account/wallet" className="block py-2">Wallet →</Link>
            <Link href="/account/returns" className="block py-2">Returns →</Link>
          </div>
        </div>
      </section>
    </main>
  );
}
