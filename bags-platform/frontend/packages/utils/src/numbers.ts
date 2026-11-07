/**
 * Normalizes Arabic-Indic (U+0660..U+0669), Eastern Arabic/Persian (U+06F0..U+06F9) numerals,
 * and Arabic comma/decimal separators into standard ASCII Latin digits.
 *
 * @param value String, number, or nullish input potentially containing Arabic numerals.
 * @return Normalized string with ASCII digits and dot decimal points.
 */
export function toLatinDigits(value: string | number | null | undefined): string {
  if (value === null || value === undefined) return '';
  let s = String(value);
  // Subtract contiguous unicode base offsets (0x0660 / 0x06F0) to map regional numeral glyphs to 0-9.
  s = s.replace(/[\u0660-\u0669]/g, (d) => String(d.charCodeAt(0) - 0x0660));
  s = s.replace(/[\u06F0-\u06F9]/g, (d) => String(d.charCodeAt(0) - 0x06F0));
  // Replace Arabic decimal separators (٫) and commas with standard period for parseFloat compatibility.
  s = s.replace(/[\u066B,]/g, '.');
  return s;
}

/** Regex matching positive quantities with up to 4 decimal places matching decimal(12,4). */
export const QTY_RE = /^\d+(\.\d{1,4})?$/;

/** Upper bound for inventory item quantities. */
export const QTY_MAX = 99999999.9999;

/**
 * Formats inventory quantity up to 4 decimal places matching database precision (decimal:12,4).
 * Handles string, numeric, and Arabic numeral inputs.
 */
export function formatQty(v: number | string | null | undefined): string {
  if (v === null || v === undefined || v === '') return '—';
  const n = Number(toLatinDigits(v));
  if (Number.isNaN(n)) return '—';
  return n.toLocaleString('ar-EG', { maximumFractionDigits: 4 });
}
