import { create } from 'zustand';

export interface Category {
  id: number;
  ar: string;
  en: string;
  parent: number | null;
  order: number;
  active: boolean;
  products: number;
  slug: string;
  dar?: string;
  den?: string;
  img?: string | null;
}

export interface Brand {
  id: number;
  ar: string;
  en: string;
  slug: string;
  logo: string;
  order: number;
  active: boolean;
  products: number;
  dar?: string;
  den?: string;
}

export interface Branch {
  id: number;
  name: string;
  code: string;
  type: 'branch' | 'warehouse';
  phone: string;
  email: string;
  city: string;
  address: string;
  active: boolean;
  locations: number;
}

export interface StorageLocation {
  id: number;
  branchId: number;
  name: string;
  code: string;
  type: 'shelf' | 'zone' | 'bin' | 'damaged' | 'receiving';
  active: boolean;
}

export interface Role {
  id: number;
  name: string;
  users: number;
  system?: boolean;
  permissions?: string[];
}

export interface StaffMember {
  id: number;
  name: string;
  email: string;
  phone: string;
  branch: number | null;
  roles: number[];
  active: boolean;
  all?: boolean;
}

export interface VariantItem {
  uid: string;
  color_ar: string;
  color_en: string;
  hex: string;
  size: string;
  capacity: string;
  sku: string;
  barcode: string;
  retail: string;
  wholesale: string;
  cost: string;
  compare: string;
  weight: string;
  reorder: string;
  order: string;
  active: boolean;
}

export interface ProductMediaItem {
  id: string;
  src: string;
  name: string;
  size?: string;
  state: 'pending' | 'uploading' | 'done' | 'failed';
  p?: number;
  variant?: string | null;
}

export interface ProductItem {
  id: number;
  ar: string;
  en: string;
  cat: number | null;
  brand: number | null;
  status: 'draft' | 'published' | 'archived';
  channel: 'retail' | 'wholesale' | 'both';
  variantsCount: number;
  price: [number, number] | null;
  featured: boolean;
  img?: string | null;
  code: string;
  created: number;
  slug: string;
  dar?: string;
  den?: string;
  mat_ar?: string;
  mat_en?: string;
  l?: string;
  w?: string;
  h?: string;
  kg?: string;
  warranty?: string;
  warranty_d?: string;
  origin?: string;
  supplier?: number | null;
  mode?: 'single' | 'multi' | null;
  axes?: {
    colors: { ar: string; en: string; hex: string }[];
    sizes: string[];
    caps: string[];
  };
  variantsList?: VariantItem[];
  media?: {
    general: ProductMediaItem[];
    video: string | null;
  };
}

export interface AdminSettings {
  company: {
    nameAr: string;
    nameEn: string;
    logo: string;
    phone: string;
    email: string;
    address: string;
  };
  tax: {
    enabled: boolean;
    rate: string;
    inclusive: boolean;
    reg: string;
  };
  numbering: {
    prefix: string;
    sep: string;
    year: boolean;
    pad: number;
    next: number;
  };
  returns: {
    type: 'off' | 'unlimited' | 'days';
    days: number;
  };
  purchases: {
    selfApprove: boolean;
  };
}

export const PERM_GROUPS = [
  {
    key: 'products',
    label: 'المنتجات',
    perms: [
      ['products.view', 'عرض المنتجات'],
      ['products.create', 'إضافة منتج'],
      ['products.update', 'تعديل منتج'],
      ['products.delete', 'حذف منتج'],
      ['products.publish', 'نشر وإلغاء نشر'],
      ['products.view_cost', 'عرض سعر التكلفة'],
    ],
  },
  {
    key: 'categories',
    label: 'الأقسام والبراندات',
    perms: [
      ['categories.view', 'عرض الأقسام'],
      ['categories.manage', 'إدارة الأقسام'],
      ['brands.view', 'عرض البراندات'],
      ['brands.manage', 'إدارة البراندات'],
    ],
  },
  {
    key: 'branches',
    label: 'الفروع والمخازن',
    perms: [
      ['branches.view', 'عرض الفروع'],
      ['branches.manage', 'إدارة الفروع'],
      ['locations.manage', 'إدارة مواقع التخزين'],
      ['branches.access_all', 'الوصول لكل الفروع'],
    ],
  },
  {
    key: 'users',
    label: 'المستخدمون والأدوار',
    perms: [
      ['users.view', 'عرض المستخدمين'],
      ['users.manage', 'إدارة المستخدمين'],
      ['roles.manage', 'إدارة الأدوار والصلاحيات'],
    ],
  },
  {
    key: 'purchases',
    label: 'المشتريات',
    perms: [
      ['purchases.view', 'عرض أوامر الشراء'],
      ['purchases.create', 'إنشاء أمر شراء'],
      ['purchases.approve', 'اعتماد أوامر الشراء'],
    ],
  },
  {
    key: 'settings',
    label: 'الإعدادات',
    perms: [
      ['settings.view', 'عرض الإعدادات'],
      ['settings.update', 'تعديل الإعدادات'],
    ],
  },
];

