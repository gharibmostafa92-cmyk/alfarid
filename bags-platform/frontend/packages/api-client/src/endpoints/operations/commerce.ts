import { api, ADMIN, asPage, crud, downloadFile, cleanParams, type Id } from './http';

export interface ShippingCompany {
  id: number;
  name: string;
  code: string;
  tracking_url_template: string | null;
  is_active: boolean;
  has_api_credentials?: boolean;
}
export interface ShippingCompanyPayload {
  name: string;
  code: string;
  tracking_url_template?: string | null;
  is_active: boolean;
}
export const shippingCompaniesApi = { ...crud<ShippingCompany, ShippingCompanyPayload, { is_active?: boolean; per_page?: number }>(`${ADMIN}/shipping-companies`), all: (signal?: AbortSignal) => allRecords<ShippingCompany>(`${ADMIN}/shipping-companies`, {}, signal) };

export interface ShippingZone {
  id: number;
  name: string;
  is_active: boolean;
  cities?: { id: number; name_ar: string; name_en?: string | null }[];
}
export interface ShippingZonePayload { name: string; is_active: boolean; city_ids: number[] }
export const shippingZonesApi = { ...crud<ShippingZone, ShippingZonePayload>(`${ADMIN}/shipping-zones`), all: (signal?: AbortSignal) => allRecords<ShippingZone>(`${ADMIN}/shipping-zones`, {}, signal) };

export interface ShippingRate {
  id: number;
  shipping_company_id: number;
  shipping_zone_id: number;
  base_cost: string;
  free_shipping_threshold: string | null;
  cod_fee: string | null;
  estimated_days_min: number;
  estimated_days_max: number;
  company_name?: string | null;
  zone_name?: string | null;
}
export interface ShippingRatePayload {
  shipping_company_id: number;
  shipping_zone_id: number;
  base_cost: number;
  free_shipping_threshold: number | null;
  cod_fee: number | null;
  estimated_days_min: number;
  estimated_days_max: number;
}
export const shippingRatesApi = { ...crud<ShippingRate, ShippingRatePayload>(`${ADMIN}/shipping-rates`), all: (signal?: AbortSignal) => allRecords<ShippingRate>(`${ADMIN}/shipping-rates`, {}, signal) };

/* ---------- Customer groups ---------- */
export interface CustomerGroupApi {
  id: number; name: string; code: string; description: string | null;
  discount_percentage: string; is_active: boolean; customers_count?: number;
}
export interface CustomerGroupPayload { name: string; code: string; description?: string | null; discount_percentage: number; is_active: boolean }
export const customerGroupsApi = { ...crud<CustomerGroupApi, CustomerGroupPayload, { search?: string; is_active?: boolean; per_page?: number }>(`${ADMIN}/customer-groups`), all: (signal?: AbortSignal) => allRecords<CustomerGroupApi>(`${ADMIN}/customer-groups`, {}, signal) };

/* ---------- Price lists ---------- */
export type PriceListType = 'retail' | 'wholesale' | 'distributor' | 'customer_group' | 'customer_specific';
export interface PriceListApi {
  id: number; name: string; type: PriceListType;
  customer?: { id: number; name: string } | null;
  customer_group?: { id: number; name: string; code: string } | null;
  branch?: { id: number; name: string } | null;
  currency: string; starts_at: string | null; ends_at: string | null;
  priority: number; is_active: boolean; items_count?: number; created_at: string;
}
export interface PriceListPayload {
  name: string; type: PriceListType;
  customer_id?: number | null; customer_group_id?: number | null; branch_id?: number | null;
  currency: 'EGP'; starts_at?: string | null; ends_at?: string | null;
  priority: number; is_active: boolean;
}
export interface PriceListParams {
  page?: number; per_page?: number; type?: PriceListType; customer_id?: number;
  customer_group_id?: number; branch_id?: number; is_active?: boolean; search?: string;
}
export interface PriceListImportReport {
  created: number; updated: number; skipped: number; errors: { line: number; message: string }[];
}
const PL = `${ADMIN}/price-lists`;
export const priceListsApi = {
  ...crud<PriceListApi, PriceListPayload, PriceListParams>(PL),
  import: (id: Id, file: File) => {
    const fd = new FormData(); fd.append('file', file);
    return api.post<{ message: string; data: PriceListImportReport }>(`${PL}/${id}/import`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data);
  },
  exportCsv: (id: Id, name: string) => downloadFile(`${PL}/${id}/export`, `${name}.csv`),
};

