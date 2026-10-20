export interface FormatDateOptions {
  locale?: string;
  includeTime?: boolean;
  dateStyle?: 'full' | 'long' | 'medium' | 'short';
  timeStyle?: 'full' | 'long' | 'medium' | 'short';
}

export function formatDate(
  dateInput: string | number | Date | null | undefined,
  options: FormatDateOptions = {}
): string {
  if (!dateInput) return '-';

  const date = typeof dateInput === 'object' && dateInput instanceof Date ? dateInput : new Date(dateInput);
  if (isNaN(date.getTime())) return '-';

  const {
    locale = 'ar-EG',
    includeTime = false,
    dateStyle = 'medium',
    timeStyle = 'short',
  } = options;

  try {
    if (includeTime) {
      return new Intl.DateTimeFormat(locale, { dateStyle, timeStyle }).format(date);
    }
    return new Intl.DateTimeFormat(locale, { dateStyle }).format(date);
  } catch {
    return date.toISOString().split('T')[0];
  }
}
