import { createStore } from 'zustand/vanilla';
import type { Customer, PosCartLine, PosProduct, QuoteResult } from '../types/pos.types';
import type { QuoteRequest } from '../services/pos-api.core';
import { spreadDiscount, toCents } from '../lib/money';

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
  quote: QuoteResult | null;
  isQuoting: boolean;
  quoteError: string | null;
  lastDeleted: PosCartLine | null;
  ctx: string | null;

  bindContext: (userId: number | string, shiftId: number | string | null) => void;
  setCart: (next: PosCartLine[] | ((prev: PosCartLine[]) => PosCartLine[])) => void;
  addItem: (p: PosProduct) => void;
  setLinePrice: (id: number, price: number) => void;
  setCustomer: (c: Customer | null) => void;
  setDiscount: (d: number) => void;
  undoDelete: () => void;
  clearCart: () => void;
  /** Runs the pending quote now and resolves with its result (null if nothing to quote). */
  flushQuote: () => Promise<QuoteResult | null>;
}

const keyOf = (userId: number | string, shiftId: number | string) => `pos-cart:${userId}:${shiftId}`;

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
      const { ctx, cart, customer, discount } = get();
      if (!ctx) return;
      deps.storage.setItem(ctx, JSON.stringify({ cart, customer, discount }));
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
          ...(override ? { unit_price: l.price } : {}),
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
      quote: null,
      isQuoting: false,
      quoteError: null,
      lastDeleted: null,
      ctx: null,

      bindContext: (userId, shiftId) => {
        const ctx = shiftId == null ? null : keyOf(userId, shiftId);
        let saved: { cart?: PosCartLine[]; customer?: Customer | null; discount?: number } = {};
        if (ctx) {
          try {
            saved = JSON.parse(deps.storage.getItem(ctx) ?? '{}');
          } catch {
            saved = {};
          }
        }
        set({ ctx, cart: saved.cart ?? [], customer: saved.customer ?? null, discount: saved.discount ?? 0, quote: null, lastDeleted: null });
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
        const unit = customer?.tier === 'wholesale' ? p.wholesale : p.price;
        get().setCart((prev) => {
          const existing = prev.find((l) => l.id === p.id);
          if (existing) return prev.map((l) => (l.id === p.id ? { ...l, qty: l.qty + 1 } : l));
          return [...prev, { id: p.id, qty: 1, price: unit, listPrice: p.price }];
        });
      },

      setLinePrice: (id, price) => {
        get().setCart((prev) => prev.map((l) => (l.id === id ? { ...l, price, manualPrice: price !== l.listPrice } : l)));
      },

      setCustomer: (customer) => {
        set({ customer });
        persist();
        schedule(true);
      },

      setDiscount: (discount) => {
        set({ discount });
        persist();
        schedule();
      },

      undoDelete: () => {
        const line = get().lastDeleted;
        if (!line) return;
        if (undoTimer) clearTimeout(undoTimer);
        set({ lastDeleted: null });
        get().setCart((prev) => [...prev, line]);
      },

      clearCart: () => {
        const { ctx } = get();
        if (undoTimer) clearTimeout(undoTimer);
        set({ cart: [], customer: null, discount: 0, lastDeleted: null });
        if (ctx) deps.storage.removeItem(ctx);
        schedule(true);
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
