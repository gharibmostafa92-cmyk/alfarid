// Shared inventory UI helpers: digits, formatting, names, permissions, 422 keys.

const AR_DIGITS = '٠١٢٣٤٥٦٧٨٩';
const FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';

// Arabic-Indic / Persian digits + Arabic decimal separators → Latin.
// Must run inside NumInput before any validation or submit, otherwise staff
// typing with an Arabic keyboard get a 422 on every quantity.
export function toLatinDigits(v) {
  if (v === null || v === undefined) return '';
  let s = String(v);
  s = s.replace(/[٠-٩]/g, (d) => String(AR_DIGITS.indexOf(d)));
  s = s.replace(/[۰-۹]/g, (d) => String(FA_DIGITS.indexOf(d)));
  s = s.replace(/[٫,]/g, '.');
  return s;
}

export const QTY_RE = /^\d+(\.\d{1,4})?$/;
export const QTY_MAX = 99999999.9999;

export function formatQty(v) {
  if (v === null || v === undefined || v === '') return '—';
  const n = Number(v);
  if (Number.isNaN(n)) return '—';
  return n.toLocaleString('ar-EG', { maximumFractionDigits: 4 });
}

// Product display name: Arabic → English → SKU. Default language is Arabic.
export function pickName(variant, lang = 'ar') {
  if (!variant) return '—';
  if (lang === 'ar') {
    return variant.product_name_ar || variant.product_name_en || variant.sku || '—';
  }
  return variant.product_name_en || variant.product_name_ar || variant.sku || '—';
}

// can('branches.update') || can('branches.manage') — branches.manage covers all
// branch operations, so every branch gate must accept either.
export function canAny(can, ...perms) {
  return perms.some((p) => can(p));
}

// 'items.3.quantity' → { index: 3, field: 'quantity' } (cf. splitVariantErrorKey
// for 'variants.N.field' in catalog/mappers.ts). Returns null when the server
// key is not an indexed item key.
export function splitIndexedErrorKey(serverKey, prefix = 'items') {
  const m = new RegExp(`^${prefix}\\.(\\d+)\\.(.+)$`).exec(serverKey);
  if (!m) return null;
  return { index: Number(m[1]), field: m[2] };
}

// Group normalizeError().fields into per-row errors: { [index]: { [field]: msg } }.
export function groupItemsErrors(fields, prefix = 'items') {
  const rows = {};
  Object.entries(fields || {}).forEach(([key, messages]) => {
    const hit = splitIndexedErrorKey(key, prefix);
    if (!hit) return;
    const msg = Array.isArray(messages) ? messages[0] : String(messages);
    rows[hit.index] = { ...(rows[hit.index] || {}), [hit.field]: msg };
  });
  return rows;
}
