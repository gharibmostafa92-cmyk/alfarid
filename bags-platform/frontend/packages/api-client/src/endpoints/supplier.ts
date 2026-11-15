import { api, asPage, one, toFormData, MULTIPART, type Id } from './operations/http';

const S = '/api/v1/supplier';

export type SupplierPortalPOStatus =
  | 'sent_to_supplier' | 'supplier_accepted' | 'change_requested'
  | 'partially_received' | 'completed' | 'cancelled';

export interface SupplierPortalPOItem {
  id: number;
  product_variant_id: number;
  variant?: { id: number; sku: string } | null;
  ordered_qty: string;
  received_qty: string;
  unit_cost: string;
  tax_rate: string;
  line_total: string;
}

export interface SupplierPortalDelivery {
  id: number;
  delivery_number: string;
  purchase_order_id: number;
  status: string;
  delivery_date: string | null;
  invoice_file_url: string | null;
  supplier_invoice_no: string | null;
  notes: string | null;
  items?: { id: number; purchase_order_item_id: number; shipped_qty: string; received_qty: string; variant?: { id: number; sku: string } | null }[];
  created_at?: string | null;
}

export interface SupplierPortalPO {
  id: number;
  po_number: string;
  status: SupplierPortalPOStatus;
  expected_delivery_date: string | null;
  supplier_change_notes: string | null;
  subtotal: string;
  discount_amount: string;
  tax_amount: string;
  grand_total: string;
  branch?: { id: number; name: string } | null;
  items?: SupplierPortalPOItem[];
  deliveries?: SupplierPortalDelivery[];
  created_at?: string | null;
}

export interface SupplierDashboard {
  new_orders_count: number;
  in_progress_count: number;
  current_balance: string;
  last_payments: { id: number; payment_number: string; amount: string; payment_date: string; invoice?: { id: number; invoice_number: string } | null }[];
}

export interface SupplierStatementEntry {
  date: string;
  type: 'invoice' | 'payment' | 'payment_void';
  reference: string;
  description: string;
  credit: number;
  debit: number;
  running_balance: number;
}

export interface SupplierStatement {
  supplier: { id: number; code: string; name: string; current_balance: string };
  from_date: string;
  to_date: string;
  opening_balance: number;
  entries: SupplierStatementEntry[];
  total_credit: number;
  total_debit: number;
  closing_balance: number;
}

export interface SupplierDeliveryPayload {
  delivery_date: string;
  supplier_invoice_no?: string | null;
  notes?: string | null;
  items: { purchase_order_item_id: number; shipped_qty: number }[];
  invoice_file?: File | null;
}

export const supplierPortalApi = {
  dashboard: (signal?: AbortSignal) => api.get(`${S}/dashboard`, { signal }).then(r => r.data.data as SupplierDashboard),
  purchaseOrders: (params?: { status?: SupplierPortalPOStatus; page?: number }, signal?: AbortSignal) =>
    api.get(`${S}/purchase-orders`, { params, signal }).then(r => asPage<SupplierPortalPO>(r)),
  purchaseOrder: (id: Id, signal?: AbortSignal) =>
    api.get(`${S}/purchase-orders/${id}`, { signal }).then(r => one<SupplierPortalPO>(r)),
  accept: (id: Id) => api.post(`${S}/purchase-orders/${id}/accept`).then(r => one<SupplierPortalPO>(r)),
  requestChange: (id: Id, notes: string) =>
    api.post(`${S}/purchase-orders/${id}/request-change`, { notes }).then(r => one<SupplierPortalPO>(r)),
  createDelivery: (id: Id, body: SupplierDeliveryPayload, idempotencyKey: string) =>
    api.post(`${S}/purchase-orders/${id}/deliveries`, toFormData(body as unknown as Record<string, unknown>), {
      ...MULTIPART,
      headers: { ...MULTIPART.headers, 'Idempotency-Key': idempotencyKey },
    }).then(r => one<SupplierPortalDelivery>(r)),
  deliveries: (params?: { purchase_order_id?: number; status?: string; page?: number }, signal?: AbortSignal) =>
    api.get(`${S}/deliveries`, { params, signal }).then(r => asPage<SupplierPortalDelivery>(r)),
  delivery: (id: Id, signal?: AbortSignal) =>
    api.get(`${S}/deliveries/${id}`, { signal }).then(r => one<SupplierPortalDelivery>(r)),
  statement: (params?: { from_date?: string; to_date?: string }, signal?: AbortSignal) =>
    api.get(`${S}/statement`, { params, signal }).then(r => r.data.data as SupplierStatement),
};
