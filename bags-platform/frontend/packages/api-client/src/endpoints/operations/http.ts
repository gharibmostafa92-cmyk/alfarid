import type { AxiosResponse } from 'axios';
import { defaultApiClient as api } from '../../axios-instance';

export { api };
export const ADMIN = '/api/v1/admin';
export const SLOW_REQUEST_TIMEOUT = 190_000;
export type Id = number;

/** شكل Laravel paginate() القياسي */
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

/** يحوّل أي استجابة قائمة (paginated أو مصفوفة) إلى Paginated موحّد */
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

/** يفك { data: T } */
export function one<T>(r: AxiosResponse<any>): T {
  return (r.data && 'data' in r.data ? r.data.data : r.data) as T;
}

/** يحذف القيم الفارغة، ويحوّل boolean إلى 1/0 لأن Laravel `boolean` يرفض "true" */
export function cleanParams(params: object = {}): Record<string, string | number> {
  const out: Record<string, string | number> = {};
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '') continue;
    out[k] = typeof v === 'boolean' ? (v ? 1 : 0) : (v as any);
  }
  return out;
}

/** FormData لرفع الملفات. spoofPut=true عند التعديل لأن PHP لا يقرأ multipart مع PUT */
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

/** CRUD عام لموارد apiResource */
export function crud<T, P extends object, Q extends object = Record<string, unknown>>(path: string) {
  return {
    list: (params?: Q, signal?: AbortSignal) =>
      api.get(path, { params: cleanParams(params), signal }).then((r) => asPage<T>(r)),
    show: (id: Id, signal?: AbortSignal) => api.get(`${path}/${id}`, { signal }).then((r) => one<T>(r)),
    create: (body: P) => api.post(path, body).then((r) => one<T>(r)),
    update: (id: Id, body: Partial<P>) => api.put(`${path}/${id}`, body).then((r) => one<T>(r)),
    remove: (id: Id) => api.delete<{ message: string }>(`${path}/${id}`).then((r) => r.data),
  };
}

const SAFE_INLINE = new Set(['application/pdf', 'image/png', 'image/jpeg', 'image/webp']);

/** Signed private downloads are navigations; their bytes never pass through XHR. */
export async function signedAttachmentUrl(response: AxiosResponse): Promise<string | null> {
  if (!String(response.headers?.['content-type'] ?? '').includes('application/json')) return null;
  const body = response.data instanceof Blob ? JSON.parse(await response.data.text()) : response.data;
  if (typeof body?.url !== 'string' || !/^https?:\/\//i.test(body.url)) {
    throw new Error('Invalid attachment URL');
  }
  return body.url;
}

function navigateToAttachment(url: string): void {
  const a = document.createElement('a');
  a.href = url;
  a.rel = 'noreferrer';
  document.body.appendChild(a);
  a.click();
  a.remove();
}

/** تحميل مرفق محمي (يحتاج Bearer) وفتحه في تبويب جديد */
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
    throw e;
  }
}

/** تنزيل ملف (CSV) */
export async function downloadFile(url: string, filename: string, params?: object): Promise<void> {
  const r = await api.get(url, { responseType: 'blob', params: { ...cleanParams(params), signed: 1 }, timeout: SLOW_REQUEST_TIMEOUT });
  const signedUrl = await signedAttachmentUrl(r);
  if (signedUrl) {
    navigateToAttachment(signedUrl);
    return;
  }
  saveBlob(r.data as Blob, filename);
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

/** مفتاح Idempotency للعمليات المالية */
export const newIdempotencyKey = (): string =>
  (globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`);

/** ===== معالجة الأخطاء ===== */
export interface ParsedApiError {
  status: number | null;
  message: string;
  fieldErrors: Record<string, string>; // أول رسالة لكل حقل
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
