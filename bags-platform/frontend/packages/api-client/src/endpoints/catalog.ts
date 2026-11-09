import { defaultApiClient as apiClient } from '../axios-instance';
import { API_ENDPOINTS } from './constants';
import type {
  AdminBrand,
  AdminCategory,
  AdminMedia,
  AdminProduct,
  AdminVariant,
  BrandPayload,
  CategoryPayload,
  CategoryReorderItem,
  ProductPayload,
  ProductStatus,
  ResourceCollection,
  SalesChannel,
  VariantPayload,
} from '../types';

const MULTIPART = { headers: { 'Content-Type': 'multipart/form-data' } };

function appendFormField(fd: FormData, key: string, v: unknown): void {
  if (v === undefined) return;
  if (v === null) return fd.append(key, '');
  if (typeof v === 'boolean') return fd.append(key, v ? '1' : '0');
  if (v instanceof Blob) return fd.append(key, v);
  fd.append(key, String(v));
}

function categoryFormData(p: CategoryPayload, spoofPut = false): FormData {
  const fd = new FormData();
  if (spoofPut) fd.append('_method', 'PUT');
  const put = (key: string, v: unknown) => appendFormField(fd, key, v);
  put('parent_id', p.parent_id);
  put('name_ar', p.name_ar);
  put('name_en', p.name_en);
  put('slug', p.slug);
  put('description_ar', p.description_ar);
  put('description_en', p.description_en);
  put('sort_order', p.sort_order);
  put('is_active', p.is_active);
  if (p.image) put('image', p.image);
  if (p.remove_image) put('remove_image', true);
  return fd;
}

function progress(cb?: (n: number) => void) {
  return (e: { loaded: number; total?: number }) => cb?.(Math.round((e.loaded / (e.total || 1)) * 100));
}

const CAT = API_ENDPOINTS.ADMIN.CATEGORIES;

export const categoriesApi = {
  tree: (signal?: AbortSignal) =>
    apiClient.get<{ data: AdminCategory[] }>(CAT, { params: { tree: 1 }, signal }).then((r) => r.data.data),
  show: (id: number) =>
    apiClient.get<{ data: AdminCategory }>(`${CAT}/${id}`).then((r) => r.data.data),
  create: (p: CategoryPayload, onProgress?: (pct: number) => void) =>
    apiClient.post<{ data: AdminCategory }>(CAT, categoryFormData(p), {
      ...MULTIPART,
      onUploadProgress: progress(onProgress),
    }).then((r) => r.data.data),
  update: (id: number, p: CategoryPayload, onProgress?: (pct: number) => void) =>
    apiClient.post<{ data: AdminCategory }>(`${CAT}/${id}`, categoryFormData(p, true), {
      ...MULTIPART,
      onUploadProgress: progress(onProgress),
    }).then((r) => r.data.data),
  remove: (id: number) =>
    apiClient.delete<{ message: string }>(`${CAT}/${id}`).then((r) => r.data),
  reorder: (items: CategoryReorderItem[]) =>
    apiClient.put<{ message: string }>(`${CAT}/reorder`, { items }).then((r) => r.data),
};

const BR = '/api/v1/admin/brands';
const PR = '/api/v1/admin/products';

export interface BrandListParams { search?: string; is_active?: 0 | 1; page?: number; per_page?: number }
export interface ProductListParams {
  search?: string; category_id?: number; include_children?: 0 | 1; brand_id?: number;
  status?: ProductStatus; sales_channel?: SalesChannel; is_featured?: 0 | 1;
  sort?: 'latest' | 'oldest' | 'name'; page?: number; per_page?: number;
}

function brandFormData(p: BrandPayload, spoofPut = false): FormData {
  const fd = new FormData();
  if (spoofPut) fd.append('_method', 'PUT');
  const put = (key: string, v: unknown) => appendFormField(fd, key, v);
  put('name_ar', p.name_ar);
  put('name_en', p.name_en);
  put('slug', p.slug);
  put('description_ar', p.description_ar);
  put('description_en', p.description_en);
  put('sort_order', p.sort_order);
  put('is_active', p.is_active);
  if (p.logo) put('logo', p.logo);
  if (p.remove_logo) put('remove_logo', true);
  return fd;
}

