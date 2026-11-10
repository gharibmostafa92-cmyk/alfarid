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
  login: (body: { login: string; password: string; redirect?: string; turnstile_token?: string }, cartSession?: string | null) =>
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
