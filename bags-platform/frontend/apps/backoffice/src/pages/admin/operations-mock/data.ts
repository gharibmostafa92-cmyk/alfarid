/* ============ Status maps + mock data (Vortix Admin) ============ */

export const BRANCHES = [
  'فرع مدينة نصر',
  'فرع المعادي',
  'فرع الإسكندرية - سموحة',
  'فرع المنصورة',
  'المخزن الرئيسي',
];

export const CITIES = [
  'القاهرة',
  'الجيزة',
  'الإسكندرية',
  'المنصورة',
  'طنطا',
  'الزقازيق',
  'أسيوط',
  'سوهاج',
  'الأقصر',
  'أسوان',
  'بورسعيد',
  'الإسماعيلية',
  'السويس',
  'دمياط',
  'المنيا',
  'بني سويف',
  'الفيوم',
  'الغردقة',
];

export const CHANNELS = ['المتجر الإلكتروني', 'تطبيق الجوال', 'نقطة البيع', 'بوابة الجملة'];
export const CASHIERS = ['محمود سعيد', 'منة الله عادل', 'كريم فتحي', 'سارة حمدي', 'يوسف عاطف'];

export interface StatusMeta {
  label: string;
  tone: 'gray' | 'amber' | 'blue' | 'violet' | 'orange' | 'green' | 'red';
}

export const ORDER_STATUS: Record<string, StatusMeta> = {
  pending: { label: 'قيد المراجعة', tone: 'amber' },
  confirmed: { label: 'مؤكد', tone: 'blue' },
  processing: { label: 'قيد التجهيز', tone: 'violet' },
  shipped: { label: 'تم الشحن', tone: 'orange' },
  delivered: { label: 'تم التسليم', tone: 'green' },
  cancelled: { label: 'ملغي', tone: 'red' },
  returned: { label: 'مرتجع', tone: 'gray' },
};

export const ORDER_NEXT: Record<string, string[]> = {
  pending: ['confirmed', 'cancelled'],
  confirmed: ['processing', 'shipped', 'cancelled'],
  processing: ['shipped', 'cancelled'],
  shipped: ['delivered', 'returned'],
  delivered: ['returned'],
  cancelled: [],
  returned: [],
};

export const PAY_STATUS: Record<string, StatusMeta> = {
  unpaid: { label: 'غير مدفوع', tone: 'amber' },
  paid: { label: 'مدفوع', tone: 'green' },
  failed: { label: 'فشل الدفع', tone: 'red' },
  refunded: { label: 'مسترد', tone: 'gray' },
};

export const PAY_METHODS: Record<string, string> = {
  cod: 'عند الاستلام',
  card: 'بطاقة',
  wallet: 'محفظة',
  bank: 'تحويل بنكي',
  credit: 'آجل',
};

export const RETURN_STATUS: Record<string, StatusMeta> = {
  pending: { label: 'قيد المراجعة', tone: 'amber' },
  approved: { label: 'موافق عليه', tone: 'blue' },
  rejected: { label: 'مرفوض', tone: 'red' },
  received: { label: 'مستلم', tone: 'violet' },
  refunded: { label: 'تم رد المبلغ', tone: 'green' },
  cancelled: { label: 'ملغي', tone: 'gray' },
};

export const REFUND_METHODS: Record<string, string> = {
  original: 'وسيلة الدفع الأصلية',
  wallet: 'المحفظة',
  bank: 'تحويل بنكي',
  cash: 'نقدي',
};

export const PR_STATUS: Record<string, StatusMeta> = {
  draft: { label: 'مسودة', tone: 'gray' },
  pending: { label: 'بانتظار الاعتماد', tone: 'amber' },
  approved: { label: 'معتمد', tone: 'green' },
  rejected: { label: 'مرفوض', tone: 'red' },
  converted_to_po: { label: 'تحول لأمر شراء', tone: 'violet' },
};

export const PO_STATUS: Record<string, StatusMeta> = {
  draft: { label: 'مسودة', tone: 'gray' },
  sent: { label: 'مرسل للمورد', tone: 'blue' },
  accepted: { label: 'مقبول من المورد', tone: 'green' },
  revision: { label: 'المورد طلب تعديلًا', tone: 'amber' },
  partial: { label: 'مستلم جزئيًا', tone: 'violet' },
  completed: { label: 'مكتمل', tone: 'green' },
  cancelled: { label: 'ملغي', tone: 'red' },
};

export const INV_STATUS: Record<string, StatusMeta> = {
  draft: { label: 'مسودة', tone: 'gray' },
  posted: { label: 'مُرحّلة', tone: 'blue' },
  partial: { label: 'مدفوعة جزئيًا', tone: 'amber' },
  paid: { label: 'مدفوعة', tone: 'green' },
  cancelled: { label: 'ملغاة', tone: 'red' },
};

export const TRANSFER_STATUS: Record<string, StatusMeta> = {
  pending: { label: 'بانتظار الاعتماد', tone: 'amber' },
  approved: { label: 'معتمد', tone: 'green' },
  rejected: { label: 'مرفوض', tone: 'red' },
};

