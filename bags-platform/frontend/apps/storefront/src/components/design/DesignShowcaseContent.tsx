'use client';

import React, { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ShowcaseStage } from './ShowcaseStage';
import { StoreImage } from '../StoreImage';
import { formatStorePrice } from '@alfarid/utils';
import { useStoreCategories, useStoreProducts, useStoreShowcase } from '@/hooks/use-store-catalog';
import { STORE_ROUTES } from '@/lib/store-routes';
import { pick, toCardVM, toDetailVM, type CatalogCardVM } from '@/lib/catalog-mappers';

const slotImg = (item: ReturnType<typeof toDetailVM> | null | undefined) =>
  item?.variants[0]?.images[0]?.url ?? item?.images[0]?.url ?? null;

export function DesignShowcaseContent() {
  const router = useRouter();
  // Browsing modes; every product opens the shared purchase stage.
  const [currentMode, setCurrentMode] = useState<'carousel' | 'grid'>('carousel');
  const [activeIndex, setActiveIndex] = useState<number>(0);
  const [activeVariantId, setActiveVariantId] = useState<number | null>(null);
  const [activeCategoryIndex, setActiveCategoryIndex] = useState<number>(0);

  // Carousel Animation & Scroll Lock Ref
  const isScrollLocked = useRef<boolean>(false);
  const [isInfoFading, setIsInfoFading] = useState<boolean>(false);

  // Data
  const showcaseQ = useStoreShowcase();
  const items = useMemo(() => (showcaseQ.data ?? []).map(toDetailVM), [showcaseQ.data]);
  const gridQ = useStoreProducts({ sort: 'featured', per_page: 12 });
  const gridCards = useMemo(() => (gridQ.data?.pages[0]?.data ?? []).map(toCardVM), [gridQ.data]);
  const categoriesQ = useStoreCategories();
  const roots = categoriesQ.data ?? [];
  const totalProducts = items.length;
  const current = items[activeIndex] ?? null;
  const canNav = totalProducts > 1;

  useEffect(() => {
    if (activeCategoryIndex > roots.length - 1) setActiveCategoryIndex(0);
  }, [roots.length, activeCategoryIndex]);

  useEffect(() => {
    if (totalProducts === 0) {
      setActiveIndex(0);
      setActiveVariantId(null);
      return;
    }
    if (activeIndex >= totalProducts) { setActiveIndex(0); return; }
    const cur = items[activeIndex];
    if (cur) setActiveVariantId((cur.variants.find((v) => v.inStock) ?? cur.variants[0])?.id ?? null);
  }, [activeIndex, items, totalProducts]);

  const variant = current?.variants.find((v) => v.id === activeVariantId) ?? current?.variants[0] ?? null;
  const activeRoot = roots[activeCategoryIndex] ?? null;


  // Navigate Carousel
  const navigateCarousel = useCallback(
    (direction: number) => {
      if (totalProducts <= 1) return;
      setIsInfoFading(true);
      setActiveIndex((prev) => (prev + direction + totalProducts) % totalProducts);
      setTimeout(() => {
        setIsInfoFading(false);
      }, 220);
    },
    [totalProducts]
  );

  const selectSlide = (index: number) => {
    if (totalProducts === 0) return;
    if (index === activeIndex) {
      openDetails();
      return;
    }
    setIsInfoFading(true);
    setActiveIndex(index);
    setTimeout(() => {
      setIsInfoFading(false);
    }, 220);
  };

  const openDetails = () => {
    const item = items[activeIndex];
    if (item) router.push(STORE_ROUTES.product(item.slug));
  };

  const switchMode = (mode: 'carousel' | 'grid') => {
    setCurrentMode(mode);
    if (typeof window !== 'undefined') {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  };

  // Keyboard navigation
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (currentMode !== 'carousel' || !canNav) return;
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
        navigateCarousel(1);
      } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
        navigateCarousel(-1);
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [currentMode, navigateCarousel, canNav]);

  // Mouse wheel scroll navigation for 3D stage
  useEffect(() => {
    const handleWheel = (e: WheelEvent) => {
      if (currentMode !== 'carousel' || !canNav) return;
      if (isScrollLocked.current) return;

      if (Math.abs(e.deltaY) > 25) {
        isScrollLocked.current = true;
        if (e.deltaY > 0) {
          navigateCarousel(1);
        } else {
          navigateCarousel(-1);
        }

        setTimeout(() => {
          isScrollLocked.current = false;
        }, 550);
      }
    };

    window.addEventListener('wheel', handleWheel, { passive: true });
    return () => window.removeEventListener('wheel', handleWheel);
  }, [currentMode, navigateCarousel, canNav]);

  // Touch Swipe for Mobile / Tablets
  const touchCoords = useRef<{ x: number; y: number }>({ x: 0, y: 0 });
  const handleTouchStart = (e: React.TouchEvent) => {
    touchCoords.current = {
      x: e.touches[0].clientX,
      y: e.touches[0].clientY,
    };
  };

  const handleTouchEnd = (e: React.TouchEvent) => {
    if (currentMode !== 'carousel' || !canNav) return;
    const diffX = touchCoords.current.x - e.changedTouches[0].clientX;
    const diffY = touchCoords.current.y - e.changedTouches[0].clientY;

    if (Math.abs(diffX) > 40 && Math.abs(diffX) > Math.abs(diffY)) {
      if (diffX > 0) navigateCarousel(1);
      else navigateCarousel(-1);
    }
  };

  const handleGridCard = (card: CatalogCardVM) => {
    router.push(STORE_ROUTES.product(card.slug));
  };

  // 3D stage slots indices
  const leftIdx = totalProducts > 0 ? (activeIndex - 1 + totalProducts) % totalProducts : 0;
  const rightIdx = totalProducts > 0 ? (activeIndex + 1) % totalProducts : 0;

  const leftItem = items[leftIdx] ?? null;
  const rightItem = items[rightIdx] ?? null;
  const centerImg = variant?.images[0]?.url ?? current?.images[0]?.url ?? null;

  const brand = current && current.categoryLabel ? current.categoryLabel.toUpperCase() : 'SOPHIE';
  const priceLabel = current ? (variant?.price ?? current.fallbackPrice) : null;
  return (
    <div className="design-page" onTouchStart={handleTouchStart} onTouchEnd={handleTouchEnd}>
      {/* ================= VIEW SWITCHER BUTTONS (TOP RIGHT) ================= */}
      <div className="design-view-switcher">
        <button
          className={`design-switch-btn ${currentMode === 'carousel' ? 'active' : ''}`}
          onClick={() => switchMode('carousel')}
          title="3D Carousel"
        >
          <i className="fa-solid fa-cube" /> <span>3D Stage</span>
        </button>
        <button
          className={`design-switch-btn ${currentMode === 'grid' ? 'active' : ''}`}
          onClick={() => switchMode('grid')}
          title="Catalog Grid"
        >
          <i className="fa-solid fa-table-cells-large" /> <span>Grid</span>
        </button>
      </div>

      {/* ================= LEFT SIDEBAR ================= */}
      <aside className={`design-sidebar ${currentMode === 'grid' ? 'expanded-subcats' : ''}`}>
        <div className="design-sidebar-tag">{'// SOPHIE ATELIER'}</div>
        <ul className="design-cat-list">
          {roots.map((cat, idx) => (
            <li
              key={cat.slug}
              className={`design-cat-item ${activeCategoryIndex === idx ? 'active' : ''}`}
              onClick={() => {
                if (idx === activeCategoryIndex) {
                  router.push(STORE_ROUTES.shop({ category: cat.slug }));
                } else {
                  setActiveCategoryIndex(idx);
                  if (totalProducts > 0) selectSlide(idx % totalProducts);
                  if (currentMode === 'grid') switchMode('carousel');
                }
              }}
            >
              {pick(cat.name_en, cat.name_ar).toUpperCase()}
            </li>
          ))}
        </ul>
        {(activeRoot?.children ?? []).length > 0 && (
          <ul className="design-subcat-list">
            {(activeRoot?.children ?? []).map((sub) => (
              <li
                key={sub.slug}
                className="design-subcat-item"
                onClick={() => router.push(STORE_ROUTES.shop({ category: sub.slug }))}
              >
                {pick(sub.name_en, sub.name_ar)}
              </li>
            ))}
          </ul>
        )}
      </aside>

      {/* ================= 1. 3D CAROUSEL VIEW ================= */}
      {currentMode === 'carousel' && (
        <main className="design-carousel-view">
          {showcaseQ.isPending ? (
            <>
              <div className="design-stage">
                {['left', 'center', 'right'].map((pos) => (
                  <div key={pos} className={`design-bag-slot ${pos}`}>
                    <div className="design-slot-shimmer" />
                    <div className="design-bag-shadow" />
                  </div>
                ))}
              </div>
              <div className="design-carousel-info">
                <h1 className="design-brand-title">SOPHIE</h1>
                <p className="design-bag-subtitle">Curating the showcase…</p>
              </div>
            </>
          ) : showcaseQ.isError ? (
            <div className="design-carousel-info">
              <h1 className="design-brand-title">SOPHIE</h1>
              <p className="design-bag-subtitle">Couldn&apos;t load the showcase.</p>
              <button className="design-btn-pill" onClick={() => showcaseQ.refetch()}>
                <span>RETRY</span>
                <i className="fa-solid fa-arrow-right" />
              </button>
            </div>
          ) : totalProducts === 0 ? (
            <div className="design-carousel-info">
              <h1 className="design-brand-title">SOPHIE</h1>
              <p className="design-bag-subtitle">Showcase coming soon.</p>
              <Link className="design-btn-pill" href="/shop">
                <span>SHOP THE COLLECTION</span>
                <i className="fa-solid fa-arrow-right" />
              </Link>
            </div>
          ) : (
            <>
              <ShowcaseStage
                previous={{ url: slotImg(leftItem), alt: leftItem?.name ?? 'Previous bag' }}
                current={{ url: centerImg, alt: current?.name ?? 'Selected bag' }}
                next={{ url: slotImg(rightItem), alt: rightItem?.name ?? 'Next bag' }}
                canNavigate={canNav} onNavigate={navigateCarousel} onExplore={openDetails}
              />

              {/* Product Details Label & Action */}
              {current && (
                <div className={`design-carousel-info ${isInfoFading ? 'fade-out' : ''}`}>
                  <h1 className="design-brand-title">{brand}</h1>
                  <p className="design-bag-subtitle">
                    {current.name} &nbsp;|&nbsp; {priceLabel !== null ? formatStorePrice(priceLabel) : '—'}
                  </p>
                  <button className="design-btn-pill" onClick={openDetails}>
                    <span>EXPLORE DETAILS</span>
                    <i className="fa-solid fa-arrow-right" />
                  </button>
                </div>
              )}

              {/* Scroll Hint */}
              {canNav && (
                <div className="design-scroll-hint">
                  <i className="fa-solid fa-arrow-down" /> Scroll Mouse Wheel / Swipe to Browse
                </div>
              )}
            </>
          )}
        </main>
      )}

      {/* ================= 3. GRID CATALOG VIEW ================= */}
      {currentMode === 'grid' && (
        <section className="design-grid-view active">
          <div className="design-products-grid">
            {gridQ.isPending ? (
              <p className="design-bag-subtitle">Loading the collection…</p>
            ) : gridQ.isError ? (
              <div className="design-carousel-info">
                <p className="design-bag-subtitle">Couldn&apos;t load the collection.</p>
                <button className="design-btn-pill" onClick={() => gridQ.refetch()}>
                  <span>RETRY</span>
                  <i className="fa-solid fa-arrow-right" />
                </button>
              </div>
            ) : (
              gridCards.map((card) => (
                <div key={card.id} className="design-grid-card" onClick={() => handleGridCard(card)}>
                  <div className="design-grid-img-box">
                    {card.badge && <span className="design-grid-card-badge">{card.badge}</span>}
                    <StoreImage src={card.img ?? ''} className="" alt={card.name} loading="lazy" />
                  </div>
                  <div className="design-grid-meta-top">
                    <span>{card.categoryLabel.toUpperCase()}</span>
                    <span>{card.priceLabel}</span>
                  </div>
                  <div className="design-grid-meta-sub">{card.name}</div>
                </div>
              ))
            )}
          </div>
        </section>
      )}


    </div>
  );
}
