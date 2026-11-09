export class PosApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public code?: string,
    public fields?: Record<string, string[]>,
    public retryable = false,
    public retryAfterMs?: number,
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
  if (r.status === 503) {
    const raw = r.headers?.["retry-after"] ?? r.headers?.["Retry-After"];
    const seconds = raw == null ? NaN : Number(raw);
    const retryAfterMs = Number.isFinite(seconds) && (seconds as number) >= 0
      ? Math.round((seconds as number) * 1000)
      : 1000;
    return new PosApiError(r.status, msg, r.data?.code, r.data?.errors, true, retryAfterMs);
  }
  return new PosApiError(r.status, msg, r.data?.code, r.data?.errors, r.status === 409 && !/مفتاح عدم التكرار/.test(msg));
}
