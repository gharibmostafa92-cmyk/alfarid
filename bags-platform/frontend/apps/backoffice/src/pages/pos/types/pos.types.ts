export type AppScreen = 'terminal' | 'stock' | 'transfers' | 'stocktake';

export interface Branch {
  id: number;
  name: string;
  code: string;
  is_active?: boolean;
}

export interface CashierUser {
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
}

export interface Customer {
  id: number;
  name: string;
  phone: string;
  tier: 'retail' | 'wholesale';
  points: number;
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
}

export interface ShiftStats {
  cashSales: number;
  cardSales: number;
  returns: number;
  expenses: number;
  invoices: number;
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
  cash_sales_total: number;
  card_sales_total: number;
  cash_returns_total: number;
  cash_expenses_total: number;
  expected_closing_cash: number;
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
  note: string;
  items: { id: number; qty: number }[];
  /** Server id (pos_held_sales.id) used for resume/delete. */
  serverId?: number;
  _id?: number;
}

export interface PastSaleItem {
  id: number;
  qty: number;
  price: number;
  /** Remaining quantity that may still be returned (qty - returned_qty). */
  returnable_qty?: number;
  net_unit_price?: number;
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

export interface StockTransfer {
  id: number;
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
  };
  log: StocktakeScanLog[];
}

export interface CreateSalePayload {
  items: {
    product_variant_id: number;
    qty: number;
    unit_price: number;
    discount_amount?: number;
  }[];
  customer_id: number | null;
  payment_method: 'cash' | 'card' | 'mixed';
  cash_amount: number;
  card_amount: number;
  notes?: string;
  /** When true every line's unit_price is sent and the server accepts the manual price. */
  override_price?: boolean;
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
  currency: string;
}
