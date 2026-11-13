import { normalizeError, PosApiError } from './pos-error';

export interface PendingSaleDraft {
  hash: string;
  key: string;
  payload: any;
  created_at?: number;
  owner?: string;
  submitted?: boolean;
}

export interface PendingSaleOptions {
  storage?: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;
  retryDelays?: number[];
  storageKey?: string;
  owner?: string;
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
      const draft = raw ? JSON.parse(raw) : null;
      // Older checkout previews included a timestamp and items with only a local id.
      if (draft?.payload?.t !== undefined && draft.payload.payment_method === undefined) return null;
      return draft;
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
    if (existing) {
      if (existing.owner === opts.owner && existing.hash === hash) return existing;
      throw new PosApiError(409, 'يجب تأكيد الفاتورة المعلقة أولًا', 'pending_sale');
    }
    const draft: PendingSaleDraft = {
      hash,
      key: defaultUuid(),
      payload,
      created_at: Date.now(),
      owner: opts.owner,
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
    const existing = getSaved();
    if (existing && (existing.owner !== opts.owner || existing.key !== idempotencyKey || existing.hash !== JSON.stringify(payload))) {
      throw new PosApiError(409, 'تعذر تأكيد الفاتورة خارج الوردية التي أُرسلت منها', 'pending_sale');
    }

    // A reload during the request must treat its result as unknown.
    let uncertain = existing?.submitted === true;
    storage?.setItem(
      keyName,
      JSON.stringify({
        hash: JSON.stringify(payload),
        key: idempotencyKey,
        payload,
        created_at: Date.now(),
        owner: opts.owner,
        submitted: true,
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
        uncertain ||= isNetworkFailure || apiErr.status === 408 || apiErr.status >= 500 || apiErr.retryable;
        if (isNetworkFailure && attempt < delays.length) {
          const delayMs = delays[attempt];
          onRetrying?.(attempt + 1, delayMs);
          await sleep(delayMs);
          continue;
        }

        // A rejection of this request cannot rule out an earlier commit.
        if (!uncertain && apiErr.status >= 400 && apiErr.status < 500) {
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
