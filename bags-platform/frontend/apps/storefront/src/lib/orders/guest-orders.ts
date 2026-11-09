export const guestOrdersKey = 'alfarid_guest_order_numbers';

/**
 * Retrieve recent guest order numbers saved in the local browser session.
 *
 * @returns Array of sanitized order number strings.
 */
export function readGuestOrders(): string[] {
  try {
    const orders: unknown = JSON.parse(localStorage.getItem(guestOrdersKey) ?? '[]');
    // Sanitize cached storage entries against malformed values or storage tampering.
    return Array.isArray(orders)
      ? orders.filter((number): number is string => typeof number === 'string' && /^[a-z0-9-]{1,100}$/i.test(number))
      : [];
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
    localStorage.setItem(guestOrdersKey, JSON.stringify([number, ...readGuestOrders().filter((saved) => saved !== number)]));
  } catch {
    // Storage quota limits or private-browsing restrictions must not interrupt post-checkout flows.
  }
}
