import { num, day } from './helpers';
import { WALLET_TYPE } from './constants';

const QUOTE_ST = { priced_by_admin: 'priced', converted_to_order: 'converted' };
const NOTICE_ST = { pending_review: 'pending' };
const RETURN_ST = { pending: 'requested' };

export const mapAccount = (me, dash) => ({
  id: me.id, name: me.name, phone: me.phone, email: me.email,
  company: me.company_name, tax_number: me.tax_number, commercial_register: me.commercial_register,
  status: me.status, approved_at: day(me.approved_at),
  credit_limit: num(dash?.credit_limit ?? me.credit_limit),
  used_credit: num(dash?.used_credit), available_credit: num(dash?.available_credit),
  in_progress_orders_count: dash?.in_progress_orders_count ?? 0,
  wallet: num(me.wallet_balance),
});

export const mapVariant = (v, p) => ({
  id: v.id, sku: v.sku, barcode: v.barcode, label: v.label || v.sku,
  price: num(v.your_price), base: num(v.base_price), qty: num(v.available_qty) ?? 0,
  tiers: (v.tier_prices || []).map((t) => [num(t.min_qty), num(t.unit_price)]),
  product: p,
});

export const mapProduct = (p) => {
  const prod = { id: p.id, name_ar: p.name, name_en: p.name_en, image: p.image };
  prod.variants = (p.variants || []).map((v) => mapVariant(v, prod));
  prod.base = prod.variants.length ? Math.max(...prod.variants.map((v) => v.base ?? 0)) : 0;
  return prod;
};

export const mapOrder = (o) => ({
  id: o.id, number: o.order_number, date: day(o.created_at),
  status: o.status, method: o.payment_method, pay: o.payment_status,
  subtotal: num(o.subtotal), discount: num(o.discount_amount), shipping: num(o.shipping_cost),
  cod_fee: num(o.cod_fee), tax: num(o.tax_amount), total: num(o.grand_total),
  cancellable: !!o.cancellable, returnable: !!o.returnable,
  recipient: o.recipient_name, phone: o.recipient_phone, city: o.city_name, address: o.shipping_address_line,
  items: (o.items || []).map((i) => ({ id: i.id, name: i.product_name, sku: i.sku, qty: num(i.qty), unit: num(i.unit_price), discount: num(i.discount_amount), line_total: num(i.line_total) })),
  track: buildTrack(o.status, o.status_history || []),
});

export const mapInvoice = (o) => ({ ...mapOrder(o), number: o.invoice_number || o.order_number });

export const mapStatement = (s) => ({
  opening_balance: num(s.opening_balance), closing_balance: num(s.closing_balance),
  entries: (s.entries || []).map((e) => ({ date: day(e.date), ref: e.reference, desc: e.description,
    debit: num(e.debit), credit: num(e.credit), bal: num(e.balance) })),
});

export const mapShippingOptions = (r) => ({
  ...r,
  options: (r.options || []).map((o) => ({ ...o, cost: num(o.cost), cod_fee: num(o.cod_fee) })),
  preview: r.preview ? Object.fromEntries(Object.entries(r.preview).map(([k, v]) => [k, num(v)])) : null,
});

export const mapQuickLookup = (r) => ({
  ...r,
  data: (r.data || []).map((l) => ({ ...l, qty: num(l.qty), available_qty: num(l.available_qty),
    unit_price: num(l.unit_price), line_total: num(l.line_total) })),
  meta: { ...r.meta, total: num(r.meta?.total) },
});

const FLOW = ['pending', 'confirmed', 'processing', 'shipped', 'delivered'];
function buildTrack(current, history) {
  history = [...history].sort((a, b) => String(a.at || '').localeCompare(String(b.at || '')));
  const at = Object.fromEntries(history.map((h) => [h.status, h.at]));
  if (current === 'cancelled' || current === 'returned') {
    return history.map((h, i) => [h.status, h.at, i === history.length - 1 ? 'now' : 'done']);
  }
  const idx = FLOW.indexOf(current);
  return FLOW.map((s, i) => [s, at[s] || null, i < idx ? 'done' : i === idx ? 'now' : 'future']);
}

export const mapQuote = (q) => ({
  id: q.id, number: q.quotation_number, created: day(q.created_at), valid_until: day(q.valid_until),
  status: QUOTE_ST[q.status] || q.status,
  subtotal: num(q.subtotal), discount: num(q.discount_amount), tax: num(q.tax_amount), total: num(q.grand_total),
  trader_note: q.customer_notes, admin_note: q.admin_notes, converted_order_id: q.converted_order_id,
  items: (q.items || []).map((i) => ({ id: i.id, variant_id: i.product_variant_id, name: i.variant?.product_name, sku: i.variant?.sku, qty: num(i.qty), target: num(i.requested_target_price), offered: num(i.offered_unit_price), line_total: num(i.line_total) })),
});

export const mapNotice = (n) => ({
  id: n.id, number: n.notice_number, order_id: n.order_id, order: n.order_number,
  amount: num(n.amount), method: n.payment_method, date: day(n.transfer_date),
  status: NOTICE_ST[n.status] || n.status, created: day(n.created_at),
  bank: n.bank_name, ref: n.reference_number, admin_note: n.admin_notes, receipt: n.has_receipt,
});

export const mapReturn = (r) => ({
  id: r.id, number: r.return_number, order_id: r.order_id, order: r.order_number,
  status: RETURN_ST[r.status] || r.status, refund: num(r.total_refund_amount),
  method: r.refund_method, ref: r.refund_reference, date: day(r.created_at), reason: r.reason,
  approved_at: day(r.approved_at), received_at: day(r.received_at), refunded_at: day(r.refunded_at),
  items: (r.items || []).map((i) => ({ name: i.product_name, sku: i.sku, qty: num(i.requested_qty), reason: i.item_reason, photos: (i.attachments || []).length, attachments: i.attachments || [] })),
});

export const mapWalletTx = (t) => ({
  id: t.id, type: num(t.amount) < 0 ? 'debit' : 'credit', label: WALLET_TYPE[t.type] || t.type,
  amount: num(t.amount), after: num(t.balance_after), date: day(t.created_at),
  ref: t.reference_type ? `${t.reference_type} #${t.reference_id}` : '—',
});

export const mapNotif = (n) => ({
  id: n.id, icon: n.data?.icon || 'fa-bell', title: n.data?.title || '', body: n.data?.body || '',
  time: day(n.created_at), unread: !n.read_at,
  link: n.data?.link?.screen ? [n.data.link.screen, n.data.link.id] : null,
});

export const mapCart = (c) => ({
  id: c.id,
  lines: (c.items || []).map((i) => ({
    id: i.id, variant_id: i.product_variant_id, name: i.product_name,
    label: [i.color, i.size].filter(Boolean).join(' / '), sku: i.sku, image: i.image,
    qty: num(i.qty), unit: num(i.unit_price), base: num(i.base_price),
    line_discount: num(i.line_discount), line_total: num(i.line_total),
    available: !!i.available, max_qty: num(i.max_qty), priced: !!i.priced,
  })),
  totals: {
    subtotal: num(c.totals.subtotal), pricing_discount: num(c.totals.pricing_discount),
    items_total: num(c.totals.items_total), coupon_discount: num(c.totals.coupon_discount),
    total: num(c.totals.total), coupon_code: c.totals.coupon_code,
  },
});
