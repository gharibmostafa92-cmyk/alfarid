'use client';

import React, { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useCartStore } from '@/stores/cart-store';
import { nextCartQuantity } from '@/stores/cart-add';
import { formatStorePrice } from '@alfarid/utils';
import { useStoreLang } from '@/providers/store-lang-provider';
import { StoreProductImage } from '../product/StoreProductImage';

interface SophieCartDrawerProps {
  isOpen: boolean;
  onClose: () => void;
}

function rubberbandSheet(overshoot: number, dimension = 180, constant = 0.25): number {
  return (overshoot * dimension * constant) / (dimension + constant * Math.abs(overshoot));
}

export function SophieCartDrawer({ isOpen, onClose }: SophieCartDrawerProps) {
  const loadCart = useCartStore(state => state.loadCart);
  useEffect(() => { if (isOpen) void loadCart(); }, [isOpen, loadCart]);
  const [rendered, setRendered] = useState(isOpen);
  const [openVisual, setOpenVisual] = useState(false);
  const [dragY, setDragY] = useState(0);
  const [isDragging, setIsDragging] = useState(false);
  const dragState = useRef<{
    startY: number;
    active: boolean;
    history: Array<{ y: number; t: number }>;
  } | null>(null);
  const panel = useRef<HTMLDivElement>(null);
  const close = useRef(onClose);
  close.current = onClose;

  useEffect(() => {
    if (isOpen) {
      setRendered(true);
      setDragY(0);
      setIsDragging(false);
      let raf2 = 0;
      const raf1 = requestAnimationFrame(() => {
        raf2 = requestAnimationFrame(() => setOpenVisual(true));
      });
      return () => {
        cancelAnimationFrame(raf1);
        cancelAnimationFrame(raf2);
      };
    }
    setOpenVisual(false);
    const timer = window.setTimeout(() => {
      setRendered(false);
      setDragY(0);
      setIsDragging(false);
    }, 320);
    return () => window.clearTimeout(timer);
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) return;
    const previous = document.activeElement as HTMLElement | null;
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const focusTimer = window.setTimeout(() => panel.current?.focus(), 20);
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
      window.clearTimeout(focusTimer);
      document.removeEventListener('keydown', keys);
      document.removeEventListener('focusin', focus);
      document.body.style.overflow = overflow;
      previous?.focus();
    };
  }, [isOpen]);

  const handleSheetPointerDown = (e: React.PointerEvent<HTMLDivElement>) => {
    if (window.innerWidth >= 768) return;
    if ((e.target as HTMLElement).closest('button, a, input')) return;
    dragState.current = {
      startY: e.clientY,
      active: true,
      history: [{ y: e.clientY, t: performance.now() }],
    };
    setIsDragging(true);
    try {
      e.currentTarget.setPointerCapture(e.pointerId);
    } catch {
      // Ignore if pointer capture is unavailable
    }
  };

  const handleSheetPointerMove = (e: React.PointerEvent<HTMLDivElement>) => {
    const state = dragState.current;
    if (!state?.active) return;
    const dy = e.clientY - state.startY;
    const now = performance.now();
    state.history.push({ y: e.clientY, t: now });
    if (state.history.length > 5) state.history.shift();
    setDragY(dy < 0 ? rubberbandSheet(dy) : dy);
  };

  const handleSheetPointerEnd = (e: React.PointerEvent<HTMLDivElement>) => {
    const state = dragState.current;
    if (!state?.active) return;
    state.active = false;
    setIsDragging(false);
    try {
      if (e.currentTarget.hasPointerCapture(e.pointerId)) {
        e.currentTarget.releasePointerCapture(e.pointerId);
      }
    } catch {
      // Ignore if pointer capture was not held
    }

    let velocity = 0;
    const history = state.history;
    if (history.length >= 2) {
      const first = history[0];
      const last = history[history.length - 1];
      const dt = (last.t - first.t) / 1000;
      if (dt > 0.005) {
        velocity = (last.y - first.y) / dt;
      }
    }

    if (dragY > 110 || velocity > 480) {
      close.current();
    } else {
      setDragY(0);
    }
  };

  if (!rendered) return null;

  const backdropOpacity = openVisual
    ? Math.max(0.12, 1 - Math.max(0, dragY) / 380)
    : 0;

  return (
    <div className={`fixed inset-0 z-50 overflow-hidden store-cart-root${openVisual ? ' is-open' : ''}${isDragging ? ' is-dragging' : ''}`}>
      {/* Backdrop */}
      <div
        className="fixed inset-0 store-cart-backdrop"
        style={{ opacity: backdropOpacity }}
        onClick={onClose}
        aria-hidden="true"
      />

      <div className="fixed inset-0 flex items-end justify-center md:items-stretch md:justify-end md:pl-10 store-cart-positioner pointer-events-none">
        <div
          ref={panel}
          tabIndex={-1}
          aria-modal="true"
          role="dialog"
          aria-label="Shopping cart"
          style={dragY !== 0 ? ({ '--cart-drag-y': `${dragY}px` } as React.CSSProperties) : undefined}
          className="store-cart-panel pointer-events-auto w-screen max-w-full md:max-w-md bg-white shadow-2xl flex flex-col focus:outline-none"
        >
          <SophieCartContents
            onClose={onClose}
            onHeaderPointerDown={handleSheetPointerDown}
            onHeaderPointerMove={handleSheetPointerMove}
            onHeaderPointerUp={handleSheetPointerEnd}
          />
        </div>
      </div>
    </div>
  );
}

