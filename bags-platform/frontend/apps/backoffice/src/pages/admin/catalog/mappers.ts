import type {
  AdminBrand,
  AdminMedia,
  AdminProduct,
  BrandPayload,
  ProductPayload,
  ProductStatus,
  VariantPayload,
} from '@alfarid/api-client';

// الملف الوحيد اللي يعرف الشكلين: شكل الـ UI (الـ mock) وشكل الـ API (قراءة وكتابة).
//
// ملاحظة على شكل الـ VariantResource الحقيقي:
// - اللون بيرجع nested تحت `color: { name_ar, name_en, hex }` مش flat.
// - صور المتغير بترجع تحت مفتاح `images` (MediaResource) مش `media`.
// الدوال هنا بتقبل الشكلين وبتوحّدهم على الـ flat/admin types.

export interface UiColor { ar: string; en: string; hex: string }

export interface UiVariantRow {
  _id: string;
  serverId?: number;                    // ضيفه علشان تعرف ده update ولا create
  color: UiColor | null;
  size: string; capacity: string;
  sku: string; barcode: string;
  retail: string; wholesale: string; cost: string; compare: string;
  weight: string; reorder: string;
  order: number;                        // 1-based زي الـ mock
  status: 'active' | 'inactive';
}

export interface UiBasic {
  ar: string; en: string;
  category: number | null; brand: number | null;
  channel: 'retail' | 'wholesale' | 'both';
  slug: string;
  desc_ar: string; desc_en: string;
  mat_ar: string; mat_en: string;
  l: string; w: string; h: string;
  kg: string; warranty: string; warranty_details: string;
  origin: string; featured: boolean; supplier: number | null;
}

export type UiMediaStatus = 'pending' | 'uploading' | 'done' | 'failed';

export interface UiMediaItem {
  id: string; serverId?: number;
  variantId?: number;
  name: string; size?: string;
  status: UiMediaStatus; url?: string;
  pct?: number; message?: string;
}

export interface UiProductState {
  status: ProductStatus | null; code: string | null;
  basic: UiBasic;
  mode: 'single' | 'matrix';
  axes: { colors: UiColor[]; sizes: string[]; capacities: string[] };
  rows: UiVariantRow[];
  media: { general: UiMediaItem[]; byVariant: Record<string, UiMediaItem[]>; video: UiMediaItem | null };
}

export interface UiBrand {
  id?: number;
  ar: string; en: string; slug: string;
  desc_ar: string; desc_en: string;
  order: number; status: 'active' | 'inactive';
  logo: string | null;                  // رابط المعاينة
  products?: number;
}

export interface UiBrandForm extends Omit<UiBrand, 'id' | 'logo' | 'products'> {
  logoFile?: File | null;
  removeLogo?: boolean;
}

let seq = 0;
function uid(): string {
  seq += 1;
  return `row-${Date.now().toString(36)}-${seq}`;
}

function str(v: unknown): string {
  if (v === null || v === undefined) return '';
  return String(v);
}

function nullIfEmpty(v: string): string | null {
  const t = v.trim();
  return t === '' ? null : v;
}

// ---------- البراند ----------

export function brandFromApi(b: AdminBrand): UiBrand {
  return {
    id: b.id,
    ar: b.name_ar, en: b.name_en, slug: b.slug,
    desc_ar: b.description_ar ?? '', desc_en: b.description_en ?? '',
    order: b.sort_order,
    status: b.is_active ? 'active' : 'inactive',
    logo: b.logo_url,
    products: b.products_count,
  };
}

export function brandToPayload(u: UiBrandForm): BrandPayload {
  const p: BrandPayload = {
    name_ar: u.ar,
    name_en: u.en,
    slug: u.slug.trim() === '' ? null : u.slug,   // فاضي = null والسيرفر يولّده
    description_ar: u.desc_ar,
    description_en: u.desc_en,
    sort_order: u.order,
    is_active: u.status === 'active',
  };
  if (u.logoFile) p.logo = u.logoFile;
  else if (u.removeLogo) p.remove_logo = true;    // شال اللوجو من غير رفع جديد
  return p;                                       // من غير لوجو جديد ماتبعتش logo خالص
}

// ---------- المنتج: قراءة ----------

interface ApiVariantLike {
  id: number; sku: string; barcode: string | null;
  color_name_ar?: string | null; color_name_en?: string | null; color_hex?: string | null;
  color?: { name_ar?: string | null; name_en?: string | null; hex?: string | null } | null;
  size: string | null; capacity: string | null;
  cost_price?: string | number | null;
  retail_price: string | number | null; wholesale_price: string | number | null; compare_at_price: string | number | null;
  reorder_level: number | null; weight_kg: string | number | null;
  sort_order: number; is_active: boolean;
  media?: AdminMedia[]; images?: AdminMedia[];
}

function variantMediaOf(v: ApiVariantLike): AdminMedia[] {
  return v.media ?? v.images ?? [];
}