export const SHIFT_STATUS: Record<string, StatusMeta> = {
  open: { label: 'مفتوحة', tone: 'blue' },
  closed: { label: 'مغلقة بانتظار الاعتماد', tone: 'amber' },
  approved: { label: 'معتمدة', tone: 'green' },
};

export const QUOTE_STATUS: Record<string, StatusMeta> = {
  draft: { label: 'مسودة', tone: 'gray' },
  requested: { label: 'مطلوب تسعيره', tone: 'amber' },
  priced: { label: 'مسعّر من الإدارة', tone: 'blue' },
  accepted: { label: 'مقبول', tone: 'green' },
  rejected: { label: 'مرفوض', tone: 'red' },
  expired: { label: 'منتهي', tone: 'gray' },
  converted: { label: 'تحول لطلب', tone: 'violet' },
};

export const NOTICE_STATUS: Record<string, StatusMeta> = {
  pending: { label: 'بانتظار المراجعة', tone: 'amber' },
  approved: { label: 'موافق عليه', tone: 'green' },
  rejected: { label: 'مرفوض', tone: 'red' },
};

/* ---------- products / variants ---------- */
export interface ProductVariant {
  id: number;
  name: string;
  sku: string;
  price: number;
  cost: number;
}

export const VARIANTS: ProductVariant[] = [
  { id: 1, name: 'زيت زيتون بكر ممتاز 1 لتر', sku: 'OIL-EV-1000', price: 385, cost: 290 },
  { id: 2, name: 'أرز مصري فاخر 5 كجم', sku: 'RCE-EG-5000', price: 210, cost: 168 },
  { id: 3, name: 'سكر أبيض ناعم 1 كجم', sku: 'SGR-WH-1000', price: 38.5, cost: 31 },
  { id: 4, name: 'شاي أسود ناعم 250 جم', sku: 'TEA-BL-250', price: 96, cost: 72 },
  { id: 5, name: 'مكرونة بيني 400 جم', sku: 'PST-PN-400', price: 22, cost: 15.5 },
  { id: 6, name: 'سمن نباتي 2 كجم', sku: 'GHE-VG-2000', price: 268, cost: 214 },
  { id: 7, name: 'عسل نحل طبيعي 500 جم', sku: 'HNY-NT-500', price: 240, cost: 175 },
  { id: 8, name: 'تونة قطع فاتح 185 جم', sku: 'TNA-LT-185', price: 64, cost: 49 },
  { id: 9, name: 'بن محوّج 200 جم', sku: 'COF-SP-200', price: 145, cost: 104 },
  { id: 10, name: 'دقيق فاخر 1 كجم', sku: 'FLR-PR-1000', price: 32, cost: 25 },
];

/* ---------- orders ---------- */
export const NAMES = [
  'أحمد عبد الرحمن',
  'مريم خالد',
  'شركة النور للتجارة',
  'محمد إبراهيم',
  'زائر',
  'نورهان سامي',
  'عمر مصطفى',
  'هبة الله فوزي',
  'سوبر ماركت الأمل',
  'إسلام حسن',
  'زائر',
  'دينا وليد',
];

export interface OrderRow {
  id: number;
  no: string;
  customer: string;
  guest: boolean;
  ctype: string;
  phone: string;
  channel: string;
  status: string;
  pay: string;
  payStatus: string;
  branch: string | null;
  total: number;
  date: string;
  time: string;
  followUp: boolean;
  needRefund: boolean;
  city: string;
}

export const ORDERS: OrderRow[] = (() => {
  const sts = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'delivered', 'cancelled', 'returned', 'pending', 'processing', 'shipped', 'confirmed'];
  const pays = ['cod', 'card', 'wallet', 'bank', 'credit', 'cod', 'card', 'card', 'cod', 'wallet', 'cod', 'credit'];
  const pst = ['unpaid', 'paid', 'paid', 'unpaid', 'paid', 'paid', 'refunded', 'paid', 'failed', 'paid', 'unpaid', 'unpaid'];
  return NAMES.map((n, i) => {
    const guest = n === 'زائر';
    return {
      id: 10482 - i * 7,
      no: 'ORD-' + (10482 - i * 7),
      customer: guest ? (i === 4 ? 'رانيا محمود' : 'طارق سليم') : n,
      guest,
      ctype: guest ? 'زائر' : (n.includes('شركة') || n.includes('سوبر')) ? 'جملة' : 'قطاعي',
      phone: '01' + ['0', '1', '2', '5'][i % 4] + String(23456781 + i * 1093217).slice(0, 8),
      channel: CHANNELS[i % 4],
      status: sts[i],
      pay: pays[i],
      payStatus: pst[i],
      branch: i === 8 ? null : BRANCHES[i % 5],
      total: [1284.5, 3920, 642, 18750, 455.75, 2310, 980, 1675, 799, 5420, 312.5, 24800][i],
      date: `2026-10-${String(27 - Math.floor(i / 2)).padStart(2, '0')}`,
      time: ['09:14', '10:42', '11:05', '12:30', '13:18', '14:02', '15:47', '16:20', '17:11', '18:35', '19:08', '20:44'][i],
      followUp: [true, false, false, true, false, false, false, false, true, false, false, true][i],
      needRefund: [false, false, false, false, false, false, true, true, false, false, false, false][i],
      city: CITIES[i % 8],
    };
  });
})();

