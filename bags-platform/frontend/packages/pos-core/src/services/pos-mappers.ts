import type {
  Customer,
  PastSale,
  PosHeldSale,
  PosProduct,
  PosShift,
  QuoteResult,
  StockTransfer,
  TransferSource,
} from '../types/pos.types';

const SHIFT_STATUS = { open: 'open', closed_pending_approval: 'pending_approval', approved: 'closed' } as const;

export const mapShift = (s: any): PosShift => ({
  ...s,
  status: SHIFT_STATUS[s.status as keyof typeof SHIFT_STATUS] ?? 'open',
  treasury_id: Number(s.treasury_id ?? s.treasury?.id),
  treasury: s.treasury ? { state: 'busy', last: '', ...s.treasury } : s.treasury,
  branch_id: s.branch_id ?? s.branch?.id,
  opening_cash: Number(s.opening_cash ?? 0),
  cash_sales_total: s.cash_sales_total == null ? undefined : Number(s.cash_sales_total),
  card_sales_total: s.card_sales_total == null ? undefined : Number(s.card_sales_total),
  cash_returns_total: s.cash_returns_total == null ? undefined : Number(s.cash_returns_total),
  cash_expenses_total: s.cash_expenses_total == null ? undefined : Number(s.cash_expenses_total),
  // Blind cashiers get no expected total: keep it undefined so the UI never renders a coerced 0.00.
  expected_closing_cash: s.expected_closing_cash == null ? undefined : Number(s.expected_closing_cash),
  actual_closing_cash: s.actual_closing_cash == null ? null : Number(s.actual_closing_cash),
  cash_difference: s.cash_difference == null ? null : Number(s.cash_difference),
  opened_at: new Date(s.opened_at),
  closed_at: s.closed_at ? new Date(s.closed_at) : null,
});

export const mapProduct = (p: any): PosProduct => ({
  id: Number(p.id),
  sku: p.sku ?? '',
  barcode: p.barcode ?? '',
  name: p.name ?? [p.product_name, p.variant_name].filter(Boolean).join(' - '),
  cat: p.category_id != null ? String(p.category_id) : 'all',
  price: Number(p.unit_price ?? 0),
  wholesale: Number(p.wholesale_price ?? p.unit_price ?? 0),
  stock: Number(p.available ?? p.on_hand ?? 0),
  reorder: Number(p.reorder_point ?? 0),
  h: (Number(p.id) * 47) % 360, // placeholder-art hue
});

const timeAr = (iso?: string) =>
  new Date(iso ?? Date.now()).toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });

export const mapHeld = (h: any): PosHeldSale => {
  const customerObj = h.customer && typeof h.customer === 'object' ? mapCustomer(h.customer) : null;
  return {
    ref: h.hold_reference,
    at: timeAr(h.created_at),
    mins: Math.max(0, Math.floor((Date.now() - +new Date(h.created_at ?? Date.now())) / 60000)),
    customer: customerObj?.name ?? (typeof h.customer === 'string' ? h.customer : null),
    customerObj,
    note: h.notes ?? '',
    items: (h.items_payload ?? []).map((i: any) => ({
      id: Number(i.product_variant_id ?? i.id),
      qty: Number(i.qty),
      ...(i.unit_price != null ? { unit_price: Number(i.unit_price) } : {}),
      ...(i.discount_amount != null ? { discount_amount: Number(i.discount_amount) } : {}),
      // F-03: the explicit manual flag survives hold/resume so wholesale and
      // scheduled prices are never mistaken for cashier overrides.
      ...(i.manual_price != null ? { manual_price: Boolean(i.manual_price) } : {}),
    })),
    serverId: Number(h.id),
    _id: Number(h.id),
    resumedBy: h.resumed_by ?? null,
    resumedAt: h.resumed_at ?? null,
  };
};

export const mapCustomer = (c: any): Customer => ({
  id: Number(c.id),
  name: c.name,
  phone: c.phone ?? '',
  tier: c.type === 'distributor' ? 'distributor' : c.type === 'wholesale' ? 'wholesale' : 'retail',
  points: Number(c.points ?? c.loyalty_points ?? 0),
  status: c.status ?? null,
});

const METHOD_AR: Record<string, string> = { cash: 'نقدي', card: 'بطاقة', mixed: 'مختلط' };

/** Prices shown for returns are net_unit_price (after line discount), never list price. */
export const mapPastSale = (s: any): PastSale => ({
  id: Number(s.id),
  number: s.order_number,
  date:
    new Date(s.created_at).toLocaleDateString('ar-EG', { day: 'numeric', month: 'long', year: 'numeric' }) +
    ` — ${timeAr(s.created_at)}`,
  cashier: s.cashier?.name ?? '',
  customer: s.customer?.name ?? 'عميل نقدي',
  customer_id: s.customer?.id == null ? null : Number(s.customer.id),
  method: METHOD_AR[s.payment_method] ?? s.payment_method ?? '',
  cash_amount: Number(s.cash_amount ?? 0),
  card_amount: Number(s.card_amount ?? 0),
  items: (s.items ?? []).map((i: any) => ({
    id: Number(i.product_variant_id),
    qty: Number(i.qty),
    price: Number(i.net_unit_price ?? i.unit_price ?? 0),
    net_unit_price: Number(i.net_unit_price ?? i.unit_price ?? 0),
    returnable_qty: Number(i.returnable_qty ?? i.qty),
    // F-07: full line value + refund history for server-compatible cent math.
    line_total: Number(i.line_total ?? (Number(i.qty) || 0) * Number(i.unit_price ?? 0)),
    previous_refunded_amount: Number(i.previous_refunded_amount ?? 0),
  })),
});

/** `id` is the variant id (what screens render by); `transferItemId` is what receive needs. */
export const mapTransfer = (t: any): StockTransfer => ({
  id: Number(t.id),
  version: typeof t.version === 'number' ? t.version : undefined,
  number: t.transfer_number,
  from: t.from_branch?.name ?? '',
  status: (t.status as StockTransfer['status']) ?? 'shipped',
  shipped: t.shipped_at ? `${new Date(t.shipped_at).toLocaleDateString('ar-EG')} ${timeAr(t.shipped_at)}` : '',
  driver: '',
  items: (t.items ?? []).map((i: any) => ({
    id: Number(i.product_variant_id),
    qty: Number(i.shipped_qty ?? i.requested_qty ?? 0),
    transferItemId: Number(i.id),
  })),
});

export const mapTransferSource = (s: any): TransferSource => ({
  id: Number(s.id),
  name: s.name,
  kind: s.type === 'warehouse' ? 'warehouse' : 'store',
});

export const mapQuote = (q: any): QuoteResult => ({
  lines: (q.lines ?? []).map((l: any) => ({
    product_variant_id: Number(l.product_variant_id),
    qty: Number(l.qty),
    unit_price: Number(l.unit_price),
    discount_amount: Number(l.discount_amount),
    line_total: Number(l.line_total),
    available: Number(l.available),
    error: l.error ?? null,
  })),
  subtotal: Number(q.subtotal),
  discount_total: Number(q.discount_total),
  grand_total: Number(q.grand_total),
});