export function productFromApi(
  product: AdminProduct,
  media: AdminMedia[] = [],
  opts: { canViewCost: boolean },
): UiProductState {
  const apiVariants = (product.variants ?? []) as ApiVariantLike[];
  const sorted = [...apiVariants].sort((a, b) => a.sort_order - b.sort_order);

  const rows: UiVariantRow[] = sorted.map((v, i) => {
    const colorEn = v.color_name_en ?? v.color?.name_en ?? null;
    const colorAr = v.color_name_ar ?? v.color?.name_ar ?? null;
    const colorHex = v.color_hex ?? v.color?.hex ?? null;
    return {
      _id: `srv-${v.id}`,
      serverId: v.id,
      color: colorEn || colorAr || colorHex
        ? { ar: colorAr ?? '', en: colorEn ?? '', hex: colorHex ?? '' }
        : null,
      size: v.size ?? '', capacity: v.capacity ?? '',
      sku: v.sku ?? '', barcode: v.barcode ?? '',
      retail: str(v.retail_price), wholesale: str(v.wholesale_price),
      cost: opts.canViewCost ? str(v.cost_price ?? '') : '',
      compare: str(v.compare_at_price),
      weight: str(v.weight_kg), reorder: v.reorder_level === null || v.reorder_level === undefined ? '' : String(v.reorder_level),
      order: v.sort_order ?? i,
      status: v.is_active ? 'active' : 'inactive',
    };
  });

  // mode عند التحميل: متغير واحد من غير لون ولا مقاس ولا سعة = single
  const single = rows.length === 1 && rows[0].color === null && rows[0].size === '' && rows[0].capacity === '';
  const mode: 'single' | 'matrix' = single ? 'single' : 'matrix';

  // axes من القيم الفريدة: الألوان unique بـ color_name_en من غير حساسية حروف
  const seenColors = new Set<string>();
  const colors: UiColor[] = [];
  const sizes: string[] = [];
  const capacities: string[] = [];
  rows.forEach(r => {
    if (r.color && r.color.en.trim() !== '') {
      const k = r.color.en.trim().toLowerCase();
      if (!seenColors.has(k)) { seenColors.add(k); colors.push(r.color); }
    }
    if (r.size.trim() !== '' && !sizes.some(s => s.trim().toLowerCase() === r.size.trim().toLowerCase())) sizes.push(r.size);
    if (r.capacity.trim() !== '' && !capacities.some(c => c.trim().toLowerCase() === r.capacity.trim().toLowerCase())) capacities.push(r.capacity);
  });

  const byId = new Map(sorted.map(v => [v.id, v] as const));
  const general: UiMediaItem[] = media
    .filter(m => m.collection === 'images' && (m.variant_id === null || m.variant_id === undefined))
    .sort((a, b) => a.order - b.order)
    .map(m => ({
      id: `srv-${m.id}`, serverId: m.id,
      name: m.url.split('/').pop()?.split('?')[0] || `image-${m.id}`,
      status: 'done' as const, url: m.thumb_url || m.url,
    }));

  const byVariant: Record<string, UiMediaItem[]> = {};
  media
    .filter(m => m.collection === 'images' && m.variant_id !== null && m.variant_id !== undefined)
    .sort((a, b) => a.order - b.order)
    .forEach(m => {
      const v = byId.get(m.variant_id as number);
      const colorEn = v ? (v.color_name_en ?? v.color?.name_en ?? '') : '';
      if (!colorEn) return;
      (byVariant[colorEn] = byVariant[colorEn] || []).push({
        id: `srv-${m.id}`, serverId: m.id, variantId: m.variant_id ?? undefined,
        name: m.url.split('/').pop()?.split('?')[0] || `image-${m.id}`,
        status: 'done' as const, url: m.thumb_url || m.url,
      });
    });

  const videoSrc = media.find(m => m.collection === 'video') ?? null;
  const d = product.dimensions ?? { length_cm: null, width_cm: null, height_cm: null };

  return {
    status: product.status, code: product.code,
    basic: {
      ar: product.name_ar, en: product.name_en,
      category: product.category?.id ?? null,
      brand: product.brand?.id ?? null,
      channel: product.sales_channel,
      slug: product.slug,
      desc_ar: product.description_ar ?? '', desc_en: product.description_en ?? '',
      mat_ar: product.material_ar ?? '', mat_en: product.material_en ?? '',
      l: str(d.length_cm), w: str(d.width_cm), h: str(d.height_cm),
      kg: str(product.weight_kg),
      warranty: product.warranty_months === null || product.warranty_months === undefined ? '' : String(product.warranty_months),
      warranty_details: product.warranty_details ?? '',
      origin: product.country_of_origin ?? '',
      featured: product.is_featured,
      supplier: product.supplier_id,
    },
    mode,
    axes: { colors, sizes, capacities },
    rows,
    media: {
      general, byVariant,
      video: videoSrc ? {
        id: `srv-${videoSrc.id}`, serverId: videoSrc.id,
        name: videoSrc.url.split('/').pop()?.split('?')[0] || `video-${videoSrc.id}`,
        status: 'done' as const, url: videoSrc.url,
      } : null,
    },
  };
}

// ---------- المنتج: كتابة ----------

