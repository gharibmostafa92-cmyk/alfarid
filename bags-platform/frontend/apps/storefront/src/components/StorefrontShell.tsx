'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { AccountMenu } from './auth/AccountMenu';
import { useBranding } from '@alfarid/ui/branding';
import { useStoreLang } from '@/providers/store-lang-provider';
import { useCartSync } from '@/hooks/use-cart-sync';
import { SophieHeader } from './sophie/layout/SophieHeader';

export type ShellLayout = 'storefront' | 'bare' | 'portal';

const STORE_EXACT = new Set(['/', '/account']);
const STORE_PREFIXES = ['/shop', '/categories', '/products', '/guides', '/bags-wholesale', '/design', '/showcase', '/cart', '/checkout', '/account', '/orders', '/security', '/legal'];
const BARE_PREFIXES = ['/auth', '/wholesale', '/supplier'];

const matches = (pathname: string, prefix: string) =>
  pathname === prefix || pathname.startsWith(`${prefix}/`);

/** Deterministically map a pathname to one of the three shell layouts. */
export function shellLayoutFor(pathname: string): ShellLayout {
  if (STORE_EXACT.has(pathname) || STORE_PREFIXES.some((prefix) => matches(pathname, prefix))) {
    return 'storefront';
  }
  if (BARE_PREFIXES.some((prefix) => matches(pathname, prefix))) {
    return 'bare';
  }

  return 'portal';
}

export function StorefrontShell({ children }: { children: React.ReactNode }) {
  useCartSync();
  const [cartMergeWarning, setCartMergeWarning] = useState('');
  const { branding } = useBranding();
  const { lang, dir } = useStoreLang();
  const pathname = usePathname();
  const layout = shellLayoutFor(pathname);
  useEffect(() => {
    if (layout !== 'storefront') return;
    document.documentElement.lang = lang;
    document.documentElement.dir = dir;
  }, [layout, lang, dir]);
  const [loginNotice, setLoginNotice] = useState<'ar' | 'en' | null>(null);
  useEffect(() => {
    if (matches(pathname, '/auth')) {
      setLoginNotice(null);
      return;
    }
    const language = sessionStorage.getItem('alfarid_login_notice');
    sessionStorage.removeItem('alfarid_login_notice');
    setLoginNotice(language === 'ar' || language === 'en' ? language : null);
  }, [pathname]);
  useEffect(() => {
    if (!loginNotice) return;
    const timer = window.setTimeout(() => setLoginNotice(null), 3000);
    return () => window.clearTimeout(timer);
  }, [loginNotice]);
  const signInNotice = loginNotice && (
    <div role="status" lang={loginNotice} dir={loginNotice === 'ar' ? 'rtl' : 'ltr'} className="fixed bottom-6 left-6 z-[9999] rounded-xl bg-emerald-950 px-5 py-3 text-sm text-white shadow-lg">
      {loginNotice === 'ar' ? 'تم تسجيل الدخول بنجاح.' : 'You signed in successfully.'}
    </div>
  );

  useEffect(() => {
    const show = (message: string) => { if (message.trim()) setCartMergeWarning(message); };
    const onWarning = (event: Event) => show((event as CustomEvent<string>).detail ?? '');
    try {
      const saved = sessionStorage.getItem('cart_merge_warning');
      if (saved) {
        show(saved);
        sessionStorage.removeItem('cart_merge_warning');
      }
    } catch { /* Session storage is optional. */ }
    window.addEventListener('cart-merge-warning', onWarning);
    return () => window.removeEventListener('cart-merge-warning', onWarning);
  }, []);

  const cartMergeNotice = cartMergeWarning ? (
    <div role="alert" className="mx-auto my-3 flex max-w-5xl items-start justify-between gap-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
      <span>{cartMergeWarning}</span>
      <button type="button" className="shrink-0 underline" onClick={() => setCartMergeWarning('')}>إغلاق</button>
    </div>
  ) : null;

  if (layout === 'storefront') {
    return (
      <div dir={dir} lang={lang} className={`store-page-shell${matches(pathname, '/checkout') ? ' is-checkout' : ''}`}>
        <a href="#main-content" className="skip-to-content">
          Skip to main content
        </a>
        <div className="store-header-shell">
          <div className="store-header-inner">
            <SophieHeader />
          </div>
        </div>
        {signInNotice}
        {cartMergeNotice}
        {children}
      </div>
    );
  }

  if (layout === 'bare') {
    return (
      <>
        <a href="#main-content" className="skip-to-content">
          Skip to main content
        </a>
        {signInNotice}
        {cartMergeNotice}
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
              className="text-lg sm:text-xl font-bold text-black font-[var(--font-poppins)] truncate min-h-[44px] inline-flex items-center"
            >
              <span dir="auto" className="truncate">{branding.name}</span>
            </Link>
            <nav aria-label="Portal navigation" className="flex items-center gap-2 sm:gap-4 text-xs sm:text-sm font-medium text-slate-700 font-[var(--font-oswald)] uppercase">
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
      {signInNotice}
        {cartMergeNotice}
      <main id="main-content">{children}</main>
    </>
  );
}
