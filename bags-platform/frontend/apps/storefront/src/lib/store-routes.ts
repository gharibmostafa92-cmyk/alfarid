export const STORE_ROUTES = {
  home: '/',
  shop: (query?: Record<string, string>) => query ? `/shop?${new URLSearchParams(query)}` : '/shop',
  product: (slug: string) => `/products/${encodeURIComponent(slug)}`,
};
