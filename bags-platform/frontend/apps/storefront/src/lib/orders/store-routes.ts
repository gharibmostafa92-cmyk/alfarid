type Query = Record<string, string | number | null | undefined>;
const qs = (q?: Query) => {
  const p = new URLSearchParams();
  Object.entries(q ?? {}).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') p.set(k, String(v)); });
  const s = p.toString(); return s ? `?${s}` : '';
};

export const STORE_ROUTES = {
  home: '/',
  shop: (q?: Query) => `/shop${qs(q)}`,
  category: (slug: string) => `/categories/${encodeURIComponent(slug)}`,
  product: (slug: string) => `/products/${encodeURIComponent(slug)}`,
};
