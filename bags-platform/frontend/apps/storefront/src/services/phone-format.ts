/**
 * Digit-only transliteration mirroring the backend ArabicDigits normalizer:
 * Eastern Arabic (U+0660..U+0669) and Persian (U+06F0..U+06F9) numerals become
 * ASCII digits. Separators are intentionally untouched so phone validation
 * keeps the exact same semantics as Customer::normalizePhone.
 */
const ARABIC_INDIC_ZERO = 0x0660;
const PERSIAN_ZERO = 0x06f0;

export function toAsciiDigits(value: string): string {
  return value
    .replace(/[\u0660-\u0669]/g, (digit) => String(digit.charCodeAt(0) - ARABIC_INDIC_ZERO))
    .replace(/[\u06f0-\u06f9]/g, (digit) => String(digit.charCodeAt(0) - PERSIAN_ZERO));
}

/** Strip an OTP code down to ASCII digits. */
export function normalizeCode(value: string): string {
  return toAsciiDigits(value).replace(/[^0-9]/g, '');
}

/** Canonicalize a phone input to the local 01xxxxxxxxx form when possible. */
export function normalizePhone(value: string): string {
  // Strip invisible Bidi/format marks (U+200B-U+200F, U+202A-U+202E,
  // U+2066-U+2069) that smartphones inject on contact copy-paste.
  const compact = toAsciiDigits(value).trim().replace(/[\s\u200B-\u200F\u202A-\u202E\u2066-\u2069().-]/g, '');
  if (/^\+?200?(1[0125][0-9]{8})$/.test(compact)) return `0${compact.slice(-10)}`;
  if (/^00200?(1[0125][0-9]{8})$/.test(compact)) return `0${compact.slice(-10)}`;
  return compact;
}
