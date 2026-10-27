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
        className="flex items-center justify-between py-6 relative z-30"
        onMouseLeave={handleScheduleCloseMega}
        onMouseEnter={handleCancelCloseMega}
      >
        {/* Brand Logo */}
        <Link
          href="/"
          title={branding.name}
          className="flex items-center gap-2 min-w-0 font-['Poppins'] text-2xl font-bold tracking-tight text-black"
        >
          <BrandLogo
            className="w-6 h-6"
            fallback={
              <svg className="w-6 h-6 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <polygon points="2,12 20,3 14,21 10,14 2,12" fill="#000" />
                <line x1="20" y1="3" x2="10" y2="14" stroke="#fff" strokeWidth="1.5" />
              </svg>
            }
          />
          <span dir="auto" className="truncate max-w-[130px] sm:max-w-[220px]">
            {branding.name}
          </span>
        </Link>

        {/* Desktop Navigation */}
        <nav className="hidden lg:flex items-center gap-8 font-['Oswald'] text-[13px] font-medium tracking-wide uppercase">
          <Link
            href="/"
            aria-current={pathname === '/' ? 'page' : undefined}
            className="transition-colors hover:text-[#4f7d88]"
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
              className="text-[#111] hover:text-[#4f7d88] transition-colors flex items-center gap-1.5"
            >
              Shop
              <svg
                className={`w-2.5 h-2.5 text-gray-400 transition-transform ${megaOpen ? 'rotate-180' : ''}`}
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
              className="text-[#111] hover:text-[#4f7d88] transition-colors flex items-center gap-1.5"
            >
              Categories
              <svg
                className={`w-2.5 h-2.5 text-gray-400 transition-transform ${megaOpen ? 'rotate-180' : ''}`}
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
            className="text-[#e46054] hover:text-[#d64538] transition-colors uppercase font-medium"
          >
            Offers
          </Link>

          <a href={STORE_ROUTES.shop({ sort: 'newest' })} className="text-[#111] hover:text-[#4f7d88] transition-colors">
            New Arrivals
          </a>

          <Link href="/checkout" aria-current={pathname === '/checkout' ? 'page' : undefined} className="text-[#111] hover:text-[#4f7d88] transition-colors">
            Checkout
          </Link>

          <Link href="/wholesale" className="text-[#111] hover:text-[#4f7d88] transition-colors">
            Wholesale
          </Link>
        </nav>

        {/* Right Icons: Profile Dropdown, Search, Cart */}
        <div className="flex items-center gap-5 text-[15px] text-black">
          {/* Search Trigger */}
          <button
            type="button"
            aria-label="Search"
            aria-expanded={searchOpen}
            onClick={() => setSearchOpen(!searchOpen)}
            className="p-1 hover:text-[#4f7d88]"
          >
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
              <circle cx="10.5" cy="10.5" r="6.5" />
              <path d="m16 16 5 5" />
            </svg>
          </button>

          {/* Sophie Profile Avatar Dropdown (authed) or Sign in (guest) */}
          {!mounted ? (
            <span className="w-8 h-8 rounded-full bg-gray-100 animate-pulse" aria-hidden />
          ) : isAuthed ? (
            <SophieProfileDropdown user={toProfileVM(user, destination)} onSelectAction={handleProfileAction} />
          ) : (
            <Link href={`/auth/login?redirect=${encodeURIComponent(pathname)}`} className="font-['Oswald'] text-[13px] uppercase tracking-wide hover:text-[#4f7d88]">
              Sign in
            </Link>
          )}

          {/* Cart Icon & Badge */}
          <button
            onClick={() => setCartOpen(true)}
            className="flex items-center gap-1.5 hover:text-[#4f7d88] transition p-1 relative"
            aria-label="Shopping Cart"
          >
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
            </svg>
            <span
              data-cart-count
              className="bg-black text-white font-['Poppins'] text-[10px] font-semibold w-[17px] h-[17px] rounded-full inline-flex items-center justify-center"
            >
              {mounted ? cartCount : 0}
            </span>
          </button>

          {/* Mobile Menu Toggle Button */}
          <button
            onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
            className="lg:hidden p-1 text-black hover:text-[#4f7d88] transition"
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
          onSubmit={(event) => {
            event.preventDefault();
            router.push(STORE_ROUTES.shop({ search: search.trim() }));
            setSearchOpen(false);
          }}
        >
          <input
            autoFocus
            aria-label="Search bags"
            placeholder="Search bags…"
            value={search}
            maxLength={100}
            onChange={(event) => setSearch(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Escape') setSearchOpen(false);
            }}
          />
          <button type="submit">Search</button>
        </form>
      )}

      {/* Mobile Drawer Menu */}
      {mobileMenuOpen && (
        <div onKeyDown={(event) => { if (event.key === 'Escape') { setMobileMenuOpen(false); document.querySelector<HTMLButtonElement>('[aria-label="Toggle navigation menu"]')?.focus(); } }} className="lg:hidden mb-6 bg-white border border-gray-100 rounded-sm p-4 shadow-lg space-y-3 font-['Oswald'] uppercase text-sm tracking-wide relative z-30">
          <Link href="/" onClick={() => setMobileMenuOpen(false)} className="block py-1.5 text-[#4f7d88]">
            Home
          </Link>
          <Link href="/shop" onClick={() => setMobileMenuOpen(false)} className="block py-1.5 text-gray-800 hover:text-[#4f7d88]">
            Shop All Collections
          </Link>
          {/* Departments accordion from the same live menu data */}
          {menuError && <p role="alert">تعذّر تحميل الأقسام. <button type="button" onClick={retryMenu}>إعادة المحاولة</button></p>}
          {deptKeys.map((key) => {
            const dept = departments![key];
            const expanded = mobileDept === key;
            return (
              <div key={key} className="border-t border-gray-100 pt-1">
                <button
                  type="button"
                  aria-expanded={expanded}
                  onClick={() => setMobileDept(expanded ? null : key)}
                  className="flex w-full items-center justify-between py-1.5 text-gray-800 hover:text-[#4f7d88]"
                >
                  <span>{dept.name}</span>
                  <span aria-hidden="true">{expanded ? '−' : '+'}</span>
                </button>
                {expanded && (
                  <div className="pb-2 pl-3 space-y-2 normal-case">
                    {[dept.col1, dept.col2, dept.col3].flat().map((group) => (
                      <div key={group.slug}>
                        <Link
                          href={STORE_ROUTES.shop({ category: group.slug })}
                          onClick={() => setMobileMenuOpen(false)}
                          className="block py-1 text-gray-800 hover:text-[#4f7d88] font-medium"
                        >
                          {group.title}
                        </Link>
                        {group.links.map((link) => (
                          <Link
                            key={link.slug}
                            href={STORE_ROUTES.shop({ category: link.slug })}
                            onClick={() => setMobileMenuOpen(false)}
                            className="block py-0.5 pl-3 text-gray-500 hover:text-[#4f7d88] text-[13px]"
                          >
                            {link.label} <span className="text-gray-300">({link.count})</span>
                          </Link>
                        ))}
                      </div>
                    ))}
                  </div>
                )}
              </div>
            );
          })}
          <Link href="/checkout" onClick={() => setMobileMenuOpen(false)} className="block py-1.5 text-gray-800 hover:text-[#4f7d88]">
            Checkout
          </Link>
          <a href={STORE_ROUTES.shop({ sort: 'newest' })} onClick={() => setMobileMenuOpen(false)} className="block py-1.5 text-gray-800 hover:text-[#4f7d88]">
            New Arrivals
          </a>
          <Link href="/wholesale" onClick={() => setMobileMenuOpen(false)} className="block py-1.5 text-gray-800 hover:text-[#4f7d88]">
            Wholesale Portal
          </Link>
        </div>
      )}

      {/* Slide-out Cart Drawer */}
      <SophieCartDrawer isOpen={cartOpen} onClose={() => setCartOpen(false)} />
    </>
  );
}

export default SophieHeader;
