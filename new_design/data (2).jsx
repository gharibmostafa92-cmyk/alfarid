// ===== Mock data mirroring backend shapes =====
const ADMIN_BRANCHES = [
  { id: 1, name: 'الفرع الرئيسي — القاهرة' },
  { id: 2, name: 'فرع المعادي' },
  { id: 3, name: 'فرع مدينة نصر' },
  { id: 4, name: 'فرع الإسكندرية' },
  { id: 5, name: 'فرع الجيزة' },
  { id: 6, name: 'مستودع العبور' },
];

const PERMISSION_GROUPS = [
  { module: 'inventory', label: 'المخزون والمستودعات', keys: 'inventory · warehouses · transfers', icon: 'box', perms: [
    ['inventory.view', 'عرض المخزون'],
    ['inventory.adjust', 'تسوية جردية', true],
    ['transfers.create', 'إنشاء تحويل مخزني'],
    ['transfers.approve', 'اعتماد التحويلات المخزنية'],
    ['stocktake.perform', 'جرد المخزن'],
    ['warehouses.manage', 'إدارة المستودعات'],
    ['inventory.export', 'تصدير أرصدة المخزون'],
  ]},
  { module: 'products', label: 'المنتجات والتصنيفات', keys: 'products · categories · brands', icon: 'tag', perms: [
    ['products.view', 'عرض المنتجات'],
    ['products.create', 'إضافة منتج'],
    ['products.update', 'تعديل بيانات منتج'],
    ['products.update_price', 'تعديل الأسعار', true],
    ['products.view_cost', 'عرض التكلفة', true],
    ['products.delete', 'حذف منتج'],
    ['categories.manage', 'إدارة التصنيفات'],
    ['categories.delete', 'حذف تصنيف'],
    ['brands.manage', 'إدارة العلامات التجارية'],
  ]},
  { module: 'orders', label: 'المبيعات والطلبات', keys: 'orders · sales', icon: 'cart', perms: [
    ['orders.view', 'عرض الطلبات'],
    ['orders.create', 'إنشاء طلب'],
    ['orders.update', 'تعديل الطلبات'],
    ['orders.approve', 'اعتماد الطلبات'],
    ['orders.cancel', 'إلغاء الطلبات', true],
    ['sales.view_all', 'عرض مبيعات جميع الموظفين'],
  ]},
  { module: 'pos', label: 'نقطة البيع (POS)', keys: 'pos', icon: 'pos', perms: [
    ['pos.access', 'الدخول إلى نقطة البيع'],
    ['pos.open_shift', 'فتح الشيفت'],
    ['pos.close_shift', 'إغلاق الشيفت'],
    ['pos.quick_sale', 'البيع السريع'],
    ['pos.discount', 'خصم يدوي', true],
    ['pos.override_price', 'تعديل السعر عند البيع', true],
    ['pos.void', 'إلغاء فاتورة بعد إصدارها', true],
  ]},
  { module: 'purchases', label: 'المشتريات والموردين', keys: 'purchases · suppliers · purchase_requests', icon: 'truck', perms: [
    ['purchases.view', 'عرض أوامر الشراء'],
    ['purchases.create', 'إنشاء أمر شراء'],
    ['purchases.approve', 'اعتماد أمر شراء', true],
    ['purchases.receive', 'استلام بضاعة'],
    ['purchase_requests.create', 'إنشاء طلب شراء'],
    ['purchase_requests.approve', 'اعتماد طلب شراء', true],
    ['suppliers.manage', 'إدارة الموردين'],
  ]},
  { module: 'returns', label: 'المرتجعات', keys: 'returns', icon: 'undo', perms: [
    ['returns.view', 'عرض المرتجعات'],
    ['returns.create', 'إنشاء مرتجع'],
    ['returns.approve', 'اعتماد المرتجع', true],
    ['returns.receive', 'استلام المرتجع في المخزن'],
    ['returns.refund', 'رد الأموال للعميل', true],
  ]},
  { module: 'branches', label: 'الفروع والمواقع', keys: 'branches · locations', icon: 'pin', perms: [
    ['branches.view', 'عرض الفروع'],
    ['branches.manage', 'إدارة الفروع'],
    ['branches.access_all', 'الوصول لكل الفروع', true],
    ['locations.manage', 'إدارة المواقع والرفوف'],
  ]},
  { module: 'finance', label: 'المالية والخزائن', keys: 'payments · treasury', icon: 'wallet', perms: [
    ['payments.view', 'عرض المدفوعات'],
    ['payments.receipt', 'سندات القبض'],
    ['payments.supplier', 'سندات صرف الموردين', true],
    ['treasury.transfer', 'تحويل بين الخزائن', true],
    ['treasury.view_balance', 'عرض أرصدة الخزائن'],
  ]},
  { module: 'users', label: 'المستخدمون والأدوار', keys: 'users · roles · permissions', icon: 'users', perms: [
    ['users.view', 'عرض الموظفين'],
    ['users.create', 'إضافة موظف'],
    ['users.update', 'تعديل بيانات موظف'],
    ['users.deactivate', 'تعطيل حساب موظف'],
    ['roles.manage', 'تعديل الأدوار', true],
    ['permissions.manage', 'إدارة الصلاحيات', true],
  ]},
  { module: 'settings', label: 'الإعدادات والتقارير', keys: 'settings · reports', icon: 'sliders', perms: [
    ['settings.view', 'عرض إعدادات النظام'],
    ['settings.update', 'تعديل إعدادات النظام', true],
    ['reports.view', 'عرض التقارير'],
    ['reports.export', 'تصدير التقارير'],
    ['reports.financial', 'التقارير المالية', true],
  ]},
];
const ALL_PERMS = PERMISSION_GROUPS.flatMap(g => g.perms.map(p => p[0]));

