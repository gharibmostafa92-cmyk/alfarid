/* Mock data shaped after the wholesale + store API responses. */
import React from 'react';

export const fmt = (n, d = 2) => Number(n).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
export const Money = ({ v, strong, cls = '' }) => (
  <span className={`num ${cls}`} style={strong ? { fontWeight: 600, color: 'var(--ink)' } : null}>
    {fmt(v)} <small style={{ fontSize: '0.78em', color: 'var(--soft)', fontWeight: 500 }}>EGP</small>
  </span>
);
export const D = (s) => <span className="num">{s}</span>;

export const ACCOUNT = {
  name: 'محمود عبد الرحمن', phone: '+20 100 482 1190', email: 'm.abdelrahman@nourtrading.com',
  company: 'شركة النور للتجارة والتوزيع', tax_number: '512-884-306', commercial_register: '148392 — القاهرة',
  status: 'approved', approved_at: '2026-03-14', rejection_reason: null,
  credit_limit: 250000, used_credit: 142650, available_credit: 107350, in_progress_orders_count: 4,
  wallet: 3840,
};

export const APP_STATUS = {
  pending:   { label: 'قيد المراجعة', v: 'warning', icon: 'fa-hourglass-half' },
  approved:  { label: 'معتمد', v: 'success', icon: 'fa-circle-check' },
  rejected:  { label: 'مرفوض', v: 'danger', icon: 'fa-circle-xmark' },
  suspended: { label: 'موقوف', v: 'default', icon: 'fa-circle-pause' },
};
export const DOCS = [
  { name: 'commercial-register-2026.pdf', type: 'السجل التجاري', status: 'approved' },
  { name: 'tax-card-front.jpg', type: 'البطاقة الضريبية', status: 'rejected', note: 'الصورة غير واضحة، والرقم الضريبي غير مقروء.' },
  { name: 'national-id-owner.pdf', type: 'بطاقة الرقم القومي للمالك', status: 'pending' },
  { name: 'shop-photo.png', type: 'صورة واجهة المحل', status: 'approved' },
];
export const DOC_STATUS = { approved: ['مقبول', 'success'], rejected: ['مرفوض', 'danger'], pending: ['قيد المراجعة', 'warning'] };

export const ORDER_STATUS = {
  pending: ['قيد المراجعة', 'warning'], confirmed: ['مؤكد', 'info'], processing: ['جاري التجهيز', 'info'],
  shipped: ['تم الشحن', 'accent'], delivered: ['تم التسليم', 'success'], cancelled: ['ملغي', 'default'], returned: ['مرتجع', 'danger'],
};
export const PAY_METHOD = { credit: 'آجل', cod: 'الدفع عند الاستلام', bank_transfer: 'تحويل بنكي', wallet: 'المحفظة' };
export const PAY_STATUS = { paid: ['مدفوع', 'success'], unpaid: ['غير مدفوع', 'danger'], partial: ['مدفوع جزئيًا', 'warning'], pending: ['في انتظار التحصيل', 'default'] };
export const QUOTE_STATUS = {
  draft: ['مسودة', 'default'], requested: ['مطلوب تسعيره', 'warning'], priced: ['تم تسعيره من الإدارة', 'info'],
  accepted: ['مقبول', 'success'], rejected: ['مرفوض', 'danger'], expired: ['منتهي', 'default'], converted: ['محوّل لطلب', 'accent'],
};
export const NOTICE_STATUS = { pending: ['قيد المراجعة', 'warning'], approved: ['معتمد', 'success'], rejected: ['مرفوض', 'danger'] };
export const RETURN_STATUS = {
  requested: ['تم تقديم الطلب', 'warning'], approved: ['تمت الموافقة', 'info'], received: ['تم استلام المرتجع', 'accent'],
  refunded: ['تم رد المبلغ', 'success'], rejected: ['مرفوض', 'danger'],
};
export const NOTICE_METHOD = { bank_transfer: 'تحويل بنكي', cheque: 'شيك', deposit: 'إيداع' };

