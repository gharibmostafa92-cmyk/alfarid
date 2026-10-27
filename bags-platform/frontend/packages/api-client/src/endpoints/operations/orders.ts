import { api, ADMIN, asPage, one, cleanParams, type Id, type Paginated } from './http';

export type OrderStatus = 'pending' | 'confirmed' | 'processing' | 'shipped' | 'delivered' | 'cancelled' | 'returned';
export type PaymentStatus = 'unpaid' | 'paid' | 'failed' | 'refunded';
export type PaymentMethod = 'cod' | 'card' | 'wallet' | 'bank_transfer' | 'credit';

export const PAYMENT_TRANSITIONS: Record<PaymentStatus, PaymentStatus[]> = {
  unpaid: ['paid', 'failed'],
  failed: ['paid', 'unpaid'],
  paid: ['refunded'],
  refunded: [],
};

export interface AdminOrderListParams {
  page?: number;
  status?: OrderStatus;
  allocated_branch_id?: number;
  payment_method?: PaymentMethod;
  date_from?: string; // Y-m-d
  date_to?: string;   // Y-m-d
  search?: string;
  needs_attention?: boolean;
  requires_refund?: boolean;
}

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
  list: (p: AdminOrderListParams, signal?: AbortSignal): Promise<Paginated<AdminOrder>> =>
    api.get(O, { params: cleanParams(p), signal }).then((r) => asPage<AdminOrder>(r)),
  show: (id: Id, signal?: AbortSignal) => api.get(`${O}/${id}`, { signal }).then((r) => one<AdminOrder>(r)),
  updateStatus: (id: Id, body: { status: Exclude<OrderStatus, 'shipped'>; notes?: string }) =>
    api.patch(`${O}/${id}/status`, body).then((r) => one<AdminOrder>(r)),
  updatePayment: (id: Id, body: { payment_status: PaymentStatus; notes?: string }) =>
    api.post(`${O}/${id}/payment`, body).then((r) => one<AdminOrder>(r)),
  allocate: (id: Id, branch_id: number | null) =>
    api.post(`${O}/${id}/allocate`, { branch_id }).then((r) => one<AdminOrder>(r)),
  deallocate: (id: Id) => api.post(`${O}/${id}/deallocate`).then((r) => one<AdminOrder>(r)),
  createShipment: (id: Id, body: { shipping_company_id: number; awb_number?: string | null }) =>
    api.post(`${O}/${id}/shipment`, body).then((r) => one<ShipmentResponse>(r)),
};

/* ===== Returns ===== */
export type ReturnStatus = 'pending' | 'approved' | 'rejected' | 'received' | 'refunded' | 'cancelled';
export type RefundMethod = 'original_payment' | 'wallet' | 'bank_transfer' | 'cash';

export interface ReturnRequestItem {
  id: number;
  order_item_id: number;
  product_variant_id?: number | null;
  product_name?: string | null;
  sku?: string | null;
  requested_qty: number;
  approved_qty: number | null;
  received_good_qty: number | null;
  received_damaged_qty: number | null;
  unit_refund_price?: string | null;
  item_reason?: string | null;
}

/** مطابق حرفياً لـ ReturnRequestResource::toArray */
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

export interface ReturnListParams { page?: number; search?: string; status?: ReturnStatus; order_id?: number; customer_id?: number }
export interface StoreReturnPayload {
  order_id: number;
  reason: string;
  refund_method?: RefundMethod | null;
  items: { order_item_id: number; requested_qty: number; item_reason?: string }[];
}
export interface ApproveReturnPayload { branch_id: number; items?: { id: number; approved_qty: number }[]; admin_notes?: string }
export interface ReceiveReturnPayload { items: { id: number; received_good_qty: number; received_damaged_qty: number }[] }
export interface RefundReturnPayload { refund_method?: RefundMethod | null; refund_reference?: string }

const R = `${ADMIN}/returns`;
export const adminReturnsApi = {
  list: (p: ReturnListParams, signal?: AbortSignal) =>
    api.get(R, { params: cleanParams(p), signal }).then((r) => asPage<ReturnRequest>(r)),
  show: (id: Id, signal?: AbortSignal) => api.get(`${R}/${id}`, { signal }).then((r) => one<ReturnRequest>(r)),
  create: (b: StoreReturnPayload) => api.post(R, b).then((r) => one<ReturnRequest>(r)),
  update: (id: Id, b: Partial<StoreReturnPayload> & { status?: 'rejected' | 'cancelled'; admin_notes?: string; refund_method?: RefundMethod | null }) =>
    api.put(`${R}/${id}`, b).then((r) => one<ReturnRequest>(r)),
  remove: (id: Id) => api.delete(`${R}/${id}`).then((r) => r.data as { message: string }),
  approve: (id: Id, b: ApproveReturnPayload) => api.post(`${R}/${id}/approve`, b).then((r) => one<ReturnRequest>(r)),
  receive: (id: Id, b: ReceiveReturnPayload) => api.post(`${R}/${id}/receive`, b).then((r) => one<ReturnRequest>(r)),
  refund: (id: Id, b: RefundReturnPayload) => api.post(`${R}/${id}/refund`, b).then((r) => one<ReturnRequest>(r)),
};
