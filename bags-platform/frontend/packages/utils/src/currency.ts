export interface FormatCurrencyOptions {
  locale?: string;
  currency?: string;
  minimumFractionDigits?: number;
  maximumFractionDigits?: number;
}

export function formatCurrency(
  amount: number | string | null | undefined,
  options: FormatCurrencyOptions = {}
): string {
  const numericAmount = typeof amount === 'string' ? parseFloat(amount) : (amount ?? 0);
  if (isNaN(numericAmount)) {
    return '0.00 ج.م';
  }

  const {
    locale = 'ar-EG',
    currency = 'EGP',
    minimumFractionDigits = 2,
    maximumFractionDigits = 2,
  } = options;

  try {
    return new Intl.NumberFormat(locale, {
      style: 'currency',
      currency,
      minimumFractionDigits,
      maximumFractionDigits,
    }).format(numericAmount);
  } catch {
    return `${numericAmount.toFixed(minimumFractionDigits)} ${currency}`;
  }
}