/* ---------- returns ---------- */
export interface ReturnRow {
  id: number;
  no: string;
  order: string;
  customer: string;
  branch: string | null;
  status: string;
  method: string | null;
  total: number;
  date: string;
}

export const RETURNS: ReturnRow[] = [
  { id: 1, no: 'RET-2041', order: 'ORD-10433', customer: 'نورهان سامي', branch: 'فرع المعادي', status: 'pending', method: 'original', total: 770, date: '2026-10-27' },
  { id: 2, no: 'RET-2040', order: 'ORD-10426', customer: 'عمر مصطفى', branch: 'فرع مدينة نصر', status: 'approved', method: 'wallet', total: 1240, date: '2026-10-26' },
  { id: 3, no: 'RET-2039', order: 'ORD-10398', customer: 'سوبر ماركت الأمل', branch: 'المخزن الرئيسي', status: 'received', method: 'bank', total: 6480, date: '2026-10-25' },
  { id: 4, no: 'RET-2038', order: 'ORD-10377', customer: 'هبة الله فوزي', branch: 'فرع الإسكندرية - سموحة', status: 'refunded', method: 'cash', total: 385, date: '2026-10-23' },
  { id: 5, no: 'RET-2037', order: 'ORD-10361', customer: 'إسلام حسن', branch: 'فرع المنصورة', status: 'rejected', method: null, total: 0, date: '2026-10-22' },
  { id: 6, no: 'RET-2036', order: 'ORD-10340', customer: 'مريم خالد', branch: null, status: 'cancelled', method: 'original', total: 210, date: '2026-10-20' },
];

/* ---------- suppliers ---------- */
export interface SupplierRow {
  id: number;
  code: string;
  name: string;
  company: string | null;
  phone: string;
  email: string | null;
  terms: number;
  balance: number;
  active: boolean;
}

export const SUPPLIERS: SupplierRow[] = [
  { id: 1, code: 'SUP-001', name: 'مصطفى الشريف', company: 'الشريف للزيوت والسمن', phone: '01001234567', email: 'orders@sherif-oils.com', terms: 30, balance: 184250, active: true },
  { id: 2, code: 'SUP-002', name: 'هاني عبد الله', company: 'مطاحن الدلتا', phone: '01112233445', email: 'sales@deltamills.eg', terms: 45, balance: 92600.5, active: true },
  { id: 3, code: 'SUP-003', name: 'إيمان رشاد', company: 'النيل للتعبئة', phone: '01223344556', email: null, terms: 15, balance: -4200, active: true },
  { id: 4, code: 'SUP-004', name: 'شريف منير', company: 'بن الحرمين', phone: '01098877665', email: 'info@haramain-coffee.com', terms: 60, balance: 38900, active: true },
  { id: 5, code: 'SUP-005', name: 'وليد قاسم', company: null, phone: '01556677889', email: null, terms: 0, balance: 0, active: false },
  { id: 6, code: 'SUP-006', name: 'رامي جمال', company: 'جمال للأغذية المحفوظة', phone: '01067788990', email: 'rami@gamal-foods.com', terms: 30, balance: 61340, active: true },
];

export interface TreasuryRow {
  id: number;
  name: string;
  code: string;
  type: 'main' | 'drawer' | 'bank';
  branch: string | null;
  balance: number;
  active: boolean;
}

export const TREASURIES: TreasuryRow[] = [
  { id: 1, name: 'الخزنة الرئيسية', code: 'TR-MAIN', type: 'main', branch: null, balance: 1284600, active: true },
  { id: 2, name: 'درج كاشير 1 — مدينة نصر', code: 'CD-NC-01', type: 'drawer', branch: 'فرع مدينة نصر', balance: 18420.5, active: true },
  { id: 3, name: 'درج كاشير 2 — مدينة نصر', code: 'CD-NC-02', type: 'drawer', branch: 'فرع مدينة نصر', balance: 9310, active: true },
  { id: 4, name: 'درج كاشير — المعادي', code: 'CD-MA-01', type: 'drawer', branch: 'فرع المعادي', balance: 12780, active: true },
  { id: 5, name: 'البنك الأهلي — جاري', code: 'BK-NBE-01', type: 'bank', branch: null, balance: 3420150.75, active: true },
  { id: 6, name: 'بنك مصر — جاري', code: 'BK-MSR-01', type: 'bank', branch: null, balance: 865300, active: true },
  { id: 7, name: 'درج كاشير — سموحة', code: 'CD-AX-01', type: 'drawer', branch: 'فرع الإسكندرية - سموحة', balance: 0, active: false },
];

