import type { StoreCategory, StoreProduct, StoreProductCard, StoreCatalogFilters } from '@alfarid/api-client';
import { formatStorePrice } from '@alfarid/utils';

export const pick = (en?: string | null, ar?: string | null) => en?.trim() || ar?.trim() || '';
const toNum = (v: unknown) => (v === null || v === undefined || v === '' ? null : Number.isFinite(Number(v)) ? Number(v) : null);

export interface CatalogCardVM {
  id: number; slug: string; name: string; categoryLabel: string;
  price: number | null; priceLabel: string; oldPriceLabel?: string;
  badge?: string; badgeType: 'badge' | 'badge sale';
  img: string | null; inStock: boolean; defaultVariantId?: number;
  discount: number | null;
  variantCount?: number;
  colors: Array<{ label: string; hex: string | null }>;
}

export function toCardVM(c: StoreProductCard): CatalogCardVM {
  const price = toNum(c.price); const old = toNum(c.old_price);
  let badge: string | undefined; let badgeType: CatalogCardVM['badgeType'] = 'badge';
  if (!c.in_stock) { badge = 'SOLD OUT'; badgeType = 'badge sale'; }
  else if (c.discount_percentage) { badge = `-${Math.round(c.discount_percentage)}%`; badgeType = 'badge sale'; }
  else if (c.badge_en || c.badge_ar) { badge = pick(c.badge_en, c.badge_ar).toUpperCase(); }
  else if (c.is_new) { badge = 'NEW'; }
  return {
    id: c.id, slug: c.slug, name: pick(c.name_en, c.name_ar),
    categoryLabel: pick(c.category?.name_en, c.category?.name_ar),
    price, priceLabel: price !== null ? formatStorePrice(price) : 'Unavailable',
    oldPriceLabel: old !== null && price !== null && old > price ? formatStorePrice(old) : undefined,
    badge, badgeType, img: c.image, inStock: c.in_stock,
    defaultVariantId: c.default_variant_id ?? undefined,
    variantCount: c.variant_count,
    discount: c.discount_percentage ?? null,
    colors: (c.colors ?? []).map((x) => ({ label: pick(x.name_en, x.name_ar), hex: x.hex })),
  };
}

export interface VariantVM {
  id: number; sku: string; colorKey: string; colorLabel: string; hex: string | null;
  sizeLabel: string | null; price: number | null; oldPrice: number | null; inStock: boolean;
  images: Array<{ url: string; thumb: string | null }>;
  maxQty: number;
}
export interface ProductDetailVM {
  id: number; slug: string; name: string; categoryLabel: string; categorySlug: string | null;
  desc: string; material: string; warrantyMonths: number | null;
  images: Array<{ url: string; thumb: string | null }>;
  colors: Array<{ key: string; label: string; hex: string | null }>;
  sizes: string[]; variants: VariantVM[]; fallbackPrice: number | null;
}

const sizeOf = (size: string | null, cap: string | null) =>
  size && cap ? `${size} (${cap})` : size || cap || null;

export function toDetailVM(p: StoreProduct): ProductDetailVM {
  const variants: VariantVM[] = p.variants.map((v) => ({
    id: v.id, sku: v.sku,
    colorKey: (pick(v.color_name_en, v.color_name_ar) || v.color_hex || 'default').toLowerCase(),
    colorLabel: pick(v.color_name_en, v.color_name_ar) || 'Default', hex: v.color_hex,
    sizeLabel: sizeOf(v.size, v.capacity), price: toNum(v.price), oldPrice: toNum(v.old_price),
    inStock: v.in_stock, maxQty: Math.max(0, Math.floor(v.max_qty ?? 0)), images: v.images.map((i) => ({ url: i.url, thumb: i.thumb })),
  }));
  const colors = [...new Map(variants.map((v) => [v.colorKey, { key: v.colorKey, label: v.colorLabel, hex: v.hex }])).values()];
  const sizes = [...new Set(variants.map((v) => v.sizeLabel).filter((s): s is string => !!s))];
  const images = p.images.length ? p.images : p.image ? [{ id: 0, url: p.image, thumb: p.image }] : [];
  return {
    id: p.id, slug: p.slug, name: pick(p.name_en, p.name_ar),
    categoryLabel: pick(p.category?.name_en, p.category?.name_ar), categorySlug: p.category?.slug ?? null,
    desc: pick(p.description_en, p.description_ar), material: pick(p.material_en, p.material_ar),
    warrantyMonths: p.warranty_months,
    images: images.map((i) => ({ url: i.url, thumb: i.thumb })),
    colors, sizes, variants, fallbackPrice: toNum(p.price),
  };
}

/** اختيار الـ variant: تطابق اللون والمقاس، وإلا أول variant متاح من نفس اللون، وإلا أول variant */
export function resolveVariant(vm: ProductDetailVM, colorKey: string | null, size: string | null): VariantVM | null {
  const sameColor = vm.variants.filter((v) => !colorKey || v.colorKey === colorKey);
  return sameColor.find((v) => (!size || v.sizeLabel === size))
      ?? sameColor.find((v) => v.inStock) ?? sameColor[0] ?? vm.variants[0] ?? null;
}

export function priceRangeLabel(r: StoreCatalogFilters['price_ranges'][number]) {
  if (r.min === null && r.max !== null) return `Under ${formatStorePrice(Math.ceil(r.max))}`;
  if (r.max === null && r.min !== null) return `${formatStorePrice(Math.floor(r.min))} & Above`;
  return `${formatStorePrice(r.min ?? 0)} – ${formatStorePrice(r.max ?? 0)}`;
}

export function findCategory(tree: StoreCategory[] | undefined, slug: string | null): { node: StoreCategory; path: StoreCategory[] } | null {
  if (!tree || !slug) return null;
  const walk = (nodes: StoreCategory[], path: StoreCategory[]): ReturnType<typeof findCategory> => {
    for (const n of nodes) {
      if (n.slug === slug) return { node: n, path: [...path, n] };
      const r = walk(n.children ?? [], [...path, n]); if (r) return r;
    }
    return null;
  };
  return walk(tree, []);
}
