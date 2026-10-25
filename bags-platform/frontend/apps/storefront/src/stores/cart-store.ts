'use client';

import { create } from 'zustand';
import { cartEndpoints, getAuthToken, type StoreCart } from '@alfarid/api-client';

export interface CartItem {
  id: number;
  productId: number;
  variantId: number;
  name: string;
  price: number | null;
  quantity: number;
  available: number;
}

interface CartState {
  items: CartItem[];
  busy: boolean;
  loading: boolean;
  error: string | null;
  serverTotal: number;
  identity: string;
  loadCart: () => Promise<boolean>;
  addItem: (variantId: number) => Promise<boolean>;
  removeItem: (id: number) => Promise<boolean>;
  updateQuantity: (id: number, quantity: number) => Promise<boolean>;
  clearCart: () => Promise<boolean>;
  total: () => number;
}

let queue: Promise<unknown> = Promise.resolve();
let volatileSession: string | null = null;
const identity = () => getAuthToken() ?? 'guest';
const session = () => {
  try { return localStorage.getItem('cart_session') ?? volatileSession; } catch { return volatileSession; }
};

export const useCartStore = create<CartState>((set, get) => {
  const apply = (cart: StoreCart) => {
    if (cart.customer_id === null && cart.cart_token) {
      volatileSession = cart.cart_token;
      try { localStorage.setItem('cart_session', cart.cart_token); } catch { /* Keep this tab functional without storage. */ }
    } else if (cart.customer_id !== null) {
      volatileSession = null;
    }
    set({
      items: cart.items.map(item => ({ id: item.id, productId: item.product_id, variantId: item.product_variant_id,
        name: item.product_name || item.sku || 'Product', price: item.unit_price,
        quantity: Number(item.qty), available: Number(item.available) })),
      serverTotal: Number(cart.totals.total),
    });
  };
  const run = (operation: () => Promise<StoreCart>, mutation: boolean): Promise<boolean> => {
    const owner = identity();
    if (get().identity !== owner) set({ items: [], serverTotal: 0, identity: owner, error: null });
    const execute = async (): Promise<boolean> => {
      if (owner !== identity()) return false;
      set({ busy: mutation, loading: !mutation, error: null });
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
          set({ error: message || 'We could not update your cart. Please try again.' });
        }
        return false;
      } finally {
        set({ busy: false, loading: false });
      }
    };
    const task = queue.then(async (): Promise<boolean> => {
      if (typeof navigator !== 'undefined' && navigator.locks) return Boolean(await navigator.locks.request('alfarid-store-cart', execute));
      return execute();
    });
    queue = task.catch(() => {});
    return task;
  };
  return {
    items: [], busy: false, loading: false, error: null, serverTotal: 0, identity: 'guest',
    loadCart: () => run(() => cartEndpoints.get(session()), false),
    addItem: (variantId) => run(() => cartEndpoints.add(variantId, 1, session()), true),
    removeItem: (id) => run(() => cartEndpoints.remove(id, session()), true),
    updateQuantity: (id, qty) => {
      if (!Number.isInteger(qty) || qty < 0 || qty > 10000) return Promise.resolve(false);
      return run(() => cartEndpoints.update(id, qty, session()), true);
    },
    clearCart: () => run(() => cartEndpoints.clear(session()), true),
    total: () => get().serverTotal,
  };
});
