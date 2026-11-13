'use client';

import { useEffect } from 'react';
import { useAuthStore } from '@/store/auth.store';
import { useCartStore } from '@/stores/cart-store';

const FOCUS_COOLDOWN_MS = 15_000;

export function useCartSync() {
  useEffect(() => {
    let lastLoadedAt = 0;
    const reload = () => {
      if (!useAuthStore.persist.hasHydrated()) return;
      lastLoadedAt = Date.now();
      void useCartStore.getState().loadCart();
    };
    const onFocus = () => {
      if (Date.now() - lastLoadedAt < FOCUS_COOLDOWN_MS) return;
      reload();
    };
    reload();
    const hydration = useAuthStore.persist.onFinishHydration(reload);
    const unsubscribe = useAuthStore.subscribe((state, previous) => { if (state.token !== previous.token) reload(); });
    const changed = (event: StorageEvent) => { if (event.key === 'cart_updated_at') reload(); };
    window.addEventListener('storage', changed);
    window.addEventListener('focus', onFocus);
    return () => { hydration(); unsubscribe(); window.removeEventListener('storage', changed); window.removeEventListener('focus', onFocus); };
  }, []);
}
