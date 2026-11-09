// POS mock data + shared POS helpers
Object.assign(IC, {
  cash: <><rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9.5v5M18 9.5v5"/></>,
  card: <><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6.5 15h4"/></>,
  split: <><path d="M12 3v18"/><rect x="3" y="6" width="18" height="12" rx="2.5"/></>,
  pause: <><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></>,
  inbox: <><path d="M3 13l3-8h12l3 8v6a1 1 0 01-1 1H4a1 1 0 01-1-1z"/><path d="M3 13h5l1.5 2.5h5L16 13h5"/></>,
  wifi: <><path d="M2 8.5a15 15 0 0120 0M5 12a10 10 0 0114 0M8.5 15.5a5 5 0 017 0"/><circle cx="12" cy="19" r="1"/></>,
  userPlus: <><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5M19 8v6M16 11h6"/></>,
  ret: <><path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 010 10h-3"/></>,
  bag: <><path d="M5 8h14l-1 12H6z"/><path d="M9 8V6a3 3 0 016 0v2"/></>,
  keyboard: <><rect x="2.5" y="6" width="19" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 14h10"/></>,
  sparkle: <path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/>,
});

const BRANCH = { id: 3, name: 'فرع مدينة نصر', code: 'NSR' };
const CASHIER = { id: 21, name: 'أحمد سمير', initials: 'أس', role: 'كاشير أول', perms: ['pos.override_price', 'pos.sales.refund', 'transfers.create', 'transfers.receive'] };
const TREASURIES = [
  { id: 1, name: 'درج كاشير ١', code: 'DRW-01', state: 'free', last: 'آخر إغلاق أمس ١١:٤٠ م' },
  { id: 2, name: 'درج كاشير ٢', code: 'DRW-02', state: 'free', last: 'آخر إغلاق أمس ١٠:١٥ م' },
  { id: 4, name: 'درج الصالة', code: 'DRW-04', state: 'busy', last: 'مفتوح مع منى عادل' },
];

const CATS = [
  { id: 'all', name: 'الكل' }, { id: 'hand', name: 'شنط يد' }, { id: 'cross', name: 'كروس' }, { id: 'back', name: 'باك باك' },
  { id: 'wallet', name: 'محافظ' }, { id: 'travel', name: 'سفر' }, { id: 'acc', name: 'إكسسوارات' },
];
// h = hue for placeholder art
const PRODUCTS = [
  { id: 101, sku: 'HB-2041-BLK', barcode: '6221031204115', name: 'شنطة يد جلد كلاسيك — أسود', cat: 'hand', price: 450, wholesale: 390, stock: 14, reorder: 6, h: 30 },
  { id: 102, sku: 'HB-2041-CML', barcode: '6221031204122', name: 'شنطة يد جلد كلاسيك — جملي', cat: 'hand', price: 450, wholesale: 390, stock: 4, reorder: 6, h: 60 },
  { id: 103, sku: 'CB-1180-RED', barcode: '6221031118009', name: 'كروس صغيرة بسلسلة — أحمر', cat: 'cross', price: 285, wholesale: 240, stock: 22, reorder: 8, h: 18 },
  { id: 104, sku: 'CB-1180-BEG', barcode: '6221031118016', name: 'كروس صغيرة بسلسلة — بيج', cat: 'cross', price: 285, wholesale: 240, stock: 9, reorder: 8, h: 75 },
  { id: 105, sku: 'BP-3302-NVY', barcode: '6221031330241', name: 'باك باك لابتوب ١٥ بوصة — كحلي', cat: 'back', price: 620, wholesale: 540, stock: 7, reorder: 4, h: 255 },
  { id: 106, sku: 'BP-3305-GRY', barcode: '6221031330555', name: 'باك باك مدرسي مقاوم للمياه', cat: 'back', price: 380, wholesale: 320, stock: 0, reorder: 5, h: 220 },
  { id: 107, sku: 'WL-0712-BLK', barcode: '6221031071205', name: 'محفظة رجالي جلد طبيعي', cat: 'wallet', price: 195, wholesale: 160, stock: 41, reorder: 12, h: 40 },
  { id: 108, sku: 'WL-0820-PNK', barcode: '6221031082003', name: 'محفظة حريمي بسوستة — وردي', cat: 'wallet', price: 230, wholesale: 190, stock: 3, reorder: 10, h: 350 },
  { id: 109, sku: 'TR-5510-BLK', barcode: '6221031551004', name: 'شنطة سفر كابينة ٢٠ بوصة', cat: 'travel', price: 1450, wholesale: 1280, stock: 5, reorder: 3, h: 280 },
  { id: 110, sku: 'TR-5520-OLV', barcode: '6221031552001', name: 'حقيبة ويك إند قماش — زيتي', cat: 'travel', price: 760, wholesale: 660, stock: 11, reorder: 4, h: 110 },
  { id: 111, sku: 'AC-0091-GLD', barcode: '6221031009109', name: 'ميدالية مفاتيح معدن ذهبي', cat: 'acc', price: 65, wholesale: 50, stock: 88, reorder: 20, h: 85 },
  { id: 112, sku: 'AC-0105-STR', barcode: '6221031010501', name: 'حزام كتف بديل مطرز', cat: 'acc', price: 120, wholesale: 95, stock: 2, reorder: 10, h: 160 },
  { id: 113, sku: 'HB-2077-WHT', barcode: '6221031207703', name: 'توت باج كبيرة — أوف وايت', cat: 'hand', price: 540, wholesale: 470, stock: 16, reorder: 6, h: 95 },
  { id: 114, sku: 'CB-1215-BRN', barcode: '6221031121504', name: 'كروس رجالي جلد — بني', cat: 'cross', price: 340, wholesale: 290, stock: 6, reorder: 6, h: 45 },
  { id: 115, sku: 'WL-0901-BLU', barcode: '6221031090107', name: 'حافظة كروت RFID — أزرق', cat: 'wallet', price: 145, wholesale: 120, stock: 27, reorder: 10, h: 230 },
];
const CUSTOMERS = [
  { id: 15, name: 'مها فتحي', phone: '01012345678', tier: 'wholesale', points: 1240 },
  { id: 22, name: 'كريم عبد الله', phone: '01155550912', tier: 'retail', points: 320 },
  { id: 31, name: 'محلات النور للشنط', phone: '01228877410', tier: 'wholesale', points: 0 },
];
const SHIFT_STATS = { cashSales: 7360, cardSales: 2840, returns: 450, expenses: 180, invoices: 23 };

