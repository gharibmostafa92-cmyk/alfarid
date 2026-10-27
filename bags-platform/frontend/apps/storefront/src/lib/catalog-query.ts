import type { StoreOccasion, StoreProductListParams, StoreSort } from '@alfarid/api-client';

export interface CatalogFilterState {
  category: string | null; color: string | null;
  priceMin: number | null; priceMax: number | null;
  occasions: StoreOccasion[]; sort: StoreSort; search: string; inStock: boolean;
}
const SORTS: StoreSort[] = ['featured', 'newest', 'best_selling', 'price_asc', 'price_desc', 'name'];
const OCC: StoreOccasion[] = ['casual', 'work', 'weekend', 'evening'];
const num = (v: string | null) => (v !== null && v !== '' && Number.isFinite(Number(v)) ? Number(v) : null);

export function parseFilters(sp: URLSearchParams): CatalogFilterState {
  const rawSort = (sp.get('sort') ?? '').replace('-', '_') as StoreSort; // يقبل price-asc كمان
  return {
    category: sp.get('category') || null,
    color: sp.get('color')?.toLowerCase() || null,
    priceMin: num(sp.get('price_min')), priceMax: num(sp.get('price_max')),
    occasions: (sp.get('occasions') ?? '').split(',').filter((o): o is StoreOccasion => OCC.includes(o as StoreOccasion)),
    sort: SORTS.includes(rawSort) ? rawSort : 'featured',
    search: sp.get('q') ?? '', inStock: sp.get('in_stock') === '1',
  };
}

export function buildSearch(state: CatalogFilterState, extra: Record<string, string | null> = {}): string {
  const p = new URLSearchParams();
  if (state.category) p.set('category', state.category);
  if (state.color) p.set('color', state.color);
  if (state.priceMin !== null) p.set('price_min', String(state.priceMin));
  if (state.priceMax !== null) p.set('price_max', String(state.priceMax));
  if (state.occasions.length) p.set('occasions', state.occasions.join(','));
  if (state.sort !== 'featured') p.set('sort', state.sort);
  if (state.search) p.set('q', state.search);
  if (state.inStock) p.set('in_stock', '1');
  Object.entries(extra).forEach(([k, v]) => { if (v) p.set(k, v); });
  const s = p.toString(); return s ? `?${s}` : '';
}

export function toApiParams(s: CatalogFilterState, perPage = 24): StoreProductListParams {
  return {
    category: s.category ?? undefined, color: s.color ?? undefined,
    price_min: s.priceMin ?? undefined, price_max: s.priceMax ?? undefined,
    occasions: s.occasions.length ? s.occasions : undefined,
    search: s.search || undefined, in_stock: s.inStock ? 1 : undefined,
    sort: s.sort, per_page: perPage,
  };
}

/** الـ facets ما بتعتمدش على اللون والسعر */
export const toFacetParams = (s: CatalogFilterState): StoreProductListParams => ({
  category: s.category ?? undefined, occasions: s.occasions.length ? s.occasions : undefined,
  search: s.search || undefined, in_stock: s.inStock ? 1 : undefined,
});
