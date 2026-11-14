import type { PosCartLine, Customer, Branch, CashierUser } from '../types/pos.types';

export interface ArchivedReceipt {
  invoiceNumber: string;
  total: number;
  method: 'cash' | 'card' | 'mixed';
  received: number;
  change: number;
  cashA: number;
  cardA: number;
  cart: PosCartLine[];
  customer: Customer | null;
  discount: number;
  notes?: string;
  branch?: Branch | null;
  cashier?: CashierUser | null;
  timestamp: number;
}

/** F-17: receipts are namespaced per cashier+shift so consecutive cashiers on
 * one terminal never see each other's invoices, and the scope persists across
 * page refreshes (sessionStorage) for reprints during the same shift. */
export interface ReceiptScope {
  cashierId?: number | string | null;
  shiftId?: number | string | null;
}

export type ReceiptStorage = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> & {
  keys?: () => string[];
  length?: number;
  key?: (i: number) => string | null;
};

const MAX_RECEIPTS = 5;
const SCOPE_PREFIX = 'pos-receipts:';

const archive: (ArchivedReceipt & { _scope?: string })[] = [];

/** A bare id is shorthand for { cashierId: id }. */
export type ReceiptScopeInput = ReceiptScope | number | string | null | undefined;

const normalizeScope = (scope: ReceiptScopeInput): ReceiptScope | undefined => {
  if (scope == null) return undefined;
  if (typeof scope === 'number') return { cashierId: scope };
  if (typeof scope === 'string') return scopeFromOwner(scope);
  return scope;
};

const scopeOf = (receipt: ArchivedReceipt, scope?: ReceiptScopeInput): string => {
  const normalized = normalizeScope(scope);
  const cashierId = normalized?.cashierId ?? receipt.cashier?.id ?? 'anon';
  const shiftId = normalized?.shiftId ?? 'any';
  return `${cashierId}:${shiftId}`;
};

const scopeKey = (scope: string): string => `${SCOPE_PREFIX}${scope}`;

function defaultStorage(): ReceiptStorage | undefined {
  try {
    if (typeof sessionStorage !== 'undefined') return sessionStorage;
  } catch {
    return undefined;
  }
  return undefined;
}

function persistScope(scope: string, storage?: ReceiptStorage): void {
  const store = storage ?? defaultStorage();
  if (!store) return;
  try {
    const rows = archive.filter((r) => (r._scope ?? scopeOf(r)) === scope).slice(0, MAX_RECEIPTS);
    if (rows.length > 0) {
      store.setItem(scopeKey(scope), JSON.stringify(rows));
    } else {
      store.removeItem(scopeKey(scope));
    }
  } catch {
    /* storage full or unavailable: memory copy still serves this session */
  }
}

function loadScope(scope: string, storage?: ReceiptStorage): ArchivedReceipt[] {
  const store = storage ?? defaultStorage();
  if (!store) return [];
  try {
    const raw = store.getItem(scopeKey(scope));
    if (!raw) return [];
    const rows = JSON.parse(raw);
    return Array.isArray(rows) ? rows : [];
  } catch {
    return [];
  }
}

export function pushReceipt(receipt: ArchivedReceipt, scope?: ReceiptScopeInput, storage?: ReceiptStorage): void {
  const key = scopeOf(receipt, scope);
  archive.unshift({ ...receipt, _scope: key });
  // Trim per scope so one cashier's history never evicts another's.
  // Newest entries sit at index 0: keep the first MAX per scope, drop the tail.
  const seen: Record<string, number> = {};
  for (let i = 0; i < archive.length; i++) {
    const k = archive[i]._scope ?? scopeOf(archive[i]);
    seen[k] = (seen[k] ?? 0) + 1;
    if (seen[k] > MAX_RECEIPTS) {
      archive.splice(i, 1);
      i--;
    }
  }
  persistScope(key, storage);
}

