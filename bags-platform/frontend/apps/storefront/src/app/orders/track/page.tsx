'use client';

import { useEffect, useState, type FormEvent } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { guestOrdersKey, readGuestOrders } from '@/lib/orders/guest-orders';

const trackUrl = (number: string) => `/orders/${encodeURIComponent(number)}/track`;

/**
 * Order lookup landing page.
 * Displays recently placed guest orders stored in localStorage, synchronizing across open browser tabs.
 */
export default function GuestOrderTrackingPage() {
  const router = useRouter();
  const [orders, setOrders] = useState<string[] | null>(null);
  const [number, setNumber] = useState('');

  useEffect(() => {
    setOrders(readGuestOrders());
    const sync = (event: StorageEvent) => {
      if (event.key === guestOrdersKey || event.key === null) setOrders(readGuestOrders());
    };
    window.addEventListener('storage', sync);
    return () => window.removeEventListener('storage', sync);
  }, []);

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (number.trim()) router.push(trackUrl(number.trim()));
  }

  return (
    <main id="main-content" dir="rtl" className="mx-auto min-h-[70vh] max-w-xl px-4 py-10 text-neutral-900">
      <h1 className="text-2xl font-bold">تتبّع طلبك</h1>
      <p className="mt-2 text-sm text-neutral-600">اختار رقم طلب محفوظ على المتصفح ده، أو اكتبه يدويًا. هتحتاج رقم الموبايل اللي طلبت بيه.</p>
      <form onSubmit={submit} className="my-6 space-y-2">
        <label htmlFor="tracking-order-number" className="block text-sm font-semibold">رقم الطلب</label>
        <div className="flex gap-2">
          <input id="tracking-order-number" dir="ltr" value={number} onChange={(event) => setNumber(event.target.value)}
            required maxLength={100} className="min-w-0 flex-1 rounded-lg border border-neutral-300 px-3 py-2" />
          <button type="submit" className="shrink-0 rounded-lg bg-neutral-900 px-4 py-2 text-sm text-white">تتبّع</button>
        </div>
      </form>
      <h2 className="border-b border-neutral-200 pb-3 text-base font-semibold">أرقام طلباتك المحفوظة</h2>
      {orders?.length === 0 && <p className="py-4 text-sm text-neutral-600">مفيش أرقام طلبات محفوظة على المتصفح ده.</p>}
      <ul className="divide-y divide-neutral-200">
        {orders?.map((saved) => (
          <li key={saved}>
            <Link href={trackUrl(saved)} className="flex justify-between gap-3 py-3 text-sm hover:underline">
              <bdi>{saved}</bdi><span>تتبّع الطلب</span>
            </Link>
          </li>
        ))}
      </ul>
    </main>
  );
}
