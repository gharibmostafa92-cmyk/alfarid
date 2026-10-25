'use client';

import React, { useEffect, useRef } from 'react';
import { useCartStore } from '@/stores/cart-store';
import { formatStorePrice } from '@alfarid/utils';
import { SophieBagPlaceholder } from './SophieBagPlaceholder';

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

      <div className="fixed inset-y-0 right-0 max-w-full flex pl-10">
        <div ref={panel} tabIndex={-1} aria-modal="true" role="dialog" aria-label="Shopping cart" className="w-screen max-w-md bg-white shadow-2xl flex flex-col">
          {/* Header */}
          <div className="p-6 border-b border-gray-100 flex items-center justify-between">
            <h2 className="font-['Oswald'] text-xl uppercase tracking-wider font-semibold text-black">
              Shopping Cart ({items.reduce((s, i) => s + i.quantity, 0)})
            </h2>
            <button
              onClick={onClose}
              className="text-gray-400 hover:text-black transition p-1"
              aria-label="Close cart"
            >
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          {/* Items List */}
          <div className="flex-1 overflow-y-auto p-6 space-y-4">
            {loading && <p role="status" className="text-sm">Updating your cart…</p>}
            {error && <div role="alert" className="text-sm text-red-700">{error}<button className="block underline mt-2" onClick={() => { void loadCart(); }}>Try again</button></div>}
            {items.length === 0 ? (
              <div className="h-full flex flex-col items-center justify-center text-center py-12">
                <div className="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center mb-4 text-gray-400">
                  <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                  </svg>
                </div>
                <p className="font-['Oswald'] text-lg font-medium text-gray-800 uppercase tracking-wide">Your cart is empty</p>
                <p className="text-xs text-gray-500 mt-1">Discover our exclusive bag collections and new arrivals.</p>
                <button
                  onClick={onClose}
                  className="mt-6 bg-black text-white px-6 py-2.5 font-['Oswald'] text-xs uppercase tracking-widest hover:bg-neutral-800 transition"
                >
                  Start Shopping
                </button>
              </div>
            ) : (
              items.map((item) => (
                <div key={item.id} className="flex gap-4 pb-4 border-b border-gray-100">
                  <div className="w-20 h-20 bg-gray-50 rounded-sm p-2 flex items-center justify-center shrink-0 [&_svg]:w-10 [&_span]:hidden"><SophieBagPlaceholder /></div>
                  <div className="flex-1 min-w-0">
                    <h3 className="font-['Oswald'] text-sm font-medium text-gray-900 truncate">{item.name}</h3>
                    <p className="text-xs text-gray-600 font-semibold mt-0.5">{item.price === null ? 'Price unavailable' : formatStorePrice(item.price)}</p>
                    <div className="flex items-center gap-2 mt-2">
                      <button
                        onClick={() => updateQuantity(item.id, Math.max(1, item.quantity - 1))}
                        disabled={busy || item.quantity <= 1}
                        aria-label={`Decrease quantity of ${item.name}`}
                        className="w-6 h-6 border border-gray-200 text-xs flex items-center justify-center hover:bg-gray-100 transition"
                      >
                        -
                      </button>
                      <span className="text-xs font-medium w-6 text-center">{item.quantity}</span>
                      <button
                        onClick={() => updateQuantity(item.id, item.quantity + 1)}
                        disabled={busy || item.quantity >= item.available || item.price === null}
                        aria-label={`Increase quantity of ${item.name}`}
                        className="w-6 h-6 border border-gray-200 text-xs flex items-center justify-center hover:bg-gray-100 transition"
                      >
                        +
                      </button>
                    </div>
                  </div>
                  <button
                    onClick={() => removeItem(item.id)}
                    disabled={busy}
                    className="text-gray-300 hover:text-red-500 transition self-start p-1"
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

          {/* Footer & Checkout */}
          {items.length > 0 && (
            <div className="p-6 border-t border-gray-100 bg-gray-50/50">
              <div className="flex justify-between items-center mb-4">
                <span className="font-['Oswald'] text-sm uppercase text-gray-600 tracking-wider">Cart total:</span>
                <span className="font-['Oswald'] text-lg font-bold text-gray-900">{formatStorePrice(total())}</span>
              </div>
              <p className="text-[11px] text-gray-500 mb-4">Shipping and taxes calculated at checkout.</p>
              <div className="space-y-2">
                <button
                  disabled
                  className="w-full bg-black text-white py-3 font-['Oswald'] text-xs font-medium uppercase tracking-widest hover:bg-neutral-800 transition"
                >
                  Checkout coming soon
                </button>
                <button
                  onClick={() => { void clearCart(); }}
                  disabled={busy}
                  className="w-full text-center text-xs text-gray-500 hover:text-red-600 transition py-1"
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