export const PRODUCTS = [
  { id: 1, name_ar: 'حقيبة توت كلاسيك جلد طبيعي', name_en: 'Classic Leather Tote', base: 1450, variants: [
    { id: 101, label: 'أسود', sku: 'SPH-TT-001-BLK', barcode: '6224001001011', price: 1080, qty: 340, tiers: [[24, 1040], [60, 995]] },
    { id: 102, label: 'كاميل', sku: 'SPH-TT-001-CML', barcode: '6224001001028', price: 1080, qty: 96, tiers: [[24, 1040], [60, 995]] },
    { id: 103, label: 'كريمي', sku: 'SPH-TT-001-CRM', barcode: '6224001001035', price: 1110, qty: 0, tiers: [[24, 1065]] } ] },
  { id: 2, name_ar: 'حقيبة كروس صغيرة', name_en: 'Mini Crossbody', base: 780, variants: [
    { id: 201, label: 'وردي', sku: 'SPH-CB-014-PNK', barcode: '6224001014011', price: 560, qty: 512, tiers: [[36, 535], [120, 510]] },
    { id: 202, label: 'أسود', sku: 'SPH-CB-014-BLK', barcode: '6224001014028', price: 560, qty: 288, tiers: [[36, 535], [120, 510]] } ] },
  { id: 3, name_ar: 'شنطة ظهر حضرية', name_en: 'Urban Backpack', base: 1290, variants: [
    { id: 301, label: 'كحلي / كبير', sku: 'SPH-BP-007-NVY-L', barcode: '6224001007015', price: 925, qty: 150, tiers: [[20, 895], [50, 860]] },
    { id: 302, label: 'رمادي / متوسط', sku: 'SPH-BP-007-GRY-M', barcode: '6224001007022', price: 880, qty: 18, tiers: [[20, 850]] } ] },
  { id: 4, name_ar: 'كلاتش سهرة مطرز', name_en: 'Embroidered Evening Clutch', base: 640, variants: [
    { id: 401, label: 'ذهبي', sku: 'SPH-CL-022-GLD', barcode: '6224001022010', price: 455, qty: 220, tiers: [[48, 430]] } ] },
  { id: 5, name_ar: 'حقيبة لابتوب بزنس', name_en: 'Business Laptop Bag', base: 1680, variants: [
    { id: 501, label: 'أسود / 15 بوصة', sku: 'SPH-LB-031-BLK-15', barcode: '6224001031012', price: 1235, qty: 74, tiers: [[12, 1199], [40, 1150]] },
    { id: 502, label: 'بني / 14 بوصة', sku: 'SPH-LB-031-BRN-14', barcode: '6224001031029', price: 1210, qty: 61, tiers: [[12, 1175]] } ] },
  { id: 6, name_ar: 'محفظة يد نسائي', name_en: 'Zip Around Wallet', base: 390, variants: [
    { id: 601, label: 'نبيتي', sku: 'SPH-WL-040-BRG', barcode: '6224001040014', price: 270, qty: 900, tiers: [[60, 255], [200, 240]] },
    { id: 602, label: 'بيج', sku: 'SPH-WL-040-BGE', barcode: '6224001040021', price: 270, qty: 640, tiers: [[60, 255], [200, 240]] } ] },
  { id: 7, name_ar: 'حقيبة سفر ويك إند', name_en: 'Weekender Duffel', base: 2150, variants: [
    { id: 701, label: 'زيتي', sku: 'SPH-DF-009-OLV', barcode: '6224001009019', price: 1590, qty: 42, tiers: [[10, 1540]] } ] },
  { id: 8, name_ar: 'شنطة كتف هوبو', name_en: 'Soft Hobo Shoulder', base: 990, variants: [
    { id: 801, label: 'تركوازي', sku: 'SPH-HB-018-TRQ', barcode: '6224001018011', price: 720, qty: 130, tiers: [[24, 690]] },
    { id: 802, label: 'أسود', sku: 'SPH-HB-018-BLK', barcode: '6224001018028', price: 720, qty: 205, tiers: [[24, 690]] } ] },
];
export const ALL_VARIANTS = PRODUCTS.flatMap((p) => p.variants.map((v) => ({ ...v, product: p })));
export const findVariant = (code) => ALL_VARIANTS.find((v) => v.sku.toLowerCase() === String(code).trim().toLowerCase() || v.barcode === String(code).trim());

