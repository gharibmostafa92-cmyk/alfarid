'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import type { HeroSlide } from '@/lib/hero-slides';
import { FALLBACK_SLIDE } from '@/lib/hero-slides';
import { SophieBagPlaceholder } from './SophieBagPlaceholder';
import { useBranding } from '@alfarid/ui/branding';
import { StoreImage } from '../StoreImage';
import { storeImageUrl } from '@/lib/store-image';

export function SophieHero({ slides, isLoading = false }: { slides: HeroSlide[]; isLoading?: boolean }) {
  const { branding } = useBranding();
  const [index, setIndex] = useState(0);
  const [failed, setFailed] = useState<Set<string>>(() => new Set());
  const [paused, setPaused] = useState(false);
  const [focused, setFocused] = useState(false);
  const [fallbackFailed, setFallbackFailed] = useState(false);
  const touchStart = useRef<number | null>(null);
  const list = useMemo(() => {
    const healthy = slides.filter((s) => s.kind !== 'banner' || !failed.has(`${s.id}:${s.image}`));
    return healthy.length ? healthy : [FALLBACK_SLIDE];
  }, [slides, failed]);
  const activeIndex = index % list.length;
  const slide = list[activeIndex];
  const advance = (direction: number) => setIndex((current) => (current + direction + list.length) % list.length);

  useEffect(() => {
    if (isLoading || paused || focused || list.length < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const timer = setInterval(() => setIndex((current) => (current + 1) % list.length), 6000);
    return () => clearInterval(timer);
  }, [isLoading, paused, focused, list.length]);

  useEffect(() => {
    if (isLoading) return;
    const next = list[(activeIndex + 1) % list.length];
    if (next.kind === 'banner') { const image = new Image(); image.src = storeImageUrl(next.image); }
  }, [activeIndex, list, isLoading]);

  const fallback = slide.kind === 'fallback';
  const kicker = fallback ? `${branding.name} ${slide.subtitle}` : slide.kicker;
  const headline = fallback ? slide.lightTitle : slide.headline;
  const bold = fallback ? slide.boldTitle : slide.headlineBold;
  const description = fallback ? slide.desc : slide.description;
  const href = fallback ? slide.ctaHref : slide.href;
  const label = fallback ? slide.ctaText : slide.ctaLabel || 'SHOP NOW';
  const hasText = Boolean(kicker || headline || bold || description);
  const external = !fallback && slide.isExternal ? { target: '_blank', rel: 'noopener noreferrer' } : {};

  return (
    <div
      className="hero-wrapper relative overflow-hidden bg-[#dfe5e9]"
      id="top"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocusCapture={() => setFocused(true)}
      onBlurCapture={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setFocused(false); }}
      onTouchStart={(event) => { touchStart.current = event.touches.length === 1 ? event.touches[0].clientX : null; }}
      onTouchCancel={() => { touchStart.current = null; }}
      onTouchEnd={(event) => {
        if (touchStart.current !== null && !isLoading && list.length > 1) {
          const delta = touchStart.current - event.changedTouches[0].clientX;
          if (Math.abs(delta) > 40) advance(delta > 0 ? 1 : -1);
        }
        touchStart.current = null;
      }}
    >
      {!isLoading && fallback && !fallbackFailed && (
        <div className="hero-bg-backdrop" aria-hidden="true">
          <div className="hero-bg-backdrop-img">
            <StoreImage
              src={slide.imgUrl}
              className={`${slide.tintClass} w-full h-full object-cover object-center`}
              alt=""
              onError={() => setFallbackFailed(true)}
            />
          </div>
          <div className="hero-bg-backdrop-overlay" />
        </div>
      )}
      {!isLoading && !fallback && (
        <div className="absolute inset-0" aria-hidden="true">
          <StoreImage
            key={`${slide.id}:${slide.image}`}
            src={slide.image}
            fetchPriority="high"
            alt=""
            className="hero-banner-img"
            onError={() => setFailed((current) => new Set(current).add(`${slide.id}:${slide.image}`))}
          />
          {hasText && <div className="hero-banner-overlay" />}
        </div>
      )}
      <div className="max-w-[1140px] mx-auto px-4 sm:px-5 relative z-10">
        <section
          className={`sophie-hero-content ${fallback ? 'is-fallback-slide' : 'is-banner-slide'} flex flex-col lg:flex-row items-center justify-between min-h-[380px] sm:min-h-[450px] pt-4 sm:pt-6 lg:pt-2 pb-16 sm:pb-14 relative`}
          aria-label="Featured collection"
          aria-busy={isLoading}
        >
          {isLoading ? (
            <>
              <div className="max-w-[430px] w-full space-y-4 mt-4 lg:mt-0" aria-hidden="true">
                <div className="sophie-skeleton h-3 w-32 mx-auto lg:mx-0" />
                <div className="sophie-skeleton h-10 sm:h-12 w-64 sm:w-72 max-w-full mx-auto lg:mx-0" />
                <div className="sophie-skeleton h-10 sm:h-12 w-52 sm:w-60 max-w-full mx-auto lg:mx-0" />
                <div className="sophie-skeleton h-3 w-full" />
                <div className="sophie-skeleton h-3 w-3/4 mx-auto lg:mx-0" />
                <div className="sophie-skeleton h-11 w-40 mx-auto lg:mx-0" />
              </div>
              <div
                className="sophie-skeleton w-full max-w-[240px] min-[380px]:max-w-[280px] sm:max-w-none sm:w-[350px] lg:w-[390px] h-[240px] sm:h-[370px] lg:h-[410px] mt-6 lg:mt-0 lg:mr-10 shrink-0"
                aria-hidden="true"
              />
            </>
          ) : (
            <>
              {hasText && (
                <div className="max-w-[430px] w-full z-10 text-center lg:text-left mt-3 sm:mt-6 lg:mt-0 break-words">
                  {kicker && (
                    <p dir="auto" className="font-['Oswald'] text-[12px] sm:text-[13px] font-medium tracking-[0.8px] uppercase text-[#111] mb-2">
                      {kicker}
                    </p>
                  )}
                  {(headline || bold) && (
                    <h1 dir="auto" className="font-['Oswald'] uppercase leading-[1.04] mb-4">
                      {headline && (
                        <span className="block text-[clamp(2rem,8vw,2.75rem)] sm:text-[54px] font-light tracking-[1px] text-[#111]">
                          {headline}
                        </span>
                      )}
                      {bold && (
                        <span className="block text-[clamp(2.2rem,8.8vw,3rem)] sm:text-[58px] font-bold tracking-[1px] text-[#111]">
                          {bold}
                        </span>
                      )}
                    </h1>
                  )}
                  {description && (
                    <p dir="auto" className="text-[13.5px] sm:text-[14px] leading-[1.65] text-[#222] mb-6 sm:mb-7 font-['Poppins'] max-w-[38ch] mx-auto lg:mx-0">
                      {description}
                    </p>
                  )}
                  {href && (
                    <a
                      href={href}
                      {...external}
                      className="inline-flex items-center justify-center min-h-[46px] w-full min-[380px]:w-auto bg-black text-white font-['Oswald'] text-[12px] font-medium tracking-[1px] uppercase px-9 py-3.5 hover:bg-[#2c2c2c] active: scale-[0.99] transition-all"
                    >
                      {label}
                    </a>
                  )}
                </div>
              )}
              {!hasText && href && !fallback && <a href={href} {...external} aria-label={slide.alt} className="absolute inset-0 z-[5]" />}
              {fallback && (
                <div className="flex-1 w-full flex justify-center lg:justify-end items-center lg:pr-10 relative mt-6 sm:mt-8 lg:mt-0">
                  <div className="hero-foreground-img w-full max-w-[240px] min-[380px]:max-w-[285px] sm:max-w-none sm:w-[350px] lg:w-[390px] h-[240px] min-[380px]:h-[280px] sm:h-[370px] lg:h-[410px]">
                    {fallbackFailed ? (
                      <SophieBagPlaceholder />
                    ) : (
                      <StoreImage
                        src={slide.imgUrl}
                        className={`${slide.tintClass} w-full h-full object-contain block`}
                        alt={`${slide.lightTitle} ${slide.boldTitle}`}
                        onError={() => setFallbackFailed(true)}
                      />
                    )}
                  </div>
                </div>
              )}
            </>
          )}
          {!isLoading && list.length > 1 && (
            <div className="absolute bottom-3 left-1/2 -translate-x-1/2 flex items-center gap-1 z-20" role="group" aria-label="Slide controls">
              {list.map((item, position) => (
                <button
                  key={item.id}
                  type="button"
                  onClick={() => setIndex(position)}
                  aria-label={`Go to slide ${position + 1}`}
                  aria-current={activeIndex === position ? 'true' : undefined}
                  className="min-w-[40px] min-h-[40px] px-1.5 inline-flex items-center justify-center"
                >
                  <span
                    className={`block h-[3px] rounded-full transition-all duration-300 ${
                      activeIndex === position ? 'w-7 bg-[#111111]' : 'w-5 bg-white/85 hover:bg-white shadow-xs'
                    }`}
                  />
                </button>
              ))}
            </div>
          )}
        </section>
      </div>
    </div>
  );
}