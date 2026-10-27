/* ============ Status maps + mock data ============ */
const BRANCHES = ['فرع مدينة نصر', 'فرع المعادي', 'فرع الإسكندرية - سموحة', 'فرع المنصورة', 'المخزن الرئيسي'];
const CITIES = ['القاهرة', 'الجيزة', 'الإسكندرية', 'المنصورة', 'طنطا', 'الزقازيق', 'أسيوط', 'سوهاج', 'الأقصر', 'أسوان', 'بورسعيد', 'الإسماعيلية', 'السويس', 'دمياط', 'المنيا', 'بني سويف', 'الفيوم', 'الغردقة'];
const CHANNELS = ['المتجر الإلكتروني', 'تطبيق الجوال', 'نقطة البيع', 'بوابة الجملة'];
const CASHIERS = ['محمود سعيد', 'منة الله عادل', 'كريم فتحي', 'سارة حمدي', 'يوسف عاطف'];

const ORDER_STATUS = {
  pending: { label: 'قيد المراجعة', tone: 'amber' },
  confirmed: { label: 'مؤكد', tone: 'blue' },
  processing: { label: 'قيد التجهيز', tone: 'violet' },
  shipped: { label: 'تم الشحن', tone: 'orange' },
  delivered: { label: 'تم التسليم', tone: 'green' },
  cancelled: { label: 'ملغي', tone: 'red' },
  returned: { label: 'مرتجع', tone: 'gray' },
};
const ORDER_NEXT = {
  pending: ['confirmed', 'cancelled'],
  confirmed: ['processing', 'shipped', 'cancelled'],
  processing: ['shipped', 'cancelled'],
  shipped: ['delivered', 'returned'],
  delivered: ['returned'],
  cancelled: [], returned: [],
};
const PAY_STATUS = {
  unpaid: { label: 'غير مدفوع', tone: 'amber' },
  paid: { label: 'مدفوع', tone: 'green' },
  failed: { label: 'فشل الدفع', tone: 'red' },
  refunded: { label: 'مسترد', tone: 'gray' },
};
const PAY_METHODS = { cod: 'عند الاستلام', card: 'بطاقة', wallet: 'محفظة', bank: 'تحويل بنكي', credit: 'آجل' };

const RETURN_STATUS = {
  pending: { label: 'قيد المراجعة', tone: 'amber' },
  approved: { label: 'موافق عليه', tone: 'blue' },
  rejected: { label: 'مرفوض', tone: 'red' },
  received: { label: 'مستلم', tone: 'violet' },
  refunded: { label: 'تم رد المبلغ', tone: 'green' },
  cancelled: { label: 'ملغي', tone: 'gray' },
};
const REFUND_METHODS = { original: 'وسيلة الدفع الأصلية', wallet: 'المحفظة', bank: 'تحويل بنكي', cash: 'نقدي' };

const PR_STATUS = {
  draft: { label: 'مسودة', tone: 'gray' },
  pending: { label: 'بانتظار الاعتماد', tone: 'amber' },
  approved: { label: 'معتمد', tone: 'green' },
  rejected: { label: 'مرفوض', tone: 'red' },
  converted_to_po: { label: 'تحول لأمر شراء', tone: 'violet' },
};
const PO_STATUS = {
  draft: { label: 'مسودة', tone: 'gray' },
  sent: { label: 'مرسل للمورد', tone: 'blue' },
  accepted: { label: 'مقبول من المورد', tone: 'green' },
  revision: { label: 'المورد طلب تعديلًا', tone: 'amber' },
  partial: { label: 'مستلم جزئيًا', tone: 'violet' },
  completed: { label: 'مكتمل', tone: 'green' },
  cancelled: { label: 'ملغي', tone: 'red' },
};
const INV_STATUS = {
  draft: { label: 'مسودة', tone: 'gray' },
  posted: { label: 'مُرحّلة', tone: 'blue' },
  partial: { label: 'مدفوعة جزئيًا', tone: 'amber' },
  paid: { label: 'مدفوعة', tone: 'green' },
  cancelled: { label: 'ملغاة', tone: 'red' },
};
const TRANSFER_STATUS = {
  pending: { label: 'بانتظار الاعتماد', tone: 'amber' },
  approved: { label: 'معتمد', tone: 'green' },
  rejected: { label: 'مرفوض', tone: 'red' },
};
const SHIFT_STATUS = {
  open: { label: 'مفتوحة', tone: 'blue' },
  closed: { label: 'مغلقة بانتظار الاعتماد', tone: 'amber' },
  approved: { label: 'معتمدة', tone: 'green' },
};
const QUOTE_STATUS = {
  draft: { label: 'مسودة', tone: 'gray' },
  requested: { label: 'مطلوب تسعيره', tone: 'amber' },
  priced: { label: 'مسعّر من الإدارة', tone: 'blue' },
  accepted: { label: 'مقبول', tone: 'green' },
  rejected: { label: 'مرفوض', tone: 'red' },
  expired: { label: 'منتهي', tone: 'gray' },
  converted: { label: 'تحول لطلب', tone: 'violet' },
};
const NOTICE_STATUS = {
  pending: { label: 'بانتظار المراجعة', tone: 'amber' },
  approved: { label: 'موافق عليه', tone: 'green' },
  rejected: { label: 'مرفوض', tone: 'red' },
};

