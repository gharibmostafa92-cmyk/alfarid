import { create } from 'zustand';
import { authEndpoints, defaultApiClient, setAuthToken, setUnauthorizedHandler, resolveServiceUrl } from '@alfarid/api-client';
import { toStaffUser } from './mappers';

export interface StaffUser {
  id: number;
  name: string;
  email: string;
  roles: string[];
  permissions: string[];
  branch_id?: number | null;
  branch?: { id: number; name: string; code?: string } | null;
  must_change_password?: boolean;
  version?: number;
}

interface AuthState {
  user: StaffUser | null;
  token: string | null;
  isAuthenticated: boolean;
  signingOut: boolean;
  destination: '/pos' | '/dashboard' | null;
  login: (token: string, user: StaffUser) => void;
  setUser: (user: StaffUser) => void;
  logout: () => void;
  logoutRemote: (scope?: 'staff' | 'unified', reason?: 'branch') => Promise<void>;
  bootstrap: (signal?: AbortSignal) => Promise<void>;
}

const WEB_URL = resolveServiceUrl((import.meta.env.VITE_WEB_URL as string | undefined) ?? 'http://localhost:3000');
export const SUPER_ADMIN_ROLE = 'Super Admin';
export function isSuperAdminRole(roles?: string[] | null): boolean {
  return (roles ?? []).some((r) => {
    const normalized = r.trim().toLowerCase();
    return normalized === 'super admin' || normalized === 'super_admin';
  });
}
export const useAuthStore = create<AuthState>((set, get) => {
  const initialToken = typeof localStorage !== 'undefined' ? localStorage.getItem('backoffice_token') : null;
  if (initialToken) {
    setAuthToken(initialToken);
  }

  // Register 401 handler to redirect to the unified web login
  setUnauthorizedHandler(() => {
    if (!get().token || localStorage.getItem('backoffice_token') !== get().token) return;
    if (typeof localStorage !== 'undefined') {
      localStorage.removeItem('backoffice_token');
    }
    setAuthToken(null);
    set({ user: null, token: null, isAuthenticated: false, destination: null });
    if (typeof window !== 'undefined' && window.location.pathname !== '/login' && !window.location.pathname.startsWith('/auth/')) {
      window.location.replace(`${WEB_URL}/auth/login`);
    }
  });

  return {
    user: null,
    signingOut: false,
    destination: null,
    token: initialToken,
    isAuthenticated: false,
    login: (token, user) => {
      localStorage.setItem('backoffice_token', token);
      setAuthToken(token);
      set({ token, user, isAuthenticated: true, signingOut: false });
    },
    setUser: (user) => set({ user }),
    logout: () => {
      localStorage.removeItem('backoffice_token');
      setAuthToken(null);
      set({ user: null, token: null, isAuthenticated: false, destination: null });
    },
    logoutRemote: async (_scope = 'staff', reason) => {
      const token = get().token;
      if (!token) return;
      if (localStorage.getItem('backoffice_token') !== token) {
        window.location.reload();
        return;
      }
      const pending = authEndpoints.logout(AbortSignal.timeout(3500), token);
      set({ signingOut: true });
      get().logout();
      try {
        await pending;
      } catch {
        // Local session was cleared before waiting for the server.
      }
      if (get().token || localStorage.getItem('backoffice_token')) return;
      window.location.replace(`${WEB_URL}/auth/login${reason === 'branch' ? '?error=branch' : ''}`);
    },
    bootstrap: async (signal) => {
      const currentToken = get().token;
      if (!currentToken) return;
      let staffUser: StaffUser;
      let destination: '/pos' | '/dashboard';
      try {
        const profile = await authEndpoints.me(signal);
        if (profile?.actor?.type !== 'staff' || !['/pos', '/dashboard'].includes(profile?.redirect?.path)) {
          throw new Error('Invalid staff profile payload');
        }
        staffUser = toStaffUser(profile.data);
        destination = profile.redirect.path;
      } catch (error) {
        const response = (error as { response?: { data?: { code?: string } } }).response;
        if (response?.data?.code !== 'password_change_required' || signal?.aborted) throw error;
        staffUser = toStaffUser(await authEndpoints.staffMe(signal));
        destination = '/dashboard';
      }
      if (signal?.aborted || get().token !== currentToken || localStorage.getItem('backoffice_token') !== currentToken) return;
      if (!Number.isSafeInteger(staffUser.id) || staffUser.id <= 0) throw new Error('Invalid staff profile payload');
      set({ user: staffUser, destination, isAuthenticated: true });
    },
  };
});

if (typeof window !== 'undefined') {
  defaultApiClient.interceptors.response.use(undefined, (error) => {
    const { token, destination, logoutRemote } = useAuthStore.getState();
    if (
      token && destination === '/pos' &&
      error?.config?.headers?.Authorization === `Bearer ${token}` &&
      error?.config?.url?.startsWith('/api/v1/pos/') &&
      error?.response?.status === 403 && error.response.data?.code === 'pos_branch_unavailable'
    ) void logoutRemote('unified', 'branch');
    return Promise.reject(error);
  });


  window.addEventListener('storage', (event) => {
    if (event.storageArea !== localStorage || (event.key !== 'backoffice_token' && event.key !== null)) return;
    const token = localStorage.getItem('backoffice_token');
    if (token === useAuthStore.getState().token) return;
    // Reload to discard in-flight POS requests and actor-scoped singleton caches.
    window.location.reload();
  });
}