export const COLOR_PRESETS = [
  { ar: 'أسود', en: 'Black', hex: '#151515' },
  { ar: 'جملي', en: 'Camel', hex: '#b98a5a' },
  { ar: 'عنابي', en: 'Burgundy', hex: '#6d1f2c' },
  { ar: 'بيج', en: 'Beige', hex: '#d9c7b0' },
  { ar: 'كحلي', en: 'Navy', hex: '#1f2a44' },
  { ar: 'زيتي', en: 'Olive', hex: '#5b5e3a' },
  { ar: 'وردي فاتح', en: 'Blush', hex: '#e8b8bd' },
];

export const SIZE_PRESETS = ['صغير', 'وسط', 'كبير', 'Mini'];
export const CAP_PRESETS = ['10L', '15L', '20L', '30L'];

interface AdminDataState {
  categories: Category[];
  brands: Brand[];
  branches: Branch[];
  locations: StorageLocation[];
  roles: Role[];
  users: StaffMember[];
  products: ProductItem[];
  settings: AdminSettings;

  // Actions
  addCategory: (cat: Omit<Category, 'id' | 'products'>) => Category;
  updateCategory: (id: number, cat: Partial<Category>) => void;
  deleteCategory: (id: number) => void;
  setCategories: (cats: Category[]) => void;

  addBrand: (brand: Omit<Brand, 'id' | 'products'>) => Brand;
  updateBrand: (id: number, brand: Partial<Brand>) => void;
  deleteBrand: (id: number) => void;

  addBranch: (b: Omit<Branch, 'id' | 'locations'>) => Branch;
  updateBranch: (id: number, b: Partial<Branch>) => void;
  deleteBranch: (id: number) => void;

  addLocation: (loc: Omit<StorageLocation, 'id'>) => StorageLocation;
  updateLocation: (id: number, loc: Partial<StorageLocation>) => void;
  deleteLocation: (id: number) => void;

  addUser: (u: Omit<StaffMember, 'id'>) => StaffMember;
  updateUser: (id: number, u: Partial<StaffMember>) => void;
  deleteUser: (id: number) => void;

  updateRole: (id: number, role: Partial<Role>) => void;

  addProduct: (p: Omit<ProductItem, 'id' | 'created' | 'code'> & { code?: string }) => ProductItem;
  updateProduct: (id: number, p: Partial<ProductItem>) => void;
  deleteProduct: (id: number) => void;

  updateSettings: (s: Partial<AdminSettings>) => void;
}

