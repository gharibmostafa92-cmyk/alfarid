import { defaultApiClient as apiClient } from '../axios-instance';
import type {
  Branch,
  CursorPaginated,
  Location,
  ProductionOptions,
  ProductionReceipt,
  RawPaginated,
  StockLevel,
  StockMovement,
  Stocktake,
  StockTransfer,
} from '../types';
import type { ResourceCollection } from '../types';

const BASE = '/api/v1/admin';

export interface BranchListParams { per_page?: number; page?: number }
export interface BranchPayload {
  name: string; code: string; type: 'branch' | 'warehouse';
  phone?: string | null; email?: string | null; address?: string | null;
  city?: string | null; city_id?: number | null; is_active?: boolean;
}
export interface LocationListParams { per_page?: number; page?: number }
export interface LocationPayload {
  name: string; code: string;
  type: 'shelf' | 'zone' | 'bin' | 'damaged' | 'receiving';
  is_active?: boolean;
}
export interface StockLevelsParams {
  branch_id?: number; location_id?: number; product_id?: number;
  product_variant_id?: number; low_stock?: boolean | 0 | 1; per_page?: number; page?: number;
}
export interface MovementsParams {
  branch_id?: number; location_id?: number; product_id?: number;
  product_variant_id?: number; type?: string;
  from_date?: string; to_date?: string; per_page?: number; cursor?: string;
}
export interface AdjustmentItemPayload {
  product_variant_id: number;
  adjustment_type: 'increase' | 'decrease' | 'mark_damaged' | 'write_off_damaged' | 'restore_damaged';
  quantity: number | string; reason?: string | null;
}
export interface TransferItemPayload {
  product_variant_id: number;
  from_location_id?: number | null; to_location_id?: number | null;
  requested_qty: number | string;
}
export interface TransferPayload {
  from_branch_id: number; to_branch_id: number; notes?: string | null;
  items: TransferItemPayload[];
}
export interface TransferStageItem { id: number; approved_qty?: number | string; shipped_qty?: number | string; received_qty?: number | string }
export interface TransferStagePayload { expected_version: number; items?: TransferStageItem[] }
export interface StocktakeItemPayload { product_variant_id: number; counted_qty?: number | string | null; recount?: boolean }
export interface StocktakePayload {
  branch_id: number; location_id?: number | null; notes?: string | null;
  items?: StocktakeItemPayload[];
}
export interface ProductionReceiptPayload {
  batch_number: string; branch_id: number; location_id?: number | null; notes?: string | null;
  items: { product_variant_id: number; quantity: number; unit_cost: number | string }[];
}

function idempotencyHeaders(key?: string) {
  return key ? { headers: { 'Idempotency-Key': key } } : {};
}

export const branchesApi = {
  list: (params: BranchListParams = {}, signal?: AbortSignal) =>
    apiClient.get<ResourceCollection<Branch>>(`${BASE}/branches`, { params, signal }).then((r) => r.data),
  show: (id: number, signal?: AbortSignal) =>
    apiClient.get<{ data: Branch }>(`${BASE}/branches/${id}`, { signal }).then((r) => r.data.data),
  create: (p: BranchPayload) =>
    apiClient.post<{ data: Branch }>(`${BASE}/branches`, p).then((r) => r.data.data),
  update: (id: number, p: Partial<BranchPayload>) =>
    apiClient.put<{ data: Branch }>(`${BASE}/branches/${id}`, p).then((r) => r.data.data),
  remove: (id: number) =>
    apiClient.delete(`${BASE}/branches/${id}`).then((r) => r.data),
};

export const locationsApi = {
  list: (branchId: number, params: LocationListParams = {}, signal?: AbortSignal) =>
    apiClient.get<ResourceCollection<Location>>(`${BASE}/branches/${branchId}/locations`, { params, signal }).then((r) => r.data),
  show: (branchId: number, id: number, signal?: AbortSignal) =>
    apiClient.get<{ data: Location }>(`${BASE}/branches/${branchId}/locations/${id}`, { signal }).then((r) => r.data.data),
  create: (branchId: number, p: LocationPayload) =>
    apiClient.post<{ data: Location }>(`${BASE}/branches/${branchId}/locations`, p).then((r) => r.data.data),
  update: (branchId: number, id: number, p: Partial<LocationPayload>) =>
    apiClient.put<{ data: Location }>(`${BASE}/branches/${branchId}/locations/${id}`, p).then((r) => r.data.data),
  remove: (branchId: number, id: number) =>
    apiClient.delete(`${BASE}/branches/${branchId}/locations/${id}`).then((r) => r.data),
};

export const stockApi = {
  levels: (params: StockLevelsParams = {}, signal?: AbortSignal) =>
    apiClient.get<ResourceCollection<StockLevel>>(`${BASE}/inventory`, {
      params: { ...params, low_stock: params.low_stock == null ? undefined : Number(params.low_stock) }, signal,
    }).then((r) => r.data),
};

