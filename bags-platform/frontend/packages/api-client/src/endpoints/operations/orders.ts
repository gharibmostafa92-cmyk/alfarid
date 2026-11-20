import { api, ADMIN, asPage, one, crud, cleanParams, reader, idempotentPost, type Id, type Paginated } from './http';

export type OrderStatus = 'pending' | 'confirmed' | 'processing' | 'shipped' | 'delivered' | 'cancelled' | 'returned';
export type PaymentStatus = 'unpaid' | 'paid' | 'failed' | 'partially_refunded' | 'refund_pending' | 'refunded';
export type PaymentMethod = 'cod' | 'card' | 'wallet' | 'bank_transfer' | 'credit';

export const PAYMENT_TRANSITIONS: Record<PaymentStatus, PaymentStatus[]> = {
  unpaid: ['paid', 'failed'],
  failed: ['paid', 'unpaid'],
  paid: ['refunded'],
  partially_refunded: ['refunded'],
  refund_pending: [],
  refunded: [],
};

export interface AdminOrderListParams {
  page?: number;
  per_page?: number;
  status?: OrderStatus;
  allocated_branch_id?: number;
  payment_method?: PaymentMethod;
  date_from?: string; // Y-m-d
  date_to?: string;   // Y-m-d
  search?: string;
  needs_attention?: boolean;
  requires_refund?: boolean;
}

export interface AdminOrderStats { pending: number; processing: number; needs_attention: number; requires_refund: number }

export interface AdminOrderItem {
  id: number;
  product_variant_id: number;
  product_name: string;
  sku: string;
  allocated_branch_id?: number | null;
  qty: number;
  unit_price: string;
  discount_amount?: string | null;
  tax_amount?: string | null;
  line_total: string;
}

export interface AdminOrderStatusHistory {
  from_status: OrderStatus | null;
  to_status: OrderStatus;
  changed_by_type: string | null;
  changed_by_id: number | null;
  notes: string | null;
  at: string | null;
}

export interface AdminOrder {
  id: number;
  order_number: string;
  channel?: string | null;
  status: OrderStatus;
  payment_status: PaymentStatus;
  payment_method: PaymentMethod;
  legal_policy_version?: string | null;
  legal_accepted_at?: string | null;
  requires_refund: boolean;
  needs_attention: boolean;
  cod_fee?: string | null;
  guest_name: string | null;
  guest_phone: string | null;
  guest_email?: string | null;
  subtotal?: string | null;
  discount_amount?: string | null;
  coupon_code?: string | null;
  shipping_cost?: string | null;
  tax_amount?: string | null;
  grand_total: string;
  shipping_address_line?: string | null;
  shipping_notes?: string | null;
  items_count?: number;
  customer?: { id: number; name: string; phone?: string | null; type?: string } | null;
  city?: { id: number; name_ar: string } | null;
  allocated_branch?: { id: number; name: string } | null;
  items?: AdminOrderItem[];
  status_history?: AdminOrderStatusHistory[];
  created_at: string;
  updated_at?: string | null;
}

export interface ShipmentResponse {
  id: number;
  order_id?: number;
  shipping_company_id: number;
  company_name?: string | null;
  awb_number: string | null;
  status?: string | null;
  tracking_url?: string | null;
  label_url?: string | null;
  shipping_cost?: string | null;
  cod_amount?: string | null;
}

const O = `${ADMIN}/orders`;
export const adminOrdersApi = {
  ...reader<AdminOrder, AdminOrderListParams>(O),
  updateStatus: (id: Id, body: { status: Exclude<OrderStatus, 'shipped'>; notes?: string }) =>
    api.patch(`${O}/${id}/status`, body).then((r) => one<AdminOrder>(r)),
  updatePayment: (id: Id, body: { payment_status: PaymentStatus; notes: string; refund_reference?: string }, idempotencyKey?: string) =>
    idempotentPost(`${O}/${id}/payment`, body, idempotencyKey).then((r) => one<AdminOrder>(r)),
  allocate: (id: Id, branch_id: number | null, idempotencyKey?: string) =>
    idempotentPost(`${O}/${id}/allocate`, { branch_id }, idempotencyKey).then((r) => one<AdminOrder>(r)),
  deallocate: (id: Id, idempotencyKey?: string) => idempotentPost(`${O}/${id}/deallocate`, {}, idempotencyKey).then((r) => one<AdminOrder>(r)),
  createShipment: (id: Id, body: { shipping_company_id: number; awb_number?: string | null }, idempotencyKey?: string) =>
    idempotentPost(`${O}/${id}/shipment`, body, idempotencyKey).then((r) => one<ShipmentResponse>(r)),
  stats: (signal?: AbortSignal): Promise<AdminOrderStats> =>
    api.get<{ data: AdminOrderStats }>(`${O}/stats`, { signal }).then((r) => r.data.data),
};

