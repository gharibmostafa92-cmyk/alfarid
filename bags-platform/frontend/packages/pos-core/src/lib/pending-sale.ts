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

const LEGACY_KEY = 'pos-pending-sale';
const SCOPED_PREFIX = 'pos-pending-sale:';

const scopedKey = (owner?: string): string => (owner ? `${SCOPED_PREFIX}${owner}` : LEGACY_KEY);

function storageKeys(storage: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>): string[] {
  const withKeys = storage as unknown as { keys?: () => string[] };
  if (typeof withKeys.keys === 'function') {
    try {
      return withKeys.keys().filter((k) => k === LEGACY_KEY || k.startsWith(SCOPED_PREFIX));
    } catch {
      return [];
    }
  }
  const ls = storage as unknown as { length?: number; key?: (i: number) => string | null };
  if (typeof ls.length === 'number' && typeof ls.key === 'function') {
    const out: string[] = [];
    for (let i = 0; i < ls.length; i++) {
      const k = ls.key(i);
      if (k === LEGACY_KEY || (k && k.startsWith(SCOPED_PREFIX))) out.push(k);
    }
    return out;
  }
  return [];
}

function parseDraft(raw: string | null): PendingSaleDraft | null {
  if (!raw) return null;
  try {
    const draft = JSON.parse(raw);
    if (!draft || typeof draft !== 'object') return null;
    // Older checkout previews included a timestamp and items with only a local id.
    if (draft?.payload?.t !== undefined && draft.payload.payment_method === undefined) return null;
    if (typeof draft.key !== 'string' || draft.payload === undefined) return null;
    return draft as PendingSaleDraft;
  } catch {
    return null;
  }
}

export function createPendingSaleManager(opts: PendingSaleOptions = {}) {
  const storage = opts.storage ?? (typeof window !== 'undefined' ? window.localStorage : undefined);
  const keyName = opts.storageKey ?? scopedKey(opts.owner);
  const delays = opts.retryDelays ?? [1000, 3000, 6000];
  const sleep = opts.sleep ?? defaultSleep;

  const getSaved = (): PendingSaleDraft | null => {
    if (!storage) return null;
    // F-05: drafts are namespaced per shift owner so one terminal can serve
    // consecutive shifts without a stale draft bricking the next cashier.
    const own = parseDraft(storage.getItem(keyName));
    if (own) return own;
    if (opts.owner) {
      // Adopt pre-scoping drafts that provably belong to this owner.
      const legacy = parseDraft(storage.getItem(LEGACY_KEY));
      if (legacy && legacy.owner === opts.owner) {
        storage.setItem(keyName, JSON.stringify(legacy));
        storage.removeItem(LEGACY_KEY);
        return legacy;
      }
    }
    return null;
  };

  const clear = (): void => {
    storage?.removeItem(keyName);
  };

  /** F-05: every draft on this terminal (own scope + stale owners + legacy) for the review banner. */
  const listDrafts = (): PendingSaleDraft[] => {
    if (!storage) return [];
    const out: PendingSaleDraft[] = [];
    for (const k of storageKeys(storage)) {
      const d = parseDraft(storage.getItem(k));
      if (d) out.push(d);
    }
    return out;
  };

  /** F-05: supervised discard of a stale draft after manual review. Never touches other owners. */
  const discardDraft = (owner: string): void => {
    if (!storage) return;
    storage.removeItem(scopedKey(owner));
    const legacy = parseDraft(storage.getItem(LEGACY_KEY));
    if (legacy && legacy.owner === owner) storage.removeItem(LEGACY_KEY);
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
    // F-05: with owner-scoped drafts there is no visible foreign draft to
    // collide with — but a foreign key must never start a send for this owner.
    if (!existing) {
      throw new PosApiError(409, 'لا توجد فاتورة معلقة لهذه الوردية بهذا المفتاح', 'pending_sale');
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
    listDrafts,
    discardDraft,
  };
}
