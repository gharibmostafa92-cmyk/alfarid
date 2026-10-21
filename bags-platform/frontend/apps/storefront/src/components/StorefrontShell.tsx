'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { AccountMenu } from './auth/AccountMenu';

export function StorefrontShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();

  if (pathname.startsWith('/auth/') || pathname === '/wholesale/register') return <>{children}</>;

  return <>
    <header className="border-b border-slate-200 bg-white sticky top-0 z-40 shadow-sm">
      <div className="container mx-auto px-4 h-16 flex items-center justify-between">
        <div className="flex items-center gap-6">
          <span className="text-xl font-bold text-brand-600">الفريد ستور</span>
          <nav className="hidden md:flex items-center gap-4 text-sm font-medium text-slate-600">
            <Link href="/" className="hover:text-brand-600">الرئيسية</Link>
            <Link href="/wholesale" className="hover:text-brand-600">بوابة الجملة</Link>
          </nav>
        </div>
        <AccountMenu />
      </div>
    </header>
    <main>{children}</main>
  </>;
}
