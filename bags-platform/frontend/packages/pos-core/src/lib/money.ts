export const toCents = (v: string | number | null | undefined): number => {
  if (v == null || v === '') return 0;
  const s = String(v).trim();
  if (!s || s === 'NaN') return 0;
  const neg = s.startsWith('-');
  const clean = neg ? s.slice(1) : s;
  const parts = clean.split('.');
  const whole = parseInt(parts[0] || '0', 10);
  if (Number.isNaN(whole)) return 0;
  const rawFrac = (parts[1] || '').replace(/\D/g, '');
  const frac = rawFrac.padEnd(2, '0').slice(0, 2);
  const third = rawFrac.length > 2 ? parseInt(rawFrac[2], 10) : 0;
  let cents = whole * 100 + (parseInt(frac, 10) || 0);
  if (third >= 5) cents += 1;
  return neg ? -cents : cents;
};
export const fromCents = (c: number): number => c / 100;
export const toApi = (c: number): string => (c / 100).toFixed(2);

/** Proportional "largest remainder" split so the discounts add up to the exact cent. */
export function spreadDiscount(lines: { id: number; totalC: number }[], discountC: number): { id: number; d: number }[] {
  const sum = lines.reduce((s, l) => s + l.totalC, 0);
  if (!sum || !discountC) return lines.map((l) => ({ id: l.id, d: 0 }));
  const raw = lines.map((l) => ({ id: l.id, exact: (discountC * l.totalC) / sum }));
  const out = raw.map((r) => ({ id: r.id, d: Math.floor(r.exact), frac: r.exact - Math.floor(r.exact) }));
  let rest = discountC - out.reduce((s, o) => s + o.d, 0);
  [...out].sort((a, b) => b.frac - a.frac).forEach((o) => {
    if (rest-- > 0) o.d += 1;
  });
  return out.map(({ id, d }) => ({ id, d }));
}

/** Highest invoice discount (in cents) allowed by max_discount_percent. */
export const maxDiscountAmount = (subtotalC: number, percent: number): number => Math.floor((subtotalC * percent) / 100);

/**
 * F-07: server-compatible proportional refund for a partial return.
 * Mirrors PosService::estimateReturnValue exactly — round((returned + sel) *
 * soldNet / soldQty) - previousRefund — so the client quote and the server
 * never drift by a cent on indivisible discounts (e.g. 3 x 100 - 10).
 */
export function proportionalRefundCents(
  soldNetCents: number,
  soldQty: number,
  alreadyReturnedQty: number,
  selQty: number,
  previousRefundCents: number,
): number {
  if (soldQty <= 0 || selQty <= 0) return 0;
  const target = Math.round(((alreadyReturnedQty + selQty) * soldNetCents) / soldQty);
  return Math.max(0, Math.min(soldNetCents, target) - previousRefundCents);
}

/** Parse a strictly positive unit price, falling back when empty, non-positive, or NaN. */
export function parsePositivePrice(raw: string | number | null | undefined, fallback: number): number {
  const trimmed = typeof raw === 'number' ? String(raw) : String(raw ?? '').trim();
  if (!trimmed) return fallback;
  const num = Number(trimmed);
  if (!Number.isFinite(num) || num <= 0) return fallback;
  return fromCents(toCents(num));
}

/** Parse a non-negative monetary amount, falling back when negative or invalid. */
export function parseNonNegativeAmount(raw: string | number | null | undefined, fallback = 0): number {
  const trimmed = typeof raw === 'number' ? String(raw) : String(raw ?? '').trim();
  if (!trimmed) return fallback;
  const num = Number(trimmed);
  if (!Number.isFinite(num) || num < 0) return fallback;
  return fromCents(toCents(num));
}

export interface CartTotals {
  subtotal: number;
  pricedTotal: number;
  lineAdj: number;
  cap: number;
  discount: number;
  total: number;
}

/** Compute cent-exact cart totals and dynamically clamp invoice discount against current priced total and cap. */
export function computeCartTotals(
  lines: { price: number; listPrice: number; qty: number }[],
  requestedDiscount: number,
  maxDiscountPercent = 100,
): CartTotals {
  const subtotalCents = lines.reduce((sum, l) => sum + toCents(l.listPrice) * l.qty, 0);
  const pricedCents = lines.reduce((sum, l) => sum + toCents(l.price) * l.qty, 0);
  const capCents = maxDiscountAmount(pricedCents, maxDiscountPercent);
  const safeDiscountCents = Math.min(Math.max(0, toCents(requestedDiscount)), pricedCents, capCents);
  const totalCents = Math.max(0, pricedCents - safeDiscountCents);
  return {
    subtotal: fromCents(subtotalCents),
    pricedTotal: fromCents(pricedCents),
    lineAdj: fromCents(subtotalCents - pricedCents),
    cap: fromCents(capCents),
    discount: fromCents(safeDiscountCents),
    total: fromCents(totalCents),
  };
}
