'use client';

/* eslint-disable @next/next/no-img-element */
import React, { useState, useEffect, useCallback, useRef } from 'react';
import Link from 'next/link';
import {
  ShowcaseProduct,
  SHOWCASE_PRODUCTS,
  GRID_CATALOG,
  SIDEBAR_CATEGORIES,
  SIDEBAR_SUBCATEGORIES,
} from '@/data/design-mock';

export function DesignShowcaseContent() {
  // Navigation & View Mode: 'carousel' | 'details' | 'grid'
  const [currentMode, setCurrentMode] = useState<'carousel' | 'details' | 'grid'>('carousel');
  const [activeIndex, setActiveIndex] = useState<number>(0);
  const [activeVariant, setActiveVariant] = useState<'primary' | 'secondary' | 'camel'>('primary');
  const [activeCategoryIndex, setActiveCategoryIndex] = useState<number>(0);

  // Cart & Wishlist State
  const [cartCount, setCartCount] = useState<number>(2);
  const [isCartBumped, setIsCartBumped] = useState<boolean>(false);
  const [wishlist, setWishlist] = useState<Set<string>>(new Set());
  const [toast, setToast] = useState<{ show: boolean; msg: string }>({ show: false, msg: '' });

  // Accordion State
  const [openAccordions, setOpenAccordions] = useState<Set<number>>(new Set());

  // Carousel Animation & Scroll Lock Ref
  const isScrollLocked = useRef<boolean>(false);
  const [isInfoFading, setIsInfoFading] = useState<boolean>(false);

  const showToast = useCallback((msg: string) => {
    setToast({ show: true, msg });
    setTimeout(() => {
      setToast({ show: false, msg: '' });
    }, 3200);
  }, []);

  const totalProducts = SHOWCASE_PRODUCTS.length;
  const currentProduct = SHOWCASE_PRODUCTS[activeIndex];

  // Navigate Carousel
  const navigateCarousel = useCallback(
    (direction: number) => {
      setIsInfoFading(true);
      setActiveIndex((prev) => (prev + direction + totalProducts) % totalProducts);
      setTimeout(() => {
        setIsInfoFading(false);
      }, 220);
    },
    [totalProducts]
  );

  const selectSlide = (index: number) => {
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
    setActiveVariant('primary');
    setCurrentMode('details');
    if (typeof window !== 'undefined') {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  };

  const closeDetails = () => {
    setCurrentMode('carousel');
    if (typeof window !== 'undefined') {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
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
      if (currentMode !== 'carousel') return;
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
        navigateCarousel(1);
      } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
        navigateCarousel(-1);
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [currentMode, navigateCarousel]);

  // Mouse wheel scroll navigation for 3D stage
  useEffect(() => {
    const handleWheel = (e: WheelEvent) => {
      if (currentMode !== 'carousel') return;
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
  }, [currentMode, navigateCarousel]);

  // Touch Swipe for Mobile / Tablets
  const touchCoords = useRef<{ x: number; y: number }>({ x: 0, y: 0 });
  const handleTouchStart = (e: React.TouchEvent) => {
    touchCoords.current = {
      x: e.touches[0].clientX,
      y: e.touches[0].clientY,
    };
  };

  const handleTouchEnd = (e: React.TouchEvent) => {
    if (currentMode !== 'carousel') return;
    const diffX = touchCoords.current.x - e.changedTouches[0].clientX;
    const diffY = touchCoords.current.y - e.changedTouches[0].clientY;

    if (Math.abs(diffX) > 40 && Math.abs(diffX) > Math.abs(diffY)) {
      if (diffX > 0) navigateCarousel(1);
      else navigateCarousel(-1);
    }
  };

  const handleAddToCart = (title: string) => {
    setCartCount((prev) => prev + 1);
    setIsCartBumped(true);
    setTimeout(() => setIsCartBumped(false), 300);
    showToast(`Added "${title}" to your shopping bag!`);
  };

  const toggleWishlist = (productId: string) => {
    setWishlist((prev) => {
      const next = new Set(prev);
      if (next.has(productId)) {
        next.delete(productId);
        showToast('Removed from your wishlist.');
      } else {
        next.add(productId);
        showToast('Saved to your wishlist!');
      }
      return next;
    });
  };

  const toggleAccordion = (index: number) => {
    setOpenAccordions((prev) => {
      const next = new Set(prev);
      if (next.has(index)) next.delete(index);
      else next.add(index);
      return next;
    });
  };

  // 3D stage slots indices
  const leftIdx = (activeIndex - 1 + totalProducts) % totalProducts;
  const rightIdx = (activeIndex + 1) % totalProducts;

  // Active Variant Subtitle Edition
  let variantSubtitleEdition = 'Rose Gold Edition';
  if (activeVariant === 'secondary') variantSubtitleEdition = 'Noir Edition';
  if (activeVariant === 'camel') variantSubtitleEdition = 'Camel Sand Edition';

  return (
    <div className="design-page" onTouchStart={handleTouchStart} onTouchEnd={handleTouchEnd}>
      {/* ================= TOP NAVIGATION ================= */}
      <header className="design-header">
        <div className="design-nav-left">
          <div
            className={`design-back-btn ${currentMode === 'details' ? 'visible' : ''}`}
            onClick={closeDetails}
          >
            <div className="design-back-circle">
              <i className="fa-solid fa-arrow-left" />
            </div>
            <span>Back</span>
          </div>

          {currentMode !== 'details' && (
            <Link href="/" className="design-logo">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <polygon points="2,12 20,3 14,21 10,14 2,12" fill="#000" />
                <line x1="20" y1="3" x2="10" y2="14" stroke="#fff" strokeWidth="1.5" />
              </svg>
              Sophie
            </Link>
          )}
        </div>

        <nav className="design-nav-center">
          <ul className="design-nav-links">
            <li>
              <Link href="/">HOME</Link>
            </li>
            <li>
              <Link href="/shop">
                SHOP <i className="fa-solid fa-chevron-down" />
              </Link>
            </li>
            <li>
              <Link href="/categories">
                CATEGORIES <i className="fa-solid fa-chevron-down" />
              </Link>
            </li>
            <li>
              <Link href="/design" className="active">
                3D SHOWCASE
              </Link>
            </li>
            <li>
              <Link href="/wholesale">WHOLESALE</Link>
            </li>
            <li>
              <a href="#contact">CONTACT US</a>
            </li>
          </ul>
        </nav>

        <div className="design-nav-right">
          <button className="design-icon-btn" aria-label="Search" title="Search">
            <i className="fa-solid fa-magnifying-glass" />
          </button>
          <button className="design-icon-btn" aria-label="Settings" title="Settings">
            <i className="fa-solid fa-gear" />
          </button>
          <button
            className="design-icon-btn"
            title="Bag"
            onClick={() => showToast(`You have ${cartCount} items in your shopping bag.`)}
          >
            <i className="fa-solid fa-cart-shopping" />
            <span className={`design-cart-badge ${isCartBumped ? 'bump' : ''}`}>
              {cartCount}
            </span>
          </button>
        </div>
      </header>

      {/* ================= VIEW SWITCHER BUTTONS (TOP RIGHT) ================= */}
      <div className={`design-view-switcher ${currentMode === 'details' ? 'hidden' : ''}`}>
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
      <aside
        className={`design-sidebar ${
          currentMode === 'details' ? 'hidden' : ''
        } ${currentMode === 'grid' ? 'expanded-subcats' : ''}`}
      >
        <div className="design-sidebar-tag">{'// SOPHIE ATELIER'}</div>
        <ul className="design-cat-list">
          {SIDEBAR_CATEGORIES.map((cat, idx) => (
            <li
              key={cat}
              className={`design-cat-item ${activeCategoryIndex === idx ? 'active' : ''}`}
              onClick={() => {
                setActiveCategoryIndex(idx);
                selectSlide(idx % totalProducts);
                if (currentMode === 'grid') switchMode('carousel');
              }}
            >
              {cat}
            </li>
          ))}
        </ul>
        <ul className="design-subcat-list">
          {SIDEBAR_SUBCATEGORIES.map((subcat, idx) => (
            <li
              key={subcat}
              className={`design-subcat-item ${idx === 0 ? 'always-show active' : ''}`}
            >
              {subcat}
            </li>
          ))}
        </ul>
      </aside>

      {/* ================= 1. 3D CAROUSEL VIEW ================= */}
      {currentMode === 'carousel' && (
        <main className="design-carousel-view">
          {/* Carousel Navigation Arrows */}
          <button
            className="design-carousel-arrow prev"
            onClick={() => navigateCarousel(-1)}
            aria-label="Previous Bag"
          >
            <i className="fa-solid fa-chevron-left" />
          </button>
          <button
            className="design-carousel-arrow next"
            onClick={() => navigateCarousel(1)}
            aria-label="Next Bag"
          >
            <i className="fa-solid fa-chevron-right" />
          </button>

          <div className="design-stage">
            {/* Slot 0 (Left) */}
            <div
              className={`design-bag-slot ${
                0 === activeIndex ? 'center' : 0 === leftIdx ? 'left' : 'right'
              }`}
              onClick={() => selectSlide(0)}
            >
              <img
                src={SHOWCASE_PRODUCTS[0].img}
                className={SHOWCASE_PRODUCTS[0].tint}
                alt="Sophie Bag 1"
              />
              <div className="design-bag-shadow" />
            </div>

            {/* Slot 1 (Center) */}
            <div
              className={`design-bag-slot ${
                1 === activeIndex ? 'center' : 1 === leftIdx ? 'left' : 'right'
              }`}
              onClick={() => selectSlide(1)}
            >
              <img
                src={SHOWCASE_PRODUCTS[1].img}
                className={SHOWCASE_PRODUCTS[1].tint}
                alt="Sophie Bag 2"
              />
              <div className="design-bag-shadow" />
            </div>

            {/* Slot 2 (Right) */}
            <div
              className={`design-bag-slot ${
                2 === activeIndex ? 'center' : 2 === leftIdx ? 'left' : 'right'
              }`}
              onClick={() => selectSlide(2)}
            >
              <img
                src={SHOWCASE_PRODUCTS[2].img}
                className={SHOWCASE_PRODUCTS[2].tint}
                alt="Sophie Bag 3"
              />
              <div className="design-bag-shadow" />
            </div>
          </div>

          {/* Product Details Label & Action */}
          <div className={`design-carousel-info ${isInfoFading ? 'fade-out' : ''}`}>
            <h1 className="design-brand-title">{currentProduct.brand}</h1>
            <p className="design-bag-subtitle">
              {currentProduct.subtitle} &nbsp;|&nbsp; {currentProduct.price}
            </p>
            <button className="design-btn-pill" onClick={openDetails}>
              <span>EXPLORE DETAILS</span>
              <i className="fa-solid fa-arrow-right" />
            </button>
          </div>

          {/* Scroll Hint */}
          <div className="design-scroll-hint">
            <i className="fa-solid fa-arrow-down" /> Scroll Mouse Wheel / Swipe to Browse
          </div>
        </main>
      )}

      {/* ================= 2. PRODUCT DETAILS VIEW ================= */}
      {currentMode === 'details' && (
        <section className="design-details-view active">
          <div className="design-details-hero">
            <div className="design-variant-stage">
              {/* Primary Variant */}
              <div
                className={`design-variant-bag ${
                  activeVariant === 'primary' || activeVariant === 'camel'
                    ? 'active-variant'
                    : 'inactive-variant-left'
                }`}
                onClick={() => setActiveVariant('primary')}
              >
                <img
                  src={currentProduct.img}
                  className={activeVariant === 'camel' ? 'tint-beige' : currentProduct.tint}
                  alt="Primary Variant"
                />
                <div className="design-bag-shadow" style={{ margin: '0 auto' }} />
              </div>

              {/* Secondary Variant */}
              <div
                className={`design-variant-bag ${
                  activeVariant === 'secondary' ? 'active-variant' : 'inactive-variant-right'
                }`}
                onClick={() => setActiveVariant('secondary')}
              >
                <img
                  src={currentProduct.secondaryImg}
                  className={currentProduct.secondaryTint}
                  alt="Secondary Variant"
                />
                <div className="design-bag-shadow" style={{ margin: '0 auto' }} />
              </div>
            </div>
          </div>

          <div className="design-details-header-bar">
            <div className="design-details-title-group">
              <div className="sku-row">
                <h1>{currentProduct.brand}</h1>
                <span className="design-sku-code">{currentProduct.sku}</span>
              </div>
              <p>
                {currentProduct.subtitle} &mdash; {variantSubtitleEdition}
              </p>
            </div>

            <div className="design-details-actions">
              <div className="design-details-price">{currentProduct.price}</div>
              <div className="design-swatches-and-size">
                <div
                  className={`design-swatch swatch-rose ${activeVariant === 'primary' ? 'active' : ''}`}
                  onClick={() => setActiveVariant('primary')}
                  title="Dusty Rose"
                />
                <div
                  className={`design-swatch swatch-black ${activeVariant === 'secondary' ? 'active' : ''}`}
                  onClick={() => setActiveVariant('secondary')}
                  title="Noir Black"
                />
                <div
                  className={`design-swatch swatch-camel ${activeVariant === 'camel' ? 'active' : ''}`}
                  onClick={() => setActiveVariant('camel')}
                  title="Camel Beige"
                />
                <select className="design-size-select" defaultValue="Medium (14L)">
                  <option>Medium (14L)</option>
                  <option>Large (20L)</option>
                  <option>Compact (9L)</option>
                </select>
              </div>

              <div className="design-cart-row">
                <button
                  className="design-btn-pill"
                  onClick={() => handleAddToCart(currentProduct.subtitle)}
                >
                  <i className="fa-solid fa-bag-shopping" /> ADD TO SHOPPING BAG
                </button>
                <button
                  className="design-wishlist-circle"
                  onClick={() => toggleWishlist(currentProduct.sku)}
                  title="Add to Wishlist"
                >
                  <i
                    className={
                      wishlist.has(currentProduct.sku)
                        ? 'fa-solid fa-heart'
                        : 'fa-regular fa-heart'
                    }
                    style={{ color: wishlist.has(currentProduct.sku) ? '#e11d48' : '' }}
                  />
                </button>
              </div>
            </div>
          </div>

          <div className="design-details-body">
            {/* Left Column: Vertical Gallery */}
            <div className="design-details-gallery">
              {currentProduct.gallery.map((item, idx) => (
                <div key={idx} className="design-gallery-card">
                  <img src={item.url} className={item.tint} alt={`Gallery view ${idx + 1}`} />
                </div>
              ))}
            </div>

            {/* Right Column: Sticky Description & Accordions */}
            <div className="design-details-description">
              <p className="design-desc-text">{currentProduct.desc}</p>

              <div
                className={`design-accordion-item ${openAccordions.has(0) ? 'open' : ''}`}
                onClick={() => toggleAccordion(0)}
              >
                <div className="design-accordion-header">
                  <span>Material &amp; Craftsmanship</span>
                  <i className="fa-solid fa-chevron-right" />
                </div>
                <div className="design-accordion-content">
                  Crafted from high-grade water-resistant vegan leather. Lining: 100% recycled cotton twill.
                  Hardware: Polished scratch-resistant zinc alloy. Designed in London, artisan assembled.
                </div>
              </div>

              <div
                className={`design-accordion-item ${openAccordions.has(1) ? 'open' : ''}`}
                onClick={() => toggleAccordion(1)}
              >
                <div className="design-accordion-header">
                  <span>Complimentary Delivery &amp; Returns</span>
                  <i className="fa-solid fa-chevron-right" />
                </div>
                <div className="design-accordion-content">
                  Enjoy complimentary express UK delivery on all orders over £50. Effortless 30-day return
                  and exchange policy with prepaid return labels included.
                </div>
              </div>

              <div
                className={`design-accordion-item ${openAccordions.has(2) ? 'open' : ''}`}
                onClick={() => toggleAccordion(2)}
              >
                <div className="design-accordion-header">
                  <span>2-Year Artisan Warranty</span>
                  <i className="fa-solid fa-chevron-right" />
                </div>
                <div className="design-accordion-content">
                  Each Sophie piece comes protected by our 24-month comprehensive craftsmanship guarantee
                  covering stitching, seams, and hardware integrity.
                </div>
              </div>
            </div>
          </div>
        </section>
      )}

      {/* ================= 3. GRID CATALOG VIEW ================= */}
      {currentMode === 'grid' && (
        <section className="design-grid-view active">
          <div className="design-products-grid">
            {GRID_CATALOG.map((item, idx) => (
              <div
                key={idx}
                className="design-grid-card"
                onClick={() => {
                  setActiveIndex(item.slideIndex);
                  switchMode('carousel');
                }}
              >
                <div className="design-grid-img-box">
                  <span className="design-grid-card-badge">{item.badge}</span>
                  <img src={item.img} className={item.tint} alt={item.brand} />
                </div>
                <div className="design-grid-meta-top">
                  <span>{item.brand}</span>
                  <span>{item.price}</span>
                </div>
                <div className="design-grid-meta-sub">{item.subtitle}</div>
              </div>
            ))}
          </div>
        </section>
      )}

      {/* ================= FLOATING TOAST ================= */}
      <div className={`design-toast-notice ${toast.show ? 'show' : ''}`}>
        <i className="fa-solid fa-circle-check" style={{ color: 'var(--accent)', fontSize: '18px' }} />
        <span>{toast.msg}</span>
      </div>
    </div>
  );
}