const HELD_SEED = [
  { ref: 'HLD-NSR-0042', at: '٠٢:١٤ م', mins: 18, customer: 'كريم عبد الله', note: 'زبون بيجيب محفظة إضافية', items: [{ id: 105, qty: 1 }, { id: 107, qty: 2 }] },
  { ref: 'HLD-NSR-0041', at: '١٢:٥٠ م', mins: 102, customer: null, note: 'بانتظار العميل', items: [{ id: 109, qty: 1 }] },
];

const PAST_SALES = {
  'POS-NSR-10233': { id: 10233, date: '٣ نوفمبر ٢٠٢٦ — ٠٤:٢٢ م', cashier: 'منى عادل', customer: 'مها فتحي', method: 'نقدي', items: [{ id: 101, qty: 2, price: 450 }, { id: 107, qty: 1, price: 195 }, { id: 111, qty: 3, price: 65 }] },
};

const TRANSFERS_IN = [
  { id: 881, number: 'TRF-2026-0881', from: 'المخزن الرئيسي — العبور', status: 'shipped', shipped: 'اليوم ٠٩:٣٠ ص', driver: 'محمود — ٠١٠٩٩٨٨٧٧٦', items: [{ id: 102, qty: 10 }, { id: 108, qty: 12 }, { id: 112, qty: 15 }, { id: 106, qty: 8 }] },
  { id: 874, number: 'TRF-2026-0874', from: 'فرع المعادي', status: 'shipped', shipped: 'أمس ٠٦:١٠ م', driver: 'عربية الفرع', items: [{ id: 104, qty: 5 }, { id: 114, qty: 4 }] },
];
const SOURCES = [
  { id: 1, name: 'المخزن الرئيسي — العبور', kind: 'warehouse' },
  { id: 2, name: 'فرع المعادي', kind: 'store' },
  { id: 5, name: 'فرع مصر الجديدة', kind: 'store' },
  { id: 6, name: 'فرع ٦ أكتوبر', kind: 'store' },
];

const pById = id => PRODUCTS.find(p => p.id === id);
const uuid = () => 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); });
const nf2 = new Intl.NumberFormat('ar-EG', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
const egp = v => nf2.format(Math.round(v * 100) / 100);
const toAsciiNum = s => String(s).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/[^\d.]/g, '');

function PImg({ p, size, children }) {
  const icon = { hand: 'bag', cross: 'bag', back: 'bag', wallet: 'wallet', travel: 'truck', acc: 'tag' }[p.cat] || 'bag';
  return <div className={cx('pimg', size)} style={{ '--h': p.h }}>
    <Icon n={icon} s={size === 'xs' ? 16 : size === 'sm' ? 20 : 34} w={1.4} style={{ position: 'relative', zIndex: 1 }} />{children}
  </div>;
}
function Api({ m = 'GET', path }) {
  return <span className="api"><b className={m}>{m}</b>{path}</span>;
}
function Kbd({ children }) { return <span className="kbd">{children}</span>; }

function MoneyInput({ value, onChange, autoFocus, size, placeholder = '0', onEnter }) {
  const ref = useRef();
  useEffect(() => { if (autoFocus) setTimeout(() => ref.current?.focus(), 60); }, [autoFocus]);
  return <label className={cx('money-in', size)}>
    <span className="cur">ج.م</span>
    <input ref={ref} inputMode="decimal" value={value} placeholder={placeholder}
      onChange={e => onChange(toAsciiNum(e.target.value))}
      onKeyDown={e => { if (e.key === 'Enter' && onEnter) onEnter(); }} />
  </label>;
}

Object.assign(window, { BRANCH, CASHIER, TREASURIES, CATS, PRODUCTS, CUSTOMERS, SHIFT_STATS, HELD_SEED, PAST_SALES, TRANSFERS_IN, SOURCES, pById, uuid, egp, toAsciiNum, PImg, Api, Kbd, MoneyInput });
