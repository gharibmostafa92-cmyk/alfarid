'use client';
import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import { setAuthToken, setUnauthorizedHandler } from '@alfarid/api-client';
import type { ActorType, Destination } from '@/types/auth';

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
    (set) => ({
      token: null, actorType: null, destination: null, home: null, user: null,
      setSession: (s) => { setAuthToken(s.token); set({ ...s }); },
      clear: () => { setAuthToken(null); set({ token: null, actorType: null, destination: null, home: null, user: null }); },
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
    if (window.location.pathname.startsWith('/auth/') || window.location.pathname === '/wholesale/register') return;
    window.location.href = `/auth/login?redirect=${encodeURIComponent(window.location.pathname)}`;
  });
}
