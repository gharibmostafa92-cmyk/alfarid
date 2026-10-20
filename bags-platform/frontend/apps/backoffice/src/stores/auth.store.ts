import { create } from 'zustand';
import { setAuthToken, setUnauthorizedHandler } from '@alfarid/api-client';

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
}

export const useAuthStore = create<AuthState>((set) => {
  const initialToken = typeof localStorage !== 'undefined' ? localStorage.getItem('backoffice_token') : null;
  if (initialToken) {
    setAuthToken(initialToken);
  }

  // Register 401 handler to redirect on session expiry
  setUnauthorizedHandler(() => {
    if (typeof localStorage !== 'undefined') {
      localStorage.removeItem('backoffice_token');
    }
    setAuthToken(null);
    set({ user: null, token: null, isAuthenticated: false });
    if (typeof window !== 'undefined' && !window.location.pathname.includes('/login')) {
      window.location.href = '/login';
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
  };
});
