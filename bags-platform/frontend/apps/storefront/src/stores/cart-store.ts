'use client';

import { create } from 'zustand';
import { cartEndpoints, getAuthToken, type StoreCart } from '@alfarid/api-client';
import { viewerKey } from '@/lib/core/viewer-key';
import { shouldQueueAdd } from './cart-add';

export { shouldQueueAdd } from './cart-add';

export interface CartItem {
  id: number;
  productId: number;
  variantId: number;
  name: string;
  price: number | null;
  quantity: number;
  available: number;
  image: string | null;
  slug: string | null;
  color: string | null;
  size: string | null;
  priced: boolean;
  lineTotal: number;
}

export interface CartTotals {
  subtotal: number;
  pricing_discount: number;
  items_total: number;
  coupon_discount: number;
  total: number;
  coupon_code: string | null;
}

interface CartState {
  items: CartItem[];
  busy: boolean;
  loading: boolean;
  error: string | null;
  serverTotal: number;
  totals: CartTotals | null;
  couponCode: string | null;
  identity: string;
  loadCart: () => Promise<boolean>;
  addItem: (variantId: number, quantity?: number) => Promise<boolean>;
  removeItem: (id: number) => Promise<boolean>;
  updateQuantity: (id: number, quantity: number) => Promise<boolean>;
  clearCart: () => Promise<boolean>;
  applyCoupon: (code: string) => Promise<boolean>;
  removeCoupon: () => Promise<boolean>;
  total: () => number;
}

let queue: Promise<unknown> = Promise.resolve();
let pendingMutations = 0;
const inFlightAdds = new Set<number>();
let volatileSession: string | null = null;
export function clearGuestCartSession() {
  volatileSession = null;
  try { localStorage.removeItem('cart_session'); } catch { /* Storage may be blocked. */ }
}
const identity = () => viewerKey(getAuthToken());
const session = () => {
  try { return localStorage.getItem('cart_session') ?? volatileSession; } catch { return volatileSession; }
};

export const getCartSession = session;
export function restoreGuestCartSession(value: string) {
  volatileSession = value;
  try { localStorage.setItem('cart_session', value); } catch { /* Volatile fallback remains available. */ }
}

const emptyTotals: CartTotals = { subtotal: 0, pricing_discount: 0, items_total: 0, coupon_discount: 0, total: 0, coupon_code: null };