export function getLastReceipt(scope?: ReceiptScopeInput, storage?: ReceiptStorage): ArchivedReceipt | null {
  const normalized = normalizeScope(scope);
  if (normalized?.cashierId == null && normalized?.shiftId == null) return null;

  const prefix = normalized.cashierId != null ? `${normalized.cashierId}:` : undefined;
  const exactKey = normalized.shiftId != null ? `${normalized.cashierId ?? 'anon'}:${normalized.shiftId}` : undefined;

  const mem = archive.find((r) => {
    const s = r._scope ?? scopeOf(r);
    if (exactKey) return s === exactKey;
    if (prefix) return s.startsWith(prefix);
    return false;
  });
  if (mem) return mem;

  if (exactKey) {
    const stored = loadScope(exactKey, storage);
    if (stored.length > 0) {
      archive.unshift({ ...stored[0], _scope: exactKey });
      return archive[0];
    }
  } else if (prefix) {
    const store = storage ?? defaultStorage();
    if (store) {
      try {
        if (typeof (store as ReceiptStorage).keys === 'function') {
          for (const k of (store as ReceiptStorage).keys!()) {
            if (k.startsWith(`${SCOPE_PREFIX}${prefix}`)) {
              const raw = store.getItem(k);
              if (raw) {
                const rows = JSON.parse(raw);
                if (Array.isArray(rows) && rows[0]) {
                  const s = k.replace(SCOPE_PREFIX, '');
                  archive.unshift({ ...rows[0], _scope: s });
                  return archive[0];
                }
              }
            }
          }
        } else if (typeof store.length === 'number' && typeof store.key === 'function') {
          for (let i = 0; i < store.length; i++) {
            const k = store.key(i);
            if (k && k.startsWith(`${SCOPE_PREFIX}${prefix}`)) {
              const raw = store.getItem(k);
              if (raw) {
                const rows = JSON.parse(raw);
                if (Array.isArray(rows) && rows[0]) {
                  const s = k.replace(SCOPE_PREFIX, '');
                  archive.unshift({ ...rows[0], _scope: s });
                  return archive[0];
                }
              }
            }
          }
        }
      } catch {
        /* best effort */
      }
    }
  }
  return null;
}

export function getReceiptArchive(): ArchivedReceipt[] {
  return [...archive];
}

export function clearReceiptArchive(scope?: ReceiptScopeInput, storage?: ReceiptStorage): void {
  const normalized = normalizeScope(scope);
  if (normalized?.cashierId == null && normalized?.shiftId == null) {
    archive.length = 0;
    const store = storage ?? defaultStorage();
    if (store) {
      try {
        if (typeof (store as ReceiptStorage).keys === 'function') {
          for (const k of (store as ReceiptStorage).keys!()) {
            if (k.startsWith(SCOPE_PREFIX)) store.removeItem(k);
          }
        } else if (typeof store.length === 'number' && typeof store.key === 'function') {
          const doomed: string[] = [];
          for (let i = 0; i < store.length; i++) {
            const k = store.key(i);
            if (k && k.startsWith(SCOPE_PREFIX)) doomed.push(k);
          }
          doomed.forEach((k) => store.removeItem(k));
        }
      } catch {
        /* best effort */
      }
    }
    return;
  }
  const prefix = normalized.cashierId != null ? `${normalized.cashierId}:` : undefined;
  const exactKey = normalized.shiftId != null ? `${normalized.cashierId ?? 'anon'}:${normalized.shiftId}` : undefined;

  for (let i = archive.length - 1; i >= 0; i--) {
    const s = archive[i]._scope ?? scopeOf(archive[i]);
    if (exactKey ? s === exactKey : (prefix ? s.startsWith(prefix) : false)) {
      archive.splice(i, 1);
    }
  }
  const store = storage ?? defaultStorage();
  try {
    if (exactKey) {
      store?.removeItem(scopeKey(exactKey));
    } else if (prefix && store) {
      if (typeof (store as ReceiptStorage).keys === 'function') {
        for (const k of (store as ReceiptStorage).keys!()) {
          if (k.startsWith(`${SCOPE_PREFIX}${prefix}`)) store.removeItem(k);
        }
      } else if (typeof store.length === 'number' && typeof store.key === 'function') {
        const doomed: string[] = [];
        for (let i = 0; i < store.length; i++) {
          const k = store.key(i);
          if (k && k.startsWith(`${SCOPE_PREFIX}${prefix}`)) doomed.push(k);
        }
        doomed.forEach((k) => store.removeItem(k));
      }
    }
  } catch {
    /* best effort */
  }
}

/** F-10/F-17 helper: derive a receipt scope from a "cashierId:shiftId" pending owner. */
export function scopeFromOwner(owner?: string): ReceiptScope | undefined {
  if (!owner) return undefined;
  const [cashierId, shiftId] = owner.split(':');
  if (!cashierId) return undefined;
  return { cashierId, shiftId: shiftId || undefined };
}
