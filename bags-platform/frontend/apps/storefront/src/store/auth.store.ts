'use client';
import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { setAuthToken, setUnauthorizedHandler } from '@alfarid/api-client';
import type { ActorType, Destination } from '@/types/auth';
import { resetCartIdentity, restoreGuestCartSession } from '@/stores/cart-store';
import { unauthorizedLoginRedirect } from '@/store/auth-redirect';
import { resolveAttemptOwner, shouldDropPendingCheckout } from '@/components/sophie/checkout/pending-checkout';

interface WebSession {
  token: string | null;
  actorType: Exclude<ActorType, 'staff'> | null;
  destination: Destination | null;
  home: string | null;
  user: Record<string, unknown> | null;
  setSession: (
    s: { token: string; actorType: 'customer' | 'supplier'; destination: Destination; home: string; user: Record<string, unknown> },
    options?: { preserveGuestCart?: string | null },
  ) => void;
  clear: () => void;
}

const AUTH_STORAGE_KEY = 'alfarid_web_auth';
const emptySession = { token: null, actorType: null, destination: null, home: null, user: null };

export const useAuthStore = create<WebSession>()(
  persist(
    (set, get) => ({
      ...emptySession,
      // Re-verifying the same session must not wipe the guest cart, last order or
      // pending checkout; only an actual identity switch invalidates cart state.
      setSession: (s, options) => {
        const switched = get().token !== s.token;
        const preserved = options?.preserveGuestCart;
        setAuthToken(s.token);
        if (switched) resetCartIdentity();
        if (typeof preserved === 'string' && preserved) restoreGuestCartSession(preserved);
        set({ ...s });
      },
      clear: () => { setAuthToken(null); resetCartIdentity(); set(emptySession); },
    }),
    {
      name: AUTH_STORAGE_KEY,
      onRehydrateStorage: () => (state) => { if (state?.token) setAuthToken(state.token); },
    },
  ),
);

if (typeof window !== 'undefined') {
  window.addEventListener('storage', (event) => {
    if (event.storageArea !== localStorage || (event.key !== AUTH_STORAGE_KEY && event.key !== null)) return;
    let session: Partial<WebSession> | undefined;
    try {
      session = JSON.parse(localStorage.getItem(AUTH_STORAGE_KEY) ?? 'null')?.state;
    } catch { /* Discard invalid persisted sessions. */ }
    const token = typeof session?.token === 'string' && session.token ? session.token : null;
    if (token === useAuthStore.getState().token) return;

    setAuthToken(token);
    resetCartIdentity({ preserveSharedStorage: true });
    const nextOwner = resolveAttemptOwner(token, session?.actorType ?? null, session?.user && (session.user as { id?: unknown }).id);
    try {
      if (shouldDropPendingCheckout(localStorage.getItem('pending_checkout'), nextOwner)) {
        localStorage.removeItem('pending_checkout');
      }
    } catch { /* Storage may be blocked. */ }
    useAuthStore.setState(token ? {
      token,
      actorType: session?.actorType ?? null,
      destination: session?.destination ?? null,
      home: session?.home ?? null,
      user: session?.user ?? null,
    } : emptySession);
  });
  setUnauthorizedHandler(() => {
    const hasSession = useAuthStore.getState().token !== null;
    const { pathname, search } = window.location;
    useAuthStore.getState().clear();
    const redirect = unauthorizedLoginRedirect(pathname, search, hasSession);
    if (redirect) window.location.href = redirect;
  });
}
