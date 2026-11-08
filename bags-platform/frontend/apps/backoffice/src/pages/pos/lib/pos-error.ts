export class PosApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public code?: string,
    public fields?: Record<string, string[]>,
    public retryable = false,
  ) {
    super(message);
    this.name = 'PosApiError';
  }
}

export function isCancel(e: any): boolean {
  return e?.code === 'ERR_CANCELED' || e?.name === 'CanceledError' || e?.name === 'AbortError' || e?.__CANCEL__ === true;
}

/** Cancellations are rethrown untouched so callers can ignore them. */
export function normalizeError(e: any): PosApiError {
  if (isCancel(e)) throw e;
  if (e instanceof PosApiError) return e;
  const r = e?.response;
  if (!r) return new PosApiError(0, 'انقطع الاتصال بالسيرفر', 'network', undefined, true);
  const msg = r.data?.message ?? 'حدث خطأ غير متوقع';
  return new PosApiError(r.status, msg, r.data?.code, r.data?.errors, r.status === 409 && !/مفتاح عدم التكرار/.test(msg));
}