export const movementsApi = {
  list: (params: MovementsParams = {}, signal?: AbortSignal) => {
    const { cursor, ...rest } = params;
    return apiClient
      .get<CursorPaginated<StockMovement>>(`${BASE}/inventory/movements`, { params: cursor ? { ...rest, cursor } : rest, signal })
      .then((r) => r.data);
  },
};

export const adjustmentsApi = {
  create: (payload: { branch_id: number; location_id?: number | null; items: AdjustmentItemPayload[] }, idempotencyKey: string) =>
    apiClient
      .post<{ message: string; data: StockMovement[] }>(`${BASE}/inventory/adjustments`, payload, idempotencyHeaders(idempotencyKey))
      .then((r) => r.data),
};

export const transfersApi = {
  list: (params: { from_branch_id?: number; to_branch_id?: number; status?: string; per_page?: number; page?: number } = {}, signal?: AbortSignal) =>
    apiClient.get<ResourceCollection<StockTransfer>>(`${BASE}/transfers`, { params, signal }).then((r) => r.data),
  show: (id: number, signal?: AbortSignal) =>
    apiClient.get<{ data: StockTransfer }>(`${BASE}/transfers/${id}`, { signal }).then((r) => r.data.data),
  create: (payload: TransferPayload, idempotencyKey: string) =>
    apiClient
      .post<{ message: string; data: StockTransfer }>(`${BASE}/transfers`, payload, idempotencyHeaders(idempotencyKey))
      .then((r) => r.data),
  update: (id: number, payload: Partial<TransferPayload>) =>
    apiClient.put<{ data: StockTransfer }>(`${BASE}/transfers/${id}`, payload).then((r) => r.data.data),
  remove: (id: number) =>
    apiClient.delete<{ message: string }>(`${BASE}/transfers/${id}`).then((r) => r.data),
  approve: (id: number, payload: TransferStagePayload) =>
    apiClient.post<{ message: string; data: StockTransfer }>(`${BASE}/transfers/${id}/approve`, payload).then((r) => r.data),
  ship: (id: number, payload: TransferStagePayload) =>
    apiClient.post<{ message: string; data: StockTransfer }>(`${BASE}/transfers/${id}/ship`, payload).then((r) => r.data),
  receive: (id: number, payload: TransferStagePayload) =>
    apiClient.post<{ message: string; data: StockTransfer }>(`${BASE}/transfers/${id}/receive`, payload).then((r) => r.data),
  cancel: (id: number) =>
    apiClient.post<{ message: string; data: StockTransfer }>(`${BASE}/transfers/${id}/cancel`, {}).then((r) => r.data),
};

export const stocktakesApi = {
  list: (params: { branch_id?: number; status?: string; per_page?: number; page?: number } = {}, signal?: AbortSignal) =>
    apiClient.get<ResourceCollection<Stocktake>>(`${BASE}/stocktakes`, { params, signal }).then((r) => r.data),
  show: (id: number, signal?: AbortSignal) =>
    apiClient.get<{ data: Stocktake }>(`${BASE}/stocktakes/${id}`, { signal }).then((r) => r.data.data),
  create: (payload: StocktakePayload, idempotencyKey: string) =>
    apiClient
      .post<{ message: string; data: Stocktake }>(`${BASE}/stocktakes`, payload, idempotencyHeaders(idempotencyKey))
      .then((r) => r.data),
  update: (id: number, payload: Partial<StocktakePayload> & { status?: string }) =>
    apiClient.put<{ data: Stocktake }>(`${BASE}/stocktakes/${id}`, payload).then((r) => r.data.data),
  updateItems: (id: number, items: StocktakeItemPayload[]) =>
    apiClient.patch<{ data: Stocktake }>(`${BASE}/stocktakes/${id}/items`, { items }).then((r) => r.data.data),
  approve: (id: number) =>
    apiClient.post<{ message: string; data: Stocktake }>(`${BASE}/stocktakes/${id}/approve`, {}).then((r) => r.data),
  remove: (id: number) =>
    apiClient.delete<{ message: string }>(`${BASE}/stocktakes/${id}`).then((r) => r.data),
};

export const productionApi = {
  options: (search = '', signal?: AbortSignal) =>
    apiClient.get<{ data: ProductionOptions }>(`${BASE}/production-receipts/options`, { params: search ? { search } : {}, signal }).then((r) => r.data.data),
  list: (params: { per_page?: number; page?: number } = {}, signal?: AbortSignal) =>
    apiClient.get<RawPaginated<ProductionReceipt>>(`${BASE}/production-receipts`, { params, signal }).then((r) => r.data),
  create: (payload: ProductionReceiptPayload, idempotencyKey: string) =>
    apiClient
      .post<{ message: string; data: ProductionReceipt }>(`${BASE}/production-receipts`, payload, idempotencyHeaders(idempotencyKey))
      .then((r) => r.data),
};
