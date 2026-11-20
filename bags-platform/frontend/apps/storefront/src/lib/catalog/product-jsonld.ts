import type { StoreProduct } from '@alfarid/api-client';
import { storeImageUrl } from './store-image';
import { toDetailVM } from './catalog-mappers';

export function productJsonLd(product: StoreProduct, origin: string, variantId?: string): Record<string, unknown> | null {
  const base = new URL(origin);
  if (base.protocol !== 'https:' || base.origin !== origin.replace(/\/$/, '')) return null;
  const url = new URL('/products/' + encodeURIComponent(product.slug), base).href;
  const variant = variantId
    ? product.variants.find((item) => String(item.id) === variantId)
    : product.variants.length === 1 ? product.variants[0] : undefined;
  const image = storeImageUrl(
    variantId && product.variants.length > 1
      ? variant?.images[0]?.url
      : product.image || product.images[0]?.url,
  );
  const data: Record<string, unknown> = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: product.name_ar || product.name_en,
    url,
  };
  if (image) data.image = new URL(image, base).href;
  const description = product.description_ar || product.description_en;
  if (description) data.description = description;
  if (variant) {
    const price = variant.price === null || variant.price === '' ? NaN : Number(variant.price);
    if (Number.isFinite(price) && price > 0) {
      data.sku = variant.sku;
      data.offers = {
        '@type': 'Offer',
        url: variantId ? url + '?variant=' + encodeURIComponent(variantId) : url,
        price,
        priceCurrency: 'EGP',
        availability: variant.in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
      };
    }
  }
  return data;
}

export function productGroupJsonLd(product: StoreProduct, origin: string): Record<string, unknown> | null {
  if (product.variants.length < 2) return null;
  const base = new URL(origin);
  if (base.protocol !== 'https:' || base.origin !== origin.replace(/\/$/, '')) return null;
  const detail = toDetailVM(product);
  const colors = new Set(detail.variants.map((variant) => variant.colorKey));
  const sizes = new Set(detail.variants.map((variant) => variant.sizeLabel));
  if ((colors.size > 1 && colors.has('default')) || (sizes.size > 1 && sizes.has(null))) return null;
  const variesBy = [
    ...(colors.size > 1 ? ['https://schema.org/color'] : []),
    ...(sizes.size > 1 && !sizes.has(null) ? ['https://schema.org/size'] : []),
  ];
  const combinations = new Set(detail.variants.map((variant) => JSON.stringify([variant.colorKey, variant.sizeLabel])));
  if (!variesBy.length || combinations.size !== detail.variants.length) return null;
  const productUrl = new URL('/products/' + encodeURIComponent(product.slug), base).href;
  const name = product.name_ar || product.name_en || '';
  const hasVariant = product.variants.map((variant, index) => {
    const view = detail.variants[index];
    const url = productUrl + '?variant=' + encodeURIComponent(String(variant.id));
    const entry: Record<string, unknown> = {
      '@type': 'Product',
      name: [name, view.colorLabel !== 'Default' ? view.colorLabel : null, view.sizeLabel].filter(Boolean).join(' - '),
      sku: variant.sku,
      url,
    };
    if (colors.size > 1) entry.color = view.colorLabel;
    if (sizes.size > 1 && view.sizeLabel) entry.size = view.sizeLabel;
    const image = storeImageUrl(variant.images[0]?.url);
    if (image) entry.image = new URL(image, base).href;
    const price = variant.price === null || variant.price === '' ? NaN : Number(variant.price);
    if (Number.isFinite(price) && price > 0) entry.offers = {
      '@type': 'Offer', url, price, priceCurrency: 'EGP',
      availability: variant.in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
    };
    return entry;
  });
  return {
    '@context': 'https://schema.org', '@type': 'ProductGroup',
    name, productGroupID: String(product.id), variesBy, hasVariant,
  };
}

export function breadcrumbJsonLd(
  items: Array<{ name: string; path: string }>,
  origin: string,
): Record<string, unknown> | null {
  if (!items.length) return null;
  const base = new URL(origin);
  if (base.protocol !== 'https:' || base.origin !== origin.replace(/\/$/, '')) return null;
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((item, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      name: item.name,
      item: new URL(item.path, base).href,
    })),
  };
}