export const ORDERS = [
  { id: 9, number: 'WS-260418', date: '2026-10-26', status: 'processing', method: 'credit', pay: 'unpaid', subtotal: 48600, discount: 2430, shipping: 350, cod_fee: 0, tax: 0, total: 46520, cancellable: true, returnable: false,
    recipient: 'محمود عبد الرحمن', phone: '+20 100 482 1190', city: 'القاهرة', address: '14 ش التحرير، الدقي، الجيزة — المخزن الرئيسي',
    items: [{ name: 'حقيبة توت كلاسيك جلد طبيعي', sku: 'SPH-TT-001-BLK', qty: 30, unit: 1040, discount: 1200 }, { name: 'محفظة يد نسائي', sku: 'SPH-WL-040-BRG', qty: 60, unit: 255, discount: 1230 }],
    track: [['pending', '2026-10-26 10:42', true], ['confirmed', '2026-10-26 13:05', true], ['processing', '2026-10-27 09:20', 'now'], ['shipped'], ['delivered']] },
  { id: 8, number: 'WS-260377', date: '2026-10-21', status: 'shipped', method: 'credit', pay: 'unpaid', subtotal: 22400, discount: 0, shipping: 250, cod_fee: 0, tax: 0, total: 22650, cancellable: false, returnable: false,
    recipient: 'محمود عبد الرحمن', phone: '+20 100 482 1190', city: 'الإسكندرية', address: '8 ش فؤاد، محطة الرمل — فرع الإسكندرية',
    items: [{ name: 'حقيبة كروس صغيرة', sku: 'SPH-CB-014-PNK', qty: 40, unit: 560, discount: 0 }],
    track: [['pending', '2026-10-21 11:10', true], ['confirmed', '2026-10-21 15:40', true], ['processing', '2026-10-22 08:00', true], ['shipped', '2026-10-23 17:25', 'now'], ['delivered']] },
  { id: 7, number: 'WS-260301', date: '2026-10-12', status: 'delivered', method: 'bank_transfer', pay: 'paid', subtotal: 36975, discount: 1100, shipping: 0, cod_fee: 0, tax: 0, total: 35875, cancellable: false, returnable: true,
    recipient: 'أحمد سمير', phone: '+20 112 300 7741', city: 'القاهرة', address: '14 ش التحرير، الدقي، الجيزة — المخزن الرئيسي',
    items: [{ name: 'شنطة ظهر حضرية', sku: 'SPH-BP-007-NVY-L', qty: 25, unit: 895, discount: 600 }, { name: 'كلاتش سهرة مطرز', sku: 'SPH-CL-022-GLD', qty: 30, unit: 455, discount: 500 }],
    track: [['pending', '2026-10-12', true], ['confirmed', '2026-10-12', true], ['processing', '2026-10-13', true], ['shipped', '2026-10-14', true], ['delivered', '2026-10-16 12:30', 'now']] },
  { id: 6, number: 'WS-260244', date: '2026-10-03', status: 'delivered', method: 'cod', pay: 'paid', subtotal: 14820, discount: 0, shipping: 200, cod_fee: 150, tax: 0, total: 15170, cancellable: false, returnable: true,
    recipient: 'محمود عبد الرحمن', phone: '+20 100 482 1190', city: 'القاهرة', address: '14 ش التحرير، الدقي، الجيزة — المخزن الرئيسي',
    items: [{ name: 'حقيبة لابتوب بزنس', sku: 'SPH-LB-031-BLK-15', qty: 12, unit: 1235, discount: 0 }],
    track: [['pending', '2026-10-03', true], ['delivered', '2026-10-06', 'now']] },
  { id: 5, number: 'WS-260190', date: '2026-09-25', status: 'pending', method: 'credit', pay: 'unpaid', subtotal: 8640, discount: 0, shipping: 200, cod_fee: 0, tax: 0, total: 8840, cancellable: true, returnable: false,
    recipient: 'محمود عبد الرحمن', phone: '+20 100 482 1190', city: 'المنصورة', address: '3 ش الجمهورية، المنصورة',
    items: [{ name: 'شنطة كتف هوبو', sku: 'SPH-HB-018-TRQ', qty: 12, unit: 720, discount: 0 }],
    track: [['pending', '2026-09-25 18:02', 'now'], ['confirmed'], ['processing'], ['shipped'], ['delivered']] },
  { id: 4, number: 'WS-260112', date: '2026-09-11', status: 'cancelled', method: 'credit', pay: 'unpaid', subtotal: 15900, discount: 0, shipping: 0, cod_fee: 0, tax: 0, total: 15900, cancellable: false, returnable: false,
    recipient: 'محمود عبد الرحمن', phone: '+20 100 482 1190', city: 'القاهرة', address: '14 ش التحرير، الدقي، الجيزة',
    items: [{ name: 'حقيبة سفر ويك إند', sku: 'SPH-DF-009-OLV', qty: 10, unit: 1590, discount: 0 }],
    track: [['pending', '2026-09-11', true], ['cancelled', '2026-09-12 10:00', 'now']] },
];