export function SophieCartContents({
  onClose,
  onHeaderPointerDown,
  onHeaderPointerMove,
  onHeaderPointerUp,
}: {
  onClose?: () => void;
  onHeaderPointerDown?: (e: React.PointerEvent<HTMLDivElement>) => void;
  onHeaderPointerMove?: (e: React.PointerEvent<HTMLDivElement>) => void;
  onHeaderPointerUp?: (e: React.PointerEvent<HTMLDivElement>) => void;
}) {
  const { isAr } = useStoreLang();
  const { items, removeItem, updateQuantity, clearCart, total, loadCart, busy, loading, error } = useCartStore();
  const Heading = onClose ? 'h2' : 'h1';
  const itemCount = items.reduce((s, i) => s + i.quantity, 0);

  return (
    <>
      {/* Header (with mobile sheet grabber) */}
      <div
        className={`px-5 sm:px-6 pb-3.5 pt-2.5 md:py-5 border-b border-gray-100 select-none${onClose ? ' store-cart-sheet-header' : ''}`}
        onPointerDown={onHeaderPointerDown}
        onPointerMove={onHeaderPointerMove}
        onPointerUp={onHeaderPointerUp}
        onPointerCancel={onHeaderPointerUp}
      >
        {onClose && (
          <div className="store-cart-grabber-wrap" aria-hidden="true">
            <span className="store-cart-grabber" />
          </div>
        )}
        <div className="flex items-center justify-between gap-3">
          <div className="flex items-center gap-2.5 min-w-0">
            <Heading className="font-[var(--font-cairo)] md:font-[var(--font-oswald)] text-lg sm:text-xl font-bold text-gray-950 tracking-tight">
              {isAr ? 'سلة التسوق' : 'Shopping Cart'}
            </Heading>
            <span className="inline-flex items-center justify-center min-w-[26px] h-6 px-2 rounded-full bg-gray-100 text-xs font-semibold text-gray-700">
              {itemCount}
            </span>
          </div>
          {onClose && <button
            type="button"
            onClick={onClose}
            className="w-11 h-11 -me-1.5 inline-flex items-center justify-center rounded-full text-gray-600 hover:text-black active:scale-95 transition-all"
            aria-label={isAr ? 'إغلاق السلة' : 'Close cart'}
          >
            <span className="w-8 h-8 rounded-full bg-gray-100 hover:bg-gray-200 inline-flex items-center justify-center transition-colors">
              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </span>
          </button>}
        </div>
      </div>

      {/* Items List */}
      <div className="flex-1 min-h-0 overflow-y-auto px-4 sm:px-6 py-4 space-y-3 overscroll-contain">
        {loading && (
          <p role="status" aria-live="polite" className="text-sm text-gray-600 flex items-center gap-2">
            <span className="inline-block w-3.5 h-3.5 border-2 border-gray-300 border-t-black rounded-full animate-spin" aria-hidden="true" />
            {isAr ? 'جاري تحديث السلة…' : 'Updating your cart…'}
          </p>
        )}
        {error && (
          <div role="alert" className="text-sm text-red-800 bg-red-50 border border-red-200 rounded-2xl p-3.5">
            <p>{error}</p>
            <button
              type="button"
              className="inline-flex items-center mt-2 font-semibold underline min-h-[36px]"
              onClick={() => { void loadCart(); }}
            >
              {isAr ? 'إعادة المحاولة' : 'Try again'}
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
            <p className="font-[var(--font-cairo)] md:font-[var(--font-oswald)] text-lg font-bold text-gray-900">
              {isAr ? 'سلتك فارغة حاليًا' : 'Your cart is empty'}
            </p>
            <p className="text-xs sm:text-sm text-gray-500 mt-1.5 max-w-[260px] leading-relaxed">
              {isAr ? 'اكتشف تشكيلتنا المختارة من الشنط والإكسسوارات.' : 'Discover our exclusive bag collections and new arrivals.'}
            </p>
            {onClose ? (
              <button type="button" onClick={onClose} className={startShoppingClass}>
                {isAr ? 'تصفح المنتجات' : 'Start Shopping'}
              </button>
            ) : (
              <Link href="/shop" className={startShoppingClass}>
                {isAr ? 'تصفح المنتجات' : 'Start Shopping'}
              </Link>
            )}
          </div>
        ) : (
          items.map((item) => {
            const decreaseTo = nextCartQuantity(item.quantity, item.available, -1);
            const increaseTo = nextCartQuantity(item.quantity, item.available, 1);
            return (
            <div key={item.id} className="flex gap-3.5 p-3 rounded-2xl bg-gray-50/75 border border-gray-100 items-start">
              <div className="w-20 h-20 bg-white border border-gray-100 rounded-xl p-2 flex items-center justify-center shrink-0 [&_svg]:w-10 [&_span]:hidden">
                <StoreProductImage src={item.image} alt={item.name} />
              </div>
              <div className="flex-1 min-w-0">
                <h3 className="font-[var(--font-cairo)] md:font-[var(--font-oswald)] text-sm font-semibold text-gray-950 line-clamp-2 leading-snug">
                  {item.name}
                </h3>
                {(item.color || item.size) && (
                  <p className="text-[11.5px] text-gray-500 mt-1 truncate">
                    {[item.color, item.size].filter(Boolean).join(' · ')}
                  </p>
                )}
                <div className="flex items-center justify-between gap-2 mt-2.5">
                  <p className="text-sm text-gray-950 font-bold">
                    {item.price === null ? (isAr ? 'السعر غير متاح' : 'Price unavailable') : formatStorePrice(item.price)}
                  </p>
                  <div className="inline-flex items-center bg-white border border-gray-200 rounded-full overflow-hidden shadow-2xs">
                    <button
                      type="button"
                      onClick={() => decreaseTo !== null && updateQuantity(item.id, decreaseTo)}
                      disabled={busy || decreaseTo === null || decreaseTo === item.quantity}
                      aria-label={isAr ? `تقليل كمية ${item.name}` : `Decrease quantity of ${item.name}`}
                      className="w-10 h-10 text-sm font-semibold flex items-center justify-center hover:bg-gray-100 active:scale-90 disabled:opacity-35 disabled:cursor-not-allowed transition-all"
                    >
                      −
                    </button>
                    <span className="text-xs font-bold min-w-[24px] text-center" aria-live="polite">
                      {item.quantity}
                    </span>
                    <button
                      type="button"
                      onClick={() => increaseTo !== null && updateQuantity(item.id, increaseTo)}
                      disabled={busy || increaseTo === null || item.price === null}
                      aria-label={isAr ? `زيادة كمية ${item.name}` : `Increase quantity of ${item.name}`}
                      className="w-10 h-10 text-sm font-semibold flex items-center justify-center hover:bg-gray-100 active:scale-90 disabled:opacity-35 disabled:cursor-not-allowed transition-all"
                    >
                      +
                    </button>
                  </div>
                </div>
              </div>
              <button
                type="button"
                onClick={() => removeItem(item.id)}
                disabled={busy}
                aria-label={isAr ? `حذف ${item.name} من السلة` : `Remove ${item.name} from cart`}
                className="w-10 h-10 -m-1 inline-flex items-center justify-center rounded-full text-gray-400 hover:text-red-600 hover:bg-red-50 active:scale-90 transition-all self-start shrink-0"
                title={isAr ? 'حذف المنتج' : 'Remove item'}
              >
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </div>
            );
          })
        )}
      </div>

      {/* Footer & Checkout (Thumb-friendly bottom zone with safe-area support) */}
      {items.length > 0 && (
        <div className="px-5 sm:px-6 pt-4 pb-[calc(1rem+env(safe-area-inset-bottom,0px))] border-t border-gray-100 bg-white/95 backdrop-blur-md shadow-[0_-8px_24px_rgba(15,23,42,0.04)]">
          <div className="flex justify-between items-center mb-1.5">
            <span className="text-sm font-medium text-gray-600">
              {isAr ? 'إجمالي السلة:' : 'Cart total:'}
            </span>
            <span className="text-lg font-bold text-gray-950">{formatStorePrice(total())}</span>
          </div>
          <p className="text-[11.5px] text-gray-500 mb-3.5">
            {isAr ? 'يتم حساب الشحن والضرائب عند إتمام الطلب.' : 'Shipping and taxes calculated at checkout.'}
          </p>
          <div className="space-y-2">
            <Link
              href="/checkout"
              onClick={onClose}
              className="w-full min-h-[50px] rounded-full inline-flex items-center justify-center text-center bg-gray-950 text-white py-3.5 px-6 text-sm font-semibold hover:bg-[#159a8c] active:scale-[0.98] transition-all shadow-md"
            >
              {isAr ? 'إتمام الطلب' : 'Proceed to Checkout'}
            </Link>
            <button
              type="button"
              onClick={() => { void clearCart(); }}
              disabled={busy}
              className="w-full min-h-[40px] text-center text-xs font-medium text-gray-500 hover:text-red-600 transition-colors py-1.5"
            >
              {isAr ? 'إفراغ السلة' : 'Clear Cart'}
            </button>
          </div>
        </div>
      )}
    </>
  );
}

const startShoppingClass = 'mt-6 inline-flex items-center justify-center rounded-full bg-gray-950 text-white px-7 py-3 min-h-[46px] text-sm font-semibold hover:bg-neutral-800 active:scale-[0.98] transition-all';
