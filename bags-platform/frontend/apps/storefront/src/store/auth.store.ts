'use client';
import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { setAuthToken, setUnauthorizedHandler } from '@alfarid/api-client';
import type { ActorType, Destination } from '@/types/auth';
import { resetCartIdentity } from '@/stores/cart-store';

interface WebSession {
  token: string | null;
  actorType: Exclude<ActorType, 'staff'> | null;
  destination: Destination | null;
  home: string | null;
  user: Record<string, unknown> | null;
  setSession: (s: { token: string; actorType: 'customer' | 'supplier'; destination: Destination; home: string; user: Record<string, unknown> }) => void;
  clear: () => void;
}

export const useAuthStore = create<WebSession>()(
  persist(
    (set, get) => ({
      token: null, actorType: null, destination: null, home: null, user: null,
      // Re-verifying the same session must not wipe the guest cart, last order or
      // pending checkout; only an actual identity switch invalidates cart state.
      setSession: (s) => {
        const switched = get().token !== s.token;
        setAuthToken(s.token);
        if (switched) resetCartIdentity();
        set({ ...s });
      },
      clear: () => { setAuthToken(null); resetCartIdentity(); set({ token: null, actorType: null, destination: null, home: null, user: null }); },
    }),
    {
      name: 'alfarid_web_auth',
      onRehydrateStorage: () => (state) => { if (state?.token) setAuthToken(state.token); },
    },
  ),
);

if (typeof window !== 'undefined') {
  window.addEventListener('storage', (event) => {
    if (event.storageArea !== localStorage || (event.key !== 'alfarid_web_auth' && event.key !== null)) return;
    let nextToken: string | null = null;
    try {
      nextToken = JSON.parse(localStorage.getItem('alfarid_web_auth') ?? 'null')?.state?.token ?? null;
    } catch { /* Invalid persisted sessions are discarded on reload. */ }
    if (nextToken !== useAuthStore.getState().token) window.location.reload();
  });
  setUnauthorizedHandler(() => {
    useAuthStore.getState().clear();
    const { pathname, search } = window.location;
    if (pathname.startsWith('/auth/') || pathname === '/wholesale/register') return;
    // Public storefront pages must degrade to a guest session instead of forcing a
    // login redirect that also drops the current query string.
    const protectedPrefixes = ['/account', '/supplier', '/wholesale'];
    if (!protectedPrefixes.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`))) return;
    window.location.href = `/auth/login?redirect=${encodeURIComponent(pathname + search)}`;
  });
}
