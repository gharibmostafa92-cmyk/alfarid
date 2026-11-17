import type { AxiosResponse } from 'axios';
import { defaultApiClient as api } from '../../axios-instance';

export { api };
export const ADMIN = '/api/v1/admin';
export const SLOW_REQUEST_TIMEOUT = 190_000;
export type Id = number;

/** Standard Laravel pagination metadata structure. */
export interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}
export interface Paginated<T> {
  data: T[];
  links?: Record<string, string | null>;
  meta: PageMeta;
}

/** Normalizes diverse list responses (paginated objects or raw arrays) into a standard Paginated<T> structure. */
export function asPage<T>(r: AxiosResponse<any>): Paginated<T> {
  const body = r.data;
  if (body && Array.isArray(body.data) && body.meta) return body as Paginated<T>;
  if (body && Array.isArray(body.data) && typeof body.current_page === 'number') {
    return { data: body.data, meta: {
      current_page: body.current_page, last_page: body.last_page, per_page: body.per_page,
      total: body.total, from: body.from ?? null, to: body.to ?? null,
    } };
  }
  const arr: T[] = Array.isArray(body) ? body : Array.isArray(body?.data) ? body.data : [];
  return { data: arr, meta: { current_page: 1, last_page: 1, per_page: arr.length || 20, total: arr.length, from: arr.length ? 1 : null, to: arr.length || null } };
}

/** Unwraps single entity payload from `{ data: T }` responses. */
export function one<T>(r: AxiosResponse<any>): T {
  return (r.data && 'data' in r.data ? r.data.data : r.data) as T;
}

/** Strips empty query parameters and normalizes booleans to 1/0 for Laravel query string validation. */
export function cleanParams(params: object = {}): Record<string, string | number> {
  const out: Record<string, string | number> = {};
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '') continue;
    out[k] = typeof v === 'boolean' ? (v ? 1 : 0) : (v as any);
  }
  return out;
}

/** Builds FormData payloads for multipart uploads, including `_method: PUT` spoofing for PHP compatibility. */
export function toFormData(obj: Record<string, unknown>, spoofPut = false): FormData {
  const fd = new FormData();
  if (spoofPut) fd.append('_method', 'PUT');
  const put = (key: string, v: unknown) => {
    if (v === undefined) return;
    if (v === null) return fd.append(key, '');
    if (typeof v === 'boolean') return fd.append(key, v ? '1' : '0');
    if (v instanceof Blob) return fd.append(key, v);
    if (Array.isArray(v)) {
      v.forEach((item, i) => {
        if (item !== null && typeof item === 'object' && !(item instanceof Blob)) {
          Object.entries(item).forEach(([ik, iv]) => put(`${key}[${i}][${ik}]`, iv));
        } else put(`${key}[${i}]`, item);
      });
      return;
    }
    fd.append(key, String(v));
  };
  Object.entries(obj).forEach(([k, v]) => put(k, v));
  return fd;
}
export const MULTIPART = { headers: { 'Content-Type': 'multipart/form-data' } };

/** Generates standard REST read methods (list & show) for an endpoint. */
export function reader<T, Q extends object = Record<string, unknown>>(path: string) {
  return {
    list: (params?: Q, signal?: AbortSignal) =>
      api.get(path, { params: cleanParams(params), signal }).then((r) => asPage<T>(r)),
    show: (id: Id, signal?: AbortSignal) => api.get(`${path}/${id}`, { signal }).then((r) => one<T>(r)),
  };
}

/** Generates standard REST CRUD client methods for an apiResource endpoint. */
export function crud<T, P extends object, Q extends object = Record<string, unknown>>(path: string) {
  return {
    ...reader<T, Q>(path),
    create: (body: P) => api.post(path, body).then((r) => one<T>(r)),
    update: (id: Id, body: Partial<P>) => api.put(`${path}/${id}`, body).then((r) => one<T>(r)),
    remove: (id: Id) => api.delete<{ message: string }>(`${path}/${id}`).then((r) => r.data),
  };
}

const SAFE_INLINE = new Set(['application/pdf', 'image/png', 'image/jpeg', 'image/webp']);
export const DEFAULT_MAX_PAGES = 20;

