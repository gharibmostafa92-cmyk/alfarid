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

// Backend error contract (verified in code, do not assume otherwise):
// - BusinessException (app/Exceptions/BusinessException.php) renders 422 by default
//   as { message, errors, code? }. Business-rule failures (insufficient stock,
//   disabled variant/location and stale stocktake) come back this way.
//   Production's missing key also returns 422 via abort(), with { message }.
//   `errors` doubles as the extra-data channel: the stale
//   stocktake rejection carries { recount_item_ids, recount_variant_ids } there.
// - BusinessException can explicitly override its default to 409; those errors
//   retain { message, errors, code? }. HandlesIdempotentCreation rejects changed
//   transfer/stocktake payloads with the same key using this override.
// - 409 means "conflict, reload then retry with a fresh read":
//   version conflict ('تم تعديل التحويل؛ أعد تحميله…'), lock timeout/deadlock
//   ('المخزون مشغول بعملية أخرى…', retry with the SAME Idempotency-Key),
//   delete-blocked branch/location ('لا يمكن حذف الفرع/الموقع لارتباطه…'),
//   unique-constraint races. The only 409 that must NOT auto-retry is
//   'مفتاح عدم التكرار … مستخدم (مسبقًا) مع بيانات مختلفة' — the payload changed.
// - 404 with { message: 'العنصر المطلوب غير موجود' } (ModelNotFound via
//   bootstrap/app.php). 401 is handled by the axios interceptor — screens do
//   nothing. Server messages are Arabic: render them verbatim, never translate
//   or replace with a generic string.
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
