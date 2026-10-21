import { defaultApiClient as apiClient } from '../axios-instance';

export type ActorType = 'staff' | 'supplier' | 'customer';
export type Destination = 'admin' | 'pos' | 'supplier' | 'wholesale' | 'wholesale_pending' | 'retail';

export interface LoginRedirect {
  app: 'web' | 'backoffice';
  path: string;
  destination?: Destination;
}
export interface UnifiedLoginResponse {
  message: string;
  actor: { type: ActorType; subtype: Destination; status: string | null; customer_type?: string };
  redirect: LoginRedirect;
  token: string | null;
  handoff_code: string | null;
  data: Record<string, unknown>;
  cart_merged?: boolean | null;
}
export interface HandoffExchangeResponse {
  data: StaffUserResource;
  token: string;
  redirect: LoginRedirect;
}
export interface StaffUserResource {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  branch_id: number | null;
  branch: { id: number; name: string } | null;
  is_active: boolean;
  roles: { id: number; name: string }[];
  permissions: string[];
}

export const authEndpoints = {
  login: (body: { login: string; password: string; redirect?: string }, cartSession?: string | null) =>
    apiClient
      .post<UnifiedLoginResponse>('/api/v1/auth/login', body, {
        headers: cartSession ? { 'X-Cart-Session': cartSession } : {},
      })
      .then((r) => r.data),
  me: () => apiClient.get('/api/v1/auth/me').then((r) => r.data),
  logout: () => apiClient.post('/api/v1/auth/logout'),
  exchangeHandoff: (code: string) =>
    apiClient.post<HandoffExchangeResponse>('/api/v1/auth/handoff/exchange', { code }).then((r) => r.data),
  staffMe: () => apiClient.get<{ data: StaffUserResource }>('/api/v1/admin/me').then((r) => r.data.data),
  staffLogout: () => apiClient.post('/api/v1/admin/auth/logout'),
};

export const API_ENDPOINTS = {
  AUTH: {
    LOGIN: '/api/v1/auth/login',
    LOGOUT: '/api/v1/auth/logout',
    ME: '/api/v1/auth/me',
  },
  ADMIN: {
    DASHBOARD: '/api/v1/admin/dashboard',
    PRODUCTS: '/api/v1/admin/products',
    CATEGORIES: '/api/v1/admin/categories',
    ORDERS: '/api/v1/admin/orders',
    CUSTOMERS: '/api/v1/admin/customers',
    INVENTORY: '/api/v1/admin/inventory',
    SUPPLIERS: '/api/v1/admin/suppliers',
    REPORTS: '/api/v1/admin/reports',
    TREASURY: '/api/v1/admin/treasuries',
    EXPENSES: '/api/v1/admin/expenses',
  },
  POS: {
    AUTH_LOGIN: '/api/v1/pos/auth/login',
    AUTH_LOGOUT: '/api/v1/pos/auth/logout',
    SHIFTS_CURRENT: '/api/v1/pos/shifts/current',
    SHIFTS_OPEN: '/api/v1/pos/shifts/open',
    SHIFTS_CLOSE: '/api/v1/pos/shifts/close',
    SALES: '/api/v1/pos/sales',
    STOCK: '/api/v1/pos/stock',
  },
  STORE: {
    PRODUCTS: '/api/v1/store/products',
    CATEGORIES: '/api/v1/store/categories',
    CART: '/api/v1/store/cart',
    CHECKOUT: '/api/v1/store/checkout',
    ORDERS: '/api/v1/store/orders',
  },
  WHOLESALE: {
    PRODUCTS: '/api/v1/wholesale/products',
    QUICK_ORDER: '/api/v1/wholesale/quick-order',
    QUOTATIONS: '/api/v1/wholesale/quotations',
    INVOICES: '/api/v1/wholesale/invoices',
    STATEMENT: '/api/v1/wholesale/statement',
  },
  SUPPLIER: {
    PURCHASE_ORDERS: '/api/v1/supplier/purchase-orders',
    DELIVERIES: '/api/v1/supplier/deliveries',
    INVOICES: '/api/v1/supplier/invoices',
  },
} as const;
