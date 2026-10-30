'use client';

import React, { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import type { StoreOccasion } from '@alfarid/api-client';
import { useCatalogFilters, useSimilarProducts, useStoreCategories, useStoreProduct, useStoreProducts } from '@/hooks/use-store-catalog';
import { buildSearch, parseFilters, toApiParams, toFacetParams, type CatalogFilterState } from '@/lib/catalog-query';
import { DEFAULT_HERO, OCCASION_LABELS, SORT_OPTIONS } from '@/lib/catalog-labels';
import { findCategory, pick, priceRangeLabel, toCardVM, type CatalogCardVM } from '@/lib/catalog-mappers';
import { ProductExperience } from '@/components/products/ProductExperience';
import { StoreImage } from '../StoreImage';

const ALL_COLOR_BG = 'linear-gradient(135deg, #ef4444, #3b82f6, #10b981)';

export function CategoriesContent() {
  const sp = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const filters = useMemo(() => parseFilters(new URLSearchParams(sp.toString())), [sp]);
  const productSlug = sp.get('product');
  const activeView: 'catalog' | 'detail' = productSlug ? 'detail' : 'catalog';

  const updateFilters = (patch: Partial<CatalogFilterState>) =>
    router.replace(`${pathname}${buildSearch({ ...filters, ...patch })}`, { scroll: false });
  const resetFilters = () => router.replace(pathname, { scroll: false });
  const openProduct = (slug: string) => {
    router.push(`${pathname}${buildSearch(filters, { product: slug })}`);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };
  const closeProduct = () => router.push(`${pathname}${buildSearch(filters)}`, { scroll: false });

  // UI state (kept local): collapse filter sidebar on mobile by default to keep products visible
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  useEffect(() => {
    const desktop = window.matchMedia('(min-width: 993px)');
    setIsSidebarOpen(desktop.matches);
    const onChange = (event: MediaQueryListEvent) => setIsSidebarOpen(event.matches);
    desktop.addEventListener('change', onChange);
    return () => desktop.removeEventListener('change', onChange);
  }, []);
  const [toast, setToast] = useState<{ show: boolean; msg: string }>({ show: false, msg: '' });
  const [wishlist, setWishlist] = useState<Set<number>>(new Set());

  useEffect(() => {
    try {
      const raw = localStorage.getItem('sophie_wishlist');
      if (raw) setWishlist(new Set(JSON.parse(raw) as number[]));
    } catch { /* storage unavailable */ }
  }, []);

  const showToast = (msg: string) => {
    setToast({ show: true, msg });
    setTimeout(() => {
      setToast({ show: false, msg: '' });
    }, 3200);
  };

  // Queries
  const categoriesQ = useStoreCategories();
  const facetsQ = useCatalogFilters(toFacetParams(filters));
  const productsQ = useStoreProducts(toApiParams(filters));
  const productQ = useStoreProduct(productSlug);
  const similarQ = useSimilarProducts(productSlug);

  const cards = useMemo(() => (productsQ.data?.pages ?? []).flatMap((p) => p.data).map(toCardVM), [productsQ.data]);
  const total = productsQ.data?.pages[0]?.meta.total ?? 0;
  const roots = categoriesQ.data ?? [];
  const allCount = roots.reduce((t, c) => t + c.products_count, 0);
  const activeCat = findCategory(roots, filters.category);

  const handleToggleWishlist = (productId: number, e: React.MouseEvent) => {
    e.stopPropagation();
    setWishlist((prev) => {
      const next = new Set(prev);
      if (next.has(productId)) {
        next.delete(productId);
        showToast('Removed from your wishlist.');
      } else {
        next.add(productId);
        showToast('Saved to your wishlist!');
      }
      try { localStorage.setItem('sophie_wishlist', JSON.stringify([...next])); } catch { /* storage unavailable */ }
      return next;
    });
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
          <Link href={`${pathname}${buildSearch(filters, { product: card.slug })}`} aria-label={card.name}>
            <StoreImage src={card.img ?? DEFAULT_HERO.img} className="" alt={card.name} loading="lazy" />
          </Link>
        </div>
        <p className="product-category-tag">{card.categoryLabel}</p>
        <h3 className="product-name"><Link href={`${pathname}${buildSearch(filters, { product: card.slug })}`}>{card.name}</Link></h3>
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
      {(similarQ.data ?? []).length > 0 && <section className="categories-container related-section"><h2 className="related-title">YOU MAY ALSO ADORE</h2><div className="product-grid">{(similarQ.data ?? []).slice(0, 4).map(toCardVM).map(renderCard)}</div></section>}
    </>;
  }

  const heroTitle = activeCat ? { light: 'SHOP', bold: pick(activeCat.node.name_en, activeCat.node.name_ar).toUpperCase() } : { light: DEFAULT_HERO.light, bold: DEFAULT_HERO.bold };
  const heroDesc = activeCat ? (pick(activeCat.node.description_en, activeCat.node.description_ar) || DEFAULT_HERO.desc) : DEFAULT_HERO.desc;
  const heroImg = activeCat?.node.image ?? DEFAULT_HERO.img;

  return (
    <main id="main-content" tabIndex={-1} className="categories-page">
      {/* ================= TOP WRAPPER & HEADER ================= */}
      {activeView === 'catalog' && <div className="cat-top-wrapper">
        <div className="categories-container">
          {/* Category Hero Banner */}
          <section className="cat-hero-banner">
            <div className="cat-hero-text">
              <div className="cat-breadcrumbs">
                <Link href="/">HOME</Link> &nbsp;/&nbsp; <Link href="/shop">SHOP</Link> &nbsp;/&nbsp;{' '}
                {activeCat ? (
                  <span className="active">
                    {activeCat.path.map((c, i) => (
                      <span key={c.slug}>
                        {i > 0 && ' / '}
                        <Link href={`${pathname}?category=${c.slug}`}>{pick(c.name_en, c.name_ar).toUpperCase()}</Link>
                      </span>
                    ))}
                  </span>
                ) : (
                  <span className="active">BAG COLLECTION</span>
                )}
              </div>
              <h1 className="cat-hero-title">
                <span className="light-text">{heroTitle.light}</span>
                <span className="bold-text">{heroTitle.bold}</span>
              </h1>
              <p className="cat-hero-desc">{heroDesc}</p>
            </div>

            <div className="cat-hero-decor">
              <StoreImage src={heroImg} className="" alt="Sophie Signature Bag" />
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
                    className={`btn-filter-toggle ${isSidebarOpen ? '' : 'open'}`}
                    onClick={() => setIsSidebarOpen(!isSidebarOpen)}
                    aria-controls="catalog-filters"
                    aria-expanded={isSidebarOpen}
                  >
                    <i className="fa-solid fa-sliders" />
                    <span>{isSidebarOpen ? 'Hide Filters' : 'Show Filters'}</span>
                  </button>
                  <span className="filter-count-badge">
                    Showing {total} pieces
                  </span>
                </div>

                {/* Quick Category Pills */}
                <div className="category-pills">
                  <button
                    className={`cat-pill ${filters.category === null ? 'active' : ''}`}
                    onClick={() => updateFilters({ category: null })}
                  >
                    All Bags
                  </button>
                  {roots.map((c) => (
                    <button
                      key={c.slug}
                      className={`cat-pill ${filters.category === c.slug ? 'active' : ''}`}
                      onClick={() => updateFilters({ category: c.slug })}
                    >
                      {pick(c.name_en, c.name_ar)}
                    </button>
                  ))}
                </div>

                <div className="toolbar-right">
                  <div className="sort-wrap">
                    <span>Sort by</span>
                    <select
                      aria-label="Sort products"
                      className="sort-select"
                      value={filters.sort}
                      onChange={(e) => updateFilters({ sort: e.target.value as CatalogFilterState['sort'] })}
                    >
                      {SORT_OPTIONS.map((o) => (
                        <option key={o.value} value={o.value}>{o.label}</option>
                      ))}
                    </select>
                  </div>
                </div>
              </div>

              <div className="catalog-layout">
                {/* Sidebar Filters */}
                <aside id="catalog-filters" hidden={!isSidebarOpen} aria-label="Product filters" className="filter-sidebar">
                  <div className="filter-sidebar-inner">
                    <div className="sidebar-header">
                      <div className="sidebar-header-left">
                        <span className="sidebar-title">Refine By</span>
                        <button className="btn-reset-filters" onClick={resetFilters}>
                          Reset All
                        </button>
                      </div>
                      <button
                        className="btn-close-sidebar"
                        onClick={() => setIsSidebarOpen(false)}
                        title="Hide Filters" aria-label="Hide Filters"
                      >
                        <i className="fa-solid fa-xmark" />
                      </button>
                    </div>

                    {facetsQ.isPending && <p role="status">جاري تحميل فلاتر السعر والألوان...</p>}
                    {facetsQ.isError && <div role="alert">
                      <p>تعذّر تحميل فلاتر السعر والألوان.</p>
                      <button type="button" className="btn-reset-filters" onClick={() => { void facetsQ.refetch(); }}>إعادة المحاولة</button>
                    </div>}

                    {/* Category Filter */}
                    <div className="filter-group">
                      <div className="group-heading">Categories</div>
                      <div className="filter-list">
                        <button type="button"
                          className={`filter-item ${filters.category === null ? 'active' : ''}`}
                          onClick={() => updateFilters({ category: null })}
                        >
                          <span>All Collections</span>
                          <span className="count">{allCount}</span>
                        </button>
                        {roots.map((c) => (
                          <React.Fragment key={c.slug}>
                            <button type="button"
                              className={`filter-item ${filters.category === c.slug ? 'active' : ''}`}
                              onClick={() => updateFilters({ category: c.slug })}
                            >
                              <span>{pick(c.name_en, c.name_ar)}</span>
                              <span className="count">{c.products_count}</span>
                            </button>
                            {(c.children ?? []).map((child) => (
                              <button type="button"
                                key={child.slug}
                                className={`filter-item ${filters.category === child.slug ? 'active' : ''}`}
                                style={{ paddingLeft: 12 }}
                                onClick={() => updateFilters({ category: child.slug })}
                              >
                                <span>{pick(child.name_en, child.name_ar)}</span>
                                <span className="count">{child.products_count}</span>
                              </button>
                            ))}
                          </React.Fragment>
                        ))}
                      </div>
                    </div>

                    {/* Occasion Filter */}
                    <div className="filter-group">
                      <div className="group-heading">Occasion</div>
                      <div className="filter-list">
                        {(facetsQ.data?.occasions ?? []).map((o) => {
                          const checked = filters.occasions.includes(o.id);
                          return (
                            <label key={o.id} className="checkbox-label">
                              <input type="checkbox" checked={checked} onChange={() => toggleOccasion(o.id)} />
                              <span className="custom-box">{checked ? '✓' : ''}</span>
                              {OCCASION_LABELS[o.id]} <span className="count">{o.count}</span>
                            </label>
                          );
                        })}
                      </div>
                    </div>

                    {/* Color Palette */}
                    <div className="filter-group">
                      <div className="group-heading">Color Palette</div>
                      <div className="color-swatches-grid">
                        <button
                          className={`swatch-btn ${filters.color === null ? 'active' : ''}`}
                          onClick={() => updateFilters({ color: null })}
                          title="All"
                        >
                          <span className="swatch-circle" style={{ background: ALL_COLOR_BG }} />
                          <span className="swatch-name">All</span>
                        </button>
                        {(facetsQ.data?.colors ?? []).map((c) => (
                          <button
                            key={c.id}
                            className={`swatch-btn ${filters.color === c.id ? 'active' : ''}`}
                            onClick={() => toggleColor(c.id)}
                            title={pick(c.name_en, c.name_ar)}
                          >
                            <span className="swatch-circle" style={{ background: c.hex ?? '#ccc' }} />
                            <span className="swatch-name">{pick(c.name_en, c.name_ar)} <span className="count">{c.count}</span></span>
                          </button>
                        ))}
                      </div>
                    </div>

                    {/* Price Range */}
                    <div className="filter-group">
                      <div className="group-heading">Price Range</div>
                      <div className="filter-list">
                        <label className="checkbox-label" onClick={() => updateFilters({ priceMin: null, priceMax: null })}>
                          <input
                            type="radio" name="priceRange" value="all"
                            checked={filters.priceMin === null && filters.priceMax === null} readOnly
                          />
                          <span className="custom-box">
                            {filters.priceMin === null && filters.priceMax === null ? '✓' : ''}
                          </span>
                          <span>All Prices</span>
                        </label>
                        {(facetsQ.data?.price_ranges ?? []).map((r) => {
                          const isActive = filters.priceMin === r.min && filters.priceMax === r.max;
                          return (
                            <label key={r.id} className="checkbox-label" onClick={() => updateFilters({ priceMin: r.min, priceMax: r.max })}>
                              <input type="radio" name="priceRange" value={r.id} checked={isActive} readOnly />
                              <span className="custom-box">{isActive ? '✓' : ''}</span>
                              <span>{priceRangeLabel(r)} <span className="count">{r.count}</span></span>
                            </label>
                          );
                        })}
                      </div>
                    </div>
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
                        <h3>COULDN&apos;T LOAD PRODUCTS</h3>
                        <p>Something went wrong while fetching the collection. Please try again.</p>
                        <button className="btn-filter-toggle" onClick={() => productsQ.refetch()}>
                          Retry
                        </button>
                      </div>
                    ) : (
                      <>{cards.map(renderCard)}</>
                    )}

                    {/* Empty State */}
                    {!productsQ.isPending && !productsQ.isError && cards.length === 0 && (
                      <div className="no-results" style={{ display: 'block' }}>
                        <h3>NO BAGS FOUND</h3>
                        <p>We couldn&apos;t find any bags matching your filter criteria.</p>
                        <button className="btn-filter-toggle" onClick={resetFilters}>
                          Reset All Filters
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
                      {productsQ.isFetchingNextPage ? 'Loading…' : 'Load more'}
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
