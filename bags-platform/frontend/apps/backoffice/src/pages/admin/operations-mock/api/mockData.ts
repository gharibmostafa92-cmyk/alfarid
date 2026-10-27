import type { AdminOrder, ReturnRequest, SalesReportData, InventoryReportData, ProfitReportData, SupplierReportRow, ShiftReportData } from '@alfarid/api-client';
import { ORDERS, RETURNS, VARIANTS, BRANCHES, SUPPLIERS, SHIFTS } from '../data';

/** Only passed to useRemote.mock; never substitutes for an empty live response. */
export function mockOrder(id: number): AdminOrder | null {
  const row = ORDERS.find(o => o.id === id);
  if (!row) return null;
  return { id: row.id, order_number: row.no, status: row.status as AdminOrder['status'], payment_method: (row.pay === 'bank' ? 'bank_transfer' : row.pay) as AdminOrder['payment_method'], payment_status: row.payStatus as AdminOrder['payment_status'], guest_name: row.customer, guest_phone: row.phone, grand_total: String(row.total), needs_attention: row.followUp, requires_refund: row.needRefund, channel: row.channel, created_at: '2026-10-27T08:00:00Z', allocated_branch: row.branch ? { id: 1, name: row.branch } : null, items: VARIANTS.slice(0, 2).map((v, i) => ({ id: i + 1, product_variant_id: v.id, product_name: v.name, sku: v.sku, qty: 1, unit_price: String(v.price), line_total: String(v.price) })), status_history: [] };
}
export function mockReturn(id: number): ReturnRequest | null {
  const r = RETURNS.find(r => r.id === id);
  if (!r) return null;
  return { id: r.id, return_number: r.no, order_id: 1, order_number: r.order, customer_id: null, customer_name: r.customer, branch_id: 1, status: r.status as ReturnRequest['status'], refund_method: r.method === 'original' ? 'original_payment' : r.method === 'bank' ? 'bank_transfer' : r.method as ReturnRequest['refund_method'], total_refund_amount: String(r.total), reason: '', admin_notes: null, refund_reference: null, approved_by: null, received_by: null, refunded_by: null, approved_at: null, received_at: null, refunded_at: null, created_at: '2026-10-27T08:00:00Z', updated_at: null, items: [] };
}
export const mockSales = (): SalesReportData => {
  const rows = ORDERS.map(o => ({ period: o.date, group_key: null, group_label: null, orders_count: 1, gross_sales: o.total, net_sales: o.total, discounts: 0, shipping: 0, tax: 0 }));
  const amount = ORDERS.reduce((sum, o) => sum + o.total, 0);
  return { rows, totals: { orders_count: rows.length, gross_sales: amount, net_sales: amount, discounts: 0, shipping: 0, tax: 0 } };
};
export const mockInventory = (): InventoryReportData => ({ valuation: BRANCHES.map((name, i) => ({ branch_id: i + 1, branch_name: name, variants_count: VARIANTS.length, total_qty: 0, total_value: 0 })), total_value: 0, dead_stock_days: 90, dead_stock: [] });
export const mockProfit = (): ProfitReportData => ({ net_sales_revenue: mockSales().totals.net_sales, cogs: 0, gross_profit: mockSales().totals.net_sales, total_expenses: 0, net_profit: mockSales().totals.net_sales });
export const mockSuppliers = (): SupplierReportRow[] => SUPPLIERS.map(s => ({ supplier_id: s.id, supplier_name: s.company || s.name, current_balance: s.balance, total_purchases: 0, aging: { not_due: Math.max(0, s.balance), overdue_1_30: 0, overdue_31_60: 0, overdue_61_90: 0, overdue_90_plus: 0 } }));
export const mockShifts = (): ShiftReportData => ({ rows: SHIFTS.map(s => ({ shift_id: s.id, shift_number: s.no, cashier_name: s.cashier, branch_name: s.branch, status: s.status, opened_at: s.open, closed_at: s.close, cash_sales_total: 0, card_sales_total: 0, cash_returns_total: 0, cash_expenses_total: 0, expected_closing_cash: s.exp ?? 0, actual_closing_cash: s.act, cash_difference: s.act != null && s.exp != null ? s.act - s.exp : null, has_difference: s.act !== s.exp })), totals: { shifts_count: SHIFTS.length, cash_sales: 0, card_sales: 0, shifts_with_difference: SHIFTS.filter(s => s.act !== s.exp).length } });
