import type { CreateSalePayload, QuoteResult } from '../types/pos.types';
import { fromCents, toCents } from './money';

interface CheckoutPayment {
  customerId: number | null;
  method: CreateSalePayload['payment_method'];
  cashCents: number;
  overridePrice: boolean;
  overrideReason?: string;
  notes: string;
  /** Server id of the resumed held sale, so the server consumes it atomically. */
  heldSaleId?: number | null;
  /** Array of server ids of resumed held sales. */
  heldSaleIds?: number[];
  /** Variant IDs that were actually overridden with manual prices. */
  manualItemIds?: Set<number> | number[];
}

export function buildCheckoutPayload(quote: QuoteResult, payment: CheckoutPayment): CreateSalePayload {
  const totalCents = toCents(quote.grand_total);
  const cashCents = payment.method === 'cash' ? totalCents : payment.method === 'mixed' ? payment.cashCents : 0;
  const reason = payment.overrideReason?.trim();
  const manualSet = payment.manualItemIds
    ? (payment.manualItemIds instanceof Set ? payment.manualItemIds : new Set(payment.manualItemIds))
    : null;

  return {
    items: quote.lines.map(({ product_variant_id, qty, unit_price, discount_amount }) => {
      const isOverridden = Boolean(payment.overridePrice && (!manualSet || manualSet.has(product_variant_id)));
      return {
        product_variant_id,
        qty,
        discount_amount,
        ...(isOverridden ? { unit_price, ...(reason ? { override_reason: reason } : {}) } : {}),
      };
    }),
    customer_id: payment.customerId,
    payment_method: payment.method,
    cash_amount: fromCents(cashCents),
    card_amount: payment.method === 'credit' ? 0 : fromCents(totalCents - cashCents),
    ...(payment.overridePrice ? { override_price: true } : {}),
    ...(payment.overridePrice && reason ? { override_reason: reason } : {}),
    ...(payment.heldSaleId != null ? { held_sale_id: payment.heldSaleId } : {}),
    ...(payment.heldSaleIds && payment.heldSaleIds.length > 0 ? { held_sale_ids: payment.heldSaleIds } : {}),
    notes: payment.notes,
  };
}