const initialCategories: Category[] = [
  { id: 1, ar: 'حقائب نسائية', en: 'Women Bags', parent: null, order: 1, active: true, products: 0, slug: 'women-bags' },
  { id: 2, ar: 'حقائب يد', en: 'Handbags', parent: 1, order: 1, active: true, products: 42, slug: 'handbags' },
  { id: 3, ar: 'كروس بودي', en: 'Crossbody', parent: 1, order: 2, active: true, products: 31, slug: 'crossbody' },
  { id: 4, ar: 'ميني كروس', en: 'Mini Crossbody', parent: 3, order: 1, active: true, products: 9, slug: 'mini-crossbody' },
  { id: 5, ar: 'توت باج', en: 'Totes', parent: 1, order: 3, active: true, products: 18, slug: 'totes' },
  { id: 6, ar: 'كلاتش وسهرة', en: 'Clutches', parent: 1, order: 4, active: false, products: 0, slug: 'clutches' },
  { id: 7, ar: 'محافظ', en: 'Wallets', parent: null, order: 2, active: true, products: 0, slug: 'wallets' },
  { id: 8, ar: 'محافظ رجالي', en: 'Men Wallets', parent: 7, order: 1, active: true, products: 27, slug: 'men-wallets' },
  { id: 9, ar: 'محافظ كروت', en: 'Card Holders', parent: 8, order: 1, active: true, products: 12, slug: 'card-holders' },
  { id: 10, ar: 'محافظ نسائية', en: 'Women Wallets', parent: 7, order: 2, active: true, products: 22, slug: 'women-wallets' },
  { id: 11, ar: 'شنط ظهر', en: 'Backpacks', parent: null, order: 3, active: true, products: 0, slug: 'backpacks' },
  { id: 12, ar: 'لابتوب', en: 'Laptop Backpacks', parent: 11, order: 1, active: true, products: 14, slug: 'laptop-backpacks' },
  { id: 13, ar: 'سفر', en: 'Travel', parent: 11, order: 2, active: true, products: 8, slug: 'travel-backpacks' },
  { id: 14, ar: 'شنط سفر', en: 'Luggage', parent: null, order: 4, active: true, products: 6, slug: 'luggage' },
];

const initialBrands: Brand[] = [
  { id: 1, ar: 'الفريد', en: 'ALFARID', slug: 'alfarid', logo: 'AF', order: 1, active: true, products: 120 },
  { id: 2, ar: 'سيدرا', en: 'Sidra Leather', slug: 'sidra-leather', logo: 'SL', order: 2, active: true, products: 38 },
  { id: 3, ar: 'نوماد', en: 'Nomad Gear', slug: 'nomad-gear', logo: 'NG', order: 3, active: true, products: 24 },
  { id: 4, ar: 'لورا', en: 'Lora Paris', slug: 'lora-paris', logo: 'LP', order: 4, active: false, products: 0 },
  { id: 5, ar: 'أطلس', en: 'Atlas', slug: 'atlas', logo: 'AT', order: 5, active: true, products: 11 },
];

const initialBranches: Branch[] = [
  { id: 1, name: 'فرع مصر الجديدة', code: 'HLP-01', type: 'branch', phone: '01001234567', email: 'heliopolis@alfarid.eg', city: 'القاهرة', address: '٤٥ شارع الميرغني', active: true, locations: 6 },
  { id: 2, name: 'فرع الشيخ زايد', code: 'ZYD-02', type: 'branch', phone: '01112345678', email: 'zayed@alfarid.eg', city: 'الجيزة', address: 'أركان بلازا، مبنى ٣', active: true, locations: 4 },
  { id: 3, name: 'المخزن الرئيسي', code: 'WH-MAIN', type: 'warehouse', phone: '0225550101', email: 'warehouse@alfarid.eg', city: 'العبور', address: 'المنطقة الصناعية، بلوك ١٢', active: true, locations: 18 },
  { id: 4, name: 'فرع سموحة', code: 'ALX-03', type: 'branch', phone: '01223456789', email: 'smouha@alfarid.eg', city: 'الإسكندرية', address: 'شارع فوزي معاذ', active: false, locations: 3 },
  { id: 5, name: 'مخزن المرتجعات', code: 'WH-RET', type: 'warehouse', phone: '', email: '', city: 'العبور', address: '', active: true, locations: 5 },
];