export const TREASURY_TYPES: Record<string, string> = {
  main: 'خزنة رئيسية',
  drawer: 'درج كاشير',
  bank: 'حساب بنكي',
};

export const WHOLESALE = [
  'شركة النور للتجارة',
  'سوبر ماركت الأمل',
  'هايبر الفتح',
  'مؤسسة الرحمة للتوزيع',
  'ماركت الصفا',
];

/* ---------- Shipping, Pricing, Promos & Brands ---------- */
export interface ShipCompany {
  id: number;
  name: string;
  code: string;
  url: string;
  active: boolean;
  api: boolean;
}

export const SHIP_COMPANIES: ShipCompany[] = [
  { id: 1, name: 'بوسطة', code: 'BOSTA', url: 'https://bosta.co/tracking-shipments?shipment-number={awb}', active: true, api: true },
  { id: 2, name: 'أرامكس', code: 'ARAMEX', url: 'https://www.aramex.com/track/results?ShipmentNumber={awb}', active: true, api: true },
  { id: 3, name: 'مرسول إكسبريس', code: 'MRSOOL', url: 'https://track.mrsool.eg/{awb}', active: true, api: false },
  { id: 4, name: 'مندوب داخلي', code: 'INHOUSE', url: '', active: true, api: false },
  { id: 5, name: 'J&T Express', code: 'JNT', url: 'https://www.jtexpress.eg/track?awb={awb}', active: false, api: false },
];

export interface ShipZone {
  id: number;
  name: string;
  cities: string[];
  active: boolean;
}

export const SHIP_ZONES: ShipZone[] = [
  { id: 1, name: 'القاهرة الكبرى', cities: ['القاهرة', 'الجيزة'], active: true },
  { id: 2, name: 'الإسكندرية والساحل', cities: ['الإسكندرية'], active: true },
  { id: 3, name: 'الدلتا', cities: ['المنصورة', 'طنطا', 'الزقازيق', 'دمياط'], active: true },
  { id: 4, name: 'القناة', cities: ['بورسعيد', 'الإسماعيلية', 'السويس'], active: true },
  { id: 5, name: 'الصعيد', cities: ['أسيوط', 'سوهاج', 'الأقصر', 'أسوان', 'المنيا', 'بني سويف', 'الفيوم'], active: true },
  { id: 6, name: 'البحر الأحمر', cities: ['الغردقة'], active: false },
];

export interface ShipRate {
  id: number;
  co: string;
  zone: number;
  base: number;
  free: number | null;
  cod: number;
  min: number;
  max: number;
}

export const SHIP_RATES: ShipRate[] = [
  { id: 1, co: 'BOSTA', zone: 1, base: 45, free: 1500, cod: 10, min: 1, max: 2 },
  { id: 2, co: 'BOSTA', zone: 3, base: 60, free: 2000, cod: 12, min: 2, max: 3 },
  { id: 3, co: 'ARAMEX', zone: 2, base: 65, free: null, cod: 15, min: 2, max: 4 },
  { id: 4, co: 'ARAMEX', zone: 5, base: 85, free: 0, cod: 15, min: 3, max: 6 },
  { id: 5, co: 'INHOUSE', zone: 1, base: 30, free: 800, cod: 0, min: 0, max: 1 },
  { id: 6, co: 'MRSOOL', zone: 4, base: 70, free: null, cod: 10, min: 2, max: 4 },
];

export interface CustomerGroup {
  id: number;
  name: string;
  code: string;
  desc: string | null;
  disc: number;
  active: boolean;
}

export const CUSTOMER_GROUPS: CustomerGroup[] = [
  { id: 1, name: 'عملاء VIP', code: 'VIP', desc: 'عملاء التجزئة الأعلى إنفاقًا', disc: 7.5, active: true },
  { id: 2, name: 'تجار الجملة — فئة أ', code: 'WHL-A', desc: 'حجم شراء شهري يتجاوز 250 ألف', disc: 12, active: true },
  { id: 3, name: 'تجار الجملة — فئة ب', code: 'WHL-B', desc: null, disc: 8, active: true },
  { id: 4, name: 'الموظفون', code: 'STAFF', desc: 'خصم موظفي الشركة', disc: 15, active: true },
  { id: 5, name: 'المطاعم والكافيهات', code: 'HORECA', desc: 'قطاع الضيافة', disc: 10, active: false },
];

export interface PriceListRow {
  id: number;
  name: string;
  type: string;
  target: string | null;
  branch: string | null;
  from: string;
  to: string | null;
  prio: number;
  active: boolean;
  items: number;
}

