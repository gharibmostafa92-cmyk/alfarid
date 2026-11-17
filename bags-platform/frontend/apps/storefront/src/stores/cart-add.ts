/** True when this variant should join the cart queue. False rejects a duplicate click. */
export function shouldQueueAdd(variantId: number, inFlight: ReadonlySet<number>): boolean {
  return !inFlight.has(variantId);
}

/** Returns the next whole-unit quantity, or null when the requested step is unavailable. */
export function nextCartQuantity(current: number, available: number, delta: number): number | null {
  if (!Number.isFinite(current) || !Number.isFinite(available) || !Number.isInteger(delta) || delta === 0) return null;
  const max = Math.floor(available);
  if (max < 1) return null;

  const quantity = Math.max(1, Math.floor(current));
  if (delta < 0 && quantity > max) return max;

  const next = quantity + delta;
  return next >= 1 && next <= max ? next : null;
}