/* ===== Returns ===== */
export type ReturnStatus = 'pending' | 'approved' | 'rejected' | 'received' | 'refunded' | 'cancelled';
export type RefundMethod = 'original_payment' | 'wallet' | 'bank_transfer' | 'cash' | 'credit_note';

export interface ReturnRequestItem {
  id: number;
  order_item_id: number;
  product_variant_id?: number | null;
  product_name?: string | null;
  sku?: string | null;
  requested_qty: number | string;
  approved_qty: number | string | null;
  received_good_qty: number | string | null;
  received_damaged_qty: number | string | null;
  unit_refund_price?: string | null;
  refund_amount?: string | null;
  item_reason?: string | null;
  attachments?: string[];
}

/** Maps directly to backend ReturnRequestResource payload. */
export interface ReturnRequest {
  id: number;
  return_number: string;
  order_id: number;
  order_number?: string | null;
  customer_id: number | null;
  customer_name?: string | null;
  branch_id: number | null;
  status: ReturnStatus;
  refund_method: RefundMethod | null;
  total_refund_amount: string | null;
  reason: string | null;
  reason_type?: 'change_of_mind' | 'defect_or_mismatch' | null;
  admin_notes: string | null;
  refund_reference: string | null;
  approved_by: number | null;
  received_by: number | null;
  refunded_by: number | null;
  approved_at: string | null;
  received_at: string | null;
  refunded_at: string | null;
  items?: ReturnRequestItem[];
  created_at: string | null;
  updated_at: string | null;
}

export interface ReturnListParams { page?: number; per_page?: number; search?: string; status?: ReturnStatus; order_id?: number; customer_id?: number }
export interface ReturnStats {
  pending: number;
  approved: number;
  rejected: number;
  received: number;
  refunded: number;
  cancelled: number;
  total: number;
}
export interface StoreReturnPayload {
  order_id: number;
  reason: string;
  reason_type?: 'change_of_mind' | 'defect_or_mismatch';
  refund_method?: RefundMethod | null;
  items: { order_item_id: number; requested_qty: number; item_reason?: string }[];
}
export interface ApproveReturnPayload { branch_id: number; items?: { id: number; approved_qty: number }[]; admin_notes?: string }
export interface ReceiveReturnPayload { items: { id: number; received_good_qty: number; received_damaged_qty: number }[] }
export interface RefundReturnPayload { refund_method?: RefundMethod | null; refund_reference?: string; treasury_id?: number }

const R = `${ADMIN}/returns`;
export const adminReturnsApi = {
  ...crud<ReturnRequest, StoreReturnPayload, ReturnListParams>(R),
  stats: (signal?: AbortSignal): Promise<ReturnStats> =>
    api.get<{ data: ReturnStats }>(`${R}/stats`, { signal }).then((r) => r.data.data),
  create: (body: StoreReturnPayload, idempotencyKey?: string) =>
    idempotentPost(R, body, idempotencyKey).then((r) => one<ReturnRequest>(r)),
  update: (id: Id, b: Partial<StoreReturnPayload> & { status?: 'rejected' | 'cancelled'; admin_notes?: string; refund_method?: RefundMethod | null }) =>
    api.put(`${R}/${id}`, b).then((r) => one<ReturnRequest>(r)),
  approve: (id: Id, b: ApproveReturnPayload, idempotencyKey?: string) =>
    idempotentPost(`${R}/${id}/approve`, b, idempotencyKey).then((r) => one<ReturnRequest>(r)),
  receive: (id: Id, b: ReceiveReturnPayload, idempotencyKey?: string) =>
    idempotentPost(`${R}/${id}/receive`, b, idempotencyKey).then((r) => one<ReturnRequest>(r)),
  refund: (id: Id, b: RefundReturnPayload, idempotencyKey?: string) =>
    idempotentPost(`${R}/${id}/refund`, b, idempotencyKey).then((r) => one<ReturnRequest>(r)),
};
