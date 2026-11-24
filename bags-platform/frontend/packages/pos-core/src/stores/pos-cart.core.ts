import { createStore } from 'zustand/vanilla';
import type { Customer, PosCartLine, PosPrepItem, PosPrepSession, PosProduct, QuoteResult } from '../types/pos.types';
import type { QuoteRequest } from '../services/pos-api.core';
import { spreadDiscount, toCents } from '../lib/money';
import { normAr } from '../lib/arabic';
import { isWholesaleEligible } from '../lib/wholesale';

export interface PrepScanOutcome {
  status: 'picked' | 'already_full' | 'not_in_order' | 'no_session';
  item?: PosPrepItem;
}

export interface CartDeps {
  quoteFn: (req: QuoteRequest, signal: AbortSignal) => Promise<QuoteResult | null>;
  storage: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;
  debounceMs?: number;
  /** How long the last deleted line stays restorable. */
  undoMs?: number;
}

export interface CartState {
  cart: PosCartLine[];
  customer: Customer | null;
  discount: number;
  heldSaleIds: number[];
  quote: QuoteResult | null;
  isQuoting: boolean;
  quoteError: string | null;
  lastDeleted: PosCartLine | null;
  ctx: string | null;
  prepSession: PosPrepSession | null;
  prepCtx: string | null;

  bindContext: (userId: number | string, shiftId: number | string | null) => void;
  setCart: (next: PosCartLine[] | ((prev: PosCartLine[]) => PosCartLine[])) => void;
  addItem: (p: PosProduct) => void;
  setLinePrice: (id: number, price: number) => void;
  setCustomer: (c: Customer | null) => void;
  setDiscount: (d: number | ((prev: number) => number)) => void;
  addHeldSaleId: (id: number) => void;
  setHeldSaleIds: (ids: number[]) => void;
  undoDelete: () => void;
  clearCart: () => void;
  startPrepSession: (session: PosPrepSession) => void;
  scanPrepItem: (codeOrVariantId: string | number) => PrepScanOutcome;
  setPrepItemQty: (productVariantId: number, qty: number) => void;
  markAllPrepPicked: () => void;
  clearPrepSession: () => void;
  /** Runs the pending quote now and resolves with its result (null if nothing to quote). */
  flushQuote: () => Promise<QuoteResult | null>;
}

const keyOf = (userId: number | string, shiftId: number | string) => `pos-cart:${userId}:${shiftId}`;
const prepKeyOf = (userId: number | string, shiftId: number | string) => `pos-prep:${userId}:${shiftId}`;

export function clearPersistedCarts(): void {
  try {
    if (typeof window === 'undefined') return;
    const storage = window.localStorage;
    for (let index = storage.length - 1; index >= 0; index--) {
      const key = storage.key(index);
      if (key?.startsWith('pos-cart:') || key?.startsWith('pos-prep:')) storage.removeItem(key);
    }
  } catch { /* Storage access may be blocked. */ }
}

