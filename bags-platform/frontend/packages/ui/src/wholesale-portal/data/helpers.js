import { signedAttachmentUrl, allPages } from '@alfarid/api-client';

export const num = (v) => {
  if (v === null || v === undefined || v === '') return null;
  const value = Number(v);
  return Number.isFinite(value) ? value : null;
};
export const parsePortalId = (value) => {
  if (typeof value !== 'string' || !/^\d+$/.test(value)) return null;
  const id = Number(value);
  return Number.isSafeInteger(id) && id > 0 ? id : null;
};
export const shouldRenewCheckoutKey = (info) => info.code === 'price_changed' || info.status === 422;
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

export function cairoParts(date = new Date()) {
  const parts = cairoCalendar.formatToParts(date);
  const get = (type) => parts.find((part) => part.type === type).value;
  return { year: get('year'), month: get('month'), day: get('day') };
}

export const today = () => {
  const parts = cairoParts();
  return `${parts.year}-${parts.month}-${parts.day}`;
};

export const firstOfMonth = () => `${today().slice(0, 8)}01`;

export const startOfYear = () => `${cairoParts().year}-01-01`;

export function monthsAgoFirst(months) {
  const parts = cairoParts();
  const shifted = new Date(Date.UTC(Number(parts.year), Number(parts.month) - 1 - months, 1, 12));
  const shiftedParts = cairoParts(shifted);
  return `${shiftedParts.year}-${shiftedParts.month}-01`;
}

export function pagerItems(page, pages) {
  if (pages <= 7) return Array.from({ length: pages }, (_, i) => i + 1);
  const start = Math.max(2, page - 2);
  const end = Math.min(pages - 1, page + 2);
  const items = [1];
  if (start > 2) items.push('…');
  for (let i = start; i <= end; i += 1) items.push(i);
  if (end < pages - 1) items.push('…');
  items.push(pages);
  return items;
}

export function addQuoteLine(items, line) {
  const index = items.findIndex((item) => item.variant_id === line.variant_id);
  if (index === -1) return [...items, line];
  return items.map((item, i) => (i === index ? { ...item, qty: Number(item.qty) + Number(line.qty || 1) } : item));
}

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
    if (!/^https?:\/\//i.test(signedUrl) && !/^\/(?!\/)/.test(signedUrl)) throw new Error('رابط الملف غير صالح.');
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
