import { createApiClient, defaultApiClient as apiClient } from '../axios-instance';
import { API_ENDPOINTS } from './constants';
import type {
  CartCouponResponse,
  CheckoutPayload,
  CheckoutResponse,
  PublicBranding,
  ResourceCollection,
  ShippingOptionsResponse,
  StoreCart,
  StoreCatalogFilters,
  StoreCategory,
  StoreCity,
  StoreHomeData,
  StoreOrder,
  StoreProduct,
  StoreProductCard,
  StoreProductListParams,
  TrackOrderResponse,
} from '../types';

const publicApiClient = createApiClient({ getToken: () => null, onUnauthorized: () => {} });

export const brandingEndpoints = {
  get: (signal?: AbortSignal) => publicApiClient.get<{ data: PublicBranding }>('/api/v1/store/branding', {
    baseURL: apiClient.defaults.baseURL,
    signal,
    timeout: 5000,
  }).then((response) => response.data.data),
};

export const cartHeaders = (session: string | null) => session ? { 'X-Cart-Session': session } : {};

export const cartEndpoints = {
  clear: (session: string | null) => apiClient.delete<{ data: StoreCart }>('/api/v1/store/cart/items', { headers: cartHeaders(session) }).then(response => response.status === 204 ? cartEndpoints.get(session) : response.data.data),
  get: (session: string | null) => apiClient.get<{ data: StoreCart }>('/api/v1/store/cart/items', { headers: cartHeaders(session) }).then(response => response.data.data),
  add: (variantId: number, qty: number, session: string | null) => apiClient.post<{ data: StoreCart }>('/api/v1/store/cart/items', { product_variant_id: variantId, qty }, { headers: cartHeaders(session) }).then(response => response.data.data),
  update: (id: number, qty: number, session: string | null) => apiClient.put<{ data: StoreCart }>(`/api/v1/store/cart/items/${id}`, { qty }, { headers: cartHeaders(session) }).then(response => response.data.data),
  remove: (id: number, session: string | null) => apiClient.delete<{ data: StoreCart }>(`/api/v1/store/cart/items/${id}`, { headers: cartHeaders(session) }).then(response => response.status === 204 ? cartEndpoints.get(session) : response.data.data),
  applyCoupon: (code: string, session: string | null) => apiClient.post<{ data: CartCouponResponse }>('/api/v1/store/cart/coupon', { code }, { headers: cartHeaders(session) }).then(response => response.data.data),
  removeCoupon: (session: string | null) => apiClient.delete<{ data: StoreCart }>('/api/v1/store/cart/coupon', { headers: cartHeaders(session) }).then(response => response.data.data),
};

export const storeEndpoints = {
  home: () => apiClient.get<{ data: StoreHomeData }>(API_ENDPOINTS.STORE.HOME).then((r) => r.data.data),
  categories: (signal?: AbortSignal) =>
    apiClient.get<{ data: StoreCategory[] }>(API_ENDPOINTS.STORE.CATEGORIES, { signal }).then((r) => r.data.data),
  products: (params: StoreProductListParams, signal?: AbortSignal) =>
    apiClient.get<ResourceCollection<StoreProductCard>>(API_ENDPOINTS.STORE.PRODUCTS, { params, signal }).then((r) => r.data),
  product: (slug: string, signal?: AbortSignal) =>
    apiClient.get<{ data: StoreProduct }>(`${API_ENDPOINTS.STORE.PRODUCTS}/${encodeURIComponent(slug)}`, { signal }).then((r) => r.data.data),
  similar: (slug: string, signal?: AbortSignal) =>
    apiClient.get<{ data: StoreProductCard[] }>(`${API_ENDPOINTS.STORE.PRODUCTS}/${encodeURIComponent(slug)}/similar`, { signal }).then((r) => r.data.data),
  filters: (params: StoreProductListParams, signal?: AbortSignal) =>
    apiClient.get<{ data: StoreCatalogFilters }>(API_ENDPOINTS.STORE.CATALOG_FILTERS, { params, signal }).then((r) => r.data.data),
  showcase: (signal?: AbortSignal) =>
    apiClient.get<{ data: StoreProduct[] }>(API_ENDPOINTS.STORE.SHOWCASE, { signal }).then((r) => r.data.data),
};

export const checkoutEndpoints = {
  cities: () => apiClient.get<{ data: StoreCity[] }>('/api/v1/store/cities').then((r) => r.data.data),
  shippingOptions: (body: { city_id: number; payment_method?: string }, session: string | null, signal?: AbortSignal) =>
    apiClient.post<{ data: ShippingOptionsResponse }>('/api/v1/store/checkout/shipping-options', body, { headers: cartHeaders(session), signal }).then((r) => r.data.data),
  place: (body: CheckoutPayload, session: string | null, idempotencyKey: string) =>
    apiClient.post<CheckoutResponse>('/api/v1/store/checkout', body, {
      headers: { ...cartHeaders(session), 'Idempotency-Key': idempotencyKey },
    }).then((r) => r.data),
  track: (number: string, phone?: string) => apiClient.get<{ data: TrackOrderResponse }>(`/api/v1/store/orders/${encodeURIComponent(number)}/track`, { params: phone ? { phone } : {} }).then((r) => r.data.data),
};

export const accountEndpoints = {
  orders: (page = 1) => apiClient.get<{ data: StoreOrder[]; meta?: { current_page: number; last_page: number } }>('/api/v1/store/account/orders', { params: { page } }).then((r) => ({
    data: r.data.data,
    currentPage: r.data.meta?.current_page ?? page,
    lastPage: r.data.meta?.last_page ?? 1,
  })),
  cancelOrder: (id: number) => apiClient.post<{ data: StoreOrder }>(`/api/v1/store/account/orders/${id}/cancel`).then((r) => r.data.data),
  wallet: (page = 1, filters: { direction?: 'credit' | 'debit'; type?: string; from?: string; to?: string } = {}) => apiClient.get<{ balance: number | string; transactions: { data: Array<{ id: number; type: string; type_label?: string | null; amount: number | string; balance_after: number | string; reference_type: string | null; reference_id: number | null; reference_label?: string | null; created_at: string }>; current_page: number; last_page: number; total: number } }>('/api/v1/store/account/wallet', { params: { page, ...filters } }).then((r) => r.data),
  returns: (page = 1) => apiClient.get<{ data: unknown[]; meta: { current_page: number; last_page: number; total: number } }>(`/api/v1/store/account/returns?page=${page}`).then((r) => r.data),
  returnDetail: (id: number) => apiClient.get<{ data: unknown }>(`/api/v1/store/account/returns/${id}`).then((r) => r.data.data),
  createReturn: (fd: FormData) => apiClient.post<{ data: { id: number } }>('/api/v1/store/account/returns', fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data.data),
  addresses: () => apiClient.get<{ data: Array<{ id: number; city_id: number; city_name_ar: string | null; city_name_en: string | null; address_line: string; is_default: boolean }> }>('/api/v1/store/account/addresses').then((r) => r.data.data),
};
