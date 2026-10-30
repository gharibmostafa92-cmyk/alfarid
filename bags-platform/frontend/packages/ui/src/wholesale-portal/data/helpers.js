import { signedAttachmentUrl } from '@alfarid/api-client';

export const num = (v) => (v === null || v === undefined || v === '' ? null : Number(v));
export const day = (iso) => (iso ? String(iso).slice(0, 10) : null);
export const calendarDay = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
export const today = () => calendarDay(new Date());

export function apiError(e) {
  const r = e?.response;
  return {
    status: r?.status,
    message: r?.data?.message || 'حصل خطأ غير متوقع، حاول تاني.',
    fields: r?.data?.errors || {},
    code: r?.data?.code || r?.data?.errors?.code,
  };
}

export async function saveBlob(promise, fallbackName) {
  const res = await promise;
  const signedUrl = await signedAttachmentUrl(res);
  if (signedUrl) {
    const link = document.createElement('a');
    link.href = signedUrl;
    link.rel = 'noreferrer';
    document.body.appendChild(link);
    link.click();
    link.remove();
    return;
  }
  const cd = res.headers?.['content-disposition'] || '';
  const name = /filename="([^"]+)"/i.exec(cd)?.[1] || /filename=([^;\s]+)/i.exec(cd)?.[1] || fallbackName;
  const url = URL.createObjectURL(res.data);
  const a = document.createElement('a'); a.href = url; a.download = name; a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export const pageMeta = (meta) => ({ page: meta?.current_page ?? 1, pages: meta?.last_page ?? 1, total: meta?.total ?? 0, per: meta?.per_page ?? 15 });

export async function allPages(fetchPage, signal) {
  const items = [];
  let page = 1;
  let pages = 1;
  do {
    const response = await fetchPage(page, signal);
    items.push(...(response.data || []));
    pages = response.meta?.last_page ?? 1;
    page += 1;
  } while (page <= pages && !signal?.aborted);
  return items;
}
