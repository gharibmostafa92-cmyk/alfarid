import { ADMIN, asPage, cleanParams, crud, one, api, toFormData, MULTIPART, openProtectedFile, type Id } from './http';

export interface SupplierUser { id: number; supplier_id: number; name: string; email: string; phone: string | null; is_active: boolean }
export interface SupplierUserPayload { name: string; email: string; phone?: string | null; password?: string; is_active: boolean }
export interface Supplier {
  id: number; code: string; name: string; company_name: string | null; email: string | null;
  phone: string | null; tax_number: string | null; commercial_register: string | null; address: string | null;
  payment_terms_days: number; opening_balance: string; current_balance: string; is_active: boolean;
  users?: SupplierUser[]; created_at?: string | null; updated_at?: string | null;
}
export interface SupplierPayload {
  code: string; name: string; company_name?: string | null; email?: string | null; phone?: string | null;
  tax_number?: string | null; commercial_register?: string | null; address?: string | null;
  payment_terms_days?: number; opening_balance?: number; is_active: boolean;
}
const S = `${ADMIN}/suppliers`;
export const suppliersApi = {
  ...crud<Supplier, SupplierPayload, { page?: number; search?: string; is_active?: boolean }>(S),
  users: (id: Id, signal?: AbortSignal) => api.get(`${S}/${id}/users`, { signal }).then(r => asPage<SupplierUser>(r).data),
  createUser: (id: Id, body: SupplierUserPayload) => api.post(`${S}/${id}/users`, body).then(r => one<SupplierUser>(r)),
  updateUser: (id: Id, user: Id, body: Partial<SupplierUserPayload>) => api.put(`${S}/${id}/users/${user}`, body).then(r => one<SupplierUser>(r)),
  removeUser: (id: Id, user: Id) => api.delete(`${S}/${id}/users/${user}`).then(r => r.data as { message: string }),
};

export type PRStatus = 'draft' | 'pending' | 'approved' | 'rejected' | 'converted_to_po';
export interface PurchaseRequest {
  id: number; request_number: string; branch_id: number; requested_by: number | null; status: PRStatus; notes: string | null;
  branch?: { id: number; name: string }; created_at?: string | null;
  items?: { id: number; product_variant_id: number; requested_qty: number; notes?: string | null; variant?: { id: number; sku: string; cost_price: string } }[];
}
export interface PurchaseRequestPayload { branch_id: number; notes?: string | null; items: { product_variant_id: number; requested_qty: number; notes?: string | null }[] }
const PR = `${ADMIN}/purchase-requests`;
export const purchaseRequestsApi = {
  ...crud<PurchaseRequest, PurchaseRequestPayload, { page?: number; status?: PRStatus; branch_id?: number }>(PR),
  submit: (id: Id) => api.post(`${PR}/${id}/submit`).then(r => one<PurchaseRequest>(r)),
  approve: (id: Id) => api.post(`${PR}/${id}/approve`).then(r => one<PurchaseRequest>(r)),
  reject: (id: Id) => api.post(`${PR}/${id}/reject`).then(r => one<PurchaseRequest>(r)),
};

export type POStatus = 'draft' | 'sent_to_supplier' | 'supplier_accepted' | 'change_requested' | 'partially_received' | 'completed' | 'cancelled';
export interface PurchaseOrderItem {
  id: number; product_variant_id: number; ordered_qty: number; received_qty: number; unit_cost: string; tax_rate: string;
  variant?: { id: number; sku: string; cost_price: string };
}
export interface PurchaseOrder {
  id: number; po_number: string; supplier_id: number; branch_id: number; purchase_request_id: number | null;
  supplier?: { id: number; code: string; name: string } | null; branch?: { id: number; name: string } | null;
  status: POStatus; approved_by: number | null; expected_delivery_date: string | null; supplier_change_notes: string | null;
  subtotal: string; discount_amount: string; tax_amount: string; grand_total: string; items?: PurchaseOrderItem[];
  deliveries?: { id: number; delivery_number: string; status: string; delivery_date: string | null }[];
}
export interface PurchaseOrderPayload {
  supplier_id: number; branch_id?: number; purchase_request_id?: number | null; expected_delivery_date?: string | null;
  discount_amount?: number; items?: { product_variant_id: number; ordered_qty: number; unit_cost: number; tax_rate?: number }[];
}
export interface POReceivePayload { location_id?: number | null; supplier_delivery_id?: number | null; items: { purchase_order_item_id: number; qty: number }[] }
const PO = `${ADMIN}/purchase-orders`;
export const purchaseOrdersApi = {
  ...crud<PurchaseOrder, PurchaseOrderPayload, { page?: number; search?: string; status?: POStatus; supplier_id?: number; branch_id?: number }>(PO),
  approve: (id: Id) => api.post(`${PO}/${id}/approve`).then(r => one<PurchaseOrder>(r)),
  send: (id: Id) => api.post(`${PO}/${id}/send`).then(r => one<PurchaseOrder>(r)),
  receive: (id: Id, body: POReceivePayload, idempotencyKey: string) => api.post(`${PO}/${id}/receive`, body, { headers: { 'Idempotency-Key': idempotencyKey } }).then(r => one<PurchaseOrder>(r)),
  cancel: (id: Id) => api.post(`${PO}/${id}/cancel`).then(r => one<PurchaseOrder>(r)),
  closeShort: (id: Id) => api.post(`${PO}/${id}/close-short`).then(r => one<PurchaseOrder>(r)),
};