export const QUOTES = [
  { id: 31, number: 'QT-2026-0031', created: '2026-10-25', valid_until: '2026-11-08', status: 'priced', subtotal: 118500, discount: 5925, tax: 0, total: 112575,
    trader_note: 'طلبية لموسم الشتاء، نحتاج التسليم قبل منتصف نوفمبر.', admin_note: 'تم تطبيق خصم كمية إضافي 5% على إجمالي العرض. الأسعار سارية حتى تاريخ الصلاحية.',
    items: [{ name: 'حقيبة توت كلاسيك جلد طبيعي', sku: 'SPH-TT-001-BLK', qty: 80, target: 950, offered: 980 }, { name: 'شنطة ظهر حضرية', sku: 'SPH-BP-007-NVY-L', qty: 50, target: 820, offered: 835 }] },
  { id: 30, number: 'QT-2026-0030', created: '2026-10-22', valid_until: null, status: 'requested', subtotal: 0, discount: 0, tax: 0, total: null,
    trader_note: 'نطلب أفضل سعر لكمية 200 قطعة.', admin_note: null,
    items: [{ name: 'محفظة يد نسائي', sku: 'SPH-WL-040-BGE', qty: 200, target: 225, offered: null }] },
  { id: 29, number: 'QT-2026-0029', created: '2026-10-20', valid_until: null, status: 'draft', subtotal: 0, discount: 0, tax: 0, total: null, trader_note: '', admin_note: null,
    items: [{ name: 'كلاتش سهرة مطرز', sku: 'SPH-CL-022-GLD', qty: 60, target: 410, offered: null }] },
  { id: 27, number: 'QT-2026-0027', created: '2026-10-01', valid_until: '2026-10-15', status: 'converted', subtotal: 37800, discount: 0, tax: 0, total: 37800, converted_order_id: 'WS-260301',
    trader_note: '', admin_note: 'مقبول.', items: [{ name: 'شنطة ظهر حضرية', sku: 'SPH-BP-007-NVY-L', qty: 25, target: 870, offered: 895 }] },
  { id: 25, number: 'QT-2026-0025', created: '2026-09-14', valid_until: '2026-09-28', status: 'expired', subtotal: 21600, discount: 0, tax: 0, total: 21600, trader_note: '', admin_note: null,
    items: [{ name: 'حقيبة كروس صغيرة', sku: 'SPH-CB-014-BLK', qty: 40, target: 500, offered: 540 }] },
  { id: 22, number: 'QT-2026-0022', created: '2026-08-30', valid_until: '2026-09-13', status: 'rejected', subtotal: 15400, discount: 0, tax: 0, total: 15400, trader_note: '', admin_note: null,
    items: [{ name: 'حقيبة سفر ويك إند', sku: 'SPH-DF-009-OLV', qty: 10, target: 1350, offered: 1540 }] },
];