export const PRICE_LISTS: PriceListRow[] = [
  { id: 1, name: 'قائمة القطاعي الأساسية', type: 'retail', target: null, branch: null, from: '2026-01-01', to: null, prio: 0, active: true, items: 1240 },
  { id: 2, name: 'أسعار الجملة 2026', type: 'wholesale', target: null, branch: null, from: '2026-01-01', to: '2026-12-31', prio: 100, active: true, items: 860 },
  { id: 3, name: 'موزعي الدلتا', type: 'distributor', target: null, branch: 'فرع المنصورة', from: '2026-07-01', to: null, prio: 150, active: true, items: 412 },
  { id: 4, name: 'خصومات VIP', type: 'group', target: 'عملاء VIP', branch: null, from: '2026-10-01', to: '2026-12-31', prio: 200, active: true, items: 95 },
  { id: 5, name: 'تعاقد سوبر ماركت الأمل', type: 'customer', target: 'سوبر ماركت الأمل', branch: null, from: '2026-09-15', to: '2027-03-15', prio: 500, active: true, items: 64 },
  { id: 6, name: 'عروض فرع سموحة', type: 'retail', target: null, branch: 'فرع الإسكندرية - سموحة', from: '2026-06-01', to: '2026-08-31', prio: 50, active: false, items: 38 },
];

export interface PromoRow {
  id: number;
  name: string;
  type: 'percent' | 'amount' | 'bxgy' | 'free_ship';
  value: number | null;
  min: number | null;
  scope: 'all' | 'categories' | 'brands' | 'variants';
  targets: string[];
  from: string;
  to: string | null;
  active: boolean;
}

export const PROMOS: PromoRow[] = [
  { id: 1, name: 'تخفيضات نوفمبر', type: 'percent', value: 15, min: 500, scope: 'categories', targets: ['البقالة', 'المعلبات'], from: '2026-11-01', to: '2026-11-30', active: true },
  { id: 2, name: 'خصم 50 جنيه على الزيوت', type: 'amount', value: 50, min: 400, scope: 'brands', targets: ['الشريف'], from: '2026-10-15', to: '2026-11-15', active: true },
  { id: 3, name: 'شحن مجاني للطلبات الكبيرة', type: 'free_ship', value: null, min: 1200, scope: 'all', targets: [], from: '2026-10-01', to: null, active: true },
  { id: 4, name: 'اشترِ 2 شاي واحصل على 1', type: 'bxgy', value: null, min: null, scope: 'variants', targets: ['TEA-BL-250'], from: '2026-09-01', to: '2026-09-30', active: false },
];

export interface CouponRow {
  id: number;
  code: string;
  type: 'percent' | 'amount' | 'free_ship';
  value: number | null;
  maxDisc: number | null;
  min: number;
  total: number | null;
  perCust: number | null;
  used: number;
  from: string;
  to: string | null;
  active: boolean;
}

export const COUPONS: CouponRow[] = [
  { id: 1, code: 'WELCOME50', type: 'amount', value: 50, maxDisc: null, min: 300, total: 5000, perCust: 1, used: 1842, from: '2026-01-01', to: null, active: true },
  { id: 2, code: 'RAMADAN20', type: 'percent', value: 20, maxDisc: 150, min: 500, total: 2000, perCust: 2, used: 2000, from: '2026-02-18', to: '2026-03-20', active: false },
  { id: 3, code: 'FREESHIP', type: 'free_ship', value: null, maxDisc: null, min: 600, total: null, perCust: 3, used: 412, from: '2026-10-01', to: '2026-12-31', active: true },
  { id: 4, code: 'VIP10', type: 'percent', value: 10, maxDisc: 300, min: 0, total: null, perCust: null, used: 96, from: '2026-10-10', to: null, active: true },
];

export interface BrandRow {
  id: number;
  ar: string;
  en: string;
  slug: string | null;
  sort: number;
  active: boolean;
  logo: string | null;
  products: number;
}

export const BRANDS: BrandRow[] = [
  { id: 1, ar: 'الشريف', en: 'El Sherif', slug: 'el-sherif', sort: 1, active: true, logo: '#e0491f', products: 48 },
  { id: 2, ar: 'الضحى', en: 'Al Doha', slug: 'al-doha', sort: 2, active: true, logo: '#18151c', products: 31 },
  { id: 3, ar: 'العروسة', en: 'El Arousa', slug: 'el-arousa', sort: 3, active: true, logo: '#0ea5a4', products: 22 },
  { id: 4, ar: 'بن الحرمين', en: 'Haramain Coffee', slug: 'haramain-coffee', sort: 4, active: true, logo: '#92400e', products: 14 },
  { id: 5, ar: 'جهينة', en: 'Juhayna', slug: 'juhayna', sort: 5, active: true, logo: '#2f5bd3', products: 57 },
  { id: 6, ar: 'ريف مصر', en: 'Reef Masr', slug: null, sort: 6, active: false, logo: null, products: 0 },
];

/* ---------- Purchasing Data ---------- */
export interface PurchaseRequest {
  id: number;
  no: string;
  branch: string;
  status: string;
  by: string;
  date: string;
  items: number;
}

