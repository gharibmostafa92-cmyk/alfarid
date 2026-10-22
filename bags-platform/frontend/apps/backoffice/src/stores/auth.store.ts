import { create } from 'zustand';
import { authEndpoints, setAuthToken, setUnauthorizedHandler, resolveServiceUrl } from '@alfarid/api-client';
import { toStaffUser } from './mappers';

export interface StaffUser {
  id: number;
  name: string;
  email: string;
  roles: string[];
  permissions: string[];
  branch_id?: number | null;
}

interface AuthState {
  user: StaffUser | null;
  token: string | null;
  isAuthenticated: boolean;
  login: (token: string, user: StaffUser) => void;
  setUser: (user: StaffUser) => void;
  logout: () => void;
  logoutRemote: () => Promise<void>;
  bootstrap: () => Promise<void>;
}

const WEB_URL = resolveServiceUrl((import.meta.env.VITE_WEB_URL as string | undefined) ?? 'http://localhost:3000');

export const useAuthStore = create<AuthState>((set, get) => {
  const initialToken = typeof localStorage !== 'undefined' ? localStorage.getItem('backoffice_token') : null;
  if (initialToken) {
    setAuthToken(initialToken);
  }

  // Register 401 handler to redirect to the unified web login
  setUnauthorizedHandler(() => {
    if (typeof localStorage !== 'undefined') {
      localStorage.removeItem('backoffice_token');
    }
    setAuthToken(null);
    set({ user: null, token: null, isAuthenticated: false });
    if (typeof window !== 'undefined' && !window.location.pathname.includes('/login')) {
      window.location.href = `${WEB_URL}/auth/login`;
    }
  });

  return {
    user: null,
    token: initialToken,
    isAuthenticated: !!initialToken,
    login: (token, user) => {
      localStorage.setItem('backoffice_token', token);
      setAuthToken(token);
      set({ token, user, isAuthenticated: true });
    },
    setUser: (user) => set({ user }),
    logout: () => {
      localStorage.removeItem('backoffice_token');
      setAuthToken(null);
      set({ user: null, token: null, isAuthenticated: false });
    },
    logoutRemote: async () => {
      try {
        await authEndpoints.staffLogout();
      } catch {
        // التوكن منتهي أو ملغي — نكمل تسجيل الخروج المحلي على أي حال
      }
      get().logout();
      window.location.href = `${WEB_URL}/auth/login`;
    },
    bootstrap: async () => {
      if (!get().token) return;
      const token = get().token;
      const u = await authEndpoints.staffMe(); // لو رجع 401 الـ handler هيحوّل لوحده
      if (get().token !== token) return;
      get().setUser(toStaffUser(u));
    },
  };
});

// Reload into the latest account instead of keeping stale identity/permissions in memory.
if (typeof window !== 'undefined') {
  window.addEventListener('storage', (event) => {
    if (window.location.pathname === '/auth/session') return;
    if (event.storageArea === localStorage && (event.key === 'backoffice_token' || event.key === null)
      && localStorage.getItem('backoffice_token') !== useAuthStore.getState().token) {
      window.location.reload();
    }
  });
}