const pick = (...prefixes) => ALL_PERMS.filter(k => prefixes.some(p => k === p || k.startsWith(p)));

const INITIAL_ROLES = [
  { id: 1, name: 'Super Admin', system: true, perms: [...ALL_PERMS] },
  { id: 2, name: 'مدير عام', perms: ALL_PERMS.filter(k => !['permissions.manage', 'settings.update', 'pos.void'].includes(k)) },
  { id: 3, name: 'مدير فرع', perms: pick('inventory.view', 'transfers.', 'stocktake.', 'products.view', 'products.update', 'orders.', 'sales.', 'pos.', 'returns.', 'branches.view', 'payments.view', 'payments.receipt', 'users.view', 'reports.view') },
  { id: 4, name: 'محاسب', perms: pick('payments.', 'treasury.', 'reports.', 'purchases.view', 'orders.view', 'products.view', 'products.view_cost', 'returns.view', 'returns.refund', 'sales.view_all') },
  { id: 5, name: 'كاشير', perms: pick('pos.access', 'pos.open_shift', 'pos.close_shift', 'pos.quick_sale', 'products.view', 'orders.view', 'orders.create', 'returns.create') },
  { id: 6, name: 'أمين مستودع', perms: pick('inventory.', 'transfers.create', 'stocktake.', 'warehouses.', 'purchases.receive', 'returns.receive', 'products.view', 'locations.') },
  { id: 7, name: 'مسؤول مشتريات', perms: pick('purchases.', 'purchase_requests.create', 'suppliers.', 'products.view', 'products.view_cost', 'inventory.view') },
  { id: 8, name: 'خدمة العملاء', perms: pick('orders.view', 'returns.view', 'returns.create', 'products.view') },
];