export const PRS: PurchaseRequest[] = [
  { id: 1, no: 'PR-0318', branch: 'فرع مدينة نصر', status: 'pending', by: 'كريم فتحي', date: '2026-10-27', items: 6 },
  { id: 2, no: 'PR-0317', branch: 'المخزن الرئيسي', status: 'draft', by: 'أحمد ناصر', date: '2026-10-26', items: 14 },
  { id: 3, no: 'PR-0316', branch: 'فرع المعادي', status: 'approved', by: 'سارة حمدي', date: '2026-10-25', items: 4 },
  { id: 4, no: 'PR-0315', branch: 'فرع المنصورة', status: 'converted_to_po', by: 'يوسف عاطف', date: '2026-10-22', items: 9 },
  { id: 5, no: 'PR-0314', branch: 'فرع الإسكندرية - سموحة', status: 'rejected', by: 'منة الله عادل', date: '2026-10-20', items: 3 },
];

export interface PurchaseOrder {
  id: number;
  no: string;
  sup: number;
  branch: string;
  status: string;
  eta: string | null;
  total: number;
}

export const POS: PurchaseOrder[] = [
  { id: 1, no: 'PO-1207', sup: 1, branch: 'المخزن الرئيسي', status: 'sent', eta: '2026-11-02', total: 148500 },
  { id: 2, no: 'PO-1206', sup: 2, branch: 'فرع مدينة نصر', status: 'revision', eta: '2026-10-30', total: 62400 },
  { id: 3, no: 'PO-1205', sup: 4, branch: 'المخزن الرئيسي', status: 'partial', eta: '2026-10-28', total: 38900 },
  { id: 4, no: 'PO-1204', sup: 6, branch: 'فرع المعادي', status: 'accepted', eta: '2026-10-29', total: 27350 },
  { id: 5, no: 'PO-1203', sup: 1, branch: 'فرع المنصورة', status: 'completed', eta: '2026-10-20', total: 91200 },
  { id: 6, no: 'PO-1202', sup: 3, branch: 'المخزن الرئيسي', status: 'draft', eta: null, total: 14800 },
  { id: 7, no: 'PO-1201', sup: 2, branch: 'فرع المعادي', status: 'cancelled', eta: '2026-10-15', total: 22000 },
];

export interface SupplierInvoice {
  id: number;
  sys: string;
  supNo: string;
  sup: number;
  po: string | null;
  issue: string;
  due: string | null;
  subtotal: number;
  tax: number;
  paid: number;
  status: string;
  file: boolean;
}

export const INVOICES: SupplierInvoice[] = [
  { id: 1, sys: 'SINV-0892', supNo: 'SH-2026-4471', sup: 1, po: 'PO-1203', issue: '2026-10-21', due: '2026-11-20', subtotal: 80000, tax: 11200, paid: 50000, status: 'partial', file: true },
  { id: 2, sys: 'SINV-0891', supNo: 'DM/10/882', sup: 2, po: null, issue: '2026-10-18', due: '2026-12-02', subtotal: 54000, tax: 7560, paid: 0, status: 'posted', file: true },
  { id: 3, sys: 'SINV-0890', supNo: 'HC-3310', sup: 4, po: 'PO-1205', issue: '2026-10-28', due: null, subtotal: 18500, tax: 2590, paid: 0, status: 'draft', file: false },
  { id: 4, sys: 'SINV-0889', supNo: 'GF-77120', sup: 6, po: null, issue: '2026-09-30', due: '2026-10-30', subtotal: 24000, tax: 3360, paid: 27360, status: 'paid', file: true },
  { id: 5, sys: 'SINV-0888', supNo: 'NP-0091', sup: 3, po: null, issue: '2026-09-12', due: '2026-09-27', subtotal: 6200, tax: 0, paid: 0, status: 'cancelled', file: false },
];

export interface SupplierPayment {
  id: number;
  no: string;
  sup: number;
  inv: string | null;
  amount: number;
  method: string;
  ref: string | null;
  date: string;
  notes: string | null;
  void: { reason: string; by: string; at: string } | null;
}

export const SPAYMENTS: SupplierPayment[] = [
  { id: 1, no: 'SPAY-0441', sup: 1, inv: 'SINV-0892', amount: 50000, method: 'bank', ref: 'NBE-TRX-882140', date: '2026-10-24', notes: 'دفعة أولى', void: null },
  { id: 2, no: 'SPAY-0440', sup: 6, inv: 'SINV-0889', amount: 27360, method: 'cheque', ref: 'CHQ-004127', date: '2026-10-22', notes: null, void: null },
  { id: 3, no: 'SPAY-0439', sup: 2, inv: null, amount: 15000, method: 'cash', ref: null, date: '2026-10-19', notes: 'دفعة تحت الحساب', void: null },
  { id: 4, no: 'SPAY-0438', sup: 4, inv: null, amount: 8000, method: 'bank', ref: 'MSR-55120', date: '2026-10-11', notes: null, void: { reason: 'تحويل مكرر', by: 'أحمد ناصر', at: '2026-10-12 10:14' } },
];

