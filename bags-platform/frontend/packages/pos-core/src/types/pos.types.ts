export type AppScreen = 'terminal' | 'stock' | 'transfers' | 'stocktake';

export interface Branch {
  id: number;
  name: string;
  code: string;
  is_active?: boolean;
}

export interface CashierUser {
  version?: number;
  id: number;
  name: string;
  initials: string;
  role: string;
  perms: string[];
}

export interface Treasury {
  id: number;
  name: string;
  code: string;
  state: 'free' | 'busy';
  last: string;
  balance?: number;
}

export interface ProductCategory {
  id: string;
  name: string;
}

export interface PosProduct {
  id: number;
  sku: string;
  barcode: string;
  name: string;
  cat: 'hand' | 'cross' | 'back' | 'wallet' | 'travel' | 'acc' | string;
  price: number;
  wholesale: number;
  stock: number;
  reorder: number;
  h: number; // Hue for placeholder art
  imageUrl?: string | null;
}

export interface Customer {
  id: number;
  name: string;
  phone: string;
    tier: 'retail' | 'wholesale' | 'distributor';
  points: number;
  /** Account review state: wholesale prices apply only when Approved. */
  status?: string | null;
}

export interface PosCartLine {
  id: number;
  qty: number;
  price: number;
  listPrice: number;
  /** True when the cashier typed this price; the quote then runs with override_price. */
  manualPrice?: boolean;
  /** Per-line message from the last quote (e.g. insufficient stock). */
  error?: string | null;
  /** Cached product metadata so lines render reliably even before/without mock catalog lookup. */
  name?: string;
  stock?: number;
  wholesale?: number;
  cat?: string;
  h?: number;
}

export interface ShiftStats {
  cashSales: number;
  cardSales: number;
  returns: number;
  expenses: number;
  invoices: number;
  expected_closing_cash?: number;
  canViewExpected?: boolean;
}

export interface PosShift {
  id: number;
  shift_number: string;
  status: 'open' | 'pending_approval' | 'closed';
  treasury_id: number;
  treasury: Treasury;
  branch_id?: number;
  branch?: Branch;
  cashier?: CashierUser;
  opening_cash: number;
  cash_sales_total?: number;
  card_sales_total?: number;
  cash_returns_total?: number;
  cash_expenses_total?: number;
  /** Absent for blind cashiers: the server strips it from the summary. */
  expected_closing_cash?: number;
  actual_closing_cash?: number | null;
  cash_difference?: number | null;
  notes?: string | null;
  opened_at: Date;
  closed_at?: Date | null;
  invoices_count?: number;
}

export interface PosHeldSale {
  ref: string;
  at: string;
  mins: number;
  customer: string | null;
  customerObj?: Customer | null;
  note: string;
  items: { id: number; qty: number; unit_price?: number; discount_amount?: number; manual_price?: boolean }[];
  /** Server id (pos_held_sales.id) used for resume/delete. */
  serverId?: number;
  _id?: number;
  resumedBy?: number | null;
  resumedAt?: string | null;
}

export interface PastSaleItem {
  id: number;
  qty: number;
  price: number;
  /** Remaining quantity that may still be returned (qty - returned_qty). */
  returnable_qty?: number;
  net_unit_price?: number;
  /** Full line value (line_total) for server-compatible proportional math. */
  line_total?: number;
  /** Already-refunded value on this line (server-computed cents). */
  previous_refunded_amount?: number;
}

export interface PastSale {
  id: number;
  number: string;
  date: string;
  cashier: string;
  customer: string;
  customer_id?: number | null;
  method: string;
  items: PastSaleItem[];
  cash_amount?: number;
  card_amount?: number;
}

export interface StockTransferItem {
  /** product_variant_id */
  id: number;
  qty: number;
  /** stock_transfer_items.id — required by POST transfers/{id}/receive. */
  transferItemId?: number;
}

export interface PosStockRow {
  id: number;
  product_variant_id: number;
  variant?: { sku?: string; barcode?: string; product_name_ar?: string; product_name_en?: string };
  location?: { name: string } | null;
  available: string | number;
  reorder_level: number;
  is_low_stock: boolean;
}

export interface StockTransfer {
  id: number;
  version?: number;
  number: string;
  from: string;
  status: 'shipped' | 'received' | 'pending' | 'draft';
  shipped: string;
  driver: string;
  items: StockTransferItem[];
}

export interface TransferSource {
  id: number;
  name: string;
  kind: 'warehouse' | 'store';
}

export interface StocktakeScanLog {
  id: number;
  add: number;
  via: 'voice' | 'barcode';
  words?: string;
  t: string;
  /** Server-provided display name so the log works before the catalog loads. */
  name?: string;
}

export interface StocktakeSession {
  id: number;
  code: string;
  status: 'draft' | 'in_progress' | 'completed';
  counts: Record<number, number>;
  lastScan?: {
    id: number;
    qty: number;
    via: 'voice' | 'barcode';
    words?: string;
    /** Server-provided display name so the card works after a reload. */
    name?: string;
  };
  log: StocktakeScanLog[];
}

export interface CreateSalePayload {
  items: {
    product_variant_id: number;
    qty: number;
    unit_price?: number;
    discount_amount?: number;
    override_reason?: string;
  }[];
  customer_id: number | null;
  payment_method: 'cash' | 'card' | 'mixed';
  cash_amount: number;
  card_amount: number;
  notes?: string;
  /** When true every line's unit_price is sent and the server accepts the manual price. */
  override_price?: boolean;
  /** Mandatory reason when override_price is true. */
  override_reason?: string;
  /** When the cart resumes a held sale: lets the server consume it atomically. */
  held_sale_id?: number | null;
  held_sale_ids?: number[];
}

export interface QuoteLineResult {
  product_variant_id: number;
  qty: number;
  unit_price: number;
  discount_amount: number;
  line_total: number;
  available: number;
  error: string | null;
}

export interface QuoteResult {
  lines: QuoteLineResult[];
  subtotal: number;
  discount_total: number;
  grand_total: number;
}

export interface PosSettings {
  max_discount_percent: number;
  return_window_days?: number;
  currency: string;
}

export interface ProcessReturnResult {
  return_order_id: number;
  exchange_order_id: number | null;
  refund_amount: number;
  cash_refund_amount: number;
  card_refund_amount: number;
  exchange_credit: number;
}
