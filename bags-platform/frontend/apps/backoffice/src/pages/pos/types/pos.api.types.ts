/**
 * Raw server shapes, captured from the real POS endpoints
 * (see .superpowers/sdd/pos-api-integration/task-{1,2,3}-report.md).
 * Money fields arrive as strings ("150.00") except catalog/lookup `unit_price`,
 * which are numbers.
 */

export interface ApiShift {
  id: number;
  shift_number: string;
  status: 'open' | 'closed_pending_approval' | 'approved';
  opened_at: string;
  closed_at: string | null;
  approved_at: string | null;
  opening_cash: string;
  cash_sales_total: string;
  card_sales_total: string;
  cash_returns_total: string;
  cash_expenses_total: string;
  expected_closing_cash: string;
  actual_closing_cash: string | null;
  cash_difference: string | null;
  invoices_count?: number;
  notes: string | null;
  cashier?: { id: number; name: string };
  treasury: { id: number; name: string; code: string };
  branch?: { id: number; name: string };
}

/** GET shifts/current */
export interface ApiShiftCurrent {
  data: ApiShift | null;
  has_open_shift: boolean;
}

/** POST shifts/open → 201 { message, data: ApiShift } */
export interface ApiShiftOpen {
  message: string;
  data: ApiShift;
}

export interface ApiLookupProduct {
  id: number;
  sku: string;
  barcode: string | null;
  product_name: string;
  variant_name: string | null;
  on_hand: number;
  available: number;
  unit_price: number;
  price_source?: string;
  price_list_id?: number | null;
}

export interface ApiCatalogItem {
  id: number;
  sku: string;
  barcode: string | null;
  name: string;
  product_name?: string;
  variant_name?: string | null;
  category_id: string | null;
  unit_price: number;
  wholesale_price: number | null;
  available: number;
  on_hand?: number;
  reorder_point: number;
  image_url: string | null;
}

export interface ApiCatalogResponse {
  data: ApiCatalogItem[];
  meta: { catalog_version: string; next_cursor: string | null };
}

export interface ApiDrawer {
  id: number;
  name: string;
  code: string;
  busy: boolean;
  balance: string;
}

export interface ApiBootstrap {
  user: {
    id: number;
    name: string;
    branch_id: number | null;
    branch: { id: number; name: string } | null;
    must_change_password?: boolean;
    roles: { id: number; name: string }[];
    permissions: string[];
  };
  branch: { id: number; name: string; code: string };
  shift: ApiShift | null;
  drawers: ApiDrawer[];
  categories: { id: string; name: string }[];
  settings: { max_discount_percent: number; currency: string };
  catalog_version: string;
  held_count: number;
}

export interface ApiQuoteLine {
  product_variant_id: number;
  qty: number;
  unit_price: string;
  discount_amount: string;
  line_total: string;
  available: number;
  error: string | null;
}

export interface ApiQuote {
  lines: ApiQuoteLine[];
  subtotal: string;
  discount_total: string;
  grand_total: string;
}

export interface ApiHeldSale {
  id: number;
  hold_reference: string;
  created_at?: string;
  customer_id?: number | null;
  customer?: { id: number; name: string } | null;
  notes: string | null;
  items_payload: { product_variant_id: number; qty: number; discount_amount?: string | number }[];
}

export interface ApiSaleLookup {
  id: number;
  order_number: string;
  created_at: string;
  payment_method: string;
  cash_amount: string;
  card_amount: string;
  grand_total?: string;
  customer: { id?: number; name: string } | null;
  cashier: { id: number; name: string } | null;
  items: {
    id: number;
    product_variant_id: number;
    qty: number;
    returned_qty: number;
    returnable_qty: number;
    unit_price: string;
    discount_amount?: string;
    net_unit_price: string;
  }[];
}

export interface ApiCustomer {
  id: number;
  name: string;
  phone: string;
  type: 'retail' | 'wholesale';
  loyalty_points?: number;
  points?: number;
}

export interface ApiTransfer {
  id: number;
  transfer_number: string;
  from_branch?: { id: number; name: string } | null;
  status: string;
  shipped_at: string | null;
  notes: string | null;
  items: { id: number; product_variant_id: number; shipped_qty?: string | number | null; requested_qty?: string | number | null }[];
}

export interface ApiTransferSource {
  id: number;
  name: string;
  code?: string;
  type: 'warehouse' | 'branch' | string;
}

export interface ApiStocktake {
  id: number;
  stocktake_number: string;
  status: 'draft' | 'in_progress' | string;
  items: { id: number; product_variant_id: number; sku: string; barcode: string | null; counted_qty: number | null; system_qty?: number }[];
}