/* ---------- products ---------- */
const VARIANTS = [
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
const NAMES = ['أحمد عبد الرحمن', 'مريم خالد', 'شركة النور للتجارة', 'محمد إبراهيم', 'زائر', 'نورهان سامي', 'عمر مصطفى', 'هبة الله فوزي', 'سوبر ماركت الأمل', 'إسلام حسن', 'زائر', 'دينا وليد'];
const ORDERS = (() => {
  const sts = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'delivered', 'cancelled', 'returned', 'pending', 'processing', 'shipped', 'confirmed'];
  const pays = ['cod', 'card', 'wallet', 'bank', 'credit', 'cod', 'card', 'card', 'cod', 'wallet', 'cod', 'credit'];
  const pst = ['unpaid', 'paid', 'paid', 'unpaid', 'paid', 'paid', 'refunded', 'paid', 'failed', 'paid', 'unpaid', 'unpaid'];
  return NAMES.map((n, i) => {
    const guest = n === 'زائر';
    return {
      id: 10482 - i * 7, no: 'ORD-' + (10482 - i * 7),
      customer: guest ? (i === 4 ? 'رانيا محمود' : 'طارق سليم') : n, guest,
      ctype: guest ? 'زائر' : (n.includes('شركة') || n.includes('سوبر')) ? 'جملة' : 'قطاعي',
      phone: '01' + ['0', '1', '2', '5'][i % 4] + String(23456781 + i * 1093217).slice(0, 8),
      channel: CHANNELS[i % 4], status: sts[i], pay: pays[i], payStatus: pst[i],
      branch: i === 8 ? null : BRANCHES[i % 5],
      total: [1284.5, 3920, 642, 18750, 455.75, 2310, 980, 1675, 799, 5420, 312.5, 24800][i],
      date: `2026-10-${String(27 - Math.floor(i / 2)).padStart(2, '0')}`, time: ['09:14', '10:42', '11:05', '12:30', '13:18', '14:02', '15:47', '16:20', '17:11', '18:35', '19:08', '20:44'][i],
      followUp: [true, false, false, true, false, false, false, false, true, false, false, true][i],
      needRefund: [false, false, false, false, false, false, true, true, false, false, false, false][i],
      city: CITIES[i % 8],
    };
  });
})();

/* ---------- returns ---------- */
const RETURNS = [
  { id: 1, no: 'RET-2041', order: 'ORD-10433', customer: 'نورهان سامي', branch: 'فرع المعادي', status: 'pending', method: 'original', total: 770, date: '2026-10-27' },
  { id: 2, no: 'RET-2040', order: 'ORD-10426', customer: 'عمر مصطفى', branch: 'فرع مدينة نصر', status: 'approved', method: 'wallet', total: 1240, date: '2026-10-26' },
  { id: 3, no: 'RET-2039', order: 'ORD-10398', customer: 'سوبر ماركت الأمل', branch: 'المخزن الرئيسي', status: 'received', method: 'bank', total: 6480, date: '2026-10-25' },
  { id: 4, no: 'RET-2038', order: 'ORD-10377', customer: 'هبة الله فوزي', branch: 'فرع الإسكندرية - سموحة', status: 'refunded', method: 'cash', total: 385, date: '2026-10-23' },
  { id: 5, no: 'RET-2037', order: 'ORD-10361', customer: 'إسلام حسن', branch: 'فرع المنصورة', status: 'rejected', method: null, total: 0, date: '2026-10-22' },
  { id: 6, no: 'RET-2036', order: 'ORD-10340', customer: 'مريم خالد', branch: null, status: 'cancelled', method: 'original', total: 210, date: '2026-10-20' },
];