const initialLocations: StorageLocation[] = [
  { id: 1, branchId: 1, name: 'رف الحقائب اليدوية A', code: 'A-01', type: 'shelf', active: true },
  { id: 2, branchId: 1, name: 'رف المحافظ B', code: 'B-01', type: 'shelf', active: true },
  { id: 3, branchId: 1, name: 'منطقة العرض الأمامية', code: 'Z-FRONT', type: 'zone', active: true },
  { id: 4, branchId: 1, name: 'خانة الإكسسوارات ٣', code: 'BIN-03', type: 'bin', active: true },
  { id: 5, branchId: 1, name: 'منطقة الاستلام', code: 'RCV-01', type: 'receiving', active: true },
  { id: 6, branchId: 1, name: 'التالف والمرتجع', code: 'DMG-01', type: 'damaged', active: false },
  { id: 7, branchId: 2, name: 'رف العرض الرئيسي', code: 'Z-01', type: 'shelf', active: true },
  { id: 8, branchId: 3, name: 'ممر التخزين العالي W-1', code: 'W-01', type: 'zone', active: true },
];

const initialRoles: Role[] = [
  { id: 1, name: 'مدير النظام', users: 2, system: true, permissions: ['*'] },
  { id: 2, name: 'مدير الكتالوج', users: 3, permissions: ['products.view', 'products.create', 'products.update', 'products.publish', 'categories.view', 'categories.manage', 'brands.view', 'brands.manage'] },
  { id: 3, name: 'مدير فرع', users: 4, permissions: ['branches.view', 'locations.manage', 'products.view', 'purchases.view'] },
  { id: 4, name: 'أمين مخزن', users: 6, permissions: ['branches.view', 'locations.manage', 'products.view'] },
  { id: 5, name: 'مدخل بيانات', users: 2, permissions: ['products.view', 'products.create'] },
];

const initialUsers: StaffMember[] = [
  { id: 1, name: 'أحمد الفريد', email: 'ahmed@alfarid.eg', phone: '01001110001', branch: null, roles: [1], active: true, all: true },
  { id: 2, name: 'منى عبد الحميد', email: 'mona.a@alfarid.eg', phone: '01001110002', branch: 1, roles: [2], active: true },
  { id: 3, name: 'كريم سامي', email: 'karim.s@alfarid.eg', phone: '01001110003', branch: 3, roles: [4], active: true },
  { id: 4, name: 'سارة يوسف', email: 'sara.y@alfarid.eg', phone: '', branch: 2, roles: [3, 5], active: true },
  { id: 5, name: 'عمر خالد', email: 'omar.k@alfarid.eg', phone: '01001110005', branch: 4, roles: [3], active: false },
  { id: 6, name: 'ليلى منصور', email: 'laila.m@alfarid.eg', phone: '01001110006', branch: null, roles: [5], active: true },
];

