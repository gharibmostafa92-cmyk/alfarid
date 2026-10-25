// ============ Mock data for Vortix Inventory ============

const INV_BRANCHES = [
  { id: 'b1', name: 'فرع مدينة نصر', code: 'BR-NSR', type: 'branch', city: 'القاهرة', phone: '01001234567', email: 'nasr@vortix.eg', address: '١٢ شارع عباس العقاد', active: true },
  { id: 'b2', name: 'فرع سموحة', code: 'BR-SMH', type: 'branch', city: 'الإسكندرية', phone: '01112345678', email: 'smouha@vortix.eg', address: '٤ ميدان فيكتور عمانويل', active: true },
  { id: 'w1', name: 'المخزن الرئيسي', code: 'WH-MAIN', type: 'warehouse', city: 'العاشر من رمضان', phone: '01223456789', email: 'main.wh@vortix.eg', address: 'المنطقة الصناعية B3', active: true },
  { id: 'b3', name: 'فرع المعادي', code: 'BR-MAD', type: 'branch', city: 'القاهرة', phone: '01098765432', email: 'maadi@vortix.eg', address: '٨٢ شارع ٩', active: true },
  { id: 'w2', name: 'مخزن الإسكندرية', code: 'WH-ALX', type: 'warehouse', city: 'برج العرب', phone: '01287654321', email: 'alx.wh@vortix.eg', address: 'المنطقة الصناعية الثالثة', active: true },
  { id: 'b4', name: 'فرع المنصورة', code: 'BR-MNS', type: 'branch', city: 'المنصورة', phone: '01555443322', email: 'mansoura@vortix.eg', address: 'شارع الجمهورية', active: false },
];

const INV_LOC_TYPES = {
  shelf:     { label: 'رف',         cls: 'b-gray'   },
  zone:      { label: 'منطقة',      cls: 'b-blue'   },
  bin:       { label: 'خانة تخزين', cls: 'b-gray'   },
  damaged:   { label: 'تالف',       cls: 'b-red'    },
  receiving: { label: 'استلام',     cls: 'b-amber'  },
};

const INV_LOCATIONS = [
  { id: 'l1', branchId: 'w1', name: 'منطقة الاستلام', code: 'RCV-01', type: 'receiving', active: true },
  { id: 'l2', branchId: 'w1', name: 'المنطقة A', code: 'Z-A', type: 'zone', active: true },
  { id: 'l3', branchId: 'w1', name: 'رف A-01', code: 'A-01', type: 'shelf', active: true },
  { id: 'l4', branchId: 'w1', name: 'رف A-02', code: 'A-02', type: 'shelf', active: true },
  { id: 'l5', branchId: 'w1', name: 'خانة A-02-03', code: 'A-02-03', type: 'bin', active: true },
  { id: 'l6', branchId: 'w1', name: 'منطقة التالف', code: 'DMG-01', type: 'damaged', active: true },
  { id: 'l7', branchId: 'w1', name: 'رف B-01', code: 'B-01', type: 'shelf', active: false },
  { id: 'l8', branchId: 'b1', name: 'رف العرض', code: 'S-01', type: 'shelf', active: true },
  { id: 'l9', branchId: 'b1', name: 'المخزن الخلفي', code: 'BK-01', type: 'zone', active: true },
  { id: 'l10', branchId: 'b1', name: 'تالف الفرع', code: 'DMG-01', type: 'damaged', active: true },
  { id: 'l11', branchId: 'b2', name: 'رف العرض', code: 'S-01', type: 'shelf', active: true },
  { id: 'l12', branchId: 'b2', name: 'استلام', code: 'RCV-01', type: 'receiving', active: true },
  { id: 'l13', branchId: 'w2', name: 'المنطقة الرئيسية', code: 'Z-M', type: 'zone', active: true },
  { id: 'l14', branchId: 'w2', name: 'رف M-01', code: 'M-01', type: 'shelf', active: true },
  { id: 'l15', branchId: 'b3', name: 'رف العرض', code: 'S-01', type: 'shelf', active: true },
];