export const SP_METHODS: Record<string, string> = { cash: 'نقدي', bank: 'تحويل بنكي', cheque: 'شيك' };
export const supName = (id: number): string => SUPPLIERS.find(s => s.id === id)?.company || SUPPLIERS.find(s => s.id === id)?.name || '';

/* ---------- Finance, Shifts, Wholesale ---------- */
export interface ExpenseRow {
  id: number;
  no: string;
  branch: string;
  tr: number;
  cat: string;
  amount: number;
  date: string;
  by: string;
  shift: string | null;
  notes: string | null;
}

export const EXPENSES: ExpenseRow[] = [
  { id: 1, no: 'EXP-2214', branch: 'فرع مدينة نصر', tr: 2, cat: 'نظافة', amount: 350, date: '2026-10-27', by: 'محمود سعيد', shift: 'SH-5521', notes: 'أدوات تنظيف' },
  { id: 2, no: 'EXP-2213', branch: 'المخزن الرئيسي', tr: 1, cat: 'صيانة', amount: 4800, date: '2026-10-26', by: 'أحمد ناصر', shift: null, notes: 'صيانة ثلاجة العرض' },
  { id: 3, no: 'EXP-2212', branch: 'فرع المعادي', tr: 4, cat: 'نقل وتحميل', amount: 600, date: '2026-10-26', by: 'كريم فتحي', shift: 'SH-5518', notes: null },
  { id: 4, no: 'EXP-2211', branch: 'المخزن الرئيسي', tr: 5, cat: 'إيجار', amount: 45000, date: '2026-10-25', by: 'أحمد ناصر', shift: null, notes: 'إيجار نوفمبر' },
  { id: 5, no: 'EXP-2210', branch: 'فرع مدينة نصر', tr: 3, cat: 'ضيافة', amount: 180, date: '2026-10-24', by: 'سارة حمدي', shift: 'SH-5512', notes: null },
];

export interface TreasuryTransfer {
  id: number;
  from: number;
  to: number;
  amount: number;
  status: string;
  by: string;
  appr: string | null;
  date: string;
  notes: string | null;
}

export const TRANSFERS: TreasuryTransfer[] = [
  { id: 1, from: 2, to: 1, amount: 15000, status: 'pending', by: 'محمود سعيد', appr: null, date: '2026-10-27', notes: 'توريد نقدية نهاية اليوم' },
  { id: 2, from: 1, to: 5, amount: 250000, status: 'pending', by: 'أحمد ناصر', appr: null, date: '2026-10-27', notes: 'إيداع بنكي' },
  { id: 3, from: 4, to: 1, amount: 10500, status: 'approved', by: 'كريم فتحي', appr: 'أحمد ناصر', date: '2026-10-26', notes: null },
  { id: 4, from: 5, to: 6, amount: 120000, status: 'approved', by: 'أحمد ناصر', appr: 'هشام رضا', date: '2026-10-24', notes: null },
  { id: 5, from: 3, to: 1, amount: 8000, status: 'rejected', by: 'سارة حمدي', appr: 'أحمد ناصر', date: '2026-10-23', notes: 'المبلغ غير مطابق للعد' },
];

export interface ShiftRow {
  id: number;
  no: string;
  cashier: string;
  branch: string;
  tr: number;
  open: string;
  close: string | null;
  status: string;
  exp: number | null;
  act: number | null;
}

export const SHIFTS: ShiftRow[] = [
  { id: 1, no: 'SH-5524', cashier: 'محمود سعيد', branch: 'فرع مدينة نصر', tr: 2, open: '2026-10-27 08:00', close: null, status: 'open', exp: null, act: null },
  { id: 2, no: 'SH-5521', cashier: 'منة الله عادل', branch: 'فرع مدينة نصر', tr: 3, open: '2026-10-26 16:00', close: '2026-10-27 00:10', status: 'closed', exp: 21840, act: 21640 },
  { id: 3, no: 'SH-5518', cashier: 'كريم فتحي', branch: 'فرع المعادي', tr: 4, open: '2026-10-26 08:00', close: '2026-10-26 16:05', status: 'closed', exp: 14260.5, act: 14310.5 },
  { id: 4, no: 'SH-5515', cashier: 'سارة حمدي', branch: 'فرع مدينة نصر', tr: 2, open: '2026-10-25 16:00', close: '2026-10-26 00:02', status: 'approved', exp: 18900, act: 18900 },
  { id: 5, no: 'SH-5512', cashier: 'يوسف عاطف', branch: 'فرع المنصورة', tr: 2, open: '2026-10-25 08:00', close: '2026-10-25 16:00', status: 'approved', exp: 9420, act: 9300 },
];

export const trName = (id: number): string => TREASURIES.find(t => t.id === id)?.name || '';

export interface QuoteRow {
  id: number;
  no: string;
  cust: string;
  company: string;
  status: string;
  total: number | null;
  valid: string | null;
  date: string;
  order?: string;
}

