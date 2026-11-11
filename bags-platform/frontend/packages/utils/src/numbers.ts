/**
 * Normalizes Arabic-Indic (U+0660..U+0669), Eastern Arabic/Persian (U+06F0..U+06F9) numerals,
 * and Arabic comma/decimal separators into standard ASCII Latin digits.
 *
 * @param value String, number, or nullish input potentially containing Arabic numerals.
 * @return Normalized string with ASCII digits and dot decimal points.
 */
const ARABIC_INDIC_START = 0x0660;
const EASTERN_ARABIC_START = 0x06f0;
const ARABIC_THOUSANDS_MARK = String.fromCharCode(0x066c);
const ARABIC_DECIMAL_MARK = String.fromCharCode(0x066b);

function blockRange(start: number): RegExp {
  const lo = String.fromCharCode(start);
  const hi = String.fromCharCode(start + 9);
  return new RegExp('[' + lo + '-' + hi + ']', 'g');
}

export function toLatinDigits(value: string | number | null | undefined): string {
  if (value === null || value === undefined) return '';
  let s = String(value);
  // Subtract contiguous unicode base offsets (0x0660 / 0x06F0) to map regional numeral glyphs to 0-9.
  s = s.replace(blockRange(ARABIC_INDIC_START), (d) => String(d.charCodeAt(0) - ARABIC_INDIC_START));
  s = s.replace(blockRange(EASTERN_ARABIC_START), (d) => String(d.charCodeAt(0) - EASTERN_ARABIC_START));
  // Strip thousands separators: the Arabic thousands mark (U+066C) always, and
  // ASCII commas only in unambiguous thousands positions (a comma followed by
  // exactly three digits and then a non-digit or the end). A lone comma keeps
  // its pinned decimal meaning (e.g. '12,34' -> '12.34') instead of guessing.
  s = s.split(ARABIC_THOUSANDS_MARK).join('');
  s = s.replace(/(?<=\d),(?=\d{3}(?:\D|$))/g, '');
  // Replace the Arabic decimal separator (U+066B) and any remaining comma
  // with a standard period for parseFloat compatibility.
  s = s.split(ARABIC_DECIMAL_MARK).join('.').replace(/,/g, '.');
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