export const brandsApi = {
  list: (params: BrandListParams, signal?: AbortSignal) =>
    apiClient.get<ResourceCollection<AdminBrand>>(BR, { params, signal }).then(r => r.data),
  show: (id: number) => apiClient.get<{ data: AdminBrand }>(`${BR}/${id}`).then(r => r.data.data),
  create: (p: BrandPayload, onProgress?: (n: number) => void) =>
    apiClient.post<{ data: AdminBrand }>(BR, brandFormData(p), { ...MULTIPART, onUploadProgress: progress(onProgress) }).then(r => r.data.data),
  update: (id: number, p: BrandPayload, onProgress?: (n: number) => void) =>
    apiClient.post<{ data: AdminBrand }>(`${BR}/${id}`, brandFormData(p, true), { ...MULTIPART, onUploadProgress: progress(onProgress) }).then(r => r.data.data),
  remove: (id: number) => apiClient.delete<{ message: string }>(`${BR}/${id}`).then(r => r.data),
};

export const productsApi = {
  list: (params: ProductListParams, signal?: AbortSignal) =>
    apiClient.get<ResourceCollection<AdminProduct>>(PR, { params, signal }).then(r => r.data),
  show: (id: number, signal?: AbortSignal) => apiClient.get<{ data: AdminProduct }>(`${PR}/${id}`, { signal }).then(r => r.data.data),
  create: (p: ProductPayload & { variants?: VariantPayload[] }) => apiClient.post<{ data: AdminProduct }>(PR, p).then(r => r.data.data),
  update: (id: number, p: Partial<ProductPayload> & { status?: ProductStatus; expected_updated_at?: string }) =>
    apiClient.put<{ data: AdminProduct }>(`${PR}/${id}`, p).then(r => r.data.data),
  remove: (id: number) => apiClient.delete<{ message: string }>(`${PR}/${id}`).then(r => r.data),
};

export const variantsApi = {
  list: (productId: number) => apiClient.get<{ data: AdminVariant[] }>(`${PR}/${productId}/variants`).then(r => r.data.data),
  create: (productId: number, p: VariantPayload) => apiClient.post<{ data: AdminVariant }>(`${PR}/${productId}/variants`, p).then(r => r.data.data),
  update: (productId: number, id: number, p: Partial<VariantPayload>) => apiClient.put<{ data: AdminVariant }>(`${PR}/${productId}/variants/${id}`, p).then(r => r.data.data),
  remove: (productId: number, id: number) => apiClient.delete(`${PR}/${productId}/variants/${id}`).then(r => r.data),
};

export const productMediaApi = {
  list: (productId: number) => apiClient.get<{ data: AdminMedia[] }>(`${PR}/${productId}/media`).then(r => r.data.data),
  uploadImages: (productId: number, files: File[], variantId?: number | null, onProgress?: (n: number) => void, clientUploadIds?: string[]) => {
    const fd = new FormData();
    fd.append('collection', 'images');
    if (variantId) fd.append('variant_id', String(variantId));
    files.forEach((f, index) => {
      fd.append('files[]', f);
      fd.append('client_upload_ids[]', clientUploadIds?.[index] ?? crypto.randomUUID());
    });
    return apiClient.post<{ data: AdminMedia[] }>(`${PR}/${productId}/media`, fd, { ...MULTIPART, timeout: 180000, onUploadProgress: progress(onProgress) }).then(r => r.data.data);
  },
  uploadVideo: (productId: number, file: File, onProgress?: (n: number) => void) => {
    const fd = new FormData();
    fd.append('collection', 'video');
    fd.append('file', file);
    return apiClient.post<{ data: AdminMedia[] }>(`${PR}/${productId}/media`, fd, { ...MULTIPART, onUploadProgress: progress(onProgress), timeout: 0 }).then(r => r.data.data);
  },
  reorder: (productId: number, ids: number[]) => apiClient.put(`${PR}/${productId}/media/reorder`, { ids }).then(r => r.data),
  remove: (productId: number, mediaId: number) => apiClient.delete(`${PR}/${productId}/media/${mediaId}`).then(r => r.data),
};
