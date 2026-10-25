'use client';

/* eslint-disable @next/next/no-img-element */
import React, { useState, useMemo } from 'react';
import Link from 'next/link';
import {
  CategoryProduct,
  CATEGORY_PRODUCTS,
  CATEGORY_PILLS,
  CATEGORIES_LIST,
  COLOR_SWATCHES,
  PRICE_RANGES,
  OCCASIONS,
} from '@/data/categories-mock';

export function CategoriesContent() {
  // Navigation & View State
  const [activeView, setActiveView] = useState<'catalog' | 'detail'>('catalog');
  const [selectedProduct, setSelectedProduct] = useState<CategoryProduct>(CATEGORY_PRODUCTS[0]);
  const [activeDetailImg, setActiveDetailImg] = useState<string>(CATEGORY_PRODUCTS[0].img);
  const [activeDetailTint, setActiveDetailTint] = useState<string>(CATEGORY_PRODUCTS[0].tint);
  const [selectedSize, setSelectedSize] = useState<string>(CATEGORY_PRODUCTS[0].sizes[0] || 'Medium (14L)');
  const [currentQty, setCurrentQty] = useState<number>(1);

  // Filter & Sort State
  const [isSidebarOpen, setIsSidebarOpen] = useState<boolean>(true);
  const [activeCategory, setActiveCategory] = useState<string>('all');
  const [activeColor, setActiveColor] = useState<string>('all');
  const [activePriceRange, setActivePriceRange] = useState<string>('all');
  const [sortBy, setSortBy] = useState<'featured' | 'price-asc' | 'price-desc' | 'name'>('featured');
  const [selectedOccasions, setSelectedOccasions] = useState<string[]>(['work']);

  // Cart & Wishlist State
  const [cartCount, setCartCount] = useState<number>(0);
  const [isCartBumped, setIsCartBumped] = useState<boolean>(false);
  const [wishlist, setWishlist] = useState<Set<string>>(new Set());
  const [toast, setToast] = useState<{ show: boolean; msg: string }>({ show: false, msg: '' });

  const showToast = (msg: string) => {
    setToast({ show: true, msg });
    setTimeout(() => {
      setToast({ show: false, msg: '' });
    }, 3200);
  };

  // Filter & Sort Logic
  const filteredProducts = useMemo(() => {
    const list = CATEGORY_PRODUCTS.filter((product) => {
      const matchCat = activeCategory === 'all' || product.category === activeCategory;
      const matchColor = activeColor === 'all' || product.color === activeColor;
      let matchPrice = true;
      if (activePriceRange === 'under-90') {
        matchPrice = product.priceValue < 90;
      } else if (activePriceRange === '90-120') {
        matchPrice = product.priceValue >= 90 && product.priceValue <= 120;
      } else if (activePriceRange === 'over-120') {
        matchPrice = product.priceValue > 120;
      }
      return matchCat && matchColor && matchPrice;
    });

    return list.sort((a, b) => {
      if (sortBy === 'price-asc') return a.priceValue - b.priceValue;
      if (sortBy === 'price-desc') return b.priceValue - a.priceValue;
      if (sortBy === 'name') return a.name.localeCompare(b.name);
      return 0; // featured default
    });
  }, [activeCategory, activeColor, activePriceRange, sortBy]);

  const resetAllFilters = () => {
    setActiveCategory('all');
    setActiveColor('all');
    setActivePriceRange('all');
    setSortBy('featured');
    setSelectedOccasions(['work']);
  };

  const toggleOccasion = (id: string) => {
    setSelectedOccasions((prev) =>
      prev.includes(id) ? prev.filter((item) => item !== id) : [...prev, id]
    );
  };

  const handleOpenDetail = (product: CategoryProduct) => {
    setSelectedProduct(product);
    setActiveDetailImg(product.img);
    setActiveDetailTint(product.tint);
    setSelectedSize(product.sizes[0] || 'Medium (14L)');
    setCurrentQty(1);
    setActiveView('detail');
    if (typeof window !== 'undefined') {
      window.scrollTo({ top: 350, behavior: 'smooth' });
    }
  };

  const handleBackToCatalog = () => {
    setActiveView('catalog');
    if (typeof window !== 'undefined') {
      window.scrollTo({ top: 350, behavior: 'smooth' });
    }
  };

  const handleAddToCart = (productName: string, qty: number) => {
    setCartCount((prev) => prev + qty);
    setIsCartBumped(true);
    setTimeout(() => setIsCartBumped(false), 300);
    showToast(`Added ${qty}x "${productName}" to your shopping bag!`);
  };

  const handleToggleWishlist = (productId: string, e: React.MouseEvent) => {
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
      return next;
    });
  };

  return (
    <div className="categories-page">
      {/* ================= TOP WRAPPER & HEADER ================= */}
      <div className="cat-top-wrapper">
        <div className="categories-container">
          <header className="cat-header">
            {/* Logo */}
            <Link href="/" className="cat-logo">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <polygon points="2,12 20,3 14,21 10,14 2,12" fill="#000" />
                <line x1="20" y1="3" x2="10" y2="14" stroke="#fff" strokeWidth="1.5" />
              </svg>
              Sophie
            </Link>

            {/* Navigation Links */}
            <ul className="cat-nav-links">
              <li>
                <Link href="/">HOME</Link>
              </li>
              <li>
                <Link href="/shop" className="active">
                  SHOP <i className="fa-solid fa-chevron-down" />
                </Link>
              </li>
              <li>
                <Link href="/categories">
                  CATEGORIES <i className="fa-solid fa-chevron-down" />
                </Link>
              </li>
              <li>
                <Link href="/design">3D SHOWCASE</Link>
              </li>
              <li>
                <Link href="/wholesale">WHOLESALE</Link>
              </li>
              <li>
                <a href="#contact">CONTACT US</a>
              </li>
            </ul>

            {/* Right Icons */}
            <div className="cat-header-icons">
              <button aria-label="Settings" title="Settings">
                <i className="fa-solid fa-gear" />
              </button>
              <button aria-label="Search" title="Search">
                <i className="fa-solid fa-magnifying-glass" />
              </button>
              <button
                className="cat-cart-icon-wrap"
                aria-label="Cart"
                onClick={() => showToast(`You have ${cartCount} item${cartCount === 1 ? '' : 's'} in your bag.`)}
              >
                <i className="fa-solid fa-cart-shopping" />
                <span className={`cat-cart-badge ${isCartBumped ? 'bump' : ''}`}>
                  {cartCount}
                </span>
              </button>
            </div>
          </header>

          {/* Category Hero Banner */}
          <section className="cat-hero-banner">
            <div className="cat-hero-text">
              <div className="cat-breadcrumbs">
                <Link href="/">HOME</Link> &nbsp;/&nbsp; <Link href="/shop">SHOP</Link> &nbsp;/&nbsp;{' '}
                <span className="active">BAG COLLECTION</span>
              </div>
              <h1 className="cat-hero-title">
                <span className="light-text">CURATED</span>
                <span className="bold-text">COLLECTIONS</span>
              </h1>
              <p className="cat-hero-desc">
                Explore our signature assortment of premium backpacks, totes, crossbodies, and artisanal leather
                goods tailored for modern elegance.
              </p>
            </div>

            <div className="cat-hero-decor">
              <img
                src="https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=500&q=80"
                className="tint-pink"
                alt="Sophie Signature Bag"
              />
            </div>
          </section>
        </div>
      </div>

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
                  >
                    <i className="fa-solid fa-sliders" />
                    <span>{isSidebarOpen ? 'Hide Filters' : 'Show Filters'}</span>
                  </button>
                  <span className="filter-count-badge">
                    Showing {filteredProducts.length} piece{filteredProducts.length === 1 ? '' : 's'}
                  </span>
                </div>

                {/* Quick Category Pills */}
                <div className="category-pills">
                  {CATEGORY_PILLS.map((pill) => (
                    <button
                      key={pill.id}
                      className={`cat-pill ${activeCategory === pill.id ? 'active' : ''}`}
                      onClick={() => setActiveCategory(pill.id)}
                    >
                      {pill.label}
                    </button>
                  ))}
                </div>

                <div className="toolbar-right">
                  <div className="sort-wrap">
                    <span>Sort by</span>
                    <select
                      className="sort-select"
                      value={sortBy}
                      onChange={(e) => setSortBy(e.target.value as any)}
                    >
                      <option value="featured">Featured ▾</option>
                      <option value="price-asc">Price: Low to High</option>
                      <option value="price-desc">Price: High to Low</option>
                      <option value="name">Product Name</option>
                    </select>
                  </div>
                </div>
              </div>

              <div className="catalog-layout">
                {/* Sidebar Filters */}
                <aside className={`filter-sidebar ${isSidebarOpen ? '' : 'hidden'}`}>
                  <div className="filter-sidebar-inner">
                    <div className="sidebar-header">
                      <div className="sidebar-header-left">
                        <span className="sidebar-title">Refine By</span>
                        <button className="btn-reset-filters" onClick={resetAllFilters}>
                          Reset All
                        </button>
                      </div>
                      <button
                        className="btn-close-sidebar"
                        onClick={() => setIsSidebarOpen(false)}
                        title="Hide Filters"
                      >
                        <i className="fa-solid fa-xmark" />
                      </button>
                    </div>

                    {/* Category Filter */}
                    <div className="filter-group">
                      <div className="group-heading">Categories</div>
                      <div className="filter-list">
                        {CATEGORIES_LIST.map((cat) => (
                          <div
                            key={cat.id}
                            className={`filter-item ${activeCategory === cat.id ? 'active' : ''}`}
                            onClick={() => setActiveCategory(cat.id)}
                          >
                            <span>{cat.label}</span>
                            <span className="count">{cat.count}</span>
                          </div>
                        ))}
                      </div>
                    </div>

                    {/* Occasion / Season Filter */}
                    <div className="filter-group">
                      <div className="group-heading">Occasion</div>
                      <div className="filter-list">
                        {OCCASIONS.map((occ) => {
                          const checked = selectedOccasions.includes(occ.id);
                          return (
                            <label key={occ.id} className="checkbox-label" onClick={() => toggleOccasion(occ.id)}>
                              <input type="checkbox" checked={checked} readOnly />
                              <span className="custom-box">{checked ? '✓' : ''}</span>
                              {occ.label}
                            </label>
                          );
                        })}
                      </div>
                    </div>

                    {/* Color Palette */}
                    <div className="filter-group">
                      <div className="group-heading">Color Palette</div>
                      <div className="color-swatches-grid">
                        {COLOR_SWATCHES.map((swatch) => (
                          <button
                            key={swatch.id}
                            className={`swatch-btn ${activeColor === swatch.id ? 'active' : ''}`}
                            onClick={() => setActiveColor(swatch.id)}
                            title={swatch.label}
                          >
                            <span className="swatch-circle" style={{ background: swatch.bg }} />
                            <span className="swatch-name">{swatch.label}</span>
                          </button>
                        ))}
                      </div>
                    </div>

                    {/* Price Range */}
                    <div className="filter-group">
                      <div className="group-heading">Price Range</div>
                      <div className="filter-list">
                        {PRICE_RANGES.map((range) => (
                          <label
                            key={range.id}
                            className="checkbox-label"
                            onClick={() => setActivePriceRange(range.id)}
                          >
                            <input
                              type="radio"
                              name="priceRange"
                              value={range.id}
                              checked={activePriceRange === range.id}
                              readOnly
                            />
                            <span className="custom-box">
                              {activePriceRange === range.id ? '✓' : ''}
                            </span>
                            <span>{range.label}</span>
                          </label>
                        ))}
                      </div>
                    </div>
                  </div>
                </aside>

                {/* Product Grid */}
                <div className="catalog-main">
                  <div className={`product-grid ${isSidebarOpen ? '' : 'four-cols'}`}>
                    {filteredProducts.map((product) => {
                      const isWishlisted = wishlist.has(product.id);
                      return (
                        <div
                          key={product.id}
                          className="product-card"
                          onClick={() => handleOpenDetail(product)}
                        >
                          <div className="product-img-box">
                            {product.badge && (
                              <span className={product.badgeType || 'badge'}>{product.badge}</span>
                            )}
                            <div className="card-actions">
                              <span className="action-btn" title="Quick View">
                                <i className="fa-regular fa-eye" />
                              </span>
                              <span
                                className="action-btn"
                                title="Add to Wishlist"
                                onClick={(e) => handleToggleWishlist(product.id, e)}
                              >
                                <i
                                  className={isWishlisted ? 'fa-solid fa-heart' : 'fa-regular fa-heart'}
                                  style={{ color: isWishlisted ? '#e11d48' : '' }}
                                />
                              </span>
                            </div>
                            <img src={product.img} className={product.tint} alt={product.name} />
                          </div>
                          <p className="product-category-tag">{product.categoryLabel}</p>
                          <h3 className="product-name">{product.name}</h3>
                          <p className="product-price">
                            {product.oldPrice && <span className="old-price">{product.oldPrice}</span>}
                            {product.price}
                          </p>
                        </div>
                      );
                    })}

                    {/* Empty State */}
                    {filteredProducts.length === 0 && (
                      <div className="no-results" style={{ display: 'block' }}>
                        <h3>NO BAGS FOUND</h3>
                        <p>We couldn&apos;t find any bags matching your filter criteria.</p>
                        <button className="btn-filter-toggle" onClick={resetAllFilters}>
                          Reset All Filters
                        </button>
                      </div>
                    )}
                  </div>
                </div>
              </div>
            </div>
          ) : (
            /* ================= VIEW 2: PRODUCT DETAIL PAGE (PDP) ================= */
            <div className="product-detail-view active">
              <div className="pdp-back-bar">
                <button className="pdp-back-btn" onClick={handleBackToCatalog}>
                  <i className="fa-solid fa-arrow-left" /> Back to Collection
                </button>
                <div className="cat-breadcrumbs" style={{ margin: 0 }}>
                  <Link href="/">HOME</Link> &nbsp;/&nbsp;{' '}
                  <span style={{ cursor: 'pointer' }} onClick={handleBackToCatalog}>
                    COLLECTION
                  </span>{' '}
                  &nbsp;/&nbsp; <span className="active">{selectedProduct.name}</span>
                </div>
              </div>

              <div className="pdp-layout">
                {/* Main Large Image */}
                <div className="pdp-main-img-box">
                  <img src={activeDetailImg} className={activeDetailTint} alt={selectedProduct.name} />
                </div>

                {/* Multi-Angle Thumbnails */}
                <div className="pdp-thumbnails">
                  {selectedProduct.thumbnails.map((thumb, idx) => (
                    <div
                      key={idx}
                      className={`pdp-thumb-box ${activeDetailImg === thumb.img && activeDetailTint === thumb.tint ? 'active' : ''}`}
                      onClick={() => {
                        setActiveDetailImg(thumb.img);
                        setActiveDetailTint(thumb.tint);
                      }}
                    >
                      <img src={thumb.img} className={thumb.tint} alt={`Angle ${idx + 1}`} />
                    </div>
                  ))}
                </div>

                {/* Product Specifications & Purchase Box */}
                <div className="pdp-info">
                  <div className="pdp-sku">Style: {selectedProduct.sku}</div>
                  <h2 className="pdp-title">{selectedProduct.name}</h2>

                  <div className="pdp-reviews-bar">
                    <div className="pdp-stars">
                      <i className="fa-solid fa-star" />
                      <i className="fa-solid fa-star" />
                      <i className="fa-solid fa-star" />
                      <i className="fa-solid fa-star" />
                      <i className="fa-solid fa-star-half-stroke" />
                    </div>
                    <span className="pdp-review-count">
                      {selectedProduct.rating} ({selectedProduct.reviewsCount} customer reviews)
                    </span>
                  </div>

                  <div className="pdp-price">{selectedProduct.price}</div>

                  <p className="pdp-desc">{selectedProduct.desc}</p>

                  {/* Color Swatches Selector */}
                  <div className="pdp-option-title">Select Colorway</div>
                  <div className="pdp-swatches">
                    {[
                      { tint: 'tint-pink', bg: '#e89aa8' },
                      { tint: 'tint-black', bg: '#1a1a1a' },
                      { tint: 'tint-beige', bg: '#d4b38d' },
                      { tint: 'tint-blue', bg: '#4f7d88' },
                    ].map((sw) => (
                      <div
                        key={sw.tint}
                        className={`pdp-swatch ${activeDetailTint === sw.tint ? 'active' : ''}`}
                        style={{ background: sw.bg }}
                        onClick={() => setActiveDetailTint(sw.tint)}
                      />
                    ))}
                  </div>

                  {/* Size / Capacity */}
                  <div className="pdp-option-title">Select Size / Volume</div>
                  <div className="pdp-sizes">
                    {selectedProduct.sizes.map((size) => (
                      <button
                        key={size}
                        className={`pdp-size-btn ${selectedSize === size ? 'active' : ''}`}
                        onClick={() => setSelectedSize(size)}
                      >
                        {size}
                      </button>
                    ))}
                  </div>

                  {/* Quantity & Add to Cart */}
                  <div className="pdp-action-row">
                    <div className="qty-picker">
                      <button className="qty-btn" onClick={() => setCurrentQty(Math.max(1, currentQty - 1))}>
                        -
                      </button>
                      <span className="qty-number">{currentQty}</span>
                      <button className="qty-btn" onClick={() => setCurrentQty(currentQty + 1)}>
                        +
                      </button>
                    </div>

                    <button
                      className="btn-add-cart"
                      onClick={() => handleAddToCart(selectedProduct.name, currentQty)}
                    >
                      <i className="fa-solid fa-bag-shopping" /> ADD TO SHOPPING BAG
                    </button>
                  </div>

                  {/* Trust Assurances */}
                  <div className="pdp-assurances">
                    <div className="pdp-assurance-item">
                      <i className="fa-solid fa-truck-fast" />
                      <span>Complimentary UK delivery on orders over £50</span>
                    </div>
                    <div className="pdp-assurance-item">
                      <i className="fa-solid fa-rotate-left" />
                      <span>Hassle-free 30-day return &amp; exchange guarantee</span>
                    </div>
                    <div className="pdp-assurance-item">
                      <i className="fa-solid fa-shield-halved" />
                      <span>2-Year Sophie Artisan craftsmanship warranty</span>
                    </div>
                  </div>
                </div>
              </div>

              {/* You May Also Like Section */}
              <div className="related-section">
                <h3 className="related-title">YOU MAY ALSO ADORE</h3>
                <div className="product-grid" style={{ gridTemplateColumns: 'repeat(3, 1fr)' }}>
                  {CATEGORY_PRODUCTS.slice(1, 4).map((related) => (
                    <div
                      key={related.id}
                      className="product-card"
                      onClick={() => handleOpenDetail(related)}
                    >
                      <div className="product-img-box">
                        {related.badge && (
                          <span className={related.badgeType || 'badge'}>{related.badge}</span>
                        )}
                        <img src={related.img} className={related.tint} alt={related.name} />
                      </div>
                      <h3 className="product-name">{related.name}</h3>
                      <p className="product-price">
                        {related.oldPrice && <span className="old-price">{related.oldPrice}</span>}
                        {related.price}
                      </p>
                    </div>
                  ))}
                </div>
              </div>

              {/* Promotional Split Banners */}
              <section className="banners-section">
                <div className="banners-grid">
                  <div className="promo-banner left-banner">
                    <div className="banner-img">
                      <img
                        src="https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?auto=format&fit=crop&w=500&q=80"
                        alt="New Collection Backpack"
                      />
                    </div>
                    <div className="banner-content">
                      <p className="banner-tag">NEW COLLECTION</p>
                      <h3 className="banner-heading">Backpack</h3>
                      <span
                        className="banner-link"
                        onClick={() => {
                          setActiveCategory('backpack');
                          setActiveView('catalog');
                        }}
                      >
                        View collection <i className="fa-solid fa-chevron-right" />
                      </span>
                    </div>
                  </div>

                  <div className="promo-banner right-banner">
                    <div className="banner-img">
                      <img
                        src="https://images.unsplash.com/photo-1590874103328-eac38a683ce7?auto=format&fit=crop&w=500&q=80"
                        alt="Bag Accessories"
                      />
                    </div>
                    <div className="banner-content">
                      <p className="banner-tag">BAG ACCESSORIES</p>
                      <h3 className="banner-heading">Accessories</h3>
                      <span
                        className="banner-link"
                        onClick={() => {
                          setActiveCategory('all');
                          setActiveView('catalog');
                        }}
                      >
                        View collection <i className="fa-solid fa-chevron-right" />
                      </span>
                    </div>
                  </div>
                </div>
              </section>
            </div>
          )}
        </div>
      </section>

      {/* ================= FLOATING TOAST ================= */}
      <div className={`cat-toast-notice ${toast.show ? 'show' : ''}`}>
        <i className="fa-solid fa-circle-check" style={{ color: '#4f7d88', fontSize: '18px' }} />
        <span>{toast.msg}</span>
      </div>
    </div>
  );
}
