// ALFARID mock data — bags & wallets
const DATA = {
  branches: [
    { id: 1, name: 'فرع المعادي', code: 'MAD-01', type: 'branch', phone: '01001234567', email: 'maadi@alfarid.com', city: 'القاهرة', address: '٢١ شارع ٩، المعادي', active: true, locations: 6 },
    { id: 2, name: 'فرع الزمالك', code: 'ZAM-02', type: 'branch', phone: '01002345678', email: 'zamalek@alfarid.com', city: 'القاهرة', address: '١٤ شارع البرازيل', active: true, locations: 4 },
    { id: 3, name: 'مخزن العبور الرئيسي', code: 'WH-OBR', type: 'warehouse', phone: '0224001100', email: 'wh@alfarid.com', city: 'العبور', address: 'المنطقة الصناعية الأولى', active: true, locations: 18 },
    { id: 4, name: 'فرع سيتي ستارز', code: 'CS-03', type: 'branch', phone: '01003456789', email: 'citystars@alfarid.com', city: 'القاهرة', address: 'سيتي ستارز، المرحلة ٢', active: true, locations: 3 },
    { id: 5, name: 'فرع سان ستيفانو', code: 'ALX-01', type: 'branch', phone: '01004567890', email: 'alex@alfarid.com', city: 'الإسكندرية', address: 'سان ستيفانو جراند بلازا', active: false, locations: 2 },
  ],
  locations: [
    { id: 1, name: 'رف الحقائب اليدوية A', code: 'A-01', type: 'shelf', active: true },
    { id: 2, name: 'رف المحافظ الجلدية', code: 'A-02', type: 'shelf', active: true },
    { id: 3, name: 'منطقة العرض الأمامية', code: 'Z-FRONT', type: 'zone', active: true },
    { id: 4, name: 'خانة الإكسسوارات ٣', code: 'B-03-2', type: 'bin', active: true },
    { id: 5, name: 'منطقة الاستلام', code: 'RCV-01', type: 'receiving', active: true },
    { id: 6, name: 'التالف والمرتجع المعيب', code: 'DMG-01', type: 'damaged', active: false },
  ],
  locTypes: { shelf: 'رف', zone: 'منطقة', bin: 'خانة', damaged: 'تالف', receiving: 'استلام' },
  users: [
    { id: 1, name: 'سارة الشريف', email: 'sara@alfarid.com', phone: '01011112222', branch: null, allBranches: true, roles: ['مدير النظام'], active: true },
    { id: 2, name: 'كريم عادل', email: 'karim.adel@alfarid.com', phone: '01022223333', branch: 'فرع المعادي', roles: ['مدير فرع'], active: true },
    { id: 3, name: 'منى حسن', email: 'mona.h@alfarid.com', phone: '01033334444', branch: 'مخزن العبور الرئيسي', roles: ['أمين مخزن'], active: true },
    { id: 4, name: 'يوسف طارق', email: 'youssef@alfarid.com', phone: '', branch: 'فرع الزمالك', roles: ['كاشير'], active: true },
    { id: 5, name: 'ليلى منصور', email: 'laila.m@alfarid.com', phone: '01055556666', branch: null, allBranches: false, roles: ['مسؤول كتالوج'], active: true },
    { id: 6, name: 'عمر فاروق', email: 'omar.f@alfarid.com', phone: '01066667777', branch: 'فرع سيتي ستارز', roles: ['كاشير'], active: false },
  ],
  roles: [
    { id: 1, name: 'مدير النظام', users: 1, perms: 48, system: true },
    { id: 2, name: 'مدير فرع', users: 4, perms: 26 },
    { id: 3, name: 'مسؤول كتالوج', users: 2, perms: 14 },
    { id: 4, name: 'أمين مخزن', users: 3, perms: 11 },
    { id: 5, name: 'كاشير', users: 9, perms: 6 },
  ],
  permGroups: [
    { key: 'products', name: 'المنتجات', perms: [
      ['products.view', 'عرض المنتجات'], ['products.create', 'إضافة منتج'], ['products.update', 'تعديل منتج'],
      ['products.delete', 'حذف منتج'], ['products.publish', 'نشر وإلغاء نشر'], ['products.view_cost', 'رؤية سعر التكلفة'] ] },
    { key: 'categories', name: 'الأقسام والبراندات', perms: [
      ['categories.manage', 'إدارة الأقسام'], ['brands.manage', 'إدارة البراندات'] ] },
    { key: 'inventory', name: 'المخزون', perms: [
      ['inventory.view', 'عرض الأرصدة'], ['inventory.adjust', 'تسوية المخزون'], ['inventory.transfer', 'التحويل بين الفروع'], ['inventory.receive', 'استلام البضاعة'] ] },
    { key: 'purchases', name: 'المشتريات', perms: [
      ['purchases.create', 'إنشاء أمر شراء'], ['purchases.approve', 'اعتماد أوامر الشراء'], ['purchases.approve_own', 'اعتماد أوامره الذاتية'] ] },
    { key: 'branches', name: 'الفروع والمستخدمون', perms: [
      ['branches.manage', 'إدارة الفروع'], ['users.manage', 'إدارة المستخدمين'], ['roles.manage', 'إدارة الأدوار'], ['branches.access_all', 'الوصول لكل الفروع'] ] },
    { key: 'settings', name: 'الإعدادات', perms: [
      ['settings.view', 'عرض الإعدادات'], ['settings.update', 'تعديل الإعدادات'] ] },
  ],
  categories: [
    { id: 1, ar: 'حقائب نسائية', en: 'Women Bags', active: true, products: 0, children: [
      { id: 11, ar: 'حقائب يد', en: 'Handbags', active: true, products: 42, children: [
        { id: 111, ar: 'توت', en: 'Tote', active: true, products: 18, children: [] },
        { id: 112, ar: 'كروس بودي', en: 'Crossbody', active: true, products: 24, children: [] },
      ] },
      { id: 12, ar: 'حقائب سهرة', en: 'Evening Clutches', active: true, products: 15, children: [] },
      { id: 13, ar: 'حقائب ظهر', en: 'Backpacks', active: false, products: 3, children: [] },
    ] },
    { id: 2, ar: 'حقائب رجالي', en: 'Men Bags', active: true, products: 0, children: [
      { id: 21, ar: 'حقائب لابتوب', en: 'Laptop Bags', active: true, products: 12, children: [] },
      { id: 22, ar: 'حقائب سفر صغيرة', en: 'Weekenders', active: true, products: 7, children: [] },
    ] },
    { id: 3, ar: 'محافظ', en: 'Wallets', active: true, products: 0, children: [
      { id: 31, ar: 'محافظ رجالي', en: 'Men Wallets', active: true, products: 38, children: [
        { id: 311, ar: 'بايفولد', en: 'Bifold', active: true, products: 21, children: [] },
        { id: 312, ar: 'حافظة كروت', en: 'Card Holders', active: true, products: 17, children: [] },
      ] },
      { id: 32, ar: 'محافظ نسائية', en: 'Women Wallets', active: true, products: 26, children: [] },
    ] },
    { id: 4, ar: 'إكسسوارات جلدية', en: 'Leather Goods', active: true, products: 9, children: [] },
  ],
  brands: [
    { id: 1, ar: 'الفريد', en: 'ALFARID', slug: 'alfarid', active: true, products: 124, order: 1 },
    { id: 2, ar: 'الفريد أتيلييه', en: 'ALFARID Atelier', slug: 'alfarid-atelier', active: true, products: 31, order: 2 },
    { id: 3, ar: 'نوار', en: 'Noir Cuir', slug: 'noir-cuir', active: true, products: 18, order: 3 },
    { id: 4, ar: 'سيينا', en: 'Siena Leather', slug: 'siena-leather', active: false, products: 0, order: 4 },
    { id: 5, ar: 'وادي', en: 'Wadi Craft', slug: 'wadi-craft', active: true, products: 11, order: 5 },
  ],
  products: [
    { id: 1, ar: 'حقيبة توت «ليالي» جلد طبيعي', en: 'Layali Leather Tote', code: 'PRD-000184', cat: 'حقائب يد › توت', brand: 'الفريد', status: 'published', channel: 'both', variants: 6, min: 3450, max: 3900, featured: true },
    { id: 2, ar: 'محفظة بايفولد «سند» مطفية', en: 'Sanad Matte Bifold', code: 'PRD-000183', cat: 'محافظ رجالي › بايفولد', brand: 'نوار', status: 'published', channel: 'retail', variants: 3, min: 890, max: 890, featured: false },
    { id: 3, ar: 'كلاتش سهرة «لؤلؤة»', en: 'Lulua Evening Clutch', code: 'PRD-000182', cat: 'حقائب سهرة', brand: 'الفريد أتيلييه', status: 'draft', channel: 'retail', variants: 4, min: null, max: null, featured: false },
    { id: 4, ar: 'حقيبة لابتوب «مسار» ١٥ بوصة', en: 'Masar 15" Laptop Bag', code: 'PRD-000181', cat: 'حقائب لابتوب', brand: 'الفريد', status: 'published', channel: 'both', variants: 2, min: 4200, max: 4650, featured: true },
    { id: 5, ar: 'حافظة كروت «رقيم»', en: 'Raqeem Card Holder', code: 'PRD-000180', cat: 'محافظ رجالي › حافظة كروت', brand: 'وادي', status: 'published', channel: 'wholesale', variants: 5, min: null, max: null, featured: false },
    { id: 6, ar: 'حقيبة كروس بودي «نسيم»', en: 'Naseem Crossbody', code: 'PRD-000179', cat: 'حقائب يد › كروس بودي', brand: 'الفريد', status: 'archived', channel: 'retail', variants: 3, min: 2100, max: 2100, featured: false },
    { id: 7, ar: 'محفظة نسائية «ورد» بسوستة', en: 'Ward Zip Wallet', code: 'PRD-000178', cat: 'محافظ نسائية', brand: 'نوار', status: 'published', channel: 'both', variants: 8, min: 1150, max: 1350, featured: true },
    { id: 8, ar: 'حقيبة ويك إند «رحّال»', en: 'Rahhal Weekender', code: 'PRD-000177', cat: 'حقائب سفر صغيرة', brand: 'الفريد أتيلييه', status: 'draft', channel: 'retail', variants: 2, min: 6800, max: 6800, featured: false },
  ],
  colors: [
    { ar: 'أسود', en: 'Black', hex: '#1d1b1a' },
    { ar: 'كونياك', en: 'Cognac', hex: '#9a5a2c' },
    { ar: 'بيج رملي', en: 'Sand', hex: '#cdb89a' },
    { ar: 'عنابي', en: 'Burgundy', hex: '#6b2430' },
    { ar: 'زيتي', en: 'Olive', hex: '#5d5f3a' },
  ],
};
const fmt = (n) => n == null ? '' : Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
const STATUS = { draft: ['مسودة', 'warn'], published: ['منشور', 'ok'], archived: ['مؤرشف', 'plain'] };
const CHANNEL = { retail: 'تجزئة', wholesale: 'جملة', both: 'تجزئة وجملة' };
Object.assign(window, { DATA, fmt, STATUS, CHANNEL });