const TRUSTED_STORAGE_SUFFIXES = [
  '.r2.cloudflarestorage.com',
  '.r2.dev',
  '.amazonaws.com',
  '.digitaloceanspaces.com',
];

function isTrustedAttachmentHost(hostname: string): boolean {
  const host = hostname.toLowerCase();
  if (TRUSTED_STORAGE_SUFFIXES.some((suffix) => host === suffix.slice(1) || host.endsWith(suffix))) {
    return true;
  }
  const baseUrl = (api as { defaults?: { baseURL?: string } })?.defaults?.baseURL;
  if (baseUrl) {
    try {
      if (new URL(baseUrl).hostname.toLowerCase() === host) return true;
    } catch { /* ignore invalid baseURL */ }
  }
  if (typeof window !== 'undefined' && window.location?.hostname) {
    if (window.location.hostname.toLowerCase() === host) return true;
  }
  const proc = (globalThis as { process?: { env?: Record<string, string | undefined> } }).process;
  const envHosts = (proc?.env?.VITE_ATTACHMENT_ALLOWED_HOSTS || '')
    .split(',')
    .map((h: string) => h.trim().toLowerCase())
    .filter(Boolean);
  return envHosts.some((allowed: string) => host === allowed || (allowed.startsWith('.') && host.endsWith(allowed)));
}

/** Traverses all pages of a paginated resource callback and collects all items into a single array. */
export async function allPages<T>(
  loadPage: (page: number, signal?: AbortSignal) => Promise<{ data: T[]; meta?: { last_page?: number } }>,
  signal?: AbortSignal,
  opts: { maxPages?: number } = {},
): Promise<T[]> {
  const items: T[] = [];
  const maxPages = opts.maxPages ?? DEFAULT_MAX_PAGES;
  let page = 1;
  let last = 1;
  do {
    if (signal?.aborted) break;
    const response = await loadPage(page, signal);
    items.push(...(response.data || []));
    const reportedLast = Number(response.meta?.last_page ?? 1);
    last = Number.isFinite(reportedLast) && reportedLast >= 1 ? Math.floor(reportedLast) : 1;
    if (page >= last) break;
    if (page >= maxPages) {
      throw new Error(`Pagination exceeded maximum page limit (${maxPages})`);
    }
    page += 1;
  } while (!signal?.aborted);
  return items;
}

/** Signed private downloads are navigations; their bytes never pass through XHR. */
export async function signedAttachmentUrl(response: AxiosResponse): Promise<string | null> {
  if (!String(response.headers?.['content-type'] ?? '').includes('application/json')) return null;
  const body = response.data instanceof Blob ? JSON.parse(await response.data.text()) : response.data;
  if (typeof body?.url !== 'string') {
    throw new Error('Invalid attachment URL');
  }
  let parsed: URL;
  try {
    parsed = new URL(body.url);
  } catch {
    throw new Error('Invalid attachment URL');
  }
  const isLocalHttp =
    parsed.protocol === 'http:' && (parsed.hostname === 'localhost' || parsed.hostname === '127.0.0.1');
  if (parsed.protocol !== 'https:' && !isLocalHttp) {
    throw new Error('Invalid attachment URL');
  }
  if (!isLocalHttp && !isTrustedAttachmentHost(parsed.hostname)) {
    throw new Error('Untrusted attachment host');
  }
  return body.url;
}

async function decodeBlobError(e: unknown): Promise<never> {
  const err = e as { response?: { data?: unknown; headers?: Record<string, string> } };
  const blob = err?.response?.data;
  if (blob instanceof Blob) {
    try {
      const text = await blob.text();
      const ct = String(err.response?.headers?.['content-type'] ?? '');
      if (ct.includes('application/json') || text.trimStart().startsWith('{')) {
        err.response!.data = JSON.parse(text);
      }
    } catch {
      // Leave original error intact if blob is not valid JSON
    }
  }
  throw e;
}

function navigateToAttachment(url: string): void {
  const a = document.createElement('a');
  a.href = url;
  a.rel = 'noreferrer';
  document.body.appendChild(a);
  a.click();
  a.remove();
}