export const STATEMENT = {
  opening: 96420, closing: 142650,
  rows: [
    { date: '2026-10-01', ref: '—', desc: 'رصيد أول المدة', debit: 0, credit: 0, bal: 96420, opening: true },
    { date: '2026-10-03', ref: 'WS-260244', desc: 'فاتورة مبيعات', debit: 15170, credit: 0, bal: 111590 },
    { date: '2026-10-06', ref: 'PN-0412', desc: 'تحصيل عند الاستلام', debit: 0, credit: 15170, bal: 96420 },
    { date: '2026-10-12', ref: 'WS-260301', desc: 'فاتورة مبيعات', debit: 35875, credit: 0, bal: 132295 },
    { date: '2026-10-15', ref: 'PN-0418', desc: 'إشعار سداد — تحويل بنكي (معتمد)', debit: 0, credit: 35875, bal: 96420 },
    { date: '2026-10-18', ref: 'RT-0091', desc: 'إشعار دائن — مرتجع', debit: 0, credit: 2790, bal: 93630 },
    { date: '2026-10-21', ref: 'WS-260377', desc: 'فاتورة مبيعات (آجل)', debit: 22650, credit: 0, bal: 116280 },
    { date: '2026-10-22', ref: 'PN-0425', desc: 'إشعار سداد — شيك (معتمد)', debit: 0, credit: 20150, bal: 96130 },
    { date: '2026-10-26', ref: 'WS-260418', desc: 'فاتورة مبيعات (آجل)', debit: 46520, credit: 0, bal: 142650 },
  ],
};

export const NOTICES = [
  { id: 431, number: 'PN-0431', order: 'WS-260377', amount: 22650, method: 'bank_transfer', date: '2026-10-27', status: 'pending', created: '2026-10-27', bank: 'البنك الأهلي المصري', ref: 'NBE-77820193', admin_note: null, receipt: 'transfer-receipt-27oct.pdf' },
  { id: 425, number: 'PN-0425', order: null, amount: 20150, method: 'cheque', date: '2026-10-22', status: 'approved', created: '2026-10-22', bank: 'بنك مصر', ref: 'CHQ-004418', admin_note: 'تم تحصيل الشيك وإضافته لحسابكم.', receipt: 'cheque-004418.jpg' },
  { id: 418, number: 'PN-0418', order: 'WS-260301', amount: 35875, method: 'bank_transfer', date: '2026-10-14', status: 'approved', created: '2026-10-14', bank: 'CIB', ref: 'CIB-2210-5531', admin_note: null, receipt: 'cib-transfer.png' },
  { id: 409, number: 'PN-0409', order: null, amount: 12000, method: 'deposit', date: '2026-09-30', status: 'rejected', created: '2026-09-30', bank: 'بنك القاهرة', ref: 'BDC-99120', admin_note: 'رقم المرجع لا يطابق كشف البنك. برجاء إرسال إشعار جديد بالإيصال الصحيح.', receipt: 'deposit-slip.jpg' },
];

