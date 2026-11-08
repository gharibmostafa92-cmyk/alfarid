export const toCents = (v: string | number | null | undefined): number => Math.round(Number(v ?? 0) * 100);
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
