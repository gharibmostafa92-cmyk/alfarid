export interface FingerprintItem {
  variantId?: number;
  id?: number;
  quantity: number;
}

/** Stable cart identity for a pending checkout, including coupon and delivery selection. */
export function cartFingerprint(
  items: readonly FingerprintItem[],
  couponCode?: string | null,
  deliveryKey?: string | null,
): string {
  const lines = items
    .map((item) => `${item.variantId ?? item.id ?? 0}:${item.quantity}`)
    .sort()
    .join('|');
  return `${lines}#${couponCode ?? ''}#${deliveryKey ?? ''}`;
}

/**
 * Guest checkouts use "guest". A token without a user id fails closed (null)
 * so a pending attempt is never restored under an unknown owner.
 */
export function resolveAttemptOwner(
  token: string | null,
  actorType: string | null,
  userId: unknown,
): string | null {
  if (!token) return 'guest';
  if (typeof userId === 'number') {
    if (!Number.isFinite(userId)) return null;
    return `${actorType || 'customer'}:${String(userId)}`;
  }
  if (typeof userId === 'string' && userId.trim() !== '') {
    return `${actorType || 'customer'}:${userId.trim()}`;
  }
  return null;
}

/** Strict owner equality. Prefix matches such as any customer:* are rejected. */
export function pendingOwnerMatches(savedOwner: unknown, attemptOwner: string | null): boolean {
  return attemptOwner !== null && savedOwner === attemptOwner;
}

/** Drop a stored attempt when the new session owner is missing or different. */
export function shouldDropPendingCheckout(raw: string | null, nextOwner: string | null): boolean {
  if (!raw) return false;
  let savedOwner: unknown;
  try {
    savedOwner = (JSON.parse(raw) as { owner?: unknown } | null)?.owner;
  } catch {
    return true;
  }
  if (typeof savedOwner !== 'string') return true;
  if (nextOwner === null) return true;
  return savedOwner !== nextOwner;
}
