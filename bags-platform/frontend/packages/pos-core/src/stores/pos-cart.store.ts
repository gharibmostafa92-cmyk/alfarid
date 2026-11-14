import { useStore } from 'zustand';
import { createPosCartStore, type CartState } from './pos-cart.core';
import { clearPersistedCarts } from './pos-cart.core';
import { pos } from '../services/pos-data-source';

export const posCartStore = createPosCartStore({
  quoteFn: (req, signal) => pos.quoteSale(req, signal),
  storage: window.localStorage,
});

export function clearPosCartStorage(): void {
  const cart = posCartStore.getState();
  cart.bindContext(0, null);
  clearPersistedCarts();
}

export function usePosCart<T>(selector: (s: CartState) => T): T {
  return useStore(posCartStore, selector);
}
