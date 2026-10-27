type RequestError = { response?: { status?: number; headers?: Record<string, unknown> } };

export function storeRetry(count: number, error: RequestError): boolean {
  return count < 3 && (!error.response || error.response.status === 503);
}

export function storeRetryDelay(_attempt: number, error: RequestError): number {
  const seconds = Number(error.response?.headers?.['retry-after'] ?? 2);
  return Number.isFinite(seconds) ? Math.max(0, Math.min(seconds, 60)) * 1000 : 2000;
}
