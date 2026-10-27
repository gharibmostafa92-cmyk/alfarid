import { api, ADMIN, cleanParams, type PageMeta } from './http';

type Money = number | string;
export interface ReportPeriod { from?: string; to?: string }
export interface SalesReportParams extends ReportPeriod { granularity?: 'daily' | 'monthly'; group_by?: 'branch' | 'channel' | 'cashier' | 'payment_method' }
export interface InventoryReportParams { dead_days?: number }
export interface SalesReportRow { period: string; group_key: string | number | null; group_label: string | null; orders_count: number; gross_sales: Money; discounts: Money; tax: Money; shipping: Money; net_sales: Money }
export interface SalesReportData { rows: SalesReportRow[]; totals: Omit<SalesReportRow, 'period' | 'group_key' | 'group_label'> }
export interface InventoryReportData {
  valuation: { branch_id: number; branch_name: string; variants_count: number; total_qty: Money; total_value: Money }[];
  total_value: Money; dead_stock_days: number;
  dead_stock: { product_variant_id: number; sku: string; product_name: string; on_hand: Money; tied_value: Money; last_movement_at: string | null }[];
}
export interface ProfitReportData { net_sales_revenue: Money; cogs: Money; gross_profit: Money; total_expenses: Money; net_profit: Money }
export interface SupplierReportRow { supplier_id: number; supplier_name: string; current_balance: Money; total_purchases: Money; aging: Record<'not_due' | 'overdue_1_30' | 'overdue_31_60' | 'overdue_61_90' | 'overdue_90_plus', Money> }
export interface ShiftReportRow { shift_id: number; shift_number: string; cashier_name: string; branch_name: string; status: string; opened_at: string; closed_at: string | null; cash_sales_total: Money; card_sales_total: Money; cash_returns_total: Money; cash_expenses_total: Money; expected_closing_cash: Money; actual_closing_cash: Money | null; cash_difference: Money | null; has_difference: boolean }
export interface ShiftReportData { rows: ShiftReportRow[]; totals: { shifts_count: number; cash_sales: Money; card_sales: Money; shifts_with_difference: number } }
const report = <T>(name: string, p: object, signal?: AbortSignal): Promise<T> =>
  api.get<{ data: T }>(`${ADMIN}/reports/${name}`, { params: cleanParams(p), signal }).then((r) => r.data.data);
export const reportsApi = {
  sales: (p: SalesReportParams = {}, signal?: AbortSignal) => report<SalesReportData>('sales', p, signal),
  profit: (p: ReportPeriod = {}, signal?: AbortSignal) => report<ProfitReportData>('profit', p, signal),
  inventory: (p: InventoryReportParams = {}, signal?: AbortSignal) => report<InventoryReportData>('inventory', p, signal),
  shifts: (p: ReportPeriod & { only_differences?: boolean } = {}, signal?: AbortSignal) => report<ShiftReportData>('shifts', p, signal),
  suppliers: (signal?: AbortSignal) => report<SupplierReportRow[]>('suppliers', {}, signal),
};

export const MAX_REPORT_DAYS = 366;
export function validatePeriod(from?: string, to?: string): string | null {
  if (!from && !to) return null;
  if (!from || !to) return 'اختر تاريخ البداية والنهاية';
  const date = (value: string) => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return NaN;
    const stamp = Date.parse(value + 'T00:00:00Z');
    return Number.isFinite(stamp) && new Date(stamp).toISOString().slice(0, 10) === value ? stamp : NaN;
  };
  const a = date(from), b = date(to);
  if (!Number.isFinite(a) || !Number.isFinite(b)) return 'صيغة التاريخ غير صحيحة';
  if (b < a) return 'تاريخ النهاية يجب أن يكون بعد تاريخ البداية';
  // ReportService uses an inclusive period (difference <= 365 days).
  if ((b - a) / 86_400_000 + 1 > MAX_REPORT_DAYS) return `أقصى مدى للتقرير ${MAX_REPORT_DAYS} يوماً`;
  return null;
}

export interface NotificationApi { id: string; type: string; data: Record<string, unknown>; read_at: string | null; created_at: string | null }
export type AdminNotification = NotificationApi;
export interface NotificationsPage { data: NotificationApi[]; meta: Omit<PageMeta, 'from' | 'to'> & { unread_count: number } }
export const notificationsApi = {
  list: (page = 1, signal?: AbortSignal) => api.get<NotificationsPage>(`${ADMIN}/notifications`, { params: { page }, signal }).then((r) => r.data),
  markRead: (id: string) => api.post<{ data: { id: string; read_at: string }; meta: { unread_count: number } }>(`${ADMIN}/notifications/${encodeURIComponent(id)}/read`).then((r) => r.data),
};