/* ---------- suppliers ---------- */
const SUPPLIERS = [
  { id: 1, code: 'SUP-001', name: 'مصطفى الشريف', company: 'الشريف للزيوت والسمن', phone: '01001234567', email: 'orders@sherif-oils.com', terms: 30, balance: 184250, active: true },
  { id: 2, code: 'SUP-002', name: 'هاني عبد الله', company: 'مطاحن الدلتا', phone: '01112233445', email: 'sales@deltamills.eg', terms: 45, balance: 92600.5, active: true },
  { id: 3, code: 'SUP-003', name: 'إيمان رشاد', company: 'النيل للتعبئة', phone: '01223344556', email: null, terms: 15, balance: -4200, active: true },
  { id: 4, code: 'SUP-004', name: 'شريف منير', company: 'بن الحرمين', phone: '01098877665', email: 'info@haramain-coffee.com', terms: 60, balance: 38900, active: true },
  { id: 5, code: 'SUP-005', name: 'وليد قاسم', company: null, phone: '01556677889', email: null, terms: 0, balance: 0, active: false },
  { id: 6, code: 'SUP-006', name: 'رامي جمال', company: 'جمال للأغذية المحفوظة', phone: '01067788990', email: 'rami@gamal-foods.com', terms: 30, balance: 61340, active: true },
];

const TREASURIES = [
  { id: 1, name: 'الخزنة الرئيسية', code: 'TR-MAIN', type: 'main', branch: null, balance: 1284600, active: true },
  { id: 2, name: 'درج كاشير 1 — مدينة نصر', code: 'CD-NC-01', type: 'drawer', branch: 'فرع مدينة نصر', balance: 18420.5, active: true },
  { id: 3, name: 'درج كاشير 2 — مدينة نصر', code: 'CD-NC-02', type: 'drawer', branch: 'فرع مدينة نصر', balance: 9310, active: true },
  { id: 4, name: 'درج كاشير — المعادي', code: 'CD-MA-01', type: 'drawer', branch: 'فرع المعادي', balance: 12780, active: true },
  { id: 5, name: 'البنك الأهلي — جاري', code: 'BK-NBE-01', type: 'bank', branch: null, balance: 3420150.75, active: true },
  { id: 6, name: 'بنك مصر — جاري', code: 'BK-MSR-01', type: 'bank', branch: null, balance: 865300, active: true },
  { id: 7, name: 'درج كاشير — سموحة', code: 'CD-AX-01', type: 'drawer', branch: 'فرع الإسكندرية - سموحة', balance: 0, active: false },
];
const TREASURY_TYPES = { main: 'خزنة رئيسية', drawer: 'درج كاشير', bank: 'حساب بنكي' };

const WHOLESALE = ['شركة النور للتجارة', 'سوبر ماركت الأمل', 'هايبر الفتح', 'مؤسسة الرحمة للتوزيع', 'ماركت الصفا'];

Object.assign(window, {
  BRANCHES, CITIES, CHANNELS, CASHIERS, ORDER_STATUS, ORDER_NEXT, PAY_STATUS, PAY_METHODS, RETURN_STATUS, REFUND_METHODS,
  PR_STATUS, PO_STATUS, INV_STATUS, TRANSFER_STATUS, SHIFT_STATUS, QUOTE_STATUS, NOTICE_STATUS,
  VARIANTS, ORDERS, RETURNS, SUPPLIERS, TREASURIES, TREASURY_TYPES, WHOLESALE,
});