export interface PriceListItemApi {
  id: number; price_list_id: number; product_variant_id: number;
  variant?: { id: number; sku: string; product_id?: number; product_name_ar?: string | null };
  min_qty: number; price: string; starts_at: string | null; ends_at: string | null;
}
export interface PriceListItemPayload { product_variant_id: number; min_qty: number; price: number; starts_at?: string | null; ends_at?: string | null }
export const priceListItemsApi = (priceListId: Id) => ({
  ...crud<PriceListItemApi, PriceListItemPayload, { page?: number; per_page?: number; product_variant_id?: number }>(`${PL}/${priceListId}/items`),
  all: (signal?: AbortSignal) => allRecords<PriceListItemApi>(`${PL}/${priceListId}/items`, {}, signal),
});

/* ---------- Promotions & Coupons (backend truth) ---------- */
export type PromotionType = 'percentage_discount' | 'fixed_discount' | 'buy_x_get_y' | 'free_shipping';
export type PromotionScope = 'all' | 'categories' | 'brands' | 'variants';
export interface PromotionApi {
  id: number; name: string; type: PromotionType; value: string | null;
  min_order_amount: string | null; applies_to: PromotionScope;
  target_ids?: number[] | null;
  starts_at: string | null; ends_at: string | null; is_active: boolean;
}
export interface PromotionPayload {
  name: string; type: PromotionType; value?: number; min_order_amount?: number | null;
  applies_to?: PromotionScope; target_ids?: number[]; starts_at?: string | null; ends_at?: string | null; is_active?: boolean;
}
export const promotionsApi = { ...crud<PromotionApi, PromotionPayload>(`${ADMIN}/promotions`), all: (signal?: AbortSignal) => allRecords<PromotionApi>(`${ADMIN}/promotions`, {}, signal) };

export type CouponType = 'percentage' | 'fixed' | 'free_shipping';
export interface CouponApi {
  id: number; code: string; type: CouponType; value: string;
  max_discount_amount: string | null; min_order_amount: string;
  usage_limit: number | null; usage_limit_per_customer: number | null; used_count: number;
  starts_at: string | null; ends_at: string | null; is_active: boolean;
}
export interface CouponPayload {
  code: string; type: CouponType; value?: number; max_discount_amount?: number | null; min_order_amount: number;
  usage_limit?: number | null; usage_limit_per_customer?: number; starts_at?: string | null; ends_at?: string | null; is_active?: boolean;
}
export const couponsApi = { ...crud<CouponApi, CouponPayload>(`${ADMIN}/coupons`), all: (signal?: AbortSignal) => allRecords<CouponApi>(`${ADMIN}/coupons`, {}, signal) };

/** Lookup controls must never invent IDs from the demonstration data. */
export interface CommerceOption { id: number; label: string }
async function allRecords<T>(path: string, params: object = {}, signal?: AbortSignal): Promise<T[]> {
  const rows: T[] = [];
  let page = 1, lastPage = 1;
  do {
    const response = await api.get(path, { params: cleanParams({ ...params, per_page: 100, page }), signal });
    const result = asPage<T>(response);
    rows.push(...result.data);
    lastPage = result.meta.last_page;
    page++;
  } while (page <= lastPage);
  return rows;
}
export const commerceLookupsApi = {
  customers: (signal?: AbortSignal) => allRecords<{ id: number; name: string }>(ADMIN + '/customers', { type: 'wholesale' }, signal),
  categories: (signal?: AbortSignal) => allRecords<{ id: number; name_ar: string }>(ADMIN + '/categories', {}, signal)
    .then(rows => rows.map(row => ({ id: row.id, label: row.name_ar }))),
  brands: (signal?: AbortSignal) => allRecords<{ id: number; name_ar: string }>(ADMIN + '/brands', {}, signal)
    .then(rows => rows.map(row => ({ id: row.id, label: row.name_ar }))),
  variants: async (signal?: AbortSignal): Promise<CommerceOption[]> => {
    const products = await allRecords<{ id: number; name_ar: string }>(ADMIN + '/products', {}, signal);
    const result: CommerceOption[] = [];
    for (const product of products) {
      const response = await api.get(ADMIN + '/products/' + product.id + '/variants', { signal });
      const variants = asPage<{ id: number; sku: string }>(response).data;
      result.push(...variants.map(variant => ({ id: variant.id, label: product.name_ar + ' · ' + variant.sku })));
    }
    return result;
  },
};
export type { Id };
