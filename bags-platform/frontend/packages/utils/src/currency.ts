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
