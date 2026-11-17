/** True when this variant should join the cart queue. False rejects a duplicate click. */
export function shouldQueueAdd(variantId: number, inFlight: ReadonlySet<number>): boolean {
  return !inFlight.has(variantId);
}