const INITIAL_USERS = [
  { id: 1, name: 'أحمد الفريد', email: 'ahmed@alfarid.co', phone: '+20 100 245 1180', branch_id: null, roles: [1], is_active: true, created_at: '2024-01-08', me: true },
  { id: 2, name: 'منى عبد الرحمن', email: 'mona.a@alfarid.co', phone: '+20 111 902 3341', branch_id: null, roles: [2], is_active: true, created_at: '2024-02-14' },
  { id: 3, name: 'كريم حسان', email: 'karim.h@alfarid.co', phone: '+20 122 410 7752', branch_id: 1, roles: [3], is_active: true, created_at: '2024-03-02' },
  { id: 4, name: 'سارة يوسف', email: 'sara.y@alfarid.co', phone: '+20 106 338 9120', branch_id: 2, roles: [3, 4], is_active: true, created_at: '2024-03-19' },
  { id: 5, name: 'عمر الشريف', email: 'omar.s@alfarid.co', phone: null, branch_id: 1, roles: [5], is_active: true, created_at: '2024-04-05' },
  { id: 6, name: 'ليلى منصور', email: 'laila.m@alfarid.co', phone: '+20 115 774 0021', branch_id: 3, roles: [5], is_active: false, created_at: '2024-04-22' },
  { id: 7, name: 'يوسف عادل', email: 'youssef.a@alfarid.co', phone: '+20 109 662 4471', branch_id: 6, roles: [6], is_active: true, created_at: '2024-05-11' },
  { id: 8, name: 'نور الهدى سامي', email: 'nour.s@alfarid.co', phone: '+20 128 113 5590', branch_id: null, roles: [4], is_active: true, created_at: '2024-06-01' },
  { id: 9, name: 'محمود فتحي', email: 'mahmoud.f@alfarid.co', phone: '+20 100 880 3367', branch_id: 4, roles: [3], is_active: true, created_at: '2024-06-18' },
  { id: 10, name: 'هالة إبراهيم', email: 'hala.i@alfarid.co', phone: null, branch_id: 4, roles: [5], is_active: true, created_at: '2024-07-03' },
  { id: 11, name: 'طارق زكي', email: 'tarek.z@alfarid.co', phone: '+20 112 559 0084', branch_id: null, roles: [7], is_active: true, created_at: '2024-07-27' },
  { id: 12, name: 'دينا كمال', email: 'dina.k@alfarid.co', phone: '+20 101 447 2296', branch_id: 5, roles: [5], is_active: false, created_at: '2024-08-15' },
  { id: 13, name: 'باسم رأفت', email: 'bassem.r@alfarid.co', phone: '+20 127 300 6618', branch_id: 6, roles: [6, 7], is_active: true, created_at: '2024-09-09' },
  { id: 14, name: 'ريم الصاوي', email: 'reem.e@alfarid.co', phone: '+20 114 208 7743', branch_id: 2, roles: [5], is_active: true, created_at: '2024-10-01' },
  { id: 15, name: 'حسام نبيل', email: 'hossam.n@alfarid.co', phone: null, branch_id: 5, roles: [3], is_active: true, created_at: '2024-11-12' },
  { id: 16, name: 'مريم فؤاد', email: 'mariam.f@alfarid.co', phone: '+20 103 991 2250', branch_id: 3, roles: [5], is_active: true, created_at: '2025-01-06' },
  { id: 17, name: 'شريف عوض', email: 'sherif.a@alfarid.co', phone: '+20 120 676 1189', branch_id: 1, roles: [4], is_active: false, created_at: '2025-02-20' },
  { id: 18, name: 'آية مجدي', email: 'aya.m@alfarid.co', phone: '+20 110 525 8834', branch_id: null, roles: [], is_active: true, created_at: '2025-03-14' },
];

const DOC_TYPES = [
  { key: 'invoice', label: 'فواتير البيع', prefix: 'INV-', padding: 6, next: 1042 },
  { key: 'order', label: 'طلبات البيع', prefix: 'SO-', padding: 6, next: 2318 },
  { key: 'purchase_order', label: 'أوامر الشراء', prefix: 'PO-', padding: 5, next: 412 },
  { key: 'quotation', label: 'عروض الأسعار', prefix: 'QT-', padding: 5, next: 187 },
  { key: 'movement', label: 'حركات المخزون', prefix: 'MV-', padding: 7, next: 15530 },
  { key: 'transfer', label: 'التحويلات المخزنية', prefix: 'TRF-', padding: 5, next: 640 },
  { key: 'stocktake', label: 'الجرد المخزني', prefix: 'STK-', padding: 4, next: 38 },
  { key: 'return', label: 'المرتجعات', prefix: 'RET-', padding: 5, next: 221 },
  { key: 'purchase_request', label: 'طلبات الشراء', prefix: 'PR-', padding: 5, next: 309 },
  { key: 'delivery', label: 'أذون التسليم والشحن', prefix: 'DN-', padding: 6, next: 1877 },
  { key: 'supplier_invoice', label: 'فواتير الموردين', prefix: 'SINV-', padding: 5, next: 356 },
  { key: 'supplier_payment', label: 'سندات صرف الموردين', prefix: 'SP-', padding: 5, next: 274 },
];

const INITIAL_SETTINGS = {
  company: {
    company_logo: '',
    company_name: 'شركة الفريد للتجارة والتوزيع',
    company_email: 'info@alfarid.co',
    company_phone: '+20 2 2735 8800',
    company_address: '14 شارع التسعين الشمالي، التجمع الخامس\nالقاهرة الجديدة، مصر',
    commercial_register: '184520',
  },
  tax: { tax_enabled: true, tax_number: '512-447-890', default_tax_rate: 14, prices_include_tax: true },
  numbering: Object.fromEntries(DOC_TYPES.map(d => [d.key, { prefix: d.prefix, padding: d.padding, next_number: d.next }])),
  orders: { return_window_days: 14 },
  purchases: { allow_self_approve_purchases: false },
};

Object.assign(window, { ADMIN_BRANCHES, PERMISSION_GROUPS, ALL_PERMS, INITIAL_ROLES, INITIAL_USERS, DOC_TYPES, INITIAL_SETTINGS });
