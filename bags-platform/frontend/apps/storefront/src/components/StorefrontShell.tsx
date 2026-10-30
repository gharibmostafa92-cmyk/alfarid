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
  const isCheckout = pathname.startsWith('/checkout');
  const isStorePage = ['/', '/shop', '/categories', '/design', '/showcase'].includes(pathname)
    || isCheckout || pathname === '/account' || pathname.startsWith('/account/') || pathname.startsWith('/orders/');
  if (isStorePage) {
    return (
      <div className={`store-page-shell${isCheckout ? ' is-checkout' : ''}`}>
        <a href="#main-content" className="skip-to-content">
          Skip to main content
        </a>
        <div className="store-header-shell">
          <div className="store-header-inner">
            <SophieHeader />
          </div>
        </div>
        {children}
      </div>
    );
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
    return (
      <>
        <a href="#main-content" className="skip-to-content">
          Skip to main content
        </a>
        {children}
      </>
    );
  }

  return (
    <>
      <a href="#main-content" className="skip-to-content">
        Skip to main content
      </a>
      <header className="border-b border-slate-200 bg-white/95 backdrop-blur-xs sticky top-0 z-40 shadow-xs">
        <div className="container mx-auto px-4 h-16 flex items-center justify-between gap-3">
          <div className="flex items-center gap-4 sm:gap-6 min-w-0">
            <Link
              href="/"
              className="text-lg sm:text-xl font-bold text-black font-['Poppins'] truncate min-h-[44px] inline-flex items-center"
            >
              <span dir="auto" className="truncate">{branding.name}</span>
            </Link>
            <nav aria-label="Portal navigation" className="flex items-center gap-2 sm:gap-4 text-xs sm:text-sm font-medium text-slate-700 font-['Oswald'] uppercase">
              <Link href="/" className="hover:text-[#4f7d88] transition-colors px-2 py-2.5 min-h-[44px] inline-flex items-center">
                Home
              </Link>
              <Link href="/wholesale" className="hover:text-[#4f7d88] transition-colors px-2 py-2.5 min-h-[44px] inline-flex items-center">
                Wholesale Portal
              </Link>
            </nav>
          </div>
          <AccountMenu />
        </div>
      </header>
      <main id="main-content">{children}</main>
    </>
  );
}
