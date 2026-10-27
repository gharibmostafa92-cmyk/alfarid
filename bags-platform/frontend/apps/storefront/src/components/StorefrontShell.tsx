'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { AccountMenu } from './auth/AccountMenu';
import { useBranding } from '@alfarid/ui/branding';
import { useCartSync } from '@/hooks/use-cart-sync';
import { SophieHeader } from './sophie/SophieHeader';

export function StorefrontShell({ children }: { children: React.ReactNode }) {
  useCartSync();
  const { branding } = useBranding();
  const pathname = usePathname();
  const isStorePage = ['/', '/shop', '/categories', '/design', '/showcase', '/checkout'].includes(pathname);
  if (isStorePage) {
    return <><div className="store-header-shell"><div className="store-header-inner"><SophieHeader /></div></div>{children}</>;
  }

  // Pages that manage their own luxury header & layout or specialized portals
  const isCustomLayout =
    pathname === '/' ||
    pathname.startsWith('/checkout') ||
    pathname.startsWith('/auth/') ||
    pathname.startsWith('/categories') ||
    pathname.startsWith('/shop') ||
    pathname.startsWith('/design') ||
    pathname.startsWith('/showcase') ||
    ['/wholesale/register', '/wholesale', '/wholesale/application-status', '/supplier'].includes(pathname);

  if (isCustomLayout) {
    return <>{children}</>;
  }

  return (
    <>
      <header className="border-b border-slate-200 bg-white sticky top-0 z-40 shadow-sm">
        <div className="container mx-auto px-4 h-16 flex items-center justify-between">
          <div className="flex items-center gap-6">
            <span dir="auto" className="text-xl font-bold text-black font-['Poppins'] truncate">{branding.name}</span>
            <nav className="hidden md:flex items-center gap-4 text-sm font-medium text-slate-600 font-['Oswald'] uppercase">
              <Link href="/" className="hover:text-[#4f7d88]">Home</Link>
              <Link href="/wholesale" className="hover:text-[#4f7d88]">Wholesale Portal</Link>
            </nav>
          </div>
          <AccountMenu />
        </div>
      </header>
      <main>{children}</main>
    </>
  );
}
