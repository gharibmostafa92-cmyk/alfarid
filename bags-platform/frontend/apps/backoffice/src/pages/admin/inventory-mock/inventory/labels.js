// Value → Arabic/English labels. API enums are ALWAYS English on the wire;
// these maps are display-only. Badge classes mirror INV_MOVE_TYPES in the mock
// so the look stays identical.
export const MOVE_TYPE_LABELS = {
  production_receipt: { ar: 'استلام إنتاج', en: 'Production receipt', cls: 'b-orange' },
  purchase_receipt:   { ar: 'استلام مشتريات', en: 'Purchase receipt', cls: 'b-orange' },
  sale:               { ar: 'بيع', en: 'Sale', cls: 'b-gray' },
  sale_return:        { ar: 'مرتجع بيع', en: 'Sale return', cls: 'b-green' },
  adjustment_in:      { ar: 'تسوية زيادة', en: 'Adjustment in', cls: 'b-green' },
  adjustment_out:     { ar: 'تسوية نقصان', en: 'Adjustment out', cls: 'b-red' },
  damage:             { ar: 'تسجيل تالف', en: 'Marked damaged', cls: 'b-amber' },
  damage_release:     { ar: 'فك تالف', en: 'Damage release', cls: 'b-green' },
  damage_write_off:   { ar: 'إعدام تالف', en: 'Damage write-off', cls: 'b-red' },
  transfer_out:       { ar: 'تحويل صادر', en: 'Transfer out', cls: 'b-blue' },
  transfer_in:        { ar: 'تحويل وارد', en: 'Transfer in', cls: 'b-blue' },
  transfer_in_transit:      { ar: 'قيد النقل', en: 'In transit', cls: 'b-blue' },
  transfer_transit_release: { ar: 'فك قيد النقل', en: 'Transit release', cls: 'b-blue' },
  transfer_shortage:  { ar: 'عجز تحويل', en: 'Transfer shortage', cls: 'b-red' },
  stocktake_gain:     { ar: 'زيادة جرد', en: 'Stocktake gain', cls: 'b-green' },
  stocktake_loss:     { ar: 'عجز جرد', en: 'Stocktake loss', cls: 'b-red' },
  reserve:            { ar: 'حجز', en: 'Reserve', cls: 'b-gray' },
  release_reserve:    { ar: 'فك حجز', en: 'Release reserve', cls: 'b-gray' },
};
export const TRANSFER_STATUS = { draft: 'مسودة', approved: 'معتمد', shipped: 'مشحون', received: 'مستلم', cancelled: 'ملغي' };
export const STOCKTAKE_STATUS = { draft: 'مسودة', in_progress: 'قيد العدّ', approved: 'معتمد', cancelled: 'ملغي' };
export const LOCATION_TYPES = { shelf: 'رف', zone: 'منطقة', bin: 'خانة تخزين', damaged: 'تالف', receiving: 'استلام' };
export const BRANCH_TYPES = { branch: 'فرع', warehouse: 'مخزن' };
// Mock adjustment keys → API adjustment_type. Never send mock keys to the API.
export const ADJ_MOCK_TO_API = { adj_in: 'increase', adj_out: 'decrease', dmg_mark: 'mark_damaged', dmg_writeoff: 'write_off_damaged', dmg_restore: 'restore_damaged' };
