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
const DEV_TOKEN = '25|azrKmywY4EgTG82FHiWucCkkl4LmHsMWEIU86jxQa0fe7687';

const ALL_PERMISSIONS = [
  'ai_agent.use', 'ai_agent.execute', 'ai_agent.logs.view', 'ai_agent.settings',
  'inventory.all_branches', 'inventory.view_cost', 'pos.override_price', 'pos.discount',
  'purchases.approve', 'purchases.receive', 'purchase_requests.approve',
  'supplier_payments.create', 'supplier_payments.update', 'supplier_payments.delete',
  'supplier_invoices.post', 'supplier_invoices.cancel',
  'returns.approve', 'returns.receive', 'returns.refund',
  'branches.view', 'branches.create', 'branches.update', 'branches.delete', 'branches.manage',
  'locations.view', 'locations.create', 'locations.update', 'locations.delete', 'locations.manage',
  'users.view', 'users.create', 'users.update', 'users.delete', 'users.manage',
  'roles.view', 'roles.create', 'roles.update', 'roles.delete', 'roles.manage',
  'permissions.view',
  'customers.view', 'customers.create', 'customers.update', 'customers.delete', 'customers.manage',
  'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete', 'suppliers.manage',
  'products.view', 'products.create', 'products.update', 'products.delete', 'products.manage',
  'categories.view', 'categories.create', 'categories.update', 'categories.delete', 'categories.manage',
  'inventory.view', 'inventory.adjust',
  'orders.view', 'orders.create', 'orders.update', 'orders.delete', 'orders.manage',
  'purchases.view', 'purchases.create', 'purchases.update', 'purchases.delete', 'purchases.manage',
  'returns.view', 'returns.create', 'returns.update', 'returns.delete', 'returns.manage',
  'payments.view', 'payments.create', 'payments.update', 'payments.delete', 'payments.manage',
  'shipping.view', 'shipping.create', 'shipping.update', 'shipping.delete', 'shipping.manage',
  'transfers.view', 'transfers.create', 'transfers.update', 'transfers.delete', 'transfers.manage',
  'reports.view', 'reports.export',
  'notifications.view', 'notifications.create', 'notifications.update', 'notifications.delete', 'notifications.manage',
  'pricing.view', 'pricing.create', 'pricing.update', 'pricing.delete', 'pricing.manage',
  'settings.view', 'settings.update', 'settings.manage',
  'store.manage', 'pos.access', 'pos.sales.view', 'pos.sales.create', 'pos.sales.refund',
  'brands.view', 'brands.create', 'brands.update', 'brands.delete',
  'products.view_cost', 'products.publish',
  'transfers.approve', 'transfers.ship', 'transfers.receive',
  'stocktakes.view', 'stocktakes.create', 'stocktakes.update', 'stocktakes.delete', 'stocktakes.approve',
  'customer_groups.view', 'customer_groups.create', 'customer_groups.update', 'customer_groups.delete',
  'price_lists.view', 'price_lists.create', 'price_lists.update', 'price_lists.delete', 'price_lists.import', 'price_lists.export',
  'customers.approve', 'customers.documents.view',
  'orders.allocate', 'orders.payment',
  'promotions.view', 'promotions.create', 'promotions.update', 'promotions.delete',
  'coupons.view', 'coupons.create', 'coupons.update', 'coupons.delete',
  'quotations.view', 'quotations.price',
  'payment_notices.view', 'payment_notices.approve', 'payment_notices.reject'
];

export const useAuthStore = create<AuthState>((set, get) => {
  let initialToken = typeof localStorage !== 'undefined' ? localStorage.getItem('backoffice_token') : null;
  if (!initialToken && import.meta.env.DEV) {
    initialToken = DEV_TOKEN;
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem('backoffice_token', DEV_TOKEN);
    }
  }
  if (initialToken) {
    setAuthToken(initialToken);
  }

  // Register 401 handler to redirect to the unified web login
  setUnauthorizedHandler(() => {
    if (import.meta.env.DEV) return;
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
    user: import.meta.env.DEV ? {
      id: 2,
      name: 'مدير النظام',
      email: 'admin@alfarid.local',
      roles: ['super_admin'],
      permissions: ALL_PERMISSIONS,
    } : null,
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
        await authEndpoints.logout();
      } catch {
        // Continue local cleanup even if remote invalidation fails
      }
      get().logout();
      window.location.href = `${WEB_URL}/auth/login`;
    },
    bootstrap: async () => {
      const currentToken = get().token;
      if (!currentToken) return;

      try {
        const rawUser = await authEndpoints.me();
        const staffUser = toStaffUser(rawUser);
        if (staffUser) {
          set({ user: staffUser, isAuthenticated: true });
        } else if (import.meta.env.DEV) {
          set({
            user: {
              id: 2,
              name: 'مدير النظام',
              email: 'admin@alfarid.local',
              roles: ['super_admin'],
              permissions: ALL_PERMISSIONS,
            },
            isAuthenticated: true,
          });
        } else {
          get().logout();
        }
      } catch (err) {
        if (import.meta.env.DEV) {
          set({
            user: {
              id: 2,
              name: 'مدير النظام',
              email: 'admin@alfarid.local',
              roles: ['super_admin'],
              permissions: ALL_PERMISSIONS,
            },
            isAuthenticated: true,
          });
          return;
        }
        get().logout();
        throw err;
      }
    },
  };
});