export function createPosCartStore(deps: CartDeps) {
  const debounceMs = deps.debounceMs ?? 250;
  const undoMs = deps.undoMs ?? 5000;
  let timer: ReturnType<typeof setTimeout> | null = null;
  let undoTimer: ReturnType<typeof setTimeout> | null = null;
  let abort: AbortController | null = null;
  let inflight: Promise<QuoteResult | null> | null = null;

  const isAbort = (e: any) => e?.name === 'AbortError' || e?.name === 'CanceledError' || e?.code === 'ERR_CANCELED';

  return createStore<CartState>((set, get) => {
    const persist = () => {
      const { ctx, cart, customer, discount, heldSaleIds } = get();
      if (!ctx) return;
      deps.storage.setItem(ctx, JSON.stringify({ cart, customer, discount, heldSaleIds }));
    };

    const persistPrep = () => {
      const { prepCtx, prepSession } = get();
      if (!prepCtx) return;
      if (!prepSession) deps.storage.removeItem(prepCtx);
      else deps.storage.setItem(prepCtx, JSON.stringify(prepSession));
    };

    const buildRequest = (): QuoteRequest => {
      const { cart, customer, discount } = get();
      const disc = spreadDiscount(
        cart.map((l) => ({ id: l.id, totalC: toCents(l.price) * l.qty })),
        toCents(discount),
      );
      const override = cart.some((l) => l.manualPrice);
      return {
        items: cart.map((l) => ({
          product_variant_id: l.id,
          qty: l.qty,
          discount_amount: (disc.find((d) => d.id === l.id)?.d ?? 0) / 100,
          ...(l.manualPrice ? { unit_price: l.price } : {}),
        })),
        customer_id: customer?.id ?? null,
        override_price: override,
      };
    };

    const apply = (q: QuoteResult) => {
      set((s) => ({
        quote: q,
        isQuoting: false,
        quoteError: null,
        cart: s.cart.map((l) => {
          const ql = q.lines.find((x) => x.product_variant_id === l.id);
          if (!ql) return l;
          return { ...l, price: l.manualPrice ? l.price : ql.unit_price, error: ql.error };
        }),
      }));
      persist();
    };

    const run = (): Promise<QuoteResult | null> => {
      timer = null;
      const ac = new AbortController();
      abort = ac;
      const p = deps
        .quoteFn(buildRequest(), ac.signal)
        .then((q) => {
          if (ac.signal.aborted || !q) return null;
          apply(q);
          return q;
        })
        .catch((e) => {
          if (ac.signal.aborted || isAbort(e)) return null;
          set({ isQuoting: false, quoteError: e?.message ?? 'تعذر حساب الأسعار' });
          return null;
        });
      inflight = p;
      return p;
    };

    /** Every cart/customer/discount change goes through here: cancel the stale request, debounce a new one. */
    const schedule = (immediate = false) => {
      if (timer) clearTimeout(timer);
      timer = null;
      abort?.abort();
      if (!get().cart.length) {
        inflight = null;
        set({ quote: null, isQuoting: false, quoteError: null });
        return;
      }
      set({ isQuoting: true });
      if (immediate) void run();
      else timer = setTimeout(() => void run(), debounceMs);
    };

    const rememberDeleted = (line: PosCartLine) => {
      if (undoTimer) clearTimeout(undoTimer);
      set({ lastDeleted: line });
      undoTimer = setTimeout(() => set({ lastDeleted: null }), undoMs);
    };

    return {
      cart: [],
      customer: null,
      discount: 0,
      heldSaleIds: [],
      quote: null,
      isQuoting: false,
      quoteError: null,
      lastDeleted: null,
      ctx: null,
      prepSession: null,
      prepCtx: null,

      bindContext: (userId, shiftId) => {
        const ctx = shiftId == null ? null : keyOf(userId, shiftId);
        const prepCtx = shiftId == null ? null : prepKeyOf(userId, shiftId);
        let saved: { cart?: PosCartLine[]; customer?: Customer | null; discount?: number; heldSaleIds?: number[] } = {};
        let savedPrep: PosPrepSession | null = null;
        if (ctx) {
          try {
            saved = JSON.parse(deps.storage.getItem(ctx) ?? '{}');
          } catch {
            saved = {};
          }
        }
        if (prepCtx) {
          try {
            const parsed = JSON.parse(deps.storage.getItem(prepCtx) ?? 'null');
            if (parsed && typeof parsed.orderId === 'number' && Array.isArray(parsed.items)) {
              savedPrep = parsed as PosPrepSession;
            }
          } catch {
            savedPrep = null;
          }
        }
        set({
          ctx,
          prepCtx,
          cart: saved.cart ?? [],
          customer: saved.customer ?? null,
          discount: saved.discount ?? 0,
          heldSaleIds: Array.isArray(saved.heldSaleIds) ? saved.heldSaleIds : [],
          quote: null,
          lastDeleted: null,
          prepSession: savedPrep,
        });
        schedule(true);
      },

      setCart: (next) => {
        const prev = get().cart;
        const cart = typeof next === 'function' ? next(prev) : next;
        const removed = prev.filter((p) => !cart.some((n) => n.id === p.id));
        set({ cart });
        if (removed.length === 1) rememberDeleted(removed[0]);
        persist();
        schedule();
      },

      addItem: (p) => {
        const { customer } = get();
        const unit = isWholesaleEligible(customer) ? p.wholesale : p.price;
        get().setCart((prev) => {
          const existing = prev.find((l) => l.id === p.id);
          if (existing) return prev.map((l) => (l.id === p.id ? { ...l, qty: l.qty + 1, stock: p.stock } : l));
          return [
            ...prev,
            {
              id: p.id,
              qty: 1,
              price: unit,
              listPrice: p.price,
              name: p.name,
              stock: p.stock,
              wholesale: p.wholesale,
              cat: p.cat,
              h: p.h,
            },
          ];
        });
      },

      setLinePrice: (id, price) => {
        get().setCart((prev) => prev.map((l) => (l.id === id ? { ...l, price, manualPrice: true } : l)));
      },

      setCustomer: (customer) => {
        set({ customer });
        persist();
        schedule(true);
      },

      setDiscount: (discount) => {
        const next = typeof discount === 'function' ? discount(get().discount) : discount;
        set({ discount: next });
        persist();
        schedule();
      },

      addHeldSaleId: (id: number) => {
        const prev = get().heldSaleIds;
        if (!prev.includes(id)) {
          set({ heldSaleIds: [...prev, id] });
          persist();
        }
      },

      setHeldSaleIds: (heldSaleIds: number[]) => {
        set({ heldSaleIds });
        persist();
      },

      undoDelete: () => {
        const line = get().lastDeleted;
        if (!line) return;
        if (undoTimer) clearTimeout(undoTimer);
        set({ lastDeleted: null });
        get().setCart((prev) =>
          prev.some((l) => l.id === line.id)
            ? prev.map((l) =>
                l.id === line.id
                  ? { ...l, qty: l.qty + line.qty, stock: line.stock }
                  : l,
              )
            : [...prev, line],
        );
      },

      clearCart: () => {
        const { ctx } = get();
        if (undoTimer) clearTimeout(undoTimer);
        set({ cart: [], customer: null, discount: 0, heldSaleIds: [], lastDeleted: null });
        if (ctx) deps.storage.removeItem(ctx);
        schedule(true);
      },

      startPrepSession: (session) => {
        set({
          prepSession: {
            ...session,
            items: session.items.map((item) => ({
              ...item,
              pickedQty: Math.max(0, Math.min(item.expectedQty, Math.trunc(item.pickedQty ?? 0))),
            })),
          },
        });
        persistPrep();
      },

      scanPrepItem: (codeOrVariantId) => {
        const session = get().prepSession;
        if (!session) return { status: 'no_session' };
        const needle = typeof codeOrVariantId === 'string' ? normAr(codeOrVariantId) : '';
        const target = session.items.find((item) => {
          if (typeof codeOrVariantId === 'number') return item.productVariantId === codeOrVariantId;
          if (!needle) return false;
          return (item.barcode && normAr(item.barcode) === needle) || (item.sku && normAr(item.sku) === needle);
        });
        if (!target) return { status: 'not_in_order' };
        if (target.pickedQty >= target.expectedQty) return { status: 'already_full', item: target };

        let updatedItem = target;
        const nextItems = session.items.map((item) => {
          if (item.productVariantId !== target.productVariantId) return item;
          updatedItem = { ...item, pickedQty: Math.min(item.expectedQty, item.pickedQty + 1) };
          return updatedItem;
        });
        set({ prepSession: { ...session, items: nextItems } });
        persistPrep();
        return { status: 'picked', item: updatedItem };
      },

      setPrepItemQty: (productVariantId, qty) => {
        const session = get().prepSession;
        if (!session) return;
        const nextItems = session.items.map((item) => {
          if (item.productVariantId !== productVariantId) return item;
          const clamped = Math.max(0, Math.min(item.expectedQty, Math.trunc(Number.isFinite(qty) ? qty : 0)));
          return { ...item, pickedQty: clamped };
        });
        set({ prepSession: { ...session, items: nextItems } });
        persistPrep();
      },

      markAllPrepPicked: () => {
        const session = get().prepSession;
        if (!session) return;
        set({
          prepSession: {
            ...session,
            items: session.items.map((item) => ({ ...item, pickedQty: item.expectedQty })),
          },
        });
        persistPrep();
      },

      clearPrepSession: () => {
        const { prepCtx } = get();
        set({ prepSession: null });
        if (prepCtx) deps.storage.removeItem(prepCtx);
      },

      flushQuote: async () => {
        if (timer) {
          clearTimeout(timer);
          return run();
        }
        return inflight ?? get().quote;
      },
    };
  });
}
