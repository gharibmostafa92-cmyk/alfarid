'use client';

import React, { useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import type { StoreOccasion } from '@alfarid/api-client';
import { AnimatedNumber } from '@alfarid/ui';
import { useCatalogFilters, useSimilarProducts, useStoreCategories, useStoreProduct, useStoreProducts } from '@/hooks/use-store-catalog';
import { buildSearch, parseFilters, toApiParams, toFacetParams, type CatalogFilterState } from '@/lib/catalog/catalog-query';
import { DEFAULT_HERO, OCCASION_LABELS, SORT_OPTIONS } from '@/lib/catalog/catalog-labels';
import { findCategory, pick, priceRangeLabel, toLocalizedCardVM, type CatalogCardVM } from '@/lib/catalog/catalog-mappers';
import { rollupCategoryProductCount } from '@/lib/catalog/sophie-mappers';
import { ProductExperience } from '@/components/products/ProductExperience';
import { StoreImage } from '../StoreImage';
import { STORE_ROUTES } from '@/lib/orders/store-routes';
import { useStoreLang } from '@/providers/store-lang-provider';

const ALL_COLOR_BG = 'linear-gradient(135deg, #ef4444, #3b82f6, #10b981)';
const SORT_AR: Record<CatalogFilterState['sort'], string> = {
  featured: 'المختارة', newest: 'الأحدث', best_selling: 'الأكثر مبيعًا',
  price_asc: 'السعر من الأقل للأعلى', price_desc: 'السعر من الأعلى للأقل', name: 'الاسم',
};
const OCCASION_AR: Record<StoreOccasion, string> = {
  casual: 'يومي', work: 'الشغل', weekend: 'نهاية الأسبوع', evening: 'السهرات',
};

export function CategoriesContent() {
  const sp = useSearchParams();
  const { lang, isAr } = useStoreLang();
  const router = useRouter();
  const pathname = usePathname();
  const filters = useMemo(() => parseFilters(new URLSearchParams(sp.toString())), [sp]);
  const productSlug = sp.get('product');
  const activeView: 'catalog' | 'detail' = productSlug ? 'detail' : 'catalog';

  const updateFilters = (patch: Partial<CatalogFilterState>) =>
    router.replace(`${pathname}${buildSearch({ ...filters, ...patch })}`, { scroll: false });
  const resetFilters = () => router.replace(pathname, { scroll: false });
  const openProduct = (slug: string) => {
    router.push(`${STORE_ROUTES.product(slug)}${buildSearch(filters)}`);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };
  const closeProduct = () => router.push(`${pathname}${buildSearch(filters)}`, { scroll: false });

  // UI state (kept local): collapse filter sidebar on mobile by default to keep products visible
  const [isDesktop, setIsDesktop] = useState(true);
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  useEffect(() => {
    const desktop = window.matchMedia('(min-width: 993px)');
    setIsDesktop(desktop.matches);
    setIsSidebarOpen(desktop.matches);
    const onChange = (event: MediaQueryListEvent) => {
      setIsDesktop(event.matches);
      setIsSidebarOpen(event.matches);
    };
    desktop.addEventListener('change', onChange);
    return () => desktop.removeEventListener('change', onChange);
  }, []);

  useEffect(() => {
    if (isDesktop || !isSidebarOpen) return;
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setIsSidebarOpen(false);
    };
    document.addEventListener('keydown', onKey);
    return () => {
      document.body.style.overflow = prevOverflow;
      document.removeEventListener('keydown', onKey);
    };
  }, [isDesktop, isSidebarOpen]);
  const [toast, setToast] = useState<{ show: boolean; msg: string }>({ show: false, msg: '' });
  const [wishlist, setWishlist] = useState<Set<number>>(new Set());
  const wishlistRef = useRef(wishlist);
  wishlistRef.current = wishlist;
  const toastTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    try {
      const raw = localStorage.getItem('sophie_wishlist');
      if (raw) {
        const parsed = JSON.parse(raw);
        if (Array.isArray(parsed)) {
          setWishlist(new Set(parsed.filter((id): id is number => typeof id === 'number')));
        }
      }
    } catch { /* storage unavailable */ }
  }, []);

  useEffect(() => {
    return () => {
      if (toastTimerRef.current) clearTimeout(toastTimerRef.current);
    };
  }, []);

  const showToast = (msg: string) => {
    if (toastTimerRef.current) clearTimeout(toastTimerRef.current);
    setToast({ show: true, msg });
    toastTimerRef.current = setTimeout(() => {
      setToast({ show: false, msg: '' });
      toastTimerRef.current = null;
    }, 3200);
  };

  // Queries
  const categoriesQ = useStoreCategories();
  const facetsQ = useCatalogFilters(toFacetParams(filters));
  const productsQ = useStoreProducts(toApiParams(filters));
  const productQ = useStoreProduct(productSlug);
  const similarQ = useSimilarProducts(productSlug);

  const cards = useMemo(() => (productsQ.data?.pages ?? []).flatMap((p) => p.data).map((product) => toLocalizedCardVM(product, lang)), [productsQ.data, lang]);
  const total = productsQ.data?.pages[0]?.meta.total ?? 0;
  const roots = categoriesQ.data ?? [];
  const allCount = roots.reduce((t, c) => t + rollupCategoryProductCount(c), 0);
  const activeCat = findCategory(roots, filters.category);
  const activeFilterCount =
    (filters.category ? 1 : 0) +
    (filters.color ? 1 : 0) +
    (filters.priceMin !== null || filters.priceMax !== null ? 1 : 0) +
    filters.occasions.length +
    (filters.inStock ? 1 : 0) +
    (filters.onSale ? 1 : 0);

  const handleToggleWishlist = (productId: number, e: React.MouseEvent) => {
    e.stopPropagation();
    const next = new Set(wishlistRef.current);
    if (next.has(productId)) {
      next.delete(productId);
      showToast(isAr ? 'تمت الإزالة من المفضلة.' : 'Removed from your wishlist.');
    } else {
      next.add(productId);
      showToast(isAr ? 'تم الحفظ في المفضلة!' : 'Saved to your wishlist!');
    }
    wishlistRef.current = next;
    setWishlist(next);
    try { localStorage.setItem('sophie_wishlist', JSON.stringify([...next])); } catch { /* storage unavailable */ }
  };

  const toggleOccasion = (id: StoreOccasion) => {
    const next = filters.occasions.includes(id)
      ? filters.occasions.filter((o) => o !== id)
      : [...filters.occasions, id];
    updateFilters({ occasions: next });
  };

  const toggleColor = (id: string) => updateFilters({ color: filters.color === id ? null : id });

  const renderCard = (card: CatalogCardVM) => {
    const isWishlisted = wishlist.has(card.id);
    return (
      <article key={card.id} className="product-card">
        <div className="product-img-box">
          {card.badge && <span className={card.badgeType}>{card.badge}</span>}
          <div className="card-actions">
            <button type="button"
              className="action-btn" title="Choose options" aria-label={`Choose options for ${card.name}`}
              onClick={(e) => {
                e.stopPropagation();
                openProduct(card.slug);
              }}
            >
              <i className="fa-solid fa-bag-shopping" />
            </button>
            <button type="button" className="action-btn" title="Quick View" aria-label={`View ${card.name}`} onClick={() => openProduct(card.slug)}>
              <i className="fa-regular fa-eye" />
            </button>
            <button type="button" className="action-btn" title="Add to Wishlist" aria-label={`Save ${card.name} to wishlist`} aria-pressed={isWishlisted} onClick={(e) => handleToggleWishlist(card.id, e)}>
              <i className={isWishlisted ? 'fa-solid fa-heart' : 'fa-regular fa-heart'} style={{ color: isWishlisted ? '#e11d48' : '' }} />
            </button>
          </div>
          <Link href={`${STORE_ROUTES.product(card.slug)}${buildSearch(filters)}`} aria-label={card.name}>
            <StoreImage src={card.img ?? DEFAULT_HERO.img} className="" alt={card.name} loading="lazy" />
          </Link>
        </div>
        <p className="product-category-tag">{card.categoryLabel}</p>
        <h3 className="product-name"><Link href={`${STORE_ROUTES.product(card.slug)}${buildSearch(filters)}`}>{card.name}</Link></h3>
        <p className="product-price">
          {card.oldPriceLabel && <span className="old-price">{card.oldPriceLabel}</span>}
          {card.priceLabel}
        </p>
      </article>
    );
  };

  if (productSlug && productQ.data && !productQ.isError) {
    return <>
      <ProductExperience key={productQ.data.slug + ':' + (filters.color ?? '') + ':' + (sp.get('size') ?? '')} product={productQ.data} onBack={closeProduct} initialColor={filters.color} initialSize={sp.get('size')} />
      {(similarQ.data ?? []).length > 0 && <section className="categories-container related-section"><h2 className="related-title">{isAr ? 'منتجات قد تعجبك' : 'YOU MAY ALSO ADORE'}</h2><div className="product-grid">{(similarQ.data ?? []).slice(0, 4).map((p) => toLocalizedCardVM(p, lang)).map(renderCard)}</div></section>}
    </>;
  }

  const parentCat = activeCat && activeCat.path.length > 1 ? activeCat.path[activeCat.path.length - 2] : null;
  const subCategories = activeCat?.node.children ?? [];
  const heroTitleText = activeCat
    ? pick(activeCat.node.name_en, activeCat.node.name_ar, lang)
    : (isAr ? 'تشكيلة الشنط' : `${DEFAULT_HERO.light} ${DEFAULT_HERO.bold}`);
  const heroDesc = activeCat
    ? pick(activeCat.node.description_en, activeCat.node.description_ar, lang)
    : (isAr ? 'تصفح الشنط المتاحة واختار الموديل المناسب ليومك.' : DEFAULT_HERO.desc);
  const heroImg = activeCat?.node.image ?? DEFAULT_HERO.img;
  const countLabel = isAr
    ? (total === 1 ? 'منتج واحد' : total === 2 ? 'منتجان' : `${total} منتج`)
    : `${total} ${total === 1 ? 'piece' : 'pieces'}`;

  return (
    <main id="main-content" tabIndex={-1} className="categories-page">
      {/* ================= TOP WRAPPER & HEADER ================= */}
      {activeView === 'catalog' && <div className="cat-top-wrapper">
        <div className="categories-container">
          {/* Category Hero Banner */}
          <section className="cat-hero-banner">
            <nav aria-label={isAr ? 'مسار التصفح' : 'Breadcrumb'} className="cat-breadcrumbs">
              <Link href="/">{isAr ? 'الرئيسية' : 'HOME'}</Link>
              <span aria-hidden="true" className="cat-breadcrumb-sep">/</span>
              <Link href="/shop">{isAr ? 'المتجر' : 'SHOP'}</Link>
              {activeCat ? (
                activeCat.path.map((c, i) => {
                  const isLast = i === activeCat.path.length - 1;
                  return (
                    <React.Fragment key={c.slug}>
                      <span aria-hidden="true" className="cat-breadcrumb-sep">/</span>
                      {isLast ? (
                        <span className="active" aria-current="page">
                          {pick(c.name_en, c.name_ar, lang)}
                        </span>
                      ) : (
                        <Link href={`${pathname}?category=${c.slug}`}>
                          {pick(c.name_en, c.name_ar, lang)}
                        </Link>
                      )}
                    </React.Fragment>
                  );
                })
              ) : (
                <>
                  <span aria-hidden="true" className="cat-breadcrumb-sep">/</span>
                  <span className="active" aria-current="page">{isAr ? 'تشكيلة الشنط' : 'BAG COLLECTION'}</span>
                </>
              )}
            </nav>

            <div className="cat-hero-main">
              <div className="cat-hero-text">
                <h1 className="cat-hero-title">
                  {heroTitleText}
                </h1>
                {heroDesc && <p className="cat-hero-desc">{heroDesc}</p>}

                <div className="cat-hero-meta">
                  <span className="cat-hero-count-pill">
                    <span className="cat-hero-count-dot" aria-hidden="true" />
                    <span>{countLabel}</span>
                  </span>
                  {parentCat && (
                    <Link
                      href={`${pathname}?category=${parentCat.slug}`}
                      className="cat-hero-parent-link"
                    >
                      {pick(parentCat.name_en, parentCat.name_ar, lang)}
                    </Link>
                  )}
                </div>

                {subCategories.length > 0 && (
                  <div className="cat-hero-subcats" aria-label={isAr ? 'الأقسام الفرعية' : 'Subcategories'}>
                    {subCategories.map((child) => (
                      <button
                        key={child.slug}
                        type="button"
                        className="cat-hero-subcat-pill"
                        onClick={() => updateFilters({ category: child.slug })}
                      >
                        {pick(child.name_en, child.name_ar, lang)}
                      </button>
                    ))}
                  </div>
                )}
              </div>

              {heroImg && (
                <div className="cat-hero-decor">
                  <div className="cat-hero-img-frame">
                    <StoreImage src={heroImg} className="" alt={heroTitleText} />
                  </div>
                </div>
              )}
            </div>
          </section>
        </div>
      </div>}

      {/* ================= CATALOG CONTENT ================= */}
      <section className="catalog-section">
        <div className="categories-container">
          {activeView === 'catalog' ? (
            /* ================= VIEW 1: CATALOG GRID ================= */
            <div className="catalog-view">
              {/* Toolbar */}
              <div className="catalog-toolbar">
                <div className="toolbar-left">
                  <button
                    type="button"
                    className={`btn-filter-toggle ${isSidebarOpen ? '' : 'open'}`}
                    onClick={() => setIsSidebarOpen(!isSidebarOpen)}
                    aria-controls="catalog-filters"
                    aria-expanded={isSidebarOpen}
                  >
                    <i className="fa-solid fa-sliders" aria-hidden="true" />
                    <span>{isSidebarOpen ? (isAr ? 'إخفاء الفلاتر' : 'Hide Filters') : (isAr ? 'الفلاتر' : 'Filters')}</span>
                    {activeFilterCount > 0 && (
                      <span className="filter-active-badge" aria-label={isAr ? `${activeFilterCount} فلاتر مفعلة` : `${activeFilterCount} active filters`}>
                        {activeFilterCount}
                      </span>
                    )}
                  </button>
                  <span className="filter-count-badge">
                    {isAr ? 'عدد المنتجات ' : 'Showing '}<AnimatedNumber value={total} /> {isAr ? '' : 'pieces'}
                  </span>
                </div>

                {/* Quick Category Pills */}
                <div className="category-pills">
                  <button
                    type="button"
                    className={`cat-pill ${filters.category === null ? 'active' : ''}`}
                    onClick={() => updateFilters({ category: null })}
                  >
                    {isAr ? 'كل الشنط' : 'All Bags'}
                  </button>
                  {roots.map((c) => (
                    <button
                      type="button"
                      key={c.slug}
                      className={`cat-pill ${filters.category === c.slug ? 'active' : ''}`}
                      onClick={() => updateFilters({ category: c.slug })}
                    >
                      {pick(c.name_en, c.name_ar, lang)}
                    </button>
                  ))}
                </div>

                <div className="toolbar-right">
                  <div className="sort-wrap">
                    <span>{isAr ? 'الترتيب' : 'Sort by'}</span>
                    <select
                      aria-label={isAr ? 'ترتيب المنتجات' : 'Sort products'}
                      className="sort-select"
                      value={filters.sort}
                      onChange={(e) => updateFilters({ sort: e.target.value as CatalogFilterState['sort'] })}
                    >
                      {SORT_OPTIONS.map((o) => (
                        <option key={o.value} value={o.value}>{isAr ? SORT_AR[o.value] : o.label}</option>
                      ))}
                    </select>
                  </div>
                </div>
              </div>

              <div className="catalog-layout">
                {!isDesktop && isSidebarOpen && (
                  <div
                    className="cat-filter-backdrop"
                    onClick={() => setIsSidebarOpen(false)}
                    aria-hidden="true"
                  />
                )}
                {/* Sidebar Filters */}
                <aside
                  id="catalog-filters"
                  hidden={!isSidebarOpen}
                  role={!isDesktop && isSidebarOpen ? 'dialog' : undefined}
                  aria-modal={!isDesktop && isSidebarOpen ? true : undefined}
                  aria-label={isAr ? 'فلاتر المنتجات' : 'Product filters'}
                  className={`filter-sidebar ${!isDesktop && isSidebarOpen ? 'is-mobile-drawer' : ''}`}
                >
                  <div className="filter-sidebar-inner">
                    <div className="sidebar-header">
                      <div className="sidebar-header-left">
                        <span className="sidebar-title">{isAr ? 'تصفية حسب' : 'Refine By'}</span>
                        {activeFilterCount > 0 && (
                          <button type="button" className="btn-reset-filters" onClick={resetFilters}>
                            {isAr ? 'مسح الكل' : 'Reset All'}
                          </button>
                        )}
                      </div>
                      <button
                        type="button"
                        className="btn-close-sidebar"
                        onClick={() => setIsSidebarOpen(false)}
                        title={isAr ? 'إخفاء الفلاتر' : 'Hide Filters'}
                        aria-label={isAr ? 'إخفاء الفلاتر' : 'Hide Filters'}
                      >
                        <i className="fa-solid fa-xmark" aria-hidden="true" />
                      </button>
                    </div>

                    <div className="sidebar-scroll-body">
                      {facetsQ.isPending && (
                        <span className="sr-only" role="status">
                          {isAr ? 'جاري تحميل فلاتر البحث' : 'Loading filters'}
                        </span>
                      )}
                      {facetsQ.isError && (
                        <div role="alert" className="filter-error-box">
                          <p>{isAr ? 'تعذّر تحميل الفلاتر.' : 'Could not load filters.'}</p>
                          <button type="button" className="btn-reset-filters" onClick={() => { void facetsQ.refetch(); }}>
                            {isAr ? 'إعادة المحاولة' : 'Retry'}
                          </button>
                        </div>
                      )}

                      {/* Category Filter */}
                      <div className="filter-group">
                        <div className="group-heading">{isAr ? 'التصنيفات' : 'Categories'}</div>
                        <div className="filter-list">
                          <button type="button"
                            className={`filter-item ${filters.category === null ? 'active' : ''}`}
                            onClick={() => updateFilters({ category: null })}
                          >
                            <span>{isAr ? 'كل التصنيفات' : 'All Collections'}</span>
                            <span className="count"><AnimatedNumber value={allCount} /></span>
                          </button>
                          {roots.map((c) => (
                            <React.Fragment key={c.slug}>
                              <button type="button"
                                className={`filter-item ${filters.category === c.slug ? 'active' : ''}`}
                                onClick={() => updateFilters({ category: c.slug })}
                              >
                                <span>{pick(c.name_en, c.name_ar, lang)}</span>
                                <span className="count"><AnimatedNumber value={rollupCategoryProductCount(c)} /></span>
                              </button>
                              {(c.children ?? []).map((child) => (
                                <button type="button"
                                  key={child.slug}
                                  className={`filter-item ${filters.category === child.slug ? 'active' : ''}`}
                                  style={{ paddingInlineStart: 12 }}
                                  onClick={() => updateFilters({ category: child.slug })}
                                >
                                  <span>{pick(child.name_en, child.name_ar, lang)}</span>
                                  <span className="count"><AnimatedNumber value={rollupCategoryProductCount(child)} /></span>
                                </button>
                              ))}
                            </React.Fragment>
                          ))}
                        </div>
                      </div>

                      {/* Occasion Filter */}
                      <div className="filter-group">
                        <div className="group-heading">{isAr ? 'الاستخدام' : 'Occasion'}</div>
                        <div className="filter-list" aria-busy={facetsQ.isPending}>
                          {facetsQ.isPending ? (
                            Array.from({ length: 3 }).map((_, idx) => (
                              <div key={idx} className="filter-skel-row" aria-hidden="true" />
                            ))
                          ) : (
                            (facetsQ.data?.occasions ?? []).map((o) => {
                              const checked = filters.occasions.includes(o.id);
                              return (
                                <label key={o.id} className="checkbox-label">
                                  <input type="checkbox" checked={checked} onChange={() => toggleOccasion(o.id)} />
                                  <span className="custom-box">{checked ? '✓' : ''}</span>
                                  {isAr ? OCCASION_AR[o.id] : OCCASION_LABELS[o.id]} <span className="count">{o.count}</span>
                                </label>
                              );
                            })
                          )}
                        </div>
                      </div>

                      {/* Color Palette */}
                      <div className="filter-group">
                        <div className="group-heading">{isAr ? 'الألوان' : 'Color Palette'}</div>
                        <div className="color-swatches-grid" aria-busy={facetsQ.isPending}>
                          {facetsQ.isPending ? (
                            Array.from({ length: 8 }).map((_, idx) => (
                              <div key={idx} className="filter-skel-swatch" aria-hidden="true">
                                <span className="filter-skel-circle" />
                                <span className="filter-skel-label" />
                              </div>
                            ))
                          ) : (
                            <>
                              <button
                                type="button"
                                className={`swatch-btn ${filters.color === null ? 'active' : ''}`}
                                onClick={() => updateFilters({ color: null })}
                                title={isAr ? 'الكل' : 'All'}
                              >
                                <span className="swatch-circle" style={{ background: ALL_COLOR_BG }} />
                                <span className="swatch-name">{isAr ? 'الكل' : 'All'}</span>
                              </button>
                              {(facetsQ.data?.colors ?? []).map((c) => (
                                <button
                                  type="button"
                                  key={c.id}
                                  className={`swatch-btn ${filters.color === c.id ? 'active' : ''}`}
                                  onClick={() => toggleColor(c.id)}
                                  title={pick(c.name_en, c.name_ar, lang)}
                                >
                                  <span className="swatch-circle" style={{ background: c.hex ?? '#ccc' }} />
                                  <span className="swatch-name">{pick(c.name_en, c.name_ar, lang)} <span className="count">{c.count}</span></span>
                                </button>
                              ))}
                            </>
                          )}
                        </div>
                      </div>

                      {/* Price Range */}
                      <div className="filter-group">
                        <div className="group-heading">{isAr ? 'السعر' : 'Price Range'}</div>
                        <div className="filter-list" aria-busy={facetsQ.isPending}>
                          {facetsQ.isPending ? (
                            Array.from({ length: 4 }).map((_, idx) => (
                              <div key={idx} className="filter-skel-row" aria-hidden="true" />
                            ))
                          ) : (
                            <>
                              <label className="checkbox-label">
                                <input
                                  type="radio" name="priceRange" value="all"
                                  checked={filters.priceMin === null && filters.priceMax === null}
                                  onChange={() => updateFilters({ priceMin: null, priceMax: null })}
                                />
                                <span className="custom-box">
                                  {filters.priceMin === null && filters.priceMax === null ? '✓' : ''}
                                </span>
                                <span>{isAr ? 'كل الأسعار' : 'All Prices'}</span>
                              </label>
                              {(facetsQ.data?.price_ranges ?? []).map((r) => {
                                const isActive = filters.priceMin === r.min && filters.priceMax === r.max;
                                return (
                                  <label key={r.id} className="checkbox-label">
                                    <input
                                      type="radio"
                                      name="priceRange"
                                      value={r.id}
                                      checked={isActive}
                                      onChange={() => updateFilters({ priceMin: r.min, priceMax: r.max })}
                                    />
                                    <span className="custom-box">{isActive ? '✓' : ''}</span>
                                    <span>{priceRangeLabel(r, lang)} <span className="count">{r.count}</span></span>
                                  </label>
                                );
                              })}
                            </>
                          )}
                        </div>
                      </div>
                    </div>

                    {!isDesktop && (
                      <div className="sidebar-mobile-footer">
                        <button
                          type="button"
                          className="sidebar-apply-btn"
                          onClick={() => setIsSidebarOpen(false)}
                        >
                          {isAr ? `عرض النتائج (${total})` : `Show Results (${total})`}
                        </button>
                      </div>
                    )}
                  </div>
                </aside>

                {/* Product Grid */}
                <div className="catalog-main">
                  <div
                    className={`product-grid ${isSidebarOpen ? '' : 'four-cols'}`}
                    style={{ opacity: productsQ.isFetching && !productsQ.isFetchingNextPage ? 0.5 : 1 }}
                  >
                    {productsQ.isPending ? (
                      Array.from({ length: 6 }).map((_, i) => (
                        <div key={i} className="product-card skeleton">
                          <div className="product-img-box" />
                          <div className="sk-line" />
                          <div className="sk-line" />
                        </div>
                      ))
                    ) : productsQ.isError ? (
                      <div className="no-results" style={{ display: 'block' }}>
                        <h3>{isAr ? 'تعذر تحميل المنتجات' : "COULDN'T LOAD PRODUCTS"}</h3>
                        <p>{isAr ? 'حاول مرة تانية.' : 'Something went wrong while fetching the collection. Please try again.'}</p>
                        <button className="btn-filter-toggle" onClick={() => productsQ.refetch()}>
                          {isAr ? 'إعادة المحاولة' : 'Retry'}
                        </button>
                      </div>
                    ) : (
                      <>{cards.map(renderCard)}</>
                    )}

                    {/* Empty State */}
                    {!productsQ.isPending && !productsQ.isError && cards.length === 0 && (
                      <div className="no-results" style={{ display: 'block' }}>
                        <h3>{isAr ? 'مفيش شنط مطابقة' : 'NO BAGS FOUND'}</h3>
                        <p>{isAr ? 'جرّب تغير الفلاتر عشان تشوف منتجات تانية.' : "We couldn't find any bags matching your filter criteria."}</p>
                        <button className="btn-filter-toggle" onClick={resetFilters}>
                          {isAr ? 'مسح الفلاتر' : 'Reset All Filters'}
                        </button>
                      </div>
                    )}
                  </div>
                  {productsQ.hasNextPage && (
                    <button
                      className="btn-filter-toggle btn-load-more"
                      disabled={productsQ.isFetchingNextPage}
                      onClick={() => productsQ.fetchNextPage()}
                    >
                      {productsQ.isFetchingNextPage ? (isAr ? 'جاري التحميل…' : 'Loading…') : (isAr ? 'عرض المزيد' : 'Load more')}
                    </button>
                  )}
                </div>
              </div>
            </div>
          ) : productQ.isPending || productQ.isError || !productQ.data ? (
            /* ================= VIEW 2: PRODUCT DETAIL (loading / error) ================= */
            <div className="product-detail-view active">
              {productQ.isPending ? (
                <div className="product-card skeleton" role="status" aria-label="Loading product">
                  <div className="product-img-box" />
                  <div className="sk-line" />
                  <div className="sk-line" />
                </div>
              ) : (
                <div className="no-results" style={{ display: 'block' }}>
                  <h3>THIS PIECE IS NO LONGER AVAILABLE</h3>
                  <p>It may have sold out or been removed from the collection.</p>
                  <button className="btn-filter-toggle" onClick={closeProduct}>
                    Back to Collection
                  </button>
                </div>
              )}
            </div>
          ) : (
            null
          )}
        </div>
      </section>

      {/* ================= FLOATING TOAST ================= */}
      <div role="status" aria-live="polite" className={`cat-toast-notice ${toast.show ? 'show' : ''}`}>
        <i className="fa-solid fa-circle-check" style={{ color: '#4f7d88', fontSize: '18px' }} />
        <span>{toast.msg}</span>
      </div>
    </main>
  );
}