// Products → variants (every pick resolves to variant/SKU)
const INV_PRODUCTS = [
  { id: 'p1', name: 'قميص قطن أكسفورد', variants: [
    { id: 'v1', sku: 'OXF-WHT-M', barcode: '6221031000011', attrs: 'أبيض · M', cost: 185 },
    { id: 'v2', sku: 'OXF-WHT-L', barcode: '6221031000028', attrs: 'أبيض · L', cost: 185 },
    { id: 'v3', sku: 'OXF-BLU-M', barcode: '6221031000035', attrs: 'أزرق · M', cost: 192.5 },
  ]},
  { id: 'p2', name: 'بنطلون تشينو', variants: [
    { id: 'v4', sku: 'CHN-BEG-32', barcode: '6221031000042', attrs: 'بيج · 32', cost: 240 },
    { id: 'v5', sku: 'CHN-NVY-34', barcode: '6221031000059', attrs: 'كحلي · 34', cost: 240 },
  ]},
  { id: 'p3', name: 'جاكيت جينز', variants: [
    { id: 'v6', sku: 'DNM-JKT-L', barcode: '6221031000066', attrs: 'أزرق غامق · L', cost: 410 },
    { id: 'v7', sku: 'DNM-JKT-XL', barcode: '6221031000073', attrs: 'أزرق غامق · XL', cost: 410 },
  ]},
  { id: 'p4', name: 'حزام جلد طبيعي', variants: [
    { id: 'v8', sku: 'BLT-BRN-95', barcode: '6221031000080', attrs: 'بني · 95 سم', cost: 96.75 },
  ]},
  { id: 'p5', name: 'قماش كتان (بالمتر)', variants: [
    { id: 'v9', sku: 'LIN-NAT-MTR', barcode: '6221031000097', attrs: 'طبيعي · متر', cost: 58.2 },
  ]},
  { id: 'p6', name: 'تيشيرت بولو', variants: [
    { id: 'v10', sku: 'PLO-BLK-M', barcode: '6221031000103', attrs: 'أسود · M', cost: 140 },
    { id: 'v11', sku: 'PLO-BLK-L', barcode: '6221031000110', attrs: 'أسود · L', cost: 140 },
  ]},
];

const INV_VARIANTS = INV_PRODUCTS.flatMap(p => p.variants.map(v => ({ ...v, productId: p.id, productName: p.name })));
const invVariant = id => INV_VARIANTS.find(v => v.id === id);
const invBranch = id => INV_BRANCHES.find(b => b.id === id);
const invLoc = id => INV_LOCATIONS.find(l => l.id === id);

// Stock balances
const INV_STOCK = [
  { id: 's1', variantId: 'v1', branchId: 'w1', locId: 'l3', onHand: 420, reserved: 36, damaged: 0, inTransit: 24, reorder: 100, updated: '2026-10-23 09:14' },
  { id: 's2', variantId: 'v2', branchId: 'w1', locId: 'l3', onHand: 380, reserved: 12, damaged: 4, inTransit: 0, reorder: 100, updated: '2026-10-23 09:14' },
  { id: 's3', variantId: 'v3', branchId: 'w1', locId: 'l4', onHand: 64, reserved: 20, damaged: 0, inTransit: 0, reorder: 80, updated: '2026-10-22 17:40' },
  { id: 's4', variantId: 'v4', branchId: 'w1', locId: 'l5', onHand: 210, reserved: 0, damaged: 2, inTransit: 30, reorder: 60, updated: '2026-10-22 13:02' },
  { id: 's5', variantId: 'v6', branchId: 'w1', locId: 'l4', onHand: 18, reserved: 6, damaged: 1, inTransit: 0, reorder: 25, updated: '2026-10-21 11:20' },
  { id: 's6', variantId: 'v9', branchId: 'w1', locId: 'l2', onHand: 1250.5, reserved: 120.25, damaged: 14.75, inTransit: 0, reorder: 300, updated: '2026-10-23 08:01' },
  { id: 's7', variantId: 'v1', branchId: 'b1', locId: 'l8', onHand: 22, reserved: 2, damaged: 0, inTransit: 24, reorder: 15, updated: '2026-10-23 10:31' },
  { id: 's8', variantId: 'v5', branchId: 'b1', locId: 'l9', onHand: 9, reserved: 1, damaged: 0, inTransit: 0, reorder: 12, updated: '2026-10-22 19:55' },
  { id: 's9', variantId: 'v8', branchId: 'b1', locId: 'l8', onHand: 41, reserved: 0, damaged: 3, inTransit: 0, reorder: 10, updated: '2026-10-20 15:12' },
  { id: 's10', variantId: 'v10', branchId: 'b2', locId: 'l11', onHand: 56, reserved: 4, damaged: 0, inTransit: 0, reorder: 20, updated: '2026-10-23 07:45' },
  { id: 's11', variantId: 'v11', branchId: 'b2', locId: 'l11', onHand: 7, reserved: 3, damaged: 0, inTransit: 0, reorder: 20, updated: '2026-10-22 21:10' },
  { id: 's12', variantId: 'v7', branchId: 'w2', locId: 'l14', onHand: 132, reserved: 10, damaged: 0, inTransit: 0, reorder: 30, updated: '2026-10-22 10:00' },
  { id: 's13', variantId: 'v4', branchId: 'b3', locId: 'l15', onHand: 0, reserved: 0, damaged: 0, inTransit: 30, reorder: 10, updated: '2026-10-22 13:02' },
];
INV_STOCK.forEach(s => { s.available = +(s.onHand - s.reserved - s.damaged).toFixed(4); });

