'use client';

import { useMemo } from 'react';
import type { StoreBanner } from '@alfarid/api-client';
import { useStoreHome } from '@/hooks/use-store-home';
import { bannerToSlide } from '@/lib/core/hero-slides';
import { mapCards } from '@/lib/catalog/sophie-mappers';
import { STORE_ROUTES } from '@/lib/orders/store-routes';
import { SophieHero } from './SophieHero';
import { SophieProductsSection } from '../product/SophieProductsSection';
import { SophiePromoBanners } from './SophiePromoBanners';
import { SophieComingSoon } from '../overlays/SophieComingSoon';
import { SophieSectionError } from '../overlays/SophieSectionError';
import { SophiePopup } from '../overlays/SophiePopup';
import { SophieFooter } from './SophieFooter';
import { useStoreLang } from '@/providers/store-lang-provider';

const EMPTY_BANNERS: StoreBanner[] = [];

export function SophieHomeContent() {
  const { lang, isAr } = useStoreLang();
  const { data, isPending, isError, refetch } = useStoreHome();
  const banners = data?.banners ?? EMPTY_BANNERS;
  const slides = useMemo(() => banners.filter((banner) => banner.position === 'main_slider').map(bannerToSlide), [banners]);
  const promos = useMemo(() => banners.filter((banner) => banner.position === 'middle_banner'), [banners]);
  const popup = banners.find((banner) => banner.position === 'popup');
  const best = useMemo(() => mapCards(data?.best_sellers, 'auto', lang), [data, lang]);
  const latest = useMemo(() => mapCards(data?.latest_products, 'new', lang), [data, lang]);
  const sale = useMemo(() => mapCards(data?.discounted_products, 'sale', lang), [data, lang]);
  const hasProducts = best.length + latest.length + sale.length > 0;

  return <>
    <SophieHero slides={slides} isLoading={isPending} />
    {isPending && <SophieProductsSection title={isAr ? 'الأكثر مبيعاً' : 'BEST SELLERS'} subtitle="" products={[]} isLoading />}
    {!isPending && isError && !data && <SophieSectionError onRetry={() => { void refetch(); }} />}
    {!isPending && data && !hasProducts && <SophieComingSoon />}
    {!isPending && data && <SophieProductsSection id="best-sellers" title={isAr ? 'الأكثر مبيعاً' : 'BEST SELLERS'} subtitle={isAr ? 'أكثر القطع مبيعاً باختيار عملائنا.' : 'Our most-loved pieces, chosen by you.'} products={best} showViewMore viewMoreHref={STORE_ROUTES.shop({ sort: 'best_selling' })} />}
    {!isPending && <SophiePromoBanners banners={promos} />}
    {!isPending && data && <>
      <SophieProductsSection id="new-arrivals" title={isAr ? 'وصل حديثاً' : 'NEW ARRIVALS'} subtitle={isAr ? 'تصميمات جديدة وصلت للتو.' : 'Fresh silhouettes, just landed.'} products={latest} showViewMore viewMoreHref={STORE_ROUTES.shop({ sort: 'newest' })} className="pt-10 pb-8 sm:pt-12 sm:pb-10" />
      <SophieProductsSection id="on-sale" title={isAr ? 'عروض وخصومات' : 'ON SALE'} subtitle={isAr ? 'أسعار لفترة محدودة على مختارات مميزة.' : 'Limited-time prices on selected styles.'} products={sale} showViewMore viewMoreHref={STORE_ROUTES.shop({ on_sale: '1' })} className="pt-10 pb-14 sm:pt-12 sm:pb-20" />
    </>}
    {!isPending && popup && <SophiePopup key={`${popup.id}:${popup.image}`} banner={popup} />}
    <SophieFooter />
  </>;
}