const initialProducts: ProductItem[] = [
  {
    id: 100,
    ar: 'حقيبة يد جلد طبيعي «ليلى»',
    en: 'Laila Leather Handbag',
    cat: 2,
    brand: 1,
    status: 'published',
    channel: 'both',
    variantsCount: 6,
    price: [2450, 2850],
    featured: true,
    img: null,
    code: 'PRD-024180',
    created: 30,
    slug: 'laila-leather-handbag',
    dar: 'حقيبة يد مصنوعة من جلد طبيعي مدبوغ نباتيًا، بحزام كتف قابل للفصل وجيوب داخلية منظمة.',
    den: 'Vegetable-tanned genuine leather handbag with detachable strap.',
    mat_ar: 'جلد طبيعي',
    mat_en: 'Genuine leather',
    l: '32',
    w: '12',
    h: '24',
    kg: '0.8',
    warranty: '12',
    origin: 'مصر',
  },
  {
    id: 101,
    ar: 'حقيبة كتف «ميرا» كلاسيك',
    en: 'Mira Classic Shoulder',
    cat: 3,
    brand: 1,
    status: 'published',
    channel: 'retail',
    variantsCount: 4,
    price: [1890, 1890],
    featured: false,
    img: null,
    code: 'PRD-024187',
    created: 29,
    slug: 'mira-classic-shoulder',
  },
  {
    id: 102,
    ar: 'محفظة كروت جلد «سليم»',
    en: 'Salim Card Holder',
    cat: 9,
    brand: 2,
    status: 'published',
    channel: 'both',
    variantsCount: 5,
    price: [390, 450],
    featured: false,
    img: null,
    code: 'PRD-024194',
    created: 28,
    slug: 'salim-card-holder',
  },
  {
    id: 103,
    ar: 'شنطة ظهر لابتوب «أوربان»',
    en: 'Urban Laptop Backpack',
    cat: 12,
    brand: 3,
    status: 'draft',
    channel: 'retail',
    variantsCount: 3,
    price: [1650, 1750],
    featured: false,
    img: null,
    code: 'PRD-024201',
    created: 27,
    slug: 'urban-laptop-backpack',
  },
  {
    id: 104,
    ar: 'توت باج «صحارى» كانفاس',
    en: 'Sahara Canvas Tote',
    cat: 5,
    brand: 1,
    status: 'published',
    channel: 'retail',
    variantsCount: 8,
    price: [990, 1150],
    featured: true,
    img: null,
    code: 'PRD-024208',
    created: 26,
    slug: 'sahara-canvas-tote',
  },
  {
    id: 105,
    ar: 'شنطة ظهر رياضية «سبرنت»',
    en: 'Sprint Sport Backpack',
    cat: 13,
    brand: 3,
    status: 'archived',
    channel: 'wholesale',
    variantsCount: 2,
    price: null,
    featured: false,
    img: null,
    code: 'PRD-024215',
    created: 25,
    slug: 'sprint-sport-backpack',
  },
  {
    id: 106,
    ar: 'ميني كروس «نور»',
    en: 'Nour Mini Crossbody',
    cat: 4,
    brand: 4,
    status: 'published',
    channel: 'retail',
    variantsCount: 6,
    price: [1290, 1390],
    featured: true,
    img: null,
    code: 'PRD-024222',
    created: 24,
    slug: 'nour-mini-crossbody',
  },
  {
    id: 107,
    ar: 'محفظة رجالي «آدم» بايفولد',
    en: 'Adam Bifold Wallet',
    cat: 8,
    brand: 1,
    status: 'published',
    channel: 'both',
    variantsCount: 4,
    price: [520, 560],
    featured: false,
    img: null,
    code: 'PRD-024229',
    created: 23,
    slug: 'adam-bifold-wallet',
  },
];

const initialSettings: AdminSettings = {
  company: {
    nameAr: 'الفريد للحقائب والجلود',
    nameEn: 'ALFARID Leather & Bags',
    logo: 'https://cdn.alfarid.eg/brand/logo.png',
    phone: '19876',
    email: 'hello@alfarid.eg',
    address: 'القاهرة، مصر',
  },
  tax: {
    enabled: true,
    rate: '14',
    inclusive: true,
    reg: '512-883-104',
  },
  numbering: {
    prefix: 'INV',
    sep: '-',
    year: true,
    pad: 6,
    next: 1284,
  },
  returns: {
    type: 'days',
    days: 14,
  },
  purchases: {
    selfApprove: false,
  },
};

