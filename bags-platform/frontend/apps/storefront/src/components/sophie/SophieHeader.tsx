'use client';

import React, { useState, useEffect } from 'react';
import Link from 'next/link';
import { useCartStore } from '@/stores/cart-store';
import { SophieCartDrawer } from './SophieCartDrawer';
import { useAuthStore } from '@/store/auth.store';
import { STORE_ROUTES } from '@/lib/store-routes';
import { BrandLogo, useBranding } from '@alfarid/ui/branding';

export function SophieHeader() {
  const { branding } = useBranding();
  const [cartOpen, setCartOpen] = useState(false);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [mounted, setMounted] = useState(false);

  const items = useCartStore((s) => s.items);
  const cartCount = items.reduce((total, item) => total + item.quantity, 0);

  const user = useAuthStore((s) => s.user);
  const token = useAuthStore((s) => s.token);
  const home = useAuthStore((s) => s.home);

  useEffect(() => {
    setMounted(true);
  }, []);

  return (
    <>
      <header className="flex items-center justify-between py-6 relative z-30">
        {/* Brand Logo */}
        <Link href="/" title={branding.name} className="flex items-center gap-2 min-w-0 font-['Poppins'] text-2xl font-bold tracking-tight text-black">
          <BrandLogo className="w-6 h-6" fallback={<svg className="w-6 h-6 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <polygon points="2,12 20,3 14,21 10,14 2,12" fill="#000" />
            <line x1="20" y1="3" x2="10" y2="14" stroke="#fff" strokeWidth="1.5" />
          </svg>} />
          <span dir="auto" className="truncate max-w-[130px] sm:max-w-[220px]">{branding.name}</span>
        </Link>

        {/* Desktop Navigation */}
        <nav className="hidden lg:flex items-center gap-8 font-['Oswald'] text-[13px] font-medium tracking-wide uppercase">
          <Link href="/" className="text-[#4f7d88] transition-colors">
            Home
          </Link>
          <Link href="/shop" className="text-[#111] hover:text-[#4f7d88] transition-colors flex items-center gap-1.5">
            Shop
            <svg className="w-2.5 h-2.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M19 9l-7 7-7-7" />
            </svg>
          </Link>
          <Link href="/categories" className="text-[#111] hover:text-[#4f7d88] transition-colors flex items-center gap-1.5">
            Categories
            <svg className="w-2.5 h-2.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M19 9l-7 7-7-7" />
            </svg>
          </Link>
          <Link href="/design" className="text-[#111] hover:text-[#4f7d88] transition-colors">
            3D Showcase
          </Link>
          <a href={STORE_ROUTES.shop({ sort: 'newest' })} className="text-[#111] hover:text-[#4f7d88] transition-colors">
            New Arrivals
          </a>
          <Link href="/wholesale" className="text-[#111] hover:text-[#4f7d88] transition-colors">
            Wholesale
          </Link>
          <a href="#footer-contact" className="text-[#111] hover:text-[#4f7d88] transition-colors">
            Contact Us
          </a>
        </nav>

        {/* Right Icons: Settings/Account, Search, Cart */}
        <div className="flex items-center gap-5 text-[15px] text-black">
          {/* Account / Login */}
          <Link
            href={mounted && token ? (home ?? '/') : '/auth/login'}
            className="hover:text-[#4f7d88] transition p-1"
            title={mounted && token ? `Signed in as ${user?.name || 'Account'}` : 'Sign In'}
            aria-label="Account"
          >
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
            </svg>
          </Link>

          {/* Cart Icon & Badge */}
          <button
            onClick={() => setCartOpen(true)}
            className="flex items-center gap-1.5 hover:text-[#4f7d88] transition p-1 relative"
            aria-label="Shopping Cart"
          >
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
            </svg>
            <span className="bg-black text-white font-['Poppins'] text-[10px] font-semibold w-[17px] h-[17px] rounded-full inline-flex items-center justify-center">
              {mounted ? cartCount : 0}
            </span>
          </button>

          {/* Mobile Menu Toggle Button */}
          <button
            onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
            className="lg:hidden p-1 text-black hover:text-[#4f7d88] transition"
            aria-label="Toggle navigation menu"
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
      </header>

      {/* Mobile Drawer Menu */}
      {mobileMenuOpen && (
        <div className="lg:hidden mb-6 bg-white border border-gray-100 rounded-sm p-4 shadow-lg space-y-3 font-['Oswald'] uppercase text-sm tracking-wide relative z-30">
          <Link href="/" onClick={() => setMobileMenuOpen(false)} className="block py-1.5 text-[#4f7d88]">
            Home
          </Link>
          <Link href="/shop" onClick={() => setMobileMenuOpen(false)} className="block py-1.5 text-gray-800 hover:text-[#4f7d88]">
            Shop
          </Link>
          <Link href="/categories" onClick={() => setMobileMenuOpen(false)} className="block py-1.5 text-gray-800 hover:text-[#4f7d88]">
            Categories & Collections
          </Link>
          <Link href="/design" onClick={() => setMobileMenuOpen(false)} className="block py-1.5 text-gray-800 hover:text-[#4f7d88]">
            3D Showcase
          </Link>
          <a href={STORE_ROUTES.shop({ sort: 'newest' })} onClick={() => setMobileMenuOpen(false)} className="block py-1.5 text-gray-800 hover:text-[#4f7d88]">
            New Arrivals
          </a>
          <Link href="/wholesale" onClick={() => setMobileMenuOpen(false)} className="block py-1.5 text-gray-800 hover:text-[#4f7d88]">
            Wholesale Portal
          </Link>
          <a href="#footer-contact" onClick={() => setMobileMenuOpen(false)} className="block py-1.5 text-gray-800 hover:text-[#4f7d88]">
            Contact Us
          </a>
        </div>
      )}

      {/* Slide-out Cart Drawer */}
      <SophieCartDrawer isOpen={cartOpen} onClose={() => setCartOpen(false)} />
    </>
  );
}
