import type { StoreProductCard } from '@alfarid/api-client';
import type { SophieProduct } from '@/components/sophie/SophieProductCard';
import { STORE_ROUTES } from './store-routes';

const num = (value: unknown) => value === null || value === undefined || value === '' ? NaN : Number(value);

export function cardToSophie(card: StoreProductCard, mode: 'new' | 'sale' | 'auto'): SophieProduct | null {
  const price = num(card.price);
  if (!Number.isFinite(price)) return null;
  const oldPrice = num(card.old_price);
  let badge: string | undefined;
  let badgeTone: SophieProduct['badgeTone'];
  if (!card.in_stock) { badge = 'SOLD OUT'; badgeTone = 'muted'; }
  else if (card.discount_percentage) { badge = `-${Math.round(card.discount_percentage)}%`; badgeTone = 'sale'; }
  else if (mode === 'new') { badge = 'NEW'; badgeTone = 'default'; }
  return {
    id: card.id, variantId: card.default_variant_id ?? undefined, slug: card.slug, name: card.name_en?.trim() || card.name_ar,
    price, oldPrice: Number.isFinite(oldPrice) && oldPrice > price ? oldPrice : undefined,
    badge, badgeTone, imgUrl: card.image || null,
    category: card.category?.name_en || card.category?.name_ar,
    href: STORE_ROUTES.product(card.slug), inStock: card.in_stock,
  };
}

export const mapCards = (cards: StoreProductCard[] | undefined, mode: 'new' | 'sale' | 'auto') =>
  (cards ?? []).map((card) => cardToSophie(card, mode)).filter((card): card is SophieProduct => card !== null);