/** Streams protected authenticated file downloads in a new tab via signed R2 URLs. */
export async function openProtectedFile(url: string): Promise<void> {
  const w = window.open('', '_blank');
  if (w) w.opener = null;
  try {
    const r = await api.get(url, { responseType: 'blob', params: { signed: 1 } });
    const signedUrl = await signedAttachmentUrl(r);
    if (signedUrl) {
      if (w) w.location.href = signedUrl;
      else navigateToAttachment(signedUrl);
      return;
    }
    const type = String(r.headers?.['content-type'] ?? '').split(';')[0].trim().toLowerCase();
    if (!SAFE_INLINE.has(type) || !w) {
      if (w) w.close();
      saveBlob(r.data as Blob, 'attachment');
      return;
    }
    const blob = new Blob([r.data], { type });
    const blobUrl = URL.createObjectURL(blob);
    w.location.href = blobUrl;
    setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000);
  } catch (e) {
    if (w) w.close();
    await decodeBlobError(e);
  }
}

/** Downloads binary export files (e.g., CSV) using signed URLs or local Blob triggers. */
export async function downloadFile(url: string, filename: string, params?: object): Promise<void> {
  try {
    const r = await api.get(url, { responseType: 'blob', params: { ...cleanParams(params), signed: 1 }, timeout: SLOW_REQUEST_TIMEOUT });
    const signedUrl = await signedAttachmentUrl(r);
    if (signedUrl) {
      navigateToAttachment(signedUrl);
      return;
    }
    saveBlob(r.data as Blob, filename);
  } catch (e) {
    await decodeBlobError(e);
  }
}

function saveBlob(blob: Blob, filename: string): void {
  const a = document.createElement('a');
  const href = URL.createObjectURL(blob);
  a.href = href;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => {
    URL.revokeObjectURL(href);
    a.remove();
  }, 30_000);
}

/** Generates unique idempotency key for transactional operations. */
export const newIdempotencyKey = (): string =>
  (globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`);

const pendingPostKeys = new Map<string, { payload: string; key: string }>();

/**
 * Sends an idempotent POST. When the caller does not own a longer-lived key,
 * retries with the same URL and payload reuse a key until the server confirms
 * success; changing the payload starts a new operation.
 */
export async function idempotentPost<T = unknown>(
  url: string,
  body: unknown,
  idempotencyKey?: string,
): Promise<AxiosResponse<T>> {
  const payload = JSON.stringify(body) ?? 'undefined';
  const pending = pendingPostKeys.get(url);
  const key = idempotencyKey ?? (pending?.payload === payload ? pending.key : newIdempotencyKey());
  if (!idempotencyKey) pendingPostKeys.set(url, { payload, key });

  const response = await api.post<T>(url, body, { headers: { 'Idempotency-Key': key } });
  if (!idempotencyKey && pendingPostKeys.get(url)?.key === key) pendingPostKeys.delete(url);
  return response;
}

/** API Error Handling and Mapping */
export interface ParsedApiError {
  status: number | null;
  message: string;
  fieldErrors: Record<string, string>; // First error message per validated field
}
export function parseApiError(e: unknown): ParsedApiError {
  const err = e as any;
  const status: number | null = err?.response?.status ?? null;
  const body = err?.response?.data ?? {};
  const fieldErrors: Record<string, string> = {};
  if (body.errors && typeof body.errors === 'object') {
    for (const [k, v] of Object.entries(body.errors)) fieldErrors[k] = Array.isArray(v) ? String(v[0]) : String(v);
  }
  let message = body.message as string | undefined;
  if (!message) {
    if (status === null) message = 'تعذّر الاتصال بالخادم';
    else if (status === 403) message = 'ليست لديك صلاحية لتنفيذ هذا الإجراء';
    else if (status === 404) message = 'السجل غير موجود';
    else if (status >= 500) message = 'حدث خطأ في الخادم، حاول لاحقاً';
    else message = 'تعذّر تنفيذ الطلب';
  }
  return { status, message, fieldErrors };
}
