export const guestOrdersKey = 'alfarid_guest_order_numbers';
const GUEST_ORDER_LIMIT = 10;
const GUEST_ORDER_MAX_AGE = 7 * 24 * 60 * 60_000;

function recentGuestOrders(): Array<{ number: string; expiresAt: number }> {
  const orders: unknown = JSON.parse(localStorage.getItem(guestOrdersKey) ?? '[]');
  if (!Array.isArray(orders)) return [];
  return orders.filter(entry => entry && typeof entry.number === 'string' &&
    /^[a-z0-9_.-]{1,40}$/i.test(entry.number) && Number.isFinite(entry.expiresAt) &&
    entry.expiresAt > Date.now()).slice(0, GUEST_ORDER_LIMIT);
}

/**
 * Retrieve recent guest order numbers saved in the local browser session.
 *
 * @returns Array of sanitized order number strings.
 */
export function readGuestOrders(): string[] {
  try {
    return recentGuestOrders().map(entry => entry.number);
  } catch {
    return [];
  }
}

/**
 * Persist an order number to local guest history, moving it to the front of the list.
 *
 * @param number - Validated order number to record.
 */
export function rememberGuestOrder(number: string): void {
  try {
    localStorage.setItem(
      guestOrdersKey,
      JSON.stringify([
        { number, expiresAt: Date.now() + GUEST_ORDER_MAX_AGE },
        ...recentGuestOrders().filter(saved => saved.number !== number),
      ].slice(0, GUEST_ORDER_LIMIT)),
    );
  } catch {
    // Storage quota limits or private-browsing restrictions must not interrupt post-checkout flows.
  }
}
