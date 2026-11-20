import type { MetadataRoute } from 'next';
import type { StoreCategory } from '@alfarid/api-client';
import { getPublicCategories, getPublicProducts } from '@/lib/catalog/server-product';

export const dynamic = 'force-dynamic';

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const configured = process.env.STOREFRONT_PUBLIC_URL;
  if (!configured) return [];
  const origin = new URL(configured);
  if (origin.protocol !== 'https:' || origin.origin !== configured.replace(/\/$/, '')) {
    throw new Error('STOREFRONT_PUBLIC_URL must be an HTTPS origin');
  }
  const entries: MetadataRoute.Sitemap = [
    { url: origin.origin + '/' },
    { url: origin.origin + '/shop' },
    { url: origin.origin + '/bags-wholesale' },
    { url: origin.origin + '/guides/laptop-bag-fit' },
    { url: origin.origin + '/guides/crossbody-capacity' },
    { url: origin.origin + '/guides/tote-vs-backpack' },
  ];
  const addCategories = (categories: StoreCategory[]) => {
    for (const category of categories) {
      if (category.products_count > 0) entries.push({ url: origin.origin + '/categories/' + encodeURIComponent(category.slug) });
      addCategories(category.children ?? []);
    }
  };
  addCategories(await getPublicCategories());
  let page = 1;
  while (true) {
    const result = await getPublicProducts('', page);
    for (const product of result.data) {
      entries.push({ url: origin.origin + '/products/' + encodeURIComponent(product.slug) });
      if (entries.length > 50000) throw new Error('Sitemap exceeds 50000 URLs');
    }
    if (page >= result.meta.last_page) break;
    page++;
  }
  return entries;
}
