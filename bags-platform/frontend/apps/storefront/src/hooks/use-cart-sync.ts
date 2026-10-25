'use client';

import { useEffect } from 'react';
import { useAuthStore } from '@/store/auth.store';
import { useCartStore } from '@/stores/cart-store';

export function useCartSync() {
  useEffect(() => {
    const reload = () => { if (useAuthStore.persist.hasHydrated()) void useCartStore.getState().loadCart(); };
    reload();
    const hydration = useAuthStore.persist.onFinishHydration(reload);
    const unsubscribe = useAuthStore.subscribe((state, previous) => { if (state.token !== previous.token) reload(); });
    const changed = (event: StorageEvent) => { if (event.key === 'cart_updated_at') reload(); };
    window.addEventListener('storage', changed);
    window.addEventListener('focus', reload);
    return () => { hydration(); unsubscribe(); window.removeEventListener('storage', changed); window.removeEventListener('focus', reload); };
  }, []);
}
