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

function latinNumerals(value: string | number | null | undefined): string {
  // Subtract contiguous unicode base offsets (0x0660 / 0x06F0) to map regional numeral glyphs to 0-9.
  return String(value ?? '')
    .replace(blockRange(ARABIC_INDIC_START), (d) => String(d.charCodeAt(0) - ARABIC_INDIC_START))
    .replace(blockRange(EASTERN_ARABIC_START), (d) => String(d.charCodeAt(0) - EASTERN_ARABIC_START));
}

function normalizeSeparators(s: string): string {
  // Display values support grouped numbers such as '1,500' and decimal commas
  // such as '12,34'. Editable amounts handle ambiguous commas separately.
  s = s.split(ARABIC_THOUSANDS_MARK).join('');
  // No lookbehind assertions here: the lookbehind form throws a SyntaxError
  // at module parse time on Safari/iOS < 16.4 and kills the whole bundle.
  // The captured digit is re-emitted, so behavior is identical.
  s = s.replace(/(\d),(?=\d{3}(?:\D|$))/g, '$1');
  return s.split(ARABIC_DECIMAL_MARK).join('.').replace(/,/g, '.');
}

export function toLatinDigits(value: string | number | null | undefined): string {
  return normalizeSeparators(latinNumerals(value));
}

export function normalizeNumericInput(value: string | number | null | undefined): string {
  const input = latinNumerals(value).trim();
  // Preserve the ambiguous separator so the field's numeric validator rejects it.
  return /^[+-]?\d+,\d{3}$/.test(input) ? input : normalizeSeparators(input);
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