const INV_MOVE_TYPES = {
  adj_in:      { label: 'تسوية زيادة',      cls: 'b-green', sign: 1 },
  adj_out:     { label: 'تسوية نقصان',      cls: 'b-red',   sign: -1 },
  dmg_mark:    { label: 'تسجيل تالف',       cls: 'b-amber', sign: -1 },
  dmg_writeoff:{ label: 'إعدام تالف',       cls: 'b-red',   sign: -1 },
  dmg_restore: { label: 'استرجاع من التالف', cls: 'b-green', sign: 1 },
  tr_out:      { label: 'تحويل صادر',       cls: 'b-blue',  sign: -1 },
  tr_in:       { label: 'تحويل وارد',       cls: 'b-blue',  sign: 1 },
  count:       { label: 'فرق جرد',          cls: 'b-gray',  sign: 0 },
  production:  { label: 'استلام إنتاج',     cls: 'b-orange',sign: 1 },
  sale:        { label: 'بيع',              cls: 'b-gray',  sign: -1 },
};

const INV_USERS = ['أحمد سمير', 'منى خالد', 'كريم عادل', 'سارة فؤاد'];

function invGenMoves() {
  const out = [];
  const types = Object.keys(INV_MOVE_TYPES);
  const refs = { adj_in: 'ADJ', adj_out: 'ADJ', dmg_mark: 'ADJ', dmg_writeoff: 'ADJ', dmg_restore: 'ADJ', tr_out: 'TRF', tr_in: 'TRF', count: 'CNT', production: 'PRD', sale: 'INV' };
  let seed = 7;
  const rnd = () => { seed = (seed * 9301 + 49297) % 233280; return seed / 233280; };
  for (let i = 0; i < 64; i++) {
    const st = INV_STOCK[Math.floor(rnd() * INV_STOCK.length)];
    const t = types[Math.floor(rnd() * types.length)];
    const mt = INV_MOVE_TYPES[t];
    const qty = st.variantId === 'v9' ? +(rnd() * 40 + 1).toFixed(2) : Math.ceil(rnd() * 24);
    const sign = mt.sign === 0 ? (rnd() > .5 ? 1 : -1) : mt.sign;
    const before = Math.round(st.onHand + rnd() * 30);
    const day = 23 - Math.floor(i / 5);
    const h = String(8 + (i * 7) % 12).padStart(2, '0'), m = String((i * 13) % 60).padStart(2, '0');
    out.push({
      id: 'm' + i, date: `2026-10-${String(day).padStart(2, '0')} ${h}:${m}`,
      ref: `${refs[t]}-2026-${String(1480 - i * 3).padStart(5, '0')}`,
      variantId: st.variantId, branchId: st.branchId, locId: st.locId, type: t,
      qty: sign * qty, before, after: +(before + sign * qty).toFixed(4),
      user: INV_USERS[i % 4], unitCost: invVariant(st.variantId).cost,
      note: ['', 'كرتونة مفتوحة', 'مطابقة رصيد افتتاحي', '', 'تلف أثناء النقل', '', 'طلب فرع'][i % 7],
    });
  }
  return out;
}
const INV_MOVES = invGenMoves();

const INV_TR_STATUS = {
  draft:     { label: 'مسودة',  cls: 'b-gray'  },
  approved:  { label: 'معتمد',  cls: 'b-blue'  },
  shipped:   { label: 'مشحون',  cls: 'b-amber' },
  received:  { label: 'مستلم',  cls: 'b-green' },
  cancelled: { label: 'ملغي',   cls: 'b-red'   },
};

const INV_TRANSFERS = [
  { id: 't1', no: 'TRF-2026-00142', from: 'w1', to: 'b1', status: 'shipped', date: '2026-10-22', note: 'تزويد رفوف العرض لموسم الشتاء', createdBy: 'منى خالد',
    lines: [
      { variantId: 'v1', fromLoc: 'l3', toLoc: 'l8', req: 30, appr: 24, ship: 24, recv: null },
      { variantId: 'v4', fromLoc: 'l5', toLoc: 'l9', req: 20, appr: 20, ship: 20, recv: null },
    ] },
  { id: 't2', no: 'TRF-2026-00141', from: 'w1', to: 'b3', status: 'approved', date: '2026-10-22', note: '', createdBy: 'أحمد سمير',
    lines: [ { variantId: 'v4', fromLoc: 'l5', toLoc: 'l15', req: 30, appr: 30, ship: null, recv: null } ] },
  { id: 't3', no: 'TRF-2026-00140', from: 'w2', to: 'b2', status: 'draft', date: '2026-10-21', note: 'طلب عاجل', createdBy: 'كريم عادل',
    lines: [ { variantId: 'v7', fromLoc: 'l14', toLoc: 'l11', req: 12, appr: null, ship: null, recv: null } ] },
  { id: 't4', no: 'TRF-2026-00138', from: 'w1', to: 'b2', status: 'received', date: '2026-10-18', note: '', createdBy: 'منى خالد',
    lines: [
      { variantId: 'v10', fromLoc: 'l3', toLoc: 'l11', req: 40, appr: 40, ship: 40, recv: 40 },
      { variantId: 'v11', fromLoc: 'l3', toLoc: 'l11', req: 40, appr: 36, ship: 36, recv: 35 },
    ] },
  { id: 't5', no: 'TRF-2026-00135', from: 'b1', to: 'w1', status: 'cancelled', date: '2026-10-15', note: 'تم الإلغاء لعدم الحاجة', createdBy: 'سارة فؤاد',
    lines: [ { variantId: 'v8', fromLoc: 'l8', toLoc: 'l3', req: 10, appr: null, ship: null, recv: null } ] },
];

