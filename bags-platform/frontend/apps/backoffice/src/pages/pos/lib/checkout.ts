import type { CreateSalePayload, QuoteResult } from '../types/pos.types';
import { fromCents, toCents } from './money';

interface CheckoutPayment {
  customerId: number | null;
  method: CreateSalePayload['payment_method'];
  cashCents: number;
  overridePrice: boolean;
  notes: string;
}

export function buildCheckoutPayload(quote: QuoteResult, payment: CheckoutPayment): CreateSalePayload {
  const totalCents = toCents(quote.grand_total);
  const cashCents = payment.method === 'cash' ? totalCents : payment.method === 'mixed' ? payment.cashCents : 0;
  return {
    items: quote.lines.map(({ product_variant_id, qty, unit_price, discount_amount }) => ({
      product_variant_id, qty, unit_price, discount_amount,
    })),
    customer_id: payment.customerId,
    payment_method: payment.method,
    cash_amount: fromCents(cashCents),
    card_amount: fromCents(totalCents - cashCents),
    ...(payment.overridePrice ? { override_price: true } : {}),
    notes: payment.notes,
  };
}