export const RETURNS = [
  { id: 91, number: 'RT-0091', order: 'WS-260244', status: 'refunded', refund: 2790, method: 'wallet', ref: 'WAL-TRX-55102', date: '2026-10-09',
    reason: 'عيوب تصنيع في بعض القطع', approved_at: '2026-10-11', received_at: '2026-10-15', refunded_at: '2026-10-18',
    items: [{ name: 'حقيبة لابتوب بزنس', sku: 'SPH-LB-031-BLK-15', qty: 2, reason: 'قطع في السحّاب', photos: 3 }] },
  { id: 95, number: 'RT-0095', order: 'WS-260301', status: 'approved', refund: 1820, method: 'credit_note', ref: null, date: '2026-10-24',
    reason: 'لون مختلف عن المطلوب', approved_at: '2026-10-25', received_at: null, refunded_at: null,
    items: [{ name: 'كلاتش سهرة مطرز', sku: 'SPH-CL-022-GLD', qty: 4, reason: 'اللون فضي بدل ذهبي', photos: 2 }] },
];
export const REFUND_METHOD = { wallet: 'رصيد المحفظة', credit_note: 'إشعار دائن على الحساب', original: 'نفس طريقة الدفع' };

export const WALLET_TX = [
  { type: 'refund', label: 'استرداد مرتجع', amount: 2790, after: 3840, date: '2026-10-18 14:20', ref: 'RT-0091' },
  { type: 'debit', label: 'خصم على طلب', amount: -1950, after: 1050, date: '2026-09-02 11:05', ref: 'WS-259870' },
  { type: 'credit', label: 'إضافة من الإدارة', amount: 1500, after: 3000, date: '2026-08-20 09:30', ref: 'ADJ-0021' },
  { type: 'refund', label: 'استرداد طلب ملغي', amount: 1500, after: 1500, date: '2026-07-14 16:45', ref: 'WS-259402' },
];

export const NOTIFS = [
  { id: 1, icon: 'fa-file-signature', title: 'تم تسعير عرض السعر QT-2026-0031', body: 'الإدارة أرسلت أسعار العرض. العرض صالح حتى 8 نوفمبر 2026.', time: 'منذ ساعتين', unread: true, link: ['quote', 31] },
  { id: 2, icon: 'fa-truck-fast', title: 'طلبك WS-260377 في الطريق', body: 'تم شحن الطلب لفرع الإسكندرية.', time: 'منذ 5 أيام', unread: true, link: ['order', 8] },
  { id: 3, icon: 'fa-money-check', title: 'تم اعتماد إشعار السداد PN-0425', body: 'تم إضافة 20,150.00 EGP لحسابكم.', time: '22 أكتوبر', unread: true, link: ['notice', 425] },
  { id: 4, icon: 'fa-rotate-left', title: 'تمت الموافقة على المرتجع RT-0095', body: 'برجاء تسليم القطع لمندوب الشحن خلال 3 أيام عمل.', time: '25 أكتوبر', unread: false, link: ['return', 95] },
  { id: 5, icon: 'fa-bell', title: 'تحديث على قائمة الأسعار', body: 'تم تحديث شرائح الكميات لعدد من المنتجات.', time: '18 أكتوبر', unread: false, link: null },
  { id: 6, icon: 'fa-circle-xmark', title: 'تم رفض إشعار السداد PN-0409', body: 'رقم المرجع لا يطابق كشف البنك.', time: '1 أكتوبر', unread: false, link: ['notice', 409] },
];

export const ADDRESSES = [
  { id: 1, label: 'المخزن الرئيسي', city: 'القاهرة', address: '14 ش التحرير، الدقي، الجيزة', default: true },
  { id: 2, label: 'فرع الإسكندرية', city: 'الإسكندرية', address: '8 ش فؤاد، محطة الرمل', default: false },
  { id: 3, label: 'فرع المنصورة', city: 'المنصورة', address: '3 ش الجمهورية', default: false },
];






