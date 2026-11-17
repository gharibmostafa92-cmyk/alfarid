export interface LastOrderInput {
  order_number: string;
  status: string;
  payment_method: string;
  payment_status: string;
  grand_total: unknown;
  items: unknown;
  recipient_phone?: string | null;
  instapay?: { address: string; amount: string; expires_at: string } | null;
}

export function lastOrderReceipt(data: LastOrderInput, attemptOwner: string | null, now = Date.now()) {
  const receipt: Record<string, unknown> = {
    order_number: data.order_number,
    status: data.status,
    payment_method: data.payment_method,
    payment_status: data.payment_status,
    grand_total: data.grand_total,
    items: data.items,
    recipient_phone: attemptOwner === 'guest' ? data.recipient_phone : undefined,
    expiresAt: now + 30 * 60_000,
  };
  if (data.instapay !== undefined) receipt.instapay = data.instapay;
  return receipt;
}

export function shouldTrackInstapay(
  order: { order_number?: string; payment_method?: string; instapay?: unknown } | null,
  orderNumber: string,
  contactPhone: string | undefined,
): boolean {
  return Boolean(
    order
    && order.order_number === orderNumber
    && order.payment_method === 'bank_transfer'
    && contactPhone
    && order.instapay === undefined,
  );
}
