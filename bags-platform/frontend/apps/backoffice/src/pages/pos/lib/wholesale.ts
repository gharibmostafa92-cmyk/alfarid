import type { Customer } from '../types/pos.types';

/** Wholesale catalog/cart prices apply only when the customer is approved. */
export function isWholesaleEligible(customer?: Customer | null): boolean {
  return Boolean(
    customer &&
      customer.tier === 'wholesale' &&
      customer.status?.toLowerCase() === 'approved',
  );
}
