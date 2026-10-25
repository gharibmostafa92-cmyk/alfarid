'use client';

import { useMemo } from 'react';
import type { StoreBanner } from '@alfarid/api-client';
import { useStoreHome } from '@/hooks/use-store-home';
import { bannerToSlide } from '@/lib/hero-slides';
import { mapCards } from '@/lib/sophie-mappers';
import { STORE_ROUTES } from '@/lib/store-routes';
import { SophieHero } from './SophieHero';
import { SophieProductsSection } from './SophieProductsSection';
import { SophiePromoBanners } from './SophiePromoBanners';
import { SophieComingSoon } from './SophieComingSoon';
import { SophieSectionError } from './SophieSectionError';
import { SophiePopup } from './SophiePopup';
import { SophieFooter } from './SophieFooter';

const EMPTY_BANNERS: StoreBanner[] = [];

export function SophieHomeContent() {
  const { data, isPending, isError, refetch } = useStoreHome();
  const banners = data?.banners ?? EMPTY_BANNERS;
  const slides = useMemo(() => banners.filter((banner) => banner.position === 'main_slider').map(bannerToSlide), [banners]);
  const promos = useMemo(() => banners.filter((banner) => banner.position === 'middle_banner'), [banners]);
  const popup = banners.find((banner) => banner.position === 'popup');
  const best = useMemo(() => mapCards(data?.best_sellers, 'auto'), [data]);
  const latest = useMemo(() => mapCards(data?.latest_products, 'new'), [data]);
  const sale = useMemo(() => mapCards(data?.discounted_products, 'sale'), [data]);
  const hasProducts = best.length + latest.length + sale.length > 0;

  return <>
    <SophieHero slides={slides} isLoading={isPending} />
    {isPending && <SophieProductsSection title="BEST SELLERS" subtitle="" products={[]} isLoading />}
    {!isPending && isError && !data && <SophieSectionError onRetry={() => { void refetch(); }} />}
    {!isPending && data && !hasProducts && <SophieComingSoon />}
    {!isPending && data && <SophieProductsSection id="best-sellers" title="BEST SELLERS" subtitle="Our most-loved pieces, chosen by you." products={best} showViewMore viewMoreHref={STORE_ROUTES.shop({ sort: 'best_selling' })} />}
    {!isPending && <SophiePromoBanners banners={promos} />}
    {!isPending && data && <>
      <SophieProductsSection id="new-arrivals" title="NEW ARRIVALS" subtitle="Fresh silhouettes, just landed." products={latest} showViewMore viewMoreHref={STORE_ROUTES.shop({ sort: 'newest' })} className="pt-12 pb-10" />
      <SophieProductsSection id="on-sale" title="ON SALE" subtitle="Limited-time prices on selected styles." products={sale} showViewMore viewMoreHref={STORE_ROUTES.shop({ on_sale: '1' })} className="pt-12 pb-20" />
    </>}
    {!isPending && popup && <SophiePopup key={`${popup.id}:${popup.image}`} banner={popup} />}
    <SophieFooter />
  </>;
}
