import { signedAttachmentUrl, allPages } from '@alfarid/api-client';

export const num = (v) => (v === null || v === undefined || v === '' ? null : Number(v));
const cairoCalendar = new Intl.DateTimeFormat('en', {
  timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit',
});

export function day(iso) {
  if (!iso) return null;
  const date = new Date(iso);
  if (!Number.isFinite(date.getTime())) return null;
  if (/^\d{4}-\d{2}-\d{2}$/.test(iso)) return date.toISOString().startsWith(iso) ? iso : null;
  const parts = cairoCalendar.formatToParts(date);
  return ['year', 'month', 'day'].map(type => parts.find(part => part.type === type).value).join('-');
}

export function tierPrice(variant, quantity) {
  let selectedPrice = variant.price;
  let selectedMinimum = -Infinity;
  for (const [minimum, price] of variant.tiers || []) {
    if (Number.isFinite(minimum) && Number.isFinite(price) && minimum <= quantity && minimum >= selectedMinimum) {
      selectedPrice = price;
      selectedMinimum = minimum;
    }
  }
  return selectedPrice;
}

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
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

export const pageMeta = (meta) => ({ page: meta?.current_page ?? 1, pages: meta?.last_page ?? 1, total: meta?.total ?? 0, per: meta?.per_page ?? 15 });

export { allPages };
