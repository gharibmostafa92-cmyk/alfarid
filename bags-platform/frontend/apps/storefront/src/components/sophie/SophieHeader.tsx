'use client';

import React, { useState, useEffect, useRef } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useCartStore } from '@/stores/cart-store';
import { SophieCartDrawer } from './SophieCartDrawer';
import { SophieMegaDropdown } from './SophieMegaDropdown';
import { SophieProfileDropdown } from './SophieProfileDropdown';
import { useAuthStore } from '@/store/auth.store';
import type { Destination } from '@/types/auth';
import { authEndpoints } from '@alfarid/api-client';
import { logoutWeb } from '@/services/auth.service';
import { prefetchMegaMenu, useMegaMenu } from '@/hooks/useMegaMenu';
import { toProfileVM } from '@/lib/sophie-mappers';
import { STORE_ROUTES } from '@/lib/store-routes';
import { BrandLogo, useBranding } from '@alfarid/ui/branding';

export function SophieHeader() {
  const pathname = usePathname();
  const router = useRouter();
  const [searchOpen, setSearchOpen] = useState(false);
  const [search, setSearch] = useState('');
  const { branding } = useBranding();
  const [cartOpen, setCartOpen] = useState(false);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [mounted, setMounted] = useState(false);
  const searchInputRef = useRef<HTMLInputElement>(null);
  const mobileMenuRef = useRef<HTMLDivElement>(null);

  // Mega dropdown state
  const [megaOpen, setMegaOpen] = useState(false);
  const [megaDept, setMegaDept] = useState<string | null>(null);
  const [mobileDept, setMobileDept] = useState<string | null>(null);
  const megaCloseTimer = useRef<NodeJS.Timeout | null>(null);
  const megaOpenTimer = useRef<NodeJS.Timeout | null>(null);
  const { departments, error: menuError, retry: retryMenu } = useMegaMenu();
  const deptKeys = departments ? Object.keys(departments) : [];
  const firstDept = deptKeys[0] ?? null;
  const currentDepartment = () => {
    const category = new URL(window.location.href).searchParams.get('category');
    return deptKeys.find((key) => key === category || [departments![key].col1, departments![key].col2, departments![key].col3]
      .flat().some((group) => group.slug === category || group.links.some((link) => link.slug === category))) ?? firstDept;
  };

  const items = useCartStore((s) => s.items);
  const cartCount = items.reduce((total, item) => total + item.quantity, 0);

  const user = useAuthStore((s) => s.user);
  const token = useAuthStore((s) => s.token);
  const destination = useAuthStore((s) => s.destination);
  const actorType = useAuthStore((s) => s.actorType);
  void actorType;
  const isAuthed = mounted && !!token;
  const isCheckoutPage = pathname.startsWith('/checkout');

  useEffect(() => {
    setMounted(true);
    return () => {
      if (megaCloseTimer.current) clearTimeout(megaCloseTimer.current);
      if (megaOpenTimer.current) clearTimeout(megaOpenTimer.current);
    };
  }, []);

  const openFromKeyboard = (event: React.KeyboardEvent<HTMLAnchorElement>) => {
    if (event.key !== 'ArrowDown') return;
    event.preventDefault();
    if (megaOpenTimer.current) clearTimeout(megaOpenTimer.current);
    setMegaDept(currentDepartment()); setMegaOpen(true);
    requestAnimationFrame(() => document.querySelector<HTMLButtonElement>('#mega-dropdown button[role="tab"]')?.focus());
  };

  useEffect(() => {
    setMobileMenuOpen(false);
    setSearchOpen(false);
    setMegaOpen(false);
  }, [pathname]);

  // Keep keyboard navigation inside the sheet, then return to its trigger.
  useEffect(() => {
    if (!mobileMenuOpen) return;
    const previousFocus = document.activeElement as HTMLElement | null;
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    mobileMenuRef.current?.focus();
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') { event.preventDefault(); setMobileMenuOpen(false); }
      if (event.key !== 'Tab') return;
      const controls = Array.from(mobileMenuRef.current?.querySelectorAll<HTMLElement>('button:not(:disabled), a[href], input:not(:disabled)') ?? [])
        .filter(control => control.getClientRects().length > 0);
      const first = controls[0], last = controls[controls.length - 1];
      if (!first) { event.preventDefault(); return; }
      if (event.shiftKey && (document.activeElement === first || document.activeElement === mobileMenuRef.current)) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || document.activeElement === mobileMenuRef.current)) {
        event.preventDefault(); first.focus();
      }
    };
    const keepFocus = (event: FocusEvent) => {
      if (!mobileMenuRef.current?.contains(event.target as Node)) mobileMenuRef.current?.focus();
    };
    const desktop = window.matchMedia('(min-width: 1024px)');
    const onResize = (event: MediaQueryListEvent) => { if (event.matches) setMobileMenuOpen(false); };
    desktop.addEventListener('change', onResize);
    document.addEventListener('keydown', onKey);
    document.addEventListener('focusin', keepFocus);
    return () => {
      document.body.style.overflow = prevOverflow;
      desktop.removeEventListener('change', onResize);
      document.removeEventListener('keydown', onKey);
      document.removeEventListener('focusin', keepFocus);
      previousFocus?.focus();
    };
  }, [mobileMenuOpen]);

  useEffect(() => {
    if (searchOpen) {
      requestAnimationFrame(() => searchInputRef.current?.focus());
    }
  }, [searchOpen]);

  const handleOpenMega = (dept: string | null) => {
    if (megaCloseTimer.current) clearTimeout(megaCloseTimer.current);
    if (megaOpenTimer.current) clearTimeout(megaOpenTimer.current);
    // Hover intent: only open after 120ms so passing cursors don't trigger it.
    megaOpenTimer.current = setTimeout(() => {
      setMegaDept(currentDepartment() ?? dept);
      setMegaOpen(true);
    }, 120);
  };

  const handleScheduleCloseMega = () => {
    if (megaOpenTimer.current) clearTimeout(megaOpenTimer.current);
    megaCloseTimer.current = setTimeout(() => {
      setMegaOpen(false);
    }, 220);
  };

  const handleCancelCloseMega = () => {
    if (megaCloseTimer.current) clearTimeout(megaCloseTimer.current);
  };

  const handleProfileAction = async (actionId: string) => {
    if (actionId === 'signout') { await logoutWeb('/'); return; }
    const routes: Record<string, string> = { profile: '/account', orders: '/account/orders', wallet: '/account/wallet', returns: '/account/returns' };
    if (routes[actionId]) router.push(routes[actionId]);
  };

  const handleSearchSubmit = (event: React.FormEvent) => {
    event.preventDefault();
    router.push(STORE_ROUTES.shop({ search: search.trim() }));
    setSearchOpen(false);
    setMobileMenuOpen(false);
  };

  // Warm the menu cache after load so it opens instantly on first hover.
  useEffect(() => {
    const w = window as unknown as { requestIdleCallback?: (cb: () => void) => number };
    if (typeof w.requestIdleCallback === 'function') {
      w.requestIdleCallback(() => prefetchMegaMenu());
    } else {
      const t = setTimeout(() => prefetchMegaMenu(), 2000);
      return () => clearTimeout(t);
    }
  }, []);

  // Session is valid 24h: revalidate once on mount when a token exists.
  // On 401 the global setUnauthorizedHandler clears the session by itself.
  useEffect(() => {
    if (!mounted || !token) return;
    let cancelled = false;
    authEndpoints.me().then((res: any) => {
      if (cancelled) return;
      useAuthStore.setState({
        user: (res?.data as Record<string, unknown>) ?? null,
        destination: (res?.redirect?.destination as Destination | null) ?? null,
        home: (res?.redirect?.path as string | null) ?? null,
      });
    }).catch(() => {});
    return () => { cancelled = true; };
  }, [mounted, token]);

  return (
    <>
      <header
        data-store-header
        className="store-header flex items-center justify-between gap-2 py-3 sm:py-5 lg:py-6 relative z-30"
        onMouseLeave={handleScheduleCloseMega}
        onMouseEnter={handleCancelCloseMega}
      >
        {/* Brand Logo */}
        <Link
          href="/"
          title={branding.name}
          className="flex items-center gap-2 min-w-0 min-h-[44px] font-['Poppins'] text-xl sm:text-2xl font-bold tracking-tight text-black"
        >
          <BrandLogo
            className="w-6 h-6 shrink-0"
            fallback={
              <svg className="w-6 h-6 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <polygon points="2,12 20,3 14,21 10,14 2,12" fill="#000" />
                <line x1="20" y1="3" x2="10" y2="14" stroke="#fff" strokeWidth="1.5" />
              </svg>
            }
          />
          <span dir="auto" className="truncate max-w-[125px] min-[380px]:max-w-[165px] sm:max-w-[220px]">
            {branding.name}
          </span>
        </Link>

        {/* Desktop Navigation */}
        <nav aria-label="Main navigation" className="hidden lg:flex items-center gap-7 xl:gap-8 font-['Oswald'] text-[13px] font-medium tracking-wide uppercase">
          <Link
            href="/"
            aria-current={pathname === '/' ? 'page' : undefined}
            className="py-2 transition-colors hover:text-[#4f7d88]"
          >
            Home
          </Link>

          {/* Shop with Mega Dropdown */}
          <div
            className="relative"
            onMouseEnter={() => handleOpenMega(firstDept)}
          >
            <Link
              href="/shop"
              aria-current={pathname === '/shop' ? 'page' : undefined}
              onClick={(e) => {
                if (window.innerWidth >= 1024 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey) {
                  e.preventDefault();
                  setMegaDept(currentDepartment());
                  setMegaOpen((prev) => !prev);
                }
              }}
              aria-controls="mega-dropdown"
              aria-expanded={megaOpen}
              onKeyDown={openFromKeyboard}
              className="py-2 text-[#111] hover:text-[#4f7d88] transition-colors flex items-center gap-1.5"
            >
              Shop
              <svg
                className={`w-2.5 h-2.5 text-gray-500 transition-transform ${megaOpen ? 'rotate-180' : ''}`}
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M19 9l-7 7-7-7" />
              </svg>
            </Link>
          </div>

          {/* Categories with Mega Dropdown */}
          <div
            className="relative"
            onMouseEnter={() => handleOpenMega(firstDept)}
          >
            <Link
              href="/categories"
              aria-current={pathname === '/categories' ? 'page' : undefined}
              onClick={(e) => {
                if (window.innerWidth >= 1024 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey) {
                  e.preventDefault();
                  setMegaDept(currentDepartment());
                  setMegaOpen((prev) => !prev);
                }
              }}
              aria-controls="mega-dropdown"
              aria-expanded={megaOpen}
              onKeyDown={openFromKeyboard}
              className="py-2 text-[#111] hover:text-[#4f7d88] transition-colors flex items-center gap-1.5"
            >
              Categories
              <svg
                className={`w-2.5 h-2.5 text-gray-500 transition-transform ${megaOpen ? 'rotate-180' : ''}`}
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M19 9l-7 7-7-7" />
              </svg>
            </Link>
          </div>

          {/* Offers: direct link (no discount sort in the catalog API, so no columns) */}
          <Link
            href={STORE_ROUTES.shop()}
            className="py-2 text-[#c93b2e] hover:text-[#a82e23] transition-colors uppercase font-medium"
          >
            Offers
          </Link>

          <a href={STORE_ROUTES.shop({ sort: 'newest' })} className="py-2 text-[#111] hover:text-[#4f7d88] transition-colors">
            New Arrivals
          </a>

          <Link href="/checkout" aria-current={pathname === '/checkout' ? 'page' : undefined} className="py-2 text-[#111] hover:text-[#4f7d88] transition-colors">
            Checkout
          </Link>

          <Link href="/wholesale" className="py-2 text-[#111] hover:text-[#4f7d88] transition-colors">
            Wholesale
          </Link>
        </nav>

        {/* Right Icons: Search, Profile Dropdown / Sign in, Cart, Mobile Menu Toggle */}
        <div className="flex items-center gap-1 sm:gap-2.5 lg:gap-4 text-[15px] text-black shrink-0">
          {/* Search Trigger */}
          <button
            type="button"
            aria-label="Search"
            aria-expanded={searchOpen}
            onClick={() => setSearchOpen(!searchOpen)}
            className="w-11 h-11 inline-flex items-center justify-center rounded-full hover:bg-gray-100 hover:text-[#4f7d88] transition-colors"
          >
            <svg className="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
              <circle cx="10.5" cy="10.5" r="6.5" />
              <path d="m16 16 5 5" />
            </svg>
          </button>

          {/* Sophie Profile Avatar Dropdown (authed) or Sign in (guest) */}
          {!mounted ? (
            <span className="hidden sm:inline-block w-9 h-9 rounded-full bg-gray-100 animate-pulse" aria-hidden />
          ) : isAuthed ? (
            <SophieProfileDropdown user={toProfileVM(user, destination)} onSelectAction={handleProfileAction} />
          ) : (
            <Link
              href={`/auth/login?redirect=${encodeURIComponent(pathname)}`}
              className="font-['Oswald'] text-[13px] uppercase tracking-wide hover:text-[#4f7d88] px-2 py-2 min-h-[44px] hidden sm:inline-flex items-center transition-colors"
            >
              Sign in
            </Link>
          )}

          {/* Cart Icon & Badge */}
          <button
            type="button"
            onClick={() => setCartOpen(true)}
            className="flex items-center gap-1.5 hover:bg-gray-100 hover:text-[#4f7d88] transition-colors px-2 py-2 min-h-[44px] rounded-full relative"
            aria-label="Shopping Cart"
          >
            <svg className="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
            </svg>
            <span
              data-cart-count
              className="bg-black text-white font-['Poppins'] text-[10px] font-semibold min-w-[18px] h-[18px] px-1 rounded-full inline-flex items-center justify-center"
            >
              {mounted ? cartCount : 0}
            </span>
          </button>

          {/* Mobile Menu Toggle Button */}
          <button
            type="button"
            onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
            className="lg:hidden w-11 h-11 inline-flex items-center justify-center rounded-full text-black hover:bg-gray-100 hover:text-[#4f7d88] transition-colors"
            aria-controls="mobile-store-menu"
            aria-label="Toggle navigation menu"
            aria-expanded={mobileMenuOpen}
          >
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              {mobileMenuOpen ? (
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              ) : (
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
              )}
            </svg>
          </button>
        </div>

        {/* Longacres-Inspired Mega Dropdown for Shop & Categories */}
        <SophieMegaDropdown
          isOpen={megaOpen}
          defaultDept={megaDept}
          departments={departments}
          error={menuError}
          onRetry={retryMenu}
          onClose={() => setMegaOpen(false)}
          onSelectCategory={(slug: string) => router.push(slug ? STORE_ROUTES.shop({ category: slug }) : STORE_ROUTES.shop())}
          onGoToCheckout={() => router.push('/checkout')}
          onAddToCart={() => setCartOpen(true)}
        />
      </header>

      {/* Header Search Box */}
      {searchOpen && (
        <form
          className="store-header-search"
          role="search"
          onSubmit={handleSearchSubmit}
        >
          <input
            ref={searchInputRef}
            autoFocus
            type="search"
            inputMode="search"
            enterKeyHint="search"
            autoComplete="off"
            aria-label="Search bags"
            placeholder="Search bags, collections, or styles…"
            value={search}
            maxLength={100}
            onChange={(event) => setSearch(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Escape') setSearchOpen(false);
            }}
          />
          {search && (
            <button
              type="button"
              onClick={() => {
                setSearch('');
                searchInputRef.current?.focus();
              }}
              aria-label="Clear search query"
              className="store-header-search-clear"
            >
              ×
            </button>
          )}
          <button type="submit">Search</button>
          <button
            type="button"
            onClick={() => setSearchOpen(false)}
            aria-label="Close search"
            className="store-header-search-close"
          >
            Cancel
          </button>
        </form>
      )}

      {/* Mobile Navigation Drawer & Backdrop */}
      {mobileMenuOpen && (
        <div className="lg:hidden fixed inset-0 z-50 flex flex-col justify-end sm:justify-start">
          <div
            className="fixed inset-0 bg-black/45 backdrop-blur-[2px] transition-opacity"
            onClick={() => setMobileMenuOpen(false)}
            aria-hidden="true"
          />
          <div
            id="mobile-store-menu"
            ref={mobileMenuRef}
            tabIndex={-1}
            role="dialog"
            aria-modal="true"
            aria-label="Mobile navigation menu"
            className="store-mobile-menu relative z-10 w-full max-h-[86dvh] bg-white rounded-t-2xl sm:rounded-t-none sm:rounded-b-xl border-t sm:border border-gray-100 shadow-2xl flex flex-col overflow-hidden focus:outline-none"
          >
            {/* Mobile Sheet Grab Handle & Header */}
            <div className="pt-2.5 pb-2 px-4 border-b border-gray-100 flex items-center justify-between bg-white">
              <span className="font-['Oswald'] text-sm font-semibold uppercase tracking-wider text-gray-900">
                Browse Store
              </span>
              <button
                type="button"
                onClick={() => setMobileMenuOpen(false)}
                aria-label="Close navigation menu"
                className="w-10 h-10 inline-flex items-center justify-center rounded-full text-gray-500 hover:text-black hover:bg-gray-100 transition-colors"
              >
                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            {/* Quick Mobile Search Inside Drawer */}
            <form onSubmit={handleSearchSubmit} className="px-4 py-3 border-b border-gray-100 bg-gray-50/70 flex items-center gap-2">
              <input
                type="search"
                inputMode="search"
                enterKeyHint="search"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search bags…"
                aria-label="Search bags"
                className="flex-1 min-w-0 h-11 px-3.5 rounded-lg border border-gray-200 bg-white text-base text-gray-900 placeholder:text-gray-400 focus:border-[#4f7d88] focus:outline-none"
              />
              <button
                type="submit"
                className="h-11 px-4 rounded-lg bg-black text-white font-['Oswald'] text-xs uppercase tracking-wider shrink-0 hover:bg-[#2c2c2c] transition-colors"
              >
                Search
              </button>
            </form>

            {/* Scrollable Navigation Links & Department Accordions */}
            <div className="flex-1 min-h-0 overflow-y-auto overscroll-contain px-4 py-3 space-y-1 font-['Oswald'] uppercase text-sm tracking-wide">
              <Link
                href="/"
                onClick={() => setMobileMenuOpen(false)}
                aria-current={pathname === '/' ? 'page' : undefined}
                className={`flex items-center justify-between py-3 px-2 rounded-lg transition-colors ${pathname === '/' ? 'text-[#4f7d88] bg-[#f0f7f8] font-semibold' : 'text-gray-800 hover:bg-gray-50'}`}
              >
                <span>Home</span>
              </Link>
              <Link
                href="/shop"
                onClick={() => setMobileMenuOpen(false)}
                aria-current={pathname === '/shop' ? 'page' : undefined}
                className={`flex items-center justify-between py-3 px-2 rounded-lg transition-colors ${pathname === '/shop' ? 'text-[#4f7d88] bg-[#f0f7f8] font-semibold' : 'text-gray-800 hover:bg-gray-50'}`}
              >
                <span>Shop All Collections</span>
              </Link>
              <Link
                href="/categories"
                onClick={() => setMobileMenuOpen(false)}
                aria-current={pathname === '/categories' ? 'page' : undefined}
                className={`flex items-center justify-between py-3 px-2 rounded-lg transition-colors ${pathname === '/categories' ? 'text-[#4f7d88] bg-[#f0f7f8] font-semibold' : 'text-gray-800 hover:bg-gray-50'}`}
              >
                <span>All Categories</span>
              </Link>

              {/* Departments accordion from the same live menu data */}
              {menuError && (
                <p role="alert" className="py-2 px-2 text-xs text-red-700 font-['Poppins'] normal-case">
                  تعذّر تحميل الأقسام.{' '}
                  <button type="button" onClick={retryMenu} className="underline font-semibold ml-1">
                    إعادة المحاولة
                  </button>
                </p>
              )}
              {deptKeys.map((key) => {
                const dept = departments![key];
                const expanded = mobileDept === key;
                return (
                  <div key={key} className="border-t border-gray-100 pt-1">
                    <button
                      type="button"
                      aria-expanded={expanded}
                      onClick={() => setMobileDept(expanded ? null : key)}
                      className="flex w-full items-center justify-between py-3 px-2 rounded-lg text-gray-800 hover:text-[#4f7d88] hover:bg-gray-50 transition-colors"
                    >
                      <span>{dept.name}</span>
                      <span
                        aria-hidden="true"
                        className="w-7 h-7 rounded-full bg-gray-100 inline-flex items-center justify-center text-base leading-none text-gray-600"
                      >
                        {expanded ? '−' : '+'}
                      </span>
                    </button>
                    {expanded && (
                      <div className="pb-3 pl-3 pr-1 space-y-2.5 normal-case font-['Poppins']">
                        {[dept.col1, dept.col2, dept.col3].flat().map((group) => (
                          <div key={group.slug} className="bg-gray-50/70 rounded-lg p-2.5">
                            <Link
                              href={STORE_ROUTES.shop({ category: group.slug })}
                              onClick={() => setMobileMenuOpen(false)}
                              className="block py-1.5 text-gray-900 hover:text-[#4f7d88] font-semibold text-[13.5px]"
                            >
                              {group.title}
                            </Link>
                            <div className="grid grid-cols-1 min-[380px]:grid-cols-2 gap-1 mt-1">
                              {group.links.map((link) => (
                                <Link
                                  key={link.slug}
                                  href={STORE_ROUTES.shop({ category: link.slug })}
                                  onClick={() => setMobileMenuOpen(false)}
                                  className="flex items-center justify-between py-2 px-2 rounded-md text-gray-600 hover:text-[#4f7d88] hover:bg-white text-[13px] transition-colors"
                                >
                                  <span className="truncate">{link.label}</span>
                                  <span className="text-gray-500 text-[11px] ml-1.5 shrink-0">({link.count})</span>
                                </Link>
                              ))}
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                );
              })}

              <div className="border-t border-gray-100 pt-1 space-y-1">
                <Link
                  href={STORE_ROUTES.shop()}
                  onClick={() => setMobileMenuOpen(false)}
                  className="flex items-center justify-between py-3 px-2 rounded-lg text-[#c93b2e] hover:bg-red-50/60"
                >
                  <span>Offers</span>
                </Link>
                <a
                  href={STORE_ROUTES.shop({ sort: 'newest' })}
                  onClick={() => setMobileMenuOpen(false)}
                  className="flex items-center justify-between py-3 px-2 rounded-lg text-gray-800 hover:text-[#4f7d88] hover:bg-gray-50"
                >
                  <span>New Arrivals</span>
                </a>
                <Link
                  href="/checkout"
                  onClick={() => setMobileMenuOpen(false)}
                  className="flex items-center justify-between py-3 px-2 rounded-lg text-gray-800 hover:text-[#4f7d88] hover:bg-gray-50"
                >
                  <span>Checkout</span>
                </Link>
                <Link
                  href="/wholesale"
                  onClick={() => setMobileMenuOpen(false)}
                  className="flex items-center justify-between py-3 px-2 rounded-lg text-gray-800 hover:text-[#4f7d88] hover:bg-gray-50"
                >
                  <span>Wholesale Portal</span>
                </Link>
              </div>
            </div>

            {/* Thumb-Friendly Sticky Drawer Footer */}
            <div className="p-4 border-t border-gray-100 bg-gray-50 flex items-center gap-3 pb-[calc(1rem+env(safe-area-inset-bottom,0px))]">
              <button
                type="button"
                onClick={() => {
                  setMobileMenuOpen(false);
                  setCartOpen(true);
                }}
                className="flex-1 min-h-[46px] rounded-lg border border-gray-300 bg-white text-gray-900 font-['Oswald'] text-xs uppercase tracking-wider inline-flex items-center justify-center gap-2 hover:bg-gray-100 transition-colors"
              >
                <span>Cart ({mounted ? cartCount : 0})</span>
              </button>
              <Link
                href="/checkout"
                onClick={() => setMobileMenuOpen(false)}
                className="flex-1 min-h-[46px] rounded-lg bg-black text-white font-['Oswald'] text-xs uppercase tracking-wider inline-flex items-center justify-center hover:bg-[#159a8c] transition-colors"
              >
                Checkout
              </Link>
            </div>
          </div>
        </div>
      )}

      {/* One-Handed Mobile Bottom Navigation Bar (Hidden on desktop and checkout where checkout bar is shown) */}
      {!isCheckoutPage && (
        <nav
          aria-label="Mobile quick navigation"
          className="mobile-bottom-nav lg:hidden"
        >
          <Link
            href="/"
            aria-current={pathname === '/' && !mobileMenuOpen && !searchOpen ? 'page' : undefined}
            onClick={() => {
              setMobileMenuOpen(false);
              setSearchOpen(false);
            }}
            className={`mobile-bottom-nav-item ${pathname === '/' && !mobileMenuOpen && !searchOpen ? 'is-active' : ''}`}
          >
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8} aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5.5v-6h-5v6H4a1 1 0 0 1-1-1v-9.5Z" />
            </svg>
            <span>Home</span>
          </Link>

          <button
            type="button"
            aria-expanded={mobileMenuOpen}
            onClick={() => {
              setSearchOpen(false);
              setMobileMenuOpen((prev) => !prev);
            }}
            className={`mobile-bottom-nav-item ${mobileMenuOpen || pathname.startsWith('/categories') || pathname.startsWith('/shop') ? 'is-active' : ''}`}
          >
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8} aria-hidden="true">
              <rect x="3" y="3" width="7" height="7" rx="1.5" />
              <rect x="14" y="3" width="7" height="7" rx="1.5" />
              <rect x="3" y="14" width="7" height="7" rx="1.5" />
              <rect x="14" y="14" width="7" height="7" rx="1.5" />
            </svg>
            <span>Shop</span>
          </button>

          <button
            type="button"
            aria-expanded={searchOpen}
            onClick={() => {
              setMobileMenuOpen(false);
              setSearchOpen((prev) => !prev);
            }}
            className={`mobile-bottom-nav-item ${searchOpen ? 'is-active' : ''}`}
          >
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8} aria-hidden="true">
              <circle cx="10.5" cy="10.5" r="6.5" />
              <path strokeLinecap="round" d="m16 16 5 5" />
            </svg>
            <span>Search</span>
          </button>

          <button
            type="button"
            onClick={() => {
              setMobileMenuOpen(false);
              setCartOpen(true);
            }}
            className={`mobile-bottom-nav-item relative ${cartOpen ? 'is-active' : ''}`}
          >
            <span className="relative inline-flex">
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8} aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
              </svg>
              {mounted && cartCount > 0 && (
                <span className="mobile-bottom-nav-badge" aria-hidden="true">
                  {cartCount > 99 ? '99+' : cartCount}
                </span>
              )}
            </span>
            <span>Cart</span>
          </button>

          <Link
            href={isAuthed ? '/account' : `/auth/login?redirect=${encodeURIComponent(pathname)}`}
            onClick={() => {
              setMobileMenuOpen(false);
              setSearchOpen(false);
            }}
            className={`mobile-bottom-nav-item ${pathname.startsWith('/account') || pathname.startsWith('/auth') ? 'is-active' : ''}`}
          >
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8} aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.25a7.5 7.5 0 0 1 15 0" />
            </svg>
            <span>{isAuthed ? 'Account' : 'Sign In'}</span>
          </Link>
        </nav>
      )}

      {/* Slide-out Cart Drawer */}
      <SophieCartDrawer isOpen={cartOpen} onClose={() => setCartOpen(false)} />
    </>
  );
}

export default SophieHeader;
