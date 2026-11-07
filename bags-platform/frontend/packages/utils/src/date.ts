export interface FormatDateOptions {
  locale?: string;
  includeTime?: boolean;
  dateStyle?: 'full' | 'long' | 'medium' | 'short';
  timeStyle?: 'full' | 'long' | 'medium' | 'short';
}

const dateTimeFormatterCache = new Map<string, Intl.DateTimeFormat>();

function getDateTimeFormatter(locale: string, dateStyle: string, timeStyle?: string): Intl.DateTimeFormat {
  const key = `${locale}_${dateStyle}_${timeStyle ?? 'none'}`;
  let formatter = dateTimeFormatterCache.get(key);
  if (!formatter) {
    const opts: Intl.DateTimeFormatOptions = {
      dateStyle: dateStyle as Intl.DateTimeFormatOptions['dateStyle'],
    };
    if (timeStyle) {
      opts.timeStyle = timeStyle as Intl.DateTimeFormatOptions['timeStyle'];
    }
    formatter = new Intl.DateTimeFormat(locale, opts);
    dateTimeFormatterCache.set(key, formatter);
  }
  return formatter;
}

export function formatDate(
  dateInput: string | number | Date | null | undefined,
  options: FormatDateOptions = {}
): string {
  if (dateInput === null || dateInput === undefined || dateInput === '') return '-';

  const date =
    dateInput instanceof Date
      ? dateInput
      : new Date(typeof dateInput === 'string' ? dateInput.trim() : dateInput);
  if (Number.isNaN(date.getTime())) return '-';

  const {
    locale = 'ar-EG',
    includeTime = false,
    dateStyle = 'medium',
    timeStyle = 'short',
  } = options;

  try {
    return getDateTimeFormatter(locale, dateStyle, includeTime ? timeStyle : undefined).format(date);
  } catch {
    return date.toISOString().split('T')[0];
  }
}
