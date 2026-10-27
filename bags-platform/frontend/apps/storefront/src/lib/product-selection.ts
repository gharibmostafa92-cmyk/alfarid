import type { ProductDetailVM } from './catalog-mappers';

export function initialProductSelection(detail: ProductDetailVM, requestedColor?: string | null, requestedSize?: string | null) {
  const candidates = detail.variants.some((variant) => variant.inStock && variant.price !== null)
    ? detail.variants.filter((variant) => variant.inStock && variant.price !== null) : detail.variants;
  const filterColor = detail.colors.find((color) => color.key === requestedColor?.trim().toLowerCase())?.key ?? null;
  const sizes = [...new Set(candidates.filter((variant) => !filterColor || variant.colorKey === filterColor).map((variant) => variant.sizeLabel))];
  const size = detail.sizes.find((option) => option.toLowerCase() === requestedSize?.trim().toLowerCase())
    ?? (sizes.length === 1 ? sizes[0] : null);
  const colors = [...new Set(candidates.filter((variant) => !size || variant.sizeLabel === size).map((variant) => variant.colorKey))];
  return { size, color: filterColor && colors.includes(filterColor) ? filterColor : colors.length === 1 ? colors[0] : null };
}
