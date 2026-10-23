// توحيد أخطاء axios الحقيقية — نفس منطق parseApiError اللي في الـ mock بس على response فعلي.
export interface ApiError {
  status: number;                       // 0 = مشكلة شبكة
  message: string;
  fields: Record<string, string[]>;     // كل المفاتيح ما عدا 'status'
  summary: string[];                    // errors.status (أسباب رفض النشر)
}

interface RawErrorBody {
  message?: unknown;
  errors?: Record<string, unknown>;
}

function toStringList(v: unknown): string[] {
  if (Array.isArray(v)) return v.map(x => String(x));
  return [String(v)];
}

export function normalizeError(e: unknown): ApiError {
  const err = e as { response?: { status?: unknown; data?: unknown }; message?: unknown } | null | undefined;
  const status = typeof err?.response?.status === 'number' ? err.response.status : 0;
  const data = (err?.response?.data ?? {}) as RawErrorBody;
  const raw: Record<string, unknown> = data.errors && typeof data.errors === 'object' ? data.errors : {};

  const fields: Record<string, string[]> = {};
  Object.entries(raw).forEach(([k, v]) => {
    if (k === 'status') return;
    fields[k] = toStringList(v);
  });

  const summary = raw.status === undefined ? [] : toStringList(raw.status);
  const message = typeof data.message === 'string' && data.message !== ''
    ? data.message
    : e instanceof Error && !err?.response && !('request' in e)
      ? e.message
      : status === 0
      ? 'تعذّر الاتصال بالخادم، تأكد من الإنترنت وحاول تاني'
      : 'حدث خطأ غير متوقع، حاول تاني';

  return { status, message, fields, summary };
}
