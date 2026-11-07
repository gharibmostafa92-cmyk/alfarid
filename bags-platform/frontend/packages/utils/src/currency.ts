import { toLatinDigits } from './numbers';

export interface FormatCurrencyOptions {
  locale?: string;
  currency?: string;
  minimumFractionDigits?: number;
  maximumFractionDigits?: number;
}

// Cache formatters to eliminate repeated Intl instantiation overhead during large catalog re-renders.
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

/**
 * Safely parses string or numeric inputs (including Arabic-Indic numerals) into a finite floating number.
 *
 * @param value Number, numeric string, or nullish input.
 * @param fallback Number to return if parsing fails or produces NaN/Infinity.
 * @return Guaranteed finite number.
 */
export function parseNumericAmount(value: number | string | null | undefined, fallback = 0): number {
  if (value === null || value === undefined) return fallback;
  const raw = typeof value === 'string' ? parseFloat(toLatinDigits(value)) : value;
  return Number.isFinite(raw) ? raw : fallback;
}

/**
 * Formats product prices for storefront display using Egyptian Pound formatting.
 *
 * @param v Raw numeric value or price string.
 * @return Formatted currency string with up to 2 decimal places (omitting decimals for whole integers).
 */
export function formatStorePrice(v: number | string | null | undefined): string {
  const numeric = parseNumericAmount(v, 0);
  return getFormatter('en-EG', 'EGP', 0, 2).format(numeric);
}

/**
 * Formats monetary amounts with customizable locale and precision settings.
 *
 * @param amount Raw numeric value or price string.
 * @param options Locale, currency code, and fraction digit boundaries.
 * @return Localized formatted currency string.
 */
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

  const numericAmount = parseNumericAmount(amount, 0);

  try {
    return getFormatter(locale, currency, minimumFractionDigits, maximumFractionDigits).format(numericAmount);
  } catch {
    // Provide plain string fallback in older or constrained JS runtimes lacking full Intl locale data.
    return `${numericAmount.toFixed(minimumFractionDigits)} ${currency}`;
  }
}

/**
 * Formats shipping cost, displaying 'Free' for zero or uncharged deliveries.
 *
 * @param cost Shipping fee amount.
 * @return 'Free' for 0 values or formatted price string.
 */
export function formatShippingPrice(cost: number | string | null | undefined): string {
  const numeric = parseNumericAmount(cost, 0);
  if (numeric === 0) {
    return 'Free';
  }
  return formatStorePrice(numeric);
}
