import { ADMIN, asPage, crud, one, api, cleanParams, openProtectedFile, downloadFile, type Id } from './http';

export type TreasuryType = 'main_safe' | 'pos_drawer' | 'bank_account';
type Named = { id: number; name: string };
export interface Treasury {
  id: number; code: string; name: string; type: TreasuryType; branch_id: number | null;
  branch?: Named | null; current_balance: string; is_active: boolean;
}
export interface TreasuryPayload { name: string; code: string; type: TreasuryType; branch_id?: number | null; is_active: boolean }
export const treasuriesApi = crud<Treasury, TreasuryPayload>(`${ADMIN}/treasuries`);

export interface Expense {
  id: number; expense_number: string; branch_id: number; branch?: Named; treasury?: Named; creator?: Named;
  shift_id: number | null; amount: string; category: string; notes: string | null;
  treasury_id: number; expense_date: string;
}
export interface ExpensePayload { branch_id: number; amount: number; category: string; notes?: string | null; treasury_id: number; expense_date: string }
export const expensesApi = crud<Expense, ExpensePayload>(`${ADMIN}/expenses`);

export interface TreasuryTransfer {
  id: number; from_treasury_id: number; to_treasury_id: number; amount: string; status: string; notes: string | null;
  from_treasury?: Named; to_treasury?: Named; creator?: Named; approver?: Named; created_at?: string;
}
const TT = `${ADMIN}/treasury-transfers`;
export const treasuryTransfersApi = {
  list: (params?: object, signal?: AbortSignal) => api.get(TT, { params: cleanParams(params), signal }).then((r) => asPage<TreasuryTransfer>(r)),
  show: (id: Id, signal?: AbortSignal) => api.get(`${TT}/${id}`, { signal }).then((r) => one<TreasuryTransfer>(r)),
  create: (body: { from_treasury_id: number; to_treasury_id: number; amount: number; notes?: string | null }) => api.post(TT, body).then((r) => one<TreasuryTransfer>(r)),
  approve: (id: Id) => api.post(`${TT}/${id}/approve`).then((r) => one<TreasuryTransfer>(r)),
  reject: (id: Id) => api.post(`${TT}/${id}/reject`).then((r) => one<TreasuryTransfer>(r)),
};

export interface PosShift {
  id: number; shift_number: string; branch_id: number; user_id: number; treasury_id: number;
  cashier?: Named; branch?: Named; treasury?: Named; approver?: Named;
  status: 'open' | 'closed_pending_approval' | 'approved'; opened_at: string; closed_at: string | null;
  opening_cash: string; cash_sales_total: string; card_sales_total: string; cash_returns_total: string; cash_expenses_total: string;
  expected_closing_cash: string | null; actual_closing_cash: string | null; cash_difference: string | null;
  expenses?: Expense[]; notes?: string | null;
}
const SH = `${ADMIN}/shifts`;
export const shiftsApi = {
  list: (params?: object, signal?: AbortSignal) => api.get(SH, { params: cleanParams(params), signal }).then((r) => asPage<PosShift>(r)),
  show: (id: Id, signal?: AbortSignal) => api.get(`${SH}/${id}`, { signal }).then((r) => one<PosShift>(r)),
  approve: (id: Id) => api.post(`${SH}/${id}/approve`).then((r) => one<PosShift>(r)),
};

export interface QuotationItem {
  id: number; product_variant_id: number; qty: number; requested_target_price: string | null;
  offered_unit_price: string | null; line_total: string | null;
  variant?: { id: number; sku: string; product_name: string | null };
}
export interface Quotation {
  id: number; quotation_number: string; customer_id: number; customer?: Named & { company_name: string | null };
  status: 'draft' | 'requested' | 'priced_by_admin' | 'accepted' | 'rejected' | 'expired' | 'converted_to_order';
  valid_until: string | null; subtotal: string; discount_amount: string; tax_amount: string; grand_total: string;
  customer_notes: string | null; admin_notes: string | null; converted_order_id: number | null;
  items?: QuotationItem[]; created_at: string;
}
export interface PriceQuotationPayload {
  items: { id: number; offered_unit_price: number }[]; valid_until?: string | null; admin_notes?: string | null;
  discount_amount?: number; tax_amount?: number;
}
const Q = `${ADMIN}/quotations`;
export const quotationsApi = {
  list: (params?: object, signal?: AbortSignal) => api.get(Q, { params: cleanParams(params), signal }).then((r) => asPage<Quotation>(r)),
  show: (id: Id, signal?: AbortSignal) => api.get(`${Q}/${id}`, { signal }).then((r) => one<Quotation>(r)),
  price: (id: Id, body: PriceQuotationPayload) => api.post(`${Q}/${id}/price`, body).then((r) => one<Quotation>(r)),
  expire: (id: Id) => api.post(`${Q}/${id}/expire`).then((r) => one<Quotation>(r)),
};

export interface PaymentNotice {
  id: number; notice_number: string; customer_id: number; customer?: Named & { company_name: string | null };
  order_id: number | null; order_number?: string | null; amount: string; payment_method: string;
  bank_name: string | null; reference_number: string | null; transfer_date: string | null; has_receipt: boolean;
  status: 'pending_review' | 'approved' | 'rejected'; admin_notes: string | null; treasury_id: number | null; created_at: string;
}
const PN = `${ADMIN}/payment-notices`;
export const paymentNoticesApi = {
  list: (params?: object, signal?: AbortSignal) => api.get(PN, { params: cleanParams(params), signal }).then((r) => asPage<PaymentNotice>(r)),
  show: (id: Id, signal?: AbortSignal) => api.get(`${PN}/${id}`, { signal }).then((r) => one<PaymentNotice>(r)),
  approve: (id: Id, body: { treasury_id?: number | null; admin_notes?: string | null }) => api.post(`${PN}/${id}/approve`, body).then((r) => one<PaymentNotice>(r)),
  reject: (id: Id, admin_notes: string) => api.post(`${PN}/${id}/reject`, { admin_notes }).then((r) => one<PaymentNotice>(r)),
  openReceipt: (id: Id) => openProtectedFile(`${PN}/${id}/receipt`),
  downloadReceipt: (id: Id) => downloadFile(`${PN}/${id}/receipt`, `payment-notice-${id}-receipt`),
};
export type { Id };
