import { normalizeError, PosApiError } from './pos-error';

export interface PendingSaleDraft {
  hash: string;
  key: string;
  payload: any;
  created_at?: number;
}

export interface PendingSaleOptions {
  storage?: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;
  retryDelays?: number[];
  storageKey?: string;
  sleep?: (ms: number) => Promise<void>;
}

const defaultSleep = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));
const defaultUuid = () => globalThis.crypto.randomUUID();

export function createPendingSaleManager(opts: PendingSaleOptions = {}) {
  const storage = opts.storage ?? (typeof window !== 'undefined' ? window.localStorage : undefined);
  const keyName = opts.storageKey ?? 'pos-pending-sale';
  const delays = opts.retryDelays ?? [1000, 3000, 6000];
  const sleep = opts.sleep ?? defaultSleep;

  const getSaved = (): PendingSaleDraft | null => {
    if (!storage) return null;
    try {
      const raw = storage.getItem(keyName);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  };

  const clear = (): void => {
    storage?.removeItem(keyName);
  };

  const prepare = (payload: any): PendingSaleDraft => {
    const hash = JSON.stringify(payload);
    const existing = getSaved();
    if (existing && existing.hash === hash) {
      return existing;
    }
    const draft: PendingSaleDraft = {
      hash,
      key: defaultUuid(),
      payload,
      created_at: Date.now(),
    };
    storage?.setItem(keyName, JSON.stringify(draft));
    return draft;
  };

  const executeWithRetry = async <T>(
    payload: any,
    idempotencyKey: string,
    sendFn: (payload: any, key: string) => Promise<T>,
    onRetrying?: (attempt: number, delayMs: number) => void,
  ): Promise<T> => {
    // Keep draft saved while trying
    storage?.setItem(
      keyName,
      JSON.stringify({
        hash: JSON.stringify(payload),
        key: idempotencyKey,
        payload,
        created_at: Date.now(),
      })
    );

    let lastError: any = null;

    for (let attempt = 0; attempt <= delays.length; attempt++) {
      try {
        const result = await sendFn(payload, idempotencyKey);
        clear();
        return result;
      } catch (err: any) {
        const apiErr = normalizeError(err);
        lastError = apiErr;

        // Only retry status 0 (network abort, timeout, disconnected)
        const isNetworkFailure = apiErr.status === 0 || apiErr.code === 'network';
        if (isNetworkFailure && attempt < delays.length) {
          const delayMs = delays[attempt];
          onRetrying?.(attempt + 1, delayMs);
          await sleep(delayMs);
          continue;
        }

        // If it's a client error (e.g. 422, or 409 idempotency conflict), don't keep draft
        if (apiErr.status >= 400 && apiErr.status < 500 && apiErr.status !== 408) {
          clear();
        }
        throw apiErr;
      }
    }

    throw lastError ?? new PosApiError(0, 'انقطع الاتصال بالسيرفر', 'network');
  };

  return {
    getSaved,
    clear,
    prepare,
    executeWithRetry,
  };
}