export const useAdminDataStore = create<AdminDataState>((set, get) => ({
  categories: initialCategories,
  brands: initialBrands,
  branches: initialBranches,
  locations: initialLocations,
  roles: initialRoles,
  users: initialUsers,
  products: initialProducts,
  settings: initialSettings,

  addCategory: (cat) => {
    const newId = Math.max(0, ...get().categories.map((c) => c.id)) + 1;
    const item: Category = { id: newId, products: 0, ...cat };
    set((state) => ({ categories: [...state.categories, item] }));
    return item;
  },
  updateCategory: (id, cat) =>
    set((state) => ({
      categories: state.categories.map((c) => (c.id === id ? { ...c, ...cat } : c)),
    })),
  deleteCategory: (id) =>
    set((state) => ({
      categories: state.categories.filter((c) => c.id !== id),
    })),
  setCategories: (categories) => set({ categories }),

  addBrand: (brand) => {
    const newId = Math.max(0, ...get().brands.map((b) => b.id)) + 1;
    const item: Brand = { id: newId, products: 0, ...brand };
    set((state) => ({ brands: [...state.brands, item] }));
    return item;
  },
  updateBrand: (id, brand) =>
    set((state) => ({
      brands: state.brands.map((b) => (b.id === id ? { ...b, ...brand } : b)),
    })),
  deleteBrand: (id) =>
    set((state) => ({
      brands: state.brands.filter((b) => b.id !== id),
    })),

  addBranch: (b) => {
    const newId = Math.max(0, ...get().branches.map((x) => x.id)) + 1;
    const item: Branch = { id: newId, locations: 0, ...b };
    set((state) => ({ branches: [...state.branches, item] }));
    return item;
  },
  updateBranch: (id, b) =>
    set((state) => ({
      branches: state.branches.map((x) => (x.id === id ? { ...x, ...b } : x)),
    })),
  deleteBranch: (id) =>
    set((state) => ({
      branches: state.branches.filter((x) => x.id !== id),
      locations: state.locations.filter((loc) => loc.branchId !== id),
    })),

  addLocation: (loc) => {
    const newId = Math.max(0, ...get().locations.map((x) => x.id)) + 1;
    const item: StorageLocation = { id: newId, ...loc };
    set((state) => ({
      locations: [...state.locations, item],
      branches: state.branches.map((b) =>
        b.id === loc.branchId ? { ...b, locations: b.locations + 1 } : b
      ),
    }));
    return item;
  },
  updateLocation: (id, loc) =>
    set((state) => ({
      locations: state.locations.map((x) => (x.id === id ? { ...x, ...loc } : x)),
    })),
  deleteLocation: (id) => {
    const target = get().locations.find((l) => l.id === id);
    if (!target) return;
    set((state) => ({
      locations: state.locations.filter((x) => x.id !== id),
      branches: state.branches.map((b) =>
        b.id === target.branchId ? { ...b, locations: Math.max(0, b.locations - 1) } : b
      ),
    }));
  },

  addUser: (u) => {
    const newId = Math.max(0, ...get().users.map((x) => x.id)) + 1;
    const item: StaffMember = { id: newId, ...u };
    set((state) => ({ users: [...state.users, item] }));
    return item;
  },
  updateUser: (id, u) =>
    set((state) => ({
      users: state.users.map((x) => (x.id === id ? { ...x, ...u } : x)),
    })),
  deleteUser: (id) =>
    set((state) => ({
      users: state.users.filter((x) => x.id !== id),
    })),

  updateRole: (id, role) =>
    set((state) => ({
      roles: state.roles.map((r) => (r.id === id ? { ...r, ...role } : r)),
    })),

  addProduct: (p) => {
    const newId = Math.max(100, ...get().products.map((x) => x.id)) + 1;
    const item: ProductItem = {
      ...p,
      id: newId,
      created: Date.now(),
      code: p.code || `PRD-${String(24250 + newId).padStart(6, '0')}`,
    };
    set((state) => ({ products: [item, ...state.products] }));
    return item;
  },
  updateProduct: (id, p) =>
    set((state) => ({
      products: state.products.map((x) => (x.id === id ? { ...x, ...p } : x)),
    })),
  deleteProduct: (id) =>
    set((state) => ({
      products: state.products.filter((x) => x.id !== id),
    })),

  updateSettings: (s) =>
    set((state) => ({
      settings: { ...state.settings, ...s },
    })),
}));

// Utility functions
export const slugify = (s: string) =>
  s
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-');

export const catPath = (list: Category[], id: number): Category[] => {
  const p: Category[] = [];
  let c = list.find((x) => x.id === id);
  while (c) {
    p.unshift(c);
    c = list.find((x) => x.id === c?.parent);
  }
  return p;
};

export const depthOf = (list: Category[], id: number): number => catPath(list, id).length;

export const subtreeHeight = (list: Category[], id: number): number => {
  const kids = list.filter((c) => c.parent === id);
  return 1 + (kids.length ? Math.max(...kids.map((k) => subtreeHeight(list, k.id))) : 0);
};

export const isDescendant = (list: Category[], parentId: number, maybeChildId: number): boolean => {
  let c = list.find((x) => x.id === maybeChildId);
  while (c) {
    if (c.parent === parentId) return true;
    c = list.find((x) => x.id === c?.parent);
  }
  return false;
};

export const totalCategoryProducts = (list: Category[], id: number): number => {
  const target = list.find((c) => c.id === id);
  if (!target) return 0;
  return list
    .filter((c) => c.parent === id)
    .reduce((sum, k) => sum + totalCategoryProducts(list, k.id), target.products);
};
