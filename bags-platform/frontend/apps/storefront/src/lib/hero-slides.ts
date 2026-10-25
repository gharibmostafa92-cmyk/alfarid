import type { StoreBanner } from '@alfarid/api-client';
import { STORE_ROUTES } from './store-routes';

export type HeroSlide =
  | { kind: 'banner'; id: number; image: string; alt: string; kicker?: string; headline?: string;
      headlineBold?: string; description?: string; ctaLabel?: string; href: string | null; isExternal: boolean }
  | { kind: 'fallback'; id: 'fallback'; subtitle: string; lightTitle: string; boldTitle: string;
      desc: string; ctaText: string; ctaHref: string; imgUrl: string; tintClass: string };

export const FALLBACK_SLIDE: Extract<HeroSlide, { kind: 'fallback' }> = {
  kind: 'fallback', id: 'fallback', subtitle: 'BAG COLLECTION', lightTitle: 'TIMELESS', boldTitle: 'BAGS',
  desc: 'Crafted pieces for every day — discover the collection.', ctaText: 'SHOP NOW',
  ctaHref: STORE_ROUTES.shop(), imgUrl: '/images/sophie-hero-fallback.png', tintClass: 'tint-pink',
};

export const bannerToSlide = (banner: StoreBanner): HeroSlide => ({
  kind: 'banner', id: banner.id, image: banner.image, alt: banner.title,
  kicker: banner.kicker ?? undefined, headline: banner.headline ?? undefined,
  headlineBold: banner.headline_bold ?? undefined, description: banner.description ?? undefined,
  ctaLabel: banner.cta_label ?? undefined, href: banner.href, isExternal: banner.is_external,
});