const INV_CNT_STATUS = {
  draft:     { label: 'مسودة',       cls: 'b-gray'  },
  counting:  { label: 'قيد العدّ',    cls: 'b-amber' },
  approved:  { label: 'معتمد',       cls: 'b-green' },
};

const INV_COUNTS = [
  { id: 'c1', no: 'CNT-2026-00031', branchId: 'w1', locId: 'l3', status: 'counting', createdBy: 'أحمد سمير', date: '2026-10-23', note: 'جرد دوري ربع سنوي',
    lines: [
      { variantId: 'v1', system: 420, actual: 418 },
      { variantId: 'v2', system: 380, actual: 380 },
      { variantId: 'v3', system: 64, actual: null },
      { variantId: 'v6', system: 18, actual: 0 },
      { variantId: 'v9', system: 1250.5, actual: null },
    ] },
  { id: 'c2', no: 'CNT-2026-00030', branchId: 'b1', locId: null, status: 'approved', createdBy: 'منى خالد', date: '2026-10-15', note: '',
    lines: [ { variantId: 'v1', system: 20, actual: 22 }, { variantId: 'v8', system: 44, actual: 41 } ] },
  { id: 'c3', no: 'CNT-2026-00029', branchId: 'b2', locId: 'l11', status: 'draft', createdBy: 'كريم عادل', date: '2026-10-14', note: '',
    lines: [ { variantId: 'v10', system: 56, actual: null }, { variantId: 'v11', system: 7, actual: null } ] },
];

const INV_PRODUCTION = [
  { id: 'pr1', no: 'PRD-2026-00088', batch: 'B-2610-07', branchId: 'w1', locId: 'l1', date: '2026-10-22', user: 'كريم عادل', note: 'دفعة الإنتاج الأسبوعية',
    lines: [ { variantId: 'v1', qty: 120, cost: 182 }, { variantId: 'v2', qty: 120, cost: 182 } ] },
  { id: 'pr2', no: 'PRD-2026-00087', batch: 'B-2610-06', branchId: 'w1', locId: 'l1', date: '2026-10-18', user: 'كريم عادل', note: '',
    lines: [ { variantId: 'v6', qty: 40, cost: 405 } ] },
  { id: 'pr3', no: 'PRD-2026-00086', batch: 'B-2610-05', branchId: 'w2', locId: null, date: '2026-10-12', user: 'سارة فؤاد', note: '',
    lines: [ { variantId: 'v7', qty: 60, cost: 402.5 }, { variantId: 'v10', qty: 80, cost: 138 }, { variantId: 'v11', qty: 80, cost: 138 } ] },
];

// Roles → permissions
const INV_ROLES = {
  admin:      { label: 'مدير النظام', perms: ['store.manage', 'branches.manage', 'stock.adjust', 'cost.view', 'transfers.create', 'transfers.approve', 'transfers.ship', 'transfers.receive', 'transfers.cancel', 'count.manage', 'count.approve'] },
  storekeeper:{ label: 'أمين مخزن',   perms: ['stock.adjust', 'transfers.create', 'transfers.ship', 'transfers.receive', 'count.manage'] },
  viewer:     { label: 'عرض فقط',     perms: [] },
};

const invFmt = (n, d = 4) => {
  if (n === null || n === undefined || n === '') return '—';
  return Number(n).toLocaleString('en-US', { maximumFractionDigits: d });
};
const invMoney = n => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

Object.assign(window, {
  INV_BRANCHES, INV_LOC_TYPES, INV_LOCATIONS, INV_PRODUCTS, INV_VARIANTS, INV_STOCK, INV_MOVE_TYPES, INV_MOVES,
  INV_TR_STATUS, INV_TRANSFERS, INV_CNT_STATUS, INV_COUNTS, INV_PRODUCTION, INV_ROLES, INV_USERS,
  invVariant, invBranch, invLoc, invFmt, invMoney,
});
