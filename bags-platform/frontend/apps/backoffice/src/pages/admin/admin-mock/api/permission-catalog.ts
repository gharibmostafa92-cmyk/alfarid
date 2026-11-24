export interface UiModule { module: string; label: string; icon: string; prefixes: string[] }

export const UI_MODULES: UiModule[] = [
  { module: 'inventory', label: 'المخزون والمستودعات', icon: 'box', prefixes: ['inventory','warehouses','transfers','stocktakes'] },
  { module: 'products', label: 'المنتجات والتصنيفات', icon: 'tag', prefixes: ['products','categories','brands'] },
  { module: 'orders', label: 'المبيعات والطلبات', icon: 'cart', prefixes: ['orders','sales','quotations'] },
  { module: 'customers', label: 'العملاء والتسعير', icon: 'users', prefixes: ['customers','customer_groups','price_lists','pricing'] },
  { module: 'pos', label: 'نقطة البيع (POS)', icon: 'pos', prefixes: ['pos', 'pos_shifts'] },
  { module: 'purchases', label: 'المشتريات والموردين', icon: 'truck', prefixes: ['purchases','purchase_requests','suppliers','supplier_invoices','supplier_payments'] },
  { module: 'returns', label: 'المرتجعات', icon: 'undo', prefixes: ['returns'] },
  { module: 'shipping', label: 'الشحن والعروض', icon: 'truck', prefixes: ['shipping','promotions','coupons'] },
  { module: 'branches', label: 'الفروع والمواقع', icon: 'pin', prefixes: ['branches','locations'] },
  { module: 'finance', label: 'المالية والخزائن', icon: 'wallet', prefixes: ['payments','payment_notices','treasuries','expenses'] },
  { module: 'users', label: 'المستخدمون والأدوار', icon: 'users', prefixes: ['users','roles','permissions'] },
  { module: 'settings', label: 'الإعدادات والتقارير', icon: 'sliders', prefixes: ['settings','reports','notifications'] },
  { module: 'ai', label: 'المساعد الذكي', icon: 'sparkles', prefixes: ['ai_agent'] },
];
export const OTHER_MODULE: UiModule = { module: 'other', label: 'صلاحيات أخرى', icon: 'shield', prefixes: [] };

// These permissions are shared with the backoffice and also checked by the POS.
const POS_SHARED_PERMISSIONS = new Set([
  'inventory.view',
  'orders.view',
  'orders.update',
  'stocktakes.view',
  'stocktakes.update',
  'stocktakes.approve',
  'transfers.view',
  'transfers.create',
  'transfers.receive',
  'transfers.approve_shortage',
]);

