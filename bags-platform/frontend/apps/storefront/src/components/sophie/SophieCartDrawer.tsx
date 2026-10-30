'use client';

import React, { useEffect, useRef } from 'react';
import Link from 'next/link';
import { useCartStore } from '@/stores/cart-store';
import { formatStorePrice } from '@alfarid/utils';
import { StoreProductImage } from './StoreProductImage';

interface SophieCartDrawerProps {
  isOpen: boolean;
  onClose: () => void;
}

export function SophieCartDrawer({ isOpen, onClose }: SophieCartDrawerProps) {
  const panel = useRef<HTMLDivElement>(null);
  const close = useRef(onClose);
  close.current = onClose;
  useEffect(() => {
    if (!isOpen) return;
    const previous = document.activeElement as HTMLElement | null;
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    panel.current?.focus();
    const keys = (event: KeyboardEvent) => {
      if (event.key === 'Escape') { event.preventDefault(); close.current(); }
      if (event.key !== 'Tab') return;
      const targets = Array.from(panel.current?.querySelectorAll<HTMLElement>('button:not(:disabled), a[href], input:not(:disabled), [tabindex="0"]') ?? []).filter(el => el.getClientRects().length > 0);
      const first = targets[0], last = targets[targets.length - 1];
      if (!first) { event.preventDefault(); panel.current?.focus(); return; }
      if (event.shiftKey && (document.activeElement === first || document.activeElement === panel.current)) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && (document.activeElement === last || document.activeElement === panel.current)) { event.preventDefault(); first.focus(); }
    };
    const focus = (event: FocusEvent) => { if (!panel.current?.contains(event.target as Node)) panel.current?.focus(); };
    document.addEventListener('keydown', keys);
    document.addEventListener('focusin', focus);
    return () => {
      document.removeEventListener('keydown', keys);
      document.removeEventListener('focusin', focus);
      document.body.style.overflow = overflow;
      previous?.focus();
    };
  }, [isOpen]);
  const { items, removeItem, updateQuantity, clearCart, total, loadCart, busy, loading, error } = useCartStore();
  useEffect(() => { if (isOpen) void loadCart(); }, [isOpen, loadCart]);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 overflow-hidden">
      {/* Backdrop */}
      <div
        className="fixed inset-0 bg-black/50 transition-opacity backdrop-blur-xs"
        onClick={onClose}
        aria-hidden="true"
      />

      <div className="fixed inset-y-0 right-0 max-w-full flex pl-0 sm:pl-10">
        <div
          ref={panel}
          tabIndex={-1}
          aria-modal="true"
          role="dialog"
          aria-label="Shopping cart"
          className="store-cart-panel w-screen max-w-md bg-white shadow-2xl flex flex-col focus:outline-none"
        >
          {/* Header */}
          <div className="px-4 sm:px-6 py-4 sm:py-5 border-b border-gray-100 flex items-center justify-between gap-2">
            <h2 className="font-['Oswald'] text-lg sm:text-xl uppercase tracking-wider font-semibold text-black">
              Shopping Cart ({items.reduce((s, i) => s + i.quantity, 0)})
            </h2>
            <button
              type="button"
              onClick={onClose}
              className="w-11 h-11 inline-flex items-center justify-center rounded-full text-gray-500 hover:text-black hover:bg-gray-100 transition-colors"
              aria-label="Close cart"
            >
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          {/* Items List */}
          <div className="flex-1 min-h-0 overflow-y-auto px-4 sm:px-6 py-5 space-y-4 overscroll-contain">
            {loading && (
              <p role="status" aria-live="polite" className="text-sm text-gray-600 flex items-center gap-2">
                <span className="inline-block w-3.5 h-3.5 border-2 border-gray-300 border-t-black rounded-full animate-spin" aria-hidden="true" />
                Updating your cart…
              </p>
            )}
            {error && (
              <div role="alert" className="text-sm text-red-800 bg-red-50 border border-red-200 rounded-lg p-3">
                <p>{error}</p>
                <button
                  type="button"
                  className="inline-flex items-center mt-2 font-semibold underline min-h-[36px]"
                  onClick={() => { void loadCart(); }}
                >
                  Try again
                </button>
              </div>
            )}
            {items.length === 0 ? (
              <div className="h-full flex flex-col items-center justify-center text-center py-12 px-4">
                <div className="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center mb-4 text-gray-500">
                  <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                  </svg>
                </div>
                <p className="font-['Oswald'] text-lg font-medium text-gray-900 uppercase tracking-wide">Your cart is empty</p>
                <p className="text-xs sm:text-sm text-gray-600 mt-1.5 max-w-[260px] leading-relaxed">
                  Discover our exclusive bag collections and new arrivals.
                </p>
                <button
                  type="button"
                  onClick={onClose}
                  className="mt-6 bg-black text-white px-7 py-3 min-h-[44px] font-['Oswald'] text-xs uppercase tracking-widest hover:bg-neutral-800 transition-colors"
                >
                  Start Shopping
                </button>
              </div>
            ) : (
              items.map((item) => (
                <div key={item.id} className="flex gap-3.5 sm:gap-4 pb-4 border-b border-gray-100 items-start">
                  <div className="w-20 h-20 bg-gray-50 rounded-md p-2 flex items-center justify-center shrink-0 [&_svg]:w-10 [&_span]:hidden">
                    <StoreProductImage src={item.image} alt={item.name} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <h3 className="font-['Oswald'] text-sm font-medium text-gray-900 line-clamp-2 leading-snug">
                      {item.name}
                    </h3>
                    {(item.color || item.size) && (
                      <p className="text-[11px] text-gray-500 mt-0.5 truncate">
                        {[item.color, item.size].filter(Boolean).join(' · ')}
                      </p>
                    )}
                    <p className="text-xs text-gray-800 font-semibold mt-1">
                      {item.price === null ? 'Price unavailable' : formatStorePrice(item.price)}
                    </p>
                    <div className="flex items-center gap-1.5 mt-2.5">
                      <button
                        type="button"
                        onClick={() => updateQuantity(item.id, Math.max(1, item.quantity - 1))}
                        disabled={busy || item.quantity <= 1}
                        aria-label={`Decrease quantity of ${item.name}`}
                        className="w-11 h-11 border border-gray-200 rounded-md text-sm font-medium flex items-center justify-center hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                      >
                        −
                      </button>
                      <span className="text-xs font-semibold min-w-[28px] text-center" aria-live="polite">
                        {item.quantity}
                      </span>
                      <button
                        type="button"
                        onClick={() => updateQuantity(item.id, item.quantity + 1)}
                        disabled={busy || item.quantity >= item.available || item.price === null}
                        aria-label={`Increase quantity of ${item.name}`}
                        className="w-11 h-11 border border-gray-200 rounded-md text-sm font-medium flex items-center justify-center hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                      >
                        +
                      </button>
                    </div>
                  </div>
                  <button
                    type="button"
                    onClick={() => removeItem(item.id)}
                    disabled={busy}
                    aria-label={`Remove ${item.name} from cart`}
                    className="w-11 h-11 inline-flex items-center justify-center rounded-full text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors self-start shrink-0"
                    title="Remove item"
                  >
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              ))
            )}
          </div>

          {/* Footer & Checkout (Thumb-friendly bottom zone with safe-area support) */}
          {items.length > 0 && (
            <div className="px-4 sm:px-6 pt-5 pb-[calc(1.25rem+env(safe-area-inset-bottom,0px))] border-t border-gray-100 bg-gray-50/80">
              <div className="flex justify-between items-center mb-2">
                <span className="font-['Oswald'] text-sm uppercase text-gray-700 tracking-wider">Cart total:</span>
                <span className="font-['Oswald'] text-lg font-bold text-gray-900">{formatStorePrice(total())}</span>
              </div>
              <p className="text-[11.5px] text-gray-600 mb-4">Shipping and taxes calculated at checkout.</p>
              <div className="space-y-2.5">
                <Link
                  href="/checkout"
                  onClick={onClose}
                  className="w-full min-h-[48px] inline-flex items-center justify-center text-center bg-black text-white py-3.5 px-6 font-['Oswald'] text-xs font-medium uppercase tracking-widest hover:bg-[#159a8c] transition-colors"
                >
                  Proceed to Checkout
                </Link>
                <button
                  type="button"
                  onClick={() => { void clearCart(); }}
                  disabled={busy}
                  className="w-full min-h-[44px] text-center text-xs text-gray-600 hover:text-red-600 transition-colors py-2"
                >
                  Clear Cart
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}