export const QUOTES: QuoteRow[] = [
  { id: 1, no: 'QT-0612', cust: 'أحمد حمدي', company: 'شركة النور للتجارة', status: 'requested', total: null, valid: null, date: '2026-10-27' },
  { id: 2, no: 'QT-0611', cust: 'سامح نبيل', company: 'ماركت الصفا', status: 'priced', total: 64200, valid: '2026-11-05', date: '2026-10-26' },
  { id: 3, no: 'QT-0610', cust: 'عادل رمضان', company: 'هايبر الفتح', status: 'accepted', total: 118400, valid: '2026-10-31', date: '2026-10-22', order: 'ORD-10452' },
  { id: 4, no: 'QT-0609', cust: 'ياسر فهمي', company: 'مؤسسة الرحمة للتوزيع', status: 'converted', total: 24800, valid: '2026-10-28', date: '2026-10-18', order: 'ORD-10475' },
  { id: 5, no: 'QT-0608', cust: 'محمد الصاوي', company: 'سوبر ماركت الأمل', status: 'expired', total: 42000, valid: '2026-10-20', date: '2026-10-10' },
];

export interface PaymentNoticeRow {
  id: number;
  no: string;
  cust: string;
  company: string;
  order: string | null;
  amount: number;
  method: string;
  bank: string;
  ref: string;
  date: string;
  file: boolean;
  status: string;
  tr?: number;
  review?: string;
}

export const NOTICES: PaymentNoticeRow[] = [
  { id: 1, no: 'PN-0284', cust: 'محمد الصاوي', company: 'شركة النور للتجارة', order: 'ORD-10475', amount: 24800, method: 'bank', bank: 'البنك الأهلي المصري', ref: 'FT26300118842', date: '2026-10-27', file: true, status: 'pending' },
  { id: 2, no: 'PN-0283', cust: 'ياسر فهمي', company: 'مؤسسة الرحمة للتوزيع', order: null, amount: 100000, method: 'cheque', bank: 'بنك مصر', ref: 'CHQ-883104', date: '2026-10-26', file: true, status: 'pending' },
  { id: 3, no: 'PN-0282', cust: 'عادل رمضان', company: 'هايبر الفتح', order: 'ORD-10452', amount: 71400, method: 'deposit', bank: 'CIB', ref: 'DEP-55021', date: '2026-10-24', file: false, status: 'approved', tr: 5, review: 'تمت المطابقة مع كشف الحساب' },
  { id: 4, no: 'PN-0281', cust: 'سامح نبيل', company: 'ماركت الصفا', order: null, amount: 9000, method: 'bank', bank: 'QNB', ref: 'QNB-1192', date: '2026-10-21', file: true, status: 'rejected', review: 'المبلغ غير ظاهر في كشف الحساب' },
];

export const NOTICE_METHODS: Record<string, string> = { bank: 'تحويل بنكي', cheque: 'شيك', deposit: 'إيداع' };

/* ---------- Notifications ---------- */
export interface NotificationItem {
  id: string;
  type: string;
  data: any;
  read_at: string | null;
  created_at: string;
}

export const NOTIFS: NotificationItem[] = [
  { id: 'n1', type: 'order.created', data: { order_no: 'ORD-10482', total: 1284.5, channel: 'المتجر الإلكتروني', target: { m: 'orders', id: 10482 } }, read_at: null, created_at: 'منذ 4 دقائق' },
  { id: 'n2', type: 'return.requested', data: { return_no: 'RET-2041', order_no: 'ORD-10433', target: { m: 'returns', id: 1 } }, read_at: null, created_at: 'منذ 22 دقيقة' },
  { id: 'n3', type: 'payment_notice.submitted', data: { notice_no: 'PN-0284', company: 'شركة النور للتجارة', amount: 24800, target: { m: 'wholesale', sub: 'notices' } }, read_at: null, created_at: 'منذ ساعة' },
  { id: 'n4', type: 'shift.closed', data: { shift_no: 'SH-5521', cashier: 'منة الله عادل', difference: -200, target: { m: 'shifts' } }, read_at: null, created_at: 'منذ 9 ساعات' },
  { id: 'n5', type: 'purchase_order.revision_requested', data: { po_no: 'PO-1206', supplier: 'مطاحن الدلتا', target: { m: 'purchasing', sub: 'po' } }, read_at: '2026-10-26', created_at: 'أمس 18:40' },
  { id: 'n6', type: 'quotation.requested', data: { quote_no: 'QT-0611', company: 'شركة النور للتجارة', target: { m: 'wholesale', sub: 'quotes' } }, read_at: '2026-10-26', created_at: 'أمس 14:12' },
  { id: 'n7', type: 'stock.low', data: { sku: 'OIL-EV-1000', branch: 'فرع المعادي', qty: 4 }, read_at: '2026-10-25', created_at: '25 أكتوبر' },
  { id: 'n8', type: 'system.message', data: { message: 'تمت جدولة صيانة الخادم يوم الجمعة 2:00 ص' }, read_at: '2026-10-24', created_at: '24 أكتوبر' },
];
