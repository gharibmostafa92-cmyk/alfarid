// Shared inventory UI helpers: digits, formatting, names, permissions, 422 keys.

export {
  normalizeNumericInput,
  canAny,
  formatQty,
  QTY_RE,
  QTY_MAX,
  splitIndexedErrorKey,
  groupIndexedErrors,
  groupIndexedErrors as groupItemsErrors,
} from '@alfarid/utils';

// Product display name: Arabic → English → SKU. Default language is Arabic.
export function pickName(variant, lang = 'ar') {
  if (!variant) return '—';
  if (lang === 'ar') {
    return variant.product_name_ar || variant.product_name_en || variant.sku || '—';
  }
  return variant.product_name_en || variant.product_name_ar || variant.sku || '—';
}
