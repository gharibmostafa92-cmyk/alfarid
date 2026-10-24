// Shared inventory contract — mirrors backend Resources 1:1.
// Numbers arrive as strings with 4 decimals ("10.0000"): always Number() before
// math, and formatQty() before display. See Branch/Location/StockLevel/
// StockMovement/Stocktake/StockTransfer Resources + Transfer/Stocktake services.
export type BranchType = 'branch' | 'warehouse';
export type LocationType = 'shelf' | 'zone' | 'bin' | 'damaged' | 'receiving';
export type TransferStatus = 'draft' | 'approved' | 'shipped' | 'received' | 'cancelled';
export type StocktakeStatus = 'draft' | 'in_progress' | 'approved' | 'cancelled';
export type AdjustmentType = 'increase' | 'decrease' | 'mark_damaged' | 'write_off_damaged' | 'restore_damaged';
export type StockMovementType =
  | 'production_receipt' | 'purchase_receipt' | 'sale' | 'sale_return'
  | 'adjustment_in' | 'adjustment_out' | 'damage' | 'damage_release' | 'damage_write_off'
  | 'transfer_out' | 'transfer_in' | 'transfer_in_transit' | 'transfer_transit_release' | 'transfer_shortage'
  | 'stocktake_gain' | 'stocktake_loss' | 'reserve' | 'release_reserve';

export interface Branch {
  id: number; name: string; code: string; type: BranchType;
  phone: string | null; email: string | null; address: string | null;
  city: string | null; city_id: number | null; is_active: boolean;
  locations_count?: number; employees_count?: number;
  created_at: string; updated_at: string;
}
export interface Location {
  id: number; branch_id: number; name: string; code: string;
  type: LocationType; is_active: boolean; created_at: string; updated_at: string;
}
export interface StockLevel {
  id: number; product_variant_id: number;
  variant?: { id: number; sku: string; barcode: string | null; product_id: number;
              product_name_ar?: string; product_name_en?: string | null };
  branch?: { id: number; name: string };
  location?: { id: number; name: string; code: string } | null;
  on_hand: string; reserved: string; damaged: string; in_transit: string;
  available: string; reorder_level: string; is_low_stock: boolean; updated_at: string;
}
export interface StockMovement {
  id: number; reference_number: string; product_variant_id: number;
  variant?: { id: number; sku: string; product_id: number };
  branch?: { id: number; name: string };
  location?: { id: number; name: string } | null;
  type: StockMovementType; affected_bucket: 'on_hand' | 'reserved' | 'damaged' | 'in_transit';
  quantity: string; balance_before: string; balance_after: string;
  unit_cost?: string; // only with inventory.view_cost — backend omits it otherwise
  reference_type: string | null; reference_id: number | null;
  created_by: number | null; creator_name?: string | null; notes: string | null; created_at: string;
}
export interface StockTransferItem {
  id: number; product_variant_id: number; sku: string | null;
  from_location: { id: number; name: string } | null;
  to_location: { id: number; name: string } | null;
  requested_qty: string; approved_qty: string | null; shipped_qty: string | null; received_qty: string | null;
}
export interface StockTransfer {
  id: number; version: number; transfer_number: string;
  from_branch?: { id: number; name: string }; to_branch?: { id: number; name: string };
  status: TransferStatus; created_by: number; creator_name?: string;
  approved_by: number | null; shipped_at: string | null; received_at: string | null;
  notes: string | null; items?: StockTransferItem[]; created_at: string; updated_at: string;
}
export interface StocktakeItem {
  id: number; product_variant_id: number; sku: string | null;
  system_qty?: string; counted_qty: string | null; difference?: string | null;
}
export interface Stocktake {
  id: number; stocktake_number: string;
  branch?: { id: number; name: string }; location?: { id: number; name: string } | null;
  status: StocktakeStatus; items_count?: number; created_by: number; creator_name?: string;
  approved_by: number | null; approved_at: string | null; notes: string | null;
  items?: StocktakeItem[]; created_at: string; updated_at: string;
}
export interface CursorPaginated<T> {
  data: T[];
  meta: { path: string; per_page: number; next_cursor: string | null; prev_cursor: string | null };
  links: { first: null; last: null; prev: string | null; next: string | null };
}

// ProductionReceipt store/show returns the MODEL itself, not a Resource:
// { id, batch_number, branch_id, location_id, created_by, notes, items[], created_at }.
export interface ProductionReceiptItem {
  product_variant_id: number; quantity: number; unit_cost?: number | string;
}
export interface ProductionReceipt {
  id: number; batch_number: string; branch_id: number; location_id: number | null;
  created_by: number; notes: string | null; items: ProductionReceiptItem[];
  created_at: string; updated_at?: string;
}
export interface ProductionOptions {
  branches: { id: number; name: string; locations: { id: number; name: string }[] }[];
  variants: { id: number; label: string }[];
}
// Raw Laravel paginator (NOT under meta): { data, current_page, last_page, ... }.
export interface RawPaginated<T> {
  data: T[]; current_page: number; last_page: number; per_page: number; total: number;
}