export const useCartStore = create<CartState>((set, get) => {
  const apply = (cart: StoreCart) => {
    if (cart.customer_id === null && cart.cart_token) {
      volatileSession = cart.cart_token;
      try { localStorage.setItem('cart_session', cart.cart_token); } catch { /* Keep this tab functional without storage. */ }
    } else if (cart.customer_id !== null) {
      // Authenticated customer cart: clear guest session token to prevent cross-session leakage on shared devices.
      clearGuestCartSession();
    }
    set({
      items: cart.items.map(item => ({ id: item.id, productId: item.product_id, variantId: item.product_variant_id,
        name: item.product_name || item.product_name_en || item.sku || 'Product', price: item.unit_price,
        // Max purchasable quantity (lower of available stock and customer limit); fall back to available for backward compatibility.
        quantity: Number(item.qty), available: Number(item.max_qty ?? item.available),
        image: item.image ?? null, slug: item.product_slug ?? null,
        color: item.color ?? null, size: item.size ?? null,
        priced: item.priced, lineTotal: Number(item.line_total ?? 0) })),
      serverTotal: Number(cart.totals.total),
      totals: { ...cart.totals },
      couponCode: cart.totals.coupon_code,
    });
  };
  const run = (operation: () => Promise<StoreCart>, mutation: boolean, guard?: () => boolean): Promise<boolean> => {
    const owner = identity();
    if (get().identity !== owner) set({ items: [], serverTotal: 0, totals: { ...emptyTotals }, couponCode: null, identity: owner, error: null });
    if (mutation) {
      pendingMutations += 1;
      set({ busy: true });
    }
    const execute = async (): Promise<boolean> => {
      if (owner !== identity()) return false;
      // Re-validate against the latest cart state once the queue/lock has settled.
      if (guard && !guard()) return false;
      set({ busy: mutation || pendingMutations > 0, loading: !mutation, error: null });
      try {
        const cart = await operation();
        if (owner !== identity()) return false;
        apply(cart);
        if (mutation) {
          try { localStorage.setItem('cart_updated_at', `${Date.now()}:${Math.random()}`); } catch { /* Cross-tab sync is optional when storage is blocked. */ }
        }
        return true;
      } catch (error) {
        if (owner === identity()) {
          const message = (error as { response?: { data?: { message?: string } } }).response?.data?.message;
          set({ error: message || 'تعذّر تحديث السلة. يرجى المحاولة مرة أخرى.' });
        }
        return false;
      }
    };
    const task = queue.then(async (): Promise<boolean> => {
      try {
        if (typeof navigator !== 'undefined' && navigator.locks) {
          try {
            const signal = typeof AbortSignal !== 'undefined' && 'timeout' in AbortSignal
              ? AbortSignal.timeout(15_000)
              : undefined;
            return Boolean(
              await (signal
                ? navigator.locks.request('alfarid-store-cart', { signal }, execute)
                : navigator.locks.request('alfarid-store-cart', execute)),
            );
          } catch (e) {
            const name = (e as Error)?.name;
            if (name === 'AbortError' || name === 'TimeoutError') {
              set({ error: 'تعذّر تحديث السلة بسبب بطء الاستجابة. حاول مجددًا.' });
              return false;
            }
            if (name === 'SecurityError' || name === 'NotSupportedError') return execute();
            set({ error: 'تعذّر تحديث السلة. يرجى المحاولة مرة أخرى.' });
            return false;
          }
        }
        return execute();
      } finally {
        if (mutation) pendingMutations = Math.max(0, pendingMutations - 1);
        set({ busy: pendingMutations > 0, loading: false });
      }
    });
    queue = task.catch(() => {});
    return task;
  };
  return {
    items: [], busy: false, loading: false, error: null, serverTotal: 0,
    totals: null, couponCode: null, identity: 'guest',
    loadCart: () => run(() => cartEndpoints.get(session()), false),
    addItem: (variantId, quantity) => {
      const qty = quantity ?? 1;
      if (!Number.isInteger(qty) || qty < 1) return Promise.resolve(false);
      if (!shouldQueueAdd(variantId, inFlightAdds)) return Promise.resolve(false);
      inFlightAdds.add(variantId);
      const task = run(() => {
        let cartSession = session();
        if (cartSession === null && getAuthToken() === null) {
          cartSession = globalThis.crypto.randomUUID();
          restoreGuestCartSession(cartSession);
        }
        return cartEndpoints.add(variantId, qty, cartSession);
      }, true);
      return task.finally(() => { inFlightAdds.delete(variantId); });
    },
    removeItem: (id) => run(() => cartEndpoints.remove(id, session()), true),
    updateQuantity: (id, qty) => {
      if (!Number.isInteger(qty) || qty < 0) return Promise.resolve(false);
      return run(() => cartEndpoints.update(id, qty, session()), true, () => {
        const item = get().items.find((item) => item.id === id);
        if (qty > 0 && item && qty > item.available) {
          set({ error: `الكمية المطلوبة غير متوفرة. الحد الأقصى المتاح هو ${item.available}.` });
          return false;
        }
        return true;
      });
    },
    clearCart: () => run(() => cartEndpoints.clear(session()), true),
    applyCoupon: (code) => run(() => cartEndpoints.applyCoupon(code, session()).then((res) => res.cart), true),
    removeCoupon: () => run(() => cartEndpoints.removeCoupon(session()), true),
    total: () => get().serverTotal,
  };
});

export function resetCartIdentity({ preserveSharedStorage = false } = {}) {
  if (preserveSharedStorage) {
    volatileSession = null;
  } else {
    clearGuestCartSession();
    try { localStorage.removeItem('pending_checkout'); } catch { /* Storage may be blocked. */ }
  }
  try { sessionStorage.removeItem('last_order'); sessionStorage.removeItem('pending_checkout'); } catch { /* Storage may be blocked. */ }
  useCartStore.setState({ items: [], serverTotal: 0, totals: null, couponCode: null, error: null, identity: identity() });
}