export function productToPayload(basic: UiBasic): ProductPayload {
  const numOrNull = (v: string): string | null => (v.trim() === '' ? null : v);
  return {
    name_ar: basic.ar,
    name_en: basic.en,
    slug: basic.slug.trim() === '' ? null : basic.slug,
    category_id: basic.category ?? undefined,
    description_ar: basic.desc_ar, description_en: basic.desc_en,
    material_ar: basic.mat_ar, material_en: basic.mat_en,
    length_cm: numOrNull(basic.l), width_cm: numOrNull(basic.w), height_cm: numOrNull(basic.h),
    weight_kg: numOrNull(basic.kg),
    warranty_months: basic.warranty.trim() === '' ? null : parseInt(basic.warranty, 10),
    warranty_details: basic.warranty_details,
    country_of_origin: basic.origin,
    sales_channel: basic.channel,
    is_featured: basic.featured,
  };
}

export function variantToPayload(
  row: UiVariantRow & { sort?: number },
  opts: { canViewCost: boolean; isCreate: boolean },
): VariantPayload {
  const p: VariantPayload = {};

  // sku: فاضي في الإنشاء = ماتبعتوش (السيرفر يولّده). وفي التعديل ماتبعتش فاضي أبدًا.
  if (row.sku.trim() !== '') p.sku = row.sku;
  if (row.barcode.trim() !== '') p.barcode = row.barcode;

  if (row.color) {
    if (row.color.ar.trim() !== '') p.color_name_ar = row.color.ar;
    else if (!opts.isCreate) p.color_name_ar = null;
    if (row.color.en.trim() !== '') p.color_name_en = row.color.en;
    else if (!opts.isCreate) p.color_name_en = null;
    if (row.color.hex.trim() !== '') p.color_hex = row.color.hex;
    else if (!opts.isCreate) p.color_hex = null;
  } else if (!opts.isCreate) {
    p.color_name_ar = null; p.color_name_en = null; p.color_hex = null;
  }

  p.size = nullIfEmpty(row.size);
  p.capacity = nullIfEmpty(row.capacity);

  // التكلفة: لو معندوش الصلاحية ماتحطش cost_price خالص (الـ backend بيشيله أصلًا بس ماتعتمدش على ده)
  if (opts.canViewCost) p.cost_price = nullIfEmpty(row.cost);

  p.retail_price = nullIfEmpty(row.retail);
  p.wholesale_price = nullIfEmpty(row.wholesale);
  p.compare_at_price = nullIfEmpty(row.compare);

  p.reorder_level = row.reorder.trim() === '' ? null : parseInt(row.reorder, 10);
  p.weight_kg = nullIfEmpty(row.weight);
  p.sort_order = typeof row.sort === 'number' ? row.sort : row.order;
  p.is_active = row.status === 'active';
  return p;
}

// ---------- أخطاء variants.* العكسية ----------

// مفتاح السيرفر -> حقل الـ UI
export const VARIANT_SERVER_KEY_TO_UI: Record<string, string> = {
  retail_price: 'retail',
  wholesale_price: 'wholesale',
  cost_price: 'cost',
  compare_at_price: 'compare',
  color_name_en: 'color',
  color_name_ar: 'color',
  color_hex: 'color',
  size: 'size',
  capacity: 'capacity',
  sku: 'sku',
  barcode: 'barcode',
  reorder_level: 'reorder',
  weight_kg: 'weight',
  sort_order: 'order',
  is_active: 'status',
};

export function splitVariantErrorKey(serverKey: string): { index: number; uiField: string } | null {
  const m = /^variants\.(\d+)\.(.+)$/.exec(serverKey);
  if (!m) return null;
  const field = m[2];
  return { index: Number(m[1]), uiField: VARIANT_SERVER_KEY_TO_UI[field] ?? field };
}

// مفاتيح الـ basic العكسية
export const BASIC_SERVER_KEY_TO_UI: Record<string, string> = {
  name_ar: 'ar', name_en: 'en', slug: 'slug',
  category_id: 'category', brand_id: 'brand', supplier_id: 'supplier',
  sales_channel: 'channel',
  description_ar: 'desc_ar', description_en: 'desc_en',
  material_ar: 'mat_ar', material_en: 'mat_en',
  length_cm: 'l', width_cm: 'w', height_cm: 'h',
  weight_kg: 'kg', warranty_months: 'warranty', warranty_details: 'warranty_details',
  country_of_origin: 'origin', is_featured: 'featured',
};

export function blankUiBasic(): UiBasic {
  return {
    ar: '', en: '', category: null, brand: null, channel: 'retail', slug: '',
    desc_ar: '', desc_en: '', mat_ar: '', mat_en: '',
    l: '', w: '', h: '', kg: '', warranty: '', warranty_details: '', origin: '',
    featured: false, supplier: null,
  };
}

export function emptyUiRow(color: UiColor | null = null, size = '', capacity = '', order = 0): UiVariantRow {
  return {
    _id: uid(), color, size, capacity,
    sku: '', barcode: '', retail: '', wholesale: '', cost: '', compare: '',
    weight: '', reorder: '', order: order + 1, status: 'active',
  };
}