const ACTION: Record<string, string> = {
  view: 'عرض', create: 'إضافة', update: 'تعديل', delete: 'حذف', manage: 'إدارة كاملة',
  approve: 'اعتماد', reject: 'رفض', export: 'تصدير', import: 'استيراد', publish: 'نشر',
  receive: 'استلام', ship: 'شحن', adjust: 'تسوية', refund: 'رد أموال', post: 'ترحيل',
  cancel: 'إلغاء', allocate: 'حجز', access: 'دخول', price: 'تسعير', payment: 'تعديل حالة الدفع',
  view_cost: 'عرض التكلفة', all_branches: 'الوصول لكل الفروع', discount: 'خصم يدوي',
  use: 'استخدام', execute: 'تنفيذ', 'logs.view': 'عرض سجلات المساعد',
  override_price: 'تعديل السعر عند البيع',
};
const RESOURCE: Record<string, string> = {
  users: 'الموظفين', roles: 'الأدوار', permissions: 'الصلاحيات', settings: 'الإعدادات',
  products: 'المنتجات', categories: 'التصنيفات', brands: 'العلامات التجارية',
  inventory: 'المخزون', warehouses: 'المستودعات', transfers: 'التحويلات', stocktakes: 'الجرد',
  orders: 'الطلبات', sales: 'المبيعات', quotations: 'عروض الأسعار', customers: 'العملاء',
  customer_groups: 'مجموعات العملاء', price_lists: 'قوائم الأسعار', pricing: 'التسعير',
  purchases: 'المشتريات', purchase_requests: 'طلبات الشراء', suppliers: 'الموردين',
  supplier_invoices: 'فواتير الموردين', supplier_payments: 'مدفوعات الموردين',
  returns: 'المرتجعات', shipping: 'الشحن', promotions: 'العروض', coupons: 'الكوبونات',
  store: 'إدارة المتجر',
  branches: 'الفروع', locations: 'المواقع', payments: 'المدفوعات', payment_notices: 'إشعارات الدفع',
  reports: 'التقارير', notifications: 'الإشعارات', pos: 'نقطة البيع', ai_agent: 'المساعد الذكي',
};
/** Custom human-readable labels for permission keys with irregular names. */
const OVERRIDES: Record<string, string> = {
  'ai_agent.use': 'استخدام المساعد الذكي',
  'ai_agent.execute': 'تنفيذ عمليات عبر المساعد الذكي',
  'ai_agent.logs.view': 'عرض محادثات المساعد للموظفين',
  'ai_agent.settings': 'تعديل إعدادات المساعد الذكي',
  'pos.access': 'الدخول إلى نقطة البيع',
  'pos.sales.view': 'عرض مبيعات نقطة البيع',
  'pos.sales.create': 'البيع من نقطة البيع',
  'pos.sales.refund': 'استرجاع مبيعات نقطة البيع',
  'pos.orders.cancel': 'إلغاء طلبات العملاء في نقطة البيع',
  'pos.held.view_all': 'عرض الفواتير المعلقة لكل موظفي الفرع',
  'pos.held.delete_any': 'حذف الفواتير المعلقة لموظفين آخرين في الفرع',
  'pos.override_opening_float': 'تجاوز حد عهدة افتتاح الوردية',
  'pos.override_price_below_cost': 'تعديل سعر البيع لأقل من التكلفة',
  'pos_shifts.view': 'عرض ورديات نقطة البيع',
  'pos_shifts.approve': 'مراجعة واعتماد ورديات نقطة البيع',
  'pos_shifts.view_expected': 'عرض النقدية المتوقعة في الوردية',
  'pos_shifts.force_close': 'إغلاق وردية نقطة البيع إجباريًا',
  'transfers.approve_shortage': 'اعتماد العجز عند استلام تحويل مخزون',
  'customers.documents.view': 'عرض مستندات العملاء',
};
const SENSITIVE = new Set<string>([
  'products.view_cost','inventory.view_cost','inventory.adjust','inventory.all_branches',
  'pos.discount','pos.override_price','pos.sales.refund','pos.orders.cancel','orders.payment',
  'purchases.approve','purchase_requests.approve','returns.approve','returns.refund',
  'supplier_invoices.post','supplier_invoices.cancel','supplier_payments.create',
  'ai_agent.execute','ai_agent.logs.view','ai_agent.settings',
  'payment_notices.approve','settings.update','settings.manage','users.manage',
  'roles.create','roles.update','roles.delete','roles.manage','price_lists.import',
]);

export function permLabel(key: string): string {
  if (OVERRIDES[key]) return OVERRIDES[key];
  const [res, ...rest] = key.split('.');
  const act = rest.join('.');
  if (ACTION[act] && RESOURCE[res]) return `${ACTION[act]} ${RESOURCE[res]}`;
  return key; // Fall back to raw permission key
}
export const isSensitive = (key: string) => SENSITIVE.has(key) || key.endsWith('.manage');

export interface UiPermGroup { module: string; label: string; icon: string; keys: string;
  perms: [string, string, boolean][] }

/** Adapts GET /permissions response into the PermissionGroup structure required by UI roles editor. */
export function buildPermissionGroups(api: Record<string, { name: string }[]>): UiPermGroup[] {
  const byModule = new Map<string, string[]>();
  for (const [prefix, permissions] of Object.entries(api)) {
    const defaultModule = UI_MODULES.find(m => m.prefixes.includes(prefix)) ?? OTHER_MODULE;
    for (const { name } of permissions) {
      const module = POS_SHARED_PERMISSIONS.has(name) ? 'pos' : defaultModule.module;
      const names = byModule.get(module) ?? [];
      names.push(name);
      byModule.set(module, names);
    }
  }
  const seen = new Set<string>();
  return [...UI_MODULES, OTHER_MODULE]
    .filter(m => byModule.has(m.module))
    .map(m => {
      const names = [...new Set(byModule.get(m.module)!)].filter(n => !seen.has(n)).sort();
      names.forEach(n => seen.add(n));
      const prefixes = [...new Set(names.map(n => n.split('.')[0]))];
      return { module: m.module, label: m.label, icon: m.icon, keys: prefixes.join(' · '),
        perms: names.map(n => [n, permLabel(n), isSensitive(n)] as [string, string, boolean]) };
    }).filter(g => g.perms.length > 0);
}
