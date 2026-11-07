export interface FormatCurrencyOptions {
  locale?: string;
  currency?: string;
  minimumFractionDigits?: number;
  maximumFractionDigits?: number;
}

const formatterCache = new Map<string, Intl.NumberFormat>();

function getFormatter(locale: string, currency: string, minDigits: number, maxDigits: number): Intl.NumberFormat {
  const key = `${locale}_${currency}_${minDigits}_${maxDigits}`;
  let formatter = formatterCache.get(key);
  if (!formatter) {
    formatter = new Intl.NumberFormat(locale, {
      style: 'currency',
      currency,
      minimumFractionDigits: minDigits,
      maximumFractionDigits: maxDigits,
    });
    formatterCache.set(key, formatter);
  }
  return formatter;
}

const egp = new Intl.NumberFormat('en-EG', { style: 'currency', currency: 'EGP', minimumFractionDigits: 0, maximumFractionDigits: 2 });
export function formatStorePrice(v: number | string | null | undefined): string {
  const raw = typeof v === 'string' ? parseFloat(v) : (v ?? 0);
  const numeric = isNaN(raw) ? 0 : raw;
  return egp.format(numeric);
}

export function formatCurrency(
  amount: number | string | null | undefined,
  options: FormatCurrencyOptions = {}
): string {
  const {
    locale = 'ar-EG',
    currency = 'EGP',
    minimumFractionDigits = 2,
    maximumFractionDigits = 2,
  } = options;

  const raw = typeof amount === 'string' ? parseFloat(amount) : (amount ?? 0);
  const numericAmount = isNaN(raw) ? 0 : raw;

  try {
    return getFormatter(locale, currency, minimumFractionDigits, maximumFractionDigits).format(numericAmount);
  } catch {
    return `${numericAmount.toFixed(minimumFractionDigits)} ${currency}`;
  }
}

/**
 * Format shipping cost with 'Free' for zero amounts, matching storefront display.
 */
export function formatShippingPrice(cost: number | string | null | undefined): string {
  const numeric = typeof cost === 'string' ? parseFloat(cost) : (cost ?? 0);
  if (numeric === 0) {
    return 'Free';
  }
  return formatStorePrice(numeric);
}
