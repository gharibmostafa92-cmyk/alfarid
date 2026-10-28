import type { AdminOrder, ReturnRequest, ShippingCompany, ShippingZone, ShippingRate, CustomerGroupApi, PriceListApi, PromotionApi, CouponApi } from '@alfarid/api-client';
import type { OrderRow, ReturnRow, ShipCompany, ShipZone, ShipRate, CustomerGroup, PriceListRow, PromoRow, CouponRow } from '../data';

const TZ = 'Africa/Cairo';
export const toDate = (iso?: string | null): string => {
  if (!iso) return '';
  const date = new Date(iso);
  if (!Number.isFinite(date.getTime())) return '';
  const parts = new Intl.DateTimeFormat('en', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date);
  const value = (key: string) => parts.find(part => part.type === key)?.value ?? '';
  return value('year') + '-' + value('month') + '-' + value('day');
};
export const toTime = (iso?: string | null): string => {
  if (!iso) return '';
  const date = new Date(iso);
  if (!Number.isFinite(date.getTime())) return '';
  return date.toLocaleTimeString('en-GB', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });
};
export const num = (v: string | number | null | undefined) => (v === null || v === undefined || v === '' ? 0 : Number(v));

export const mapErrors = (e: Record<string, string>, m: Record<string, string>) =>
  Object.fromEntries(Object.entries(e).map(([k, v]) => [m[k] ?? k, v])) as Record<string, string>;

const CTYPE_GUEST = 'زائر';
const CTYPE_WHOLESALE = 'جملة';
const CTYPE_RETAIL = 'قطاعي';

/** normalize backend values to the keys the current UI maps expect */
const normalizePay = (p?: string | null): string => {
  if (!p) return '';
  return p === 'bank_transfer' ? 'bank' : p;
};
const normalizeRefundMethod = (m?: string | null): string | null => {
  if (!m) return null;
  if (m === 'original_payment') return 'original';
  if (m === 'bank_transfer') return 'bank';
  return m;
};

export function mapOrder(o: AdminOrder): OrderRow {
  return {
    id: o.id,
    no: o.order_number,
    customer: o.customer?.name ?? o.guest_name ?? '—',
    guest: !o.customer,
    ctype: !o.customer ? CTYPE_GUEST : o.customer.type === 'wholesale' ? CTYPE_WHOLESALE : CTYPE_RETAIL,
    phone: o.customer?.phone ?? o.guest_phone ?? '',
    channel: o.channel ?? 'web',
    city: (o.city as any)?.name_ar ?? (o.city as any)?.name ?? '',
    status: o.status,
    pay: normalizePay(o.payment_method),
    payStatus: o.payment_status,
    branch: o.allocated_branch?.name ?? null,
    total: num(o.grand_total),
    date: toDate(o.created_at),
    time: toTime(o.created_at),
    followUp: !!o.needs_attention,
    needRefund: !!o.requires_refund,
  };
}

export function mapReturn(r: ReturnRequest, branchName?: (id: number | null) => string | null): ReturnRow {
  return {
    id: r.id,
    no: r.return_number,
    order: r.order_number ?? `#${r.order_id}`,
    customer: r.customer_name ?? '—',
    branch: branchName ? branchName(r.branch_id) : r.branch_id ? `#${r.branch_id}` : null,
    status: r.status,
    method: normalizeRefundMethod(r.refund_method),
    total: num(r.total_refund_amount),
    date: toDate(r.created_at),
  };
}

export const branchOptions = (list: { id: number; name: string }[]) => list.map((b) => ({ value: String(b.id), label: b.name }));

export const mapShipCompany = (c: ShippingCompany): ShipCompany => ({
  id: c.id, name: c.name, code: c.code, url: c.tracking_url_template ?? '', active: c.is_active, api: !!c.has_api_credentials,
});
export const mapShipZone = (z: ShippingZone): ShipZone & { cityIds: number[] } => ({
  id: z.id, name: z.name, active: z.is_active,
  cities: (z.cities ?? []).map((c) => c.name_ar ?? ''), cityIds: (z.cities ?? []).map((c) => c.id),
});
export const mapShipRate = (r: ShippingRate): ShipRate & { companyId: number } => ({
  id: r.id, co: r.company_name ?? String(r.shipping_company_id), companyId: r.shipping_company_id,
  zone: r.shipping_zone_id, base: num(r.base_cost),
  free: r.free_shipping_threshold === null ? null : num(r.free_shipping_threshold),
  cod: num(r.cod_fee ?? 0), min: r.estimated_days_min, max: r.estimated_days_max,
});
export const mapCustomerGroup = (g: CustomerGroupApi): CustomerGroup => ({
  id: g.id, name: g.name, code: g.code, desc: g.description, disc: num(g.discount_percentage), active: g.is_active,
});
export const mapPriceList = (p: PriceListApi): PriceListRow & { raw: PriceListApi } => ({
  id: p.id, name: p.name, type: p.type === 'customer_group' ? 'group' : p.type === 'customer_specific' ? 'customer' : p.type,
  target: p.customer?.name ?? p.customer_group?.name ?? null,
  branch: p.branch?.name ?? null,
  from: p.starts_at?.slice(0, 10) ?? '', to: p.ends_at?.slice(0, 10) ?? null,
  prio: p.priority, active: p.is_active, items: p.items_count ?? 0, raw: p,
});
const promoToUI = (t: string): string => t === 'percentage_discount' ? 'percent' : t === 'fixed_discount' ? 'amount' : t === 'buy_x_get_y' ? 'bxgy' : 'free_ship';
export const promoToBackend = (t: string): any => t === 'percent' ? 'percentage_discount' : t === 'amount' ? 'fixed_discount' : t === 'bxgy' ? 'buy_x_get_y' : 'free_shipping';
export const mapPromo = (p: PromotionApi): PromoRow => ({
  id: p.id, name: p.name, type: promoToUI(p.type) as PromoRow['type'], value: p.value === null ? null : num(p.value),
  min: p.min_order_amount === null ? null : num(p.min_order_amount),
  scope: p.applies_to as PromoRow['scope'], targets: [],
  from: p.starts_at?.slice(0, 10) ?? '', to: p.ends_at?.slice(0, 10) ?? null, active: p.is_active,
});
const couponToUI = (t: string): string => t === 'percentage' ? 'percent' : t === 'fixed' ? 'amount' : 'free_ship';
export const couponToBackend = (t: string): any => t === 'percent' ? 'percentage' : t === 'amount' ? 'fixed' : 'free_shipping';
export const mapCoupon = (c: CouponApi): CouponRow => ({
  id: c.id, code: c.code, type: couponToUI(c.type) as CouponRow['type'], value: num(c.value) || null,
  maxDisc: c.max_discount_amount === null ? null : num(c.max_discount_amount), min: num(c.min_order_amount),
  total: c.usage_limit, perCust: c.usage_limit_per_customer, used: c.used_count,
  from: c.starts_at?.slice(0, 10) ?? '', to: c.ends_at?.slice(0, 10) ?? null, active: c.is_active,
});

export async function optimisticToggle<T extends { id: number }>(
  list: T[], setList: (l: T[]) => void, id: number, patch: Partial<T>, call: () => Promise<unknown>,
  toast: (t: string, tone?: 'accent' | 'green' | 'red') => void,
) {
  const prev = list;
  setList(list.map((x) => (x.id === id ? { ...x, ...patch } : x)));
  try { await call(); } catch (e) {
    const { parseApiError } = await import('@alfarid/api-client');
    setList(prev); toast(parseApiError(e).message, 'red');
  }
}