export interface SupplierInvoice {
  id: number; invoice_number: string; supplier_invoice_no: string | null; supplier_id: number;
  supplier?: { id: number; code: string; name: string } | null; purchase_order_id: number | null;
  purchase_order?: { id: number; po_number: string } | null; issue_date: string | null; due_date: string | null;
  subtotal: string; tax_amount: string; total_amount: string; paid_amount: string; remaining_amount: string;
  status: 'draft' | 'posted' | 'partially_paid' | 'paid' | 'cancelled'; attachment_url: string | null;
}
export interface SupplierInvoicePayload {
  supplier_id: number; supplier_invoice_no: string; purchase_order_id?: number | null; issue_date: string;
  due_date?: string | null; subtotal: number; tax_amount?: number; attachment?: File | null;
}
const INV = `${ADMIN}/supplier-invoices`;
export const supplierInvoicesApi = {
  ...crud<SupplierInvoice, SupplierInvoicePayload, { page?: number; status?: string; supplier_id?: number; purchase_order_id?: number }>(INV),
  create: (body: SupplierInvoicePayload) => api.post(INV, toFormData(body as unknown as Record<string, unknown>), MULTIPART).then(r => one<SupplierInvoice>(r)),
  update: (id: Id, body: Partial<Omit<SupplierInvoicePayload, 'supplier_id'>>) => api.post(`${INV}/${id}`, toFormData(body as Record<string, unknown>, true), MULTIPART).then(r => one<SupplierInvoice>(r)),
  post: (id: Id) => api.post(`${INV}/${id}/post`).then(r => one<SupplierInvoice>(r)),
  cancel: (id: Id) => api.post(`${INV}/${id}/cancel`).then(r => one<SupplierInvoice>(r)),
  openAttachment: (id: Id) => openProtectedFile(`${INV}/${id}/attachment`),
};

export interface SupplierPaymentApi {
  id: number; payment_number: string; supplier_id: number; supplier_invoice_id: number | null;
  invoice?: { id: number; invoice_number: string; supplier_invoice_no: string | null } | null;
  amount: string; payment_method: 'cash' | 'bank_transfer' | 'check'; reference_no: string | null;
  payment_date: string; notes: string | null; voided_at: string | null; voided_by: number | null; void_reason: string | null;
}
export interface SupplierPaymentPayload {
  supplier_id: number; supplier_invoice_id?: number | null; amount: number; payment_method: SupplierPaymentApi['payment_method'];
  reference_no?: string | null; payment_date: string; notes?: string | null;
}
const PAY = `${ADMIN}/supplier-payments`;
export const supplierPaymentsApi = {
  list: (params: { page?: number; supplier_id?: number; supplier_invoice_id?: number; from_date?: string; to_date?: string } = {}, signal?: AbortSignal) => api.get(PAY, { params: cleanParams(params), signal }).then(r => asPage<SupplierPaymentApi>(r)),
  show: (id: Id, signal?: AbortSignal) => api.get(`${PAY}/${id}`, { signal }).then(r => one<SupplierPaymentApi>(r)),
  create: (body: SupplierPaymentPayload, idempotencyKey: string) => api.post(PAY, body, { headers: { 'Idempotency-Key': idempotencyKey } }).then(r => one<SupplierPaymentApi>(r)),
  update: (id: Id, body: Pick<SupplierPaymentPayload, 'payment_method' | 'reference_no' | 'notes'>) => api.put(`${PAY}/${id}`, body).then(r => one<SupplierPaymentApi>(r)),
  allocate: (id: Id, supplier_invoice_id: number) => api.post(`${PAY}/${id}/allocate`, { supplier_invoice_id }).then(r => one<SupplierPaymentApi>(r)),
  void: (id: Id, void_reason: string) => api.post(`${PAY}/${id}/void`, { void_reason }).then(r => r.data as { message: string }),
};
