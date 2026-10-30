'use client';

import React, { useState, useEffect, useRef } from 'react';
import { useRouter } from 'next/navigation';
import { STORE_ROUTES } from '@/lib/store-routes';
import { useSpotlight } from '@/hooks/useSpotlight';
import { useCartStore } from '@/stores/cart-store';
import { StoreProductImage } from './StoreProductImage';

/**
 * SophieMegaDropdown Component
 *
 * Luxury department store mega menu for Shop & Categories, fed by real
 * backend category data (`departments`, required — built by
 * `toMegaDepartments`). While `departments` is null a 3-column skeleton
 * is shown. The Offers tab is a direct link (the catalog API has no
 * discount sort/filter), so it renders without columns.
 *
 * @param {Object} props
 * @param {boolean} [props.isOpen] Controlled open state
 * @param {Function} [props.onClose] Callback to close mega menu
 * @param {string|null} [props.defaultDept] Initial active department (defaults to first key)
 * @param {Function} [props.onSelectCategory] Callback when a category link is clicked: (slug) => void
 * @param {Function} [props.onAddToCart] Callback when spotlight item is added to cart
 * @param {Function} [props.onGoToCheckout] Callback to navigate to checkout
 * @param {*} [props.departments] Departments data (required; null while loading)
 * @param {boolean} [props.error] Whether loading categories failed
 * @param {Function} [props.onRetry] Retry category loading
 * @param {string} [props.className] Additional CSS class
 */
export function SophieMegaDropdown({
  isOpen = false,
  onClose,
  defaultDept = null,
  onSelectCategory,
  onAddToCart,
  onGoToCheckout,
  departments = null,
  error = false,
  onRetry,
  className = ''
}) {
  const router = useRouter();
  const deptKeys = departments ? Object.keys(departments) : [];
  const [activeDeptKey, setActiveDeptKey] = useState(defaultDept ?? deptKeys[0] ?? null);
  const menuRef = useRef(null);
  const openerRef = useRef(null);
  const [currentCategory, setCurrentCategory] = useState(null);

  // Sync active dept when departments load or defaultDept changes
  useEffect(() => {
    setActiveDeptKey((current) => defaultDept && departments?.[defaultDept] ? defaultDept
      : departments?.[current] ? current : deptKeys[0] ?? null);
    if (isOpen) setCurrentCategory(new URL(window.location.href).searchParams.get('category'));
  }, [defaultDept, departments, isOpen]);

  // Remember the opener to restore focus after close
  useEffect(() => {
    if (isOpen) {
      openerRef.current = document.activeElement;
    }
  }, [isOpen]);

  // Handle ESC key to close
  useEffect(() => {
    function handleKeyDown(e) {
      if (e.key === 'Escape' && isOpen) {
        onClose?.();
        openerRef.current?.focus?.();
      }
    }
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, onClose]);

  const activeDept = (activeDeptKey && departments?.[activeDeptKey]) || (deptKeys.length ? departments[deptKeys[0]] : null);
  const spotlight = useSpotlight(activeDept?.slug, isOpen);
  const spot = spotlight.data;
  const addItem = useCartStore((s) => s.addItem);
  const cartBusy = useCartStore((s) => s.busy);
  const [addError, setAddError] = useState('');
  const adding = useRef(false);

  const handleTabChange = (deptKey) => {
    setActiveDeptKey(deptKey);
  };

  const handleCategoryClick = (e, slug) => {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1) return;
    onClose?.();
    if (onSelectCategory) {
      e.preventDefault();
      onSelectCategory(slug);
    }
  };

  const handleAddToCart = async (e) => {
    e.stopPropagation();
    if (!spot || adding.current) return;
    if (!spot.defaultVariantId || spot.variantCount !== 1) {
      onClose?.();
      router.push(STORE_ROUTES.product(spot.slug));
      return;
    }
    adding.current = true;
    setAddError('');
    const ok = await addItem(spot.defaultVariantId, 1);
    adding.current = false;
    if (ok) onAddToCart?.();
    else setAddError(useCartStore.getState().error || 'تعذّرت الإضافة، حاول تاني.');
  };

  const handleProceedCheckout = (e) => {
    e.preventDefault();
    onClose?.();
    if (onGoToCheckout) {
      onGoToCheckout();
    } else router.push('/checkout');
  };

  const spotIsMultiVariant = spot?.variantCount !== 1;
  const spotActionLabel = !spot || !spot.defaultVariantId || spotIsMultiVariant ? 'View product' : 'Add to cart';

  // Helper to render link columns
  const renderColumn = (colGroups = [], colIndex) => {
    return (
      <div key={`col-${colIndex}`} className="mega-col" id={`mega-col-${colIndex}`}>
        {colGroups.map((group, gIdx) => (
          <div key={`group-${gIdx}`} className="mega-group">
            <h4 className="mega-group-title">
              <a
                href={STORE_ROUTES.shop({ category: group.slug })}
                aria-current={currentCategory === group.slug ? 'page' : undefined}
                onClick={(e) => handleCategoryClick(e, group.slug)}
              >
                {group.title}
              </a>
            </h4>
            <ul className="mega-group-list">
              {group.links.map((link, lIdx) => (
                <li key={`link-${lIdx}`}>
                  <a
                    href={STORE_ROUTES.shop({ category: link.slug })}
                    aria-current={currentCategory === link.slug ? 'page' : undefined}
                    className="mega-link"
                    onClick={(e) => handleCategoryClick(e, link.slug)}
                    data-category={link.slug}
                  >
                    <span>{link.label}</span>
                    <span className="mega-link-count">{link.count}</span>
                  </a>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
    );
  };

  const renderSkeleton = () => (
    <div className="mega-body" aria-hidden="true">
      {[1, 2, 3].map((col) => (
        <div key={`skel-${col}`} className="mega-col">
          {[1, 2].map((g) => (
            <div key={`skel-${col}-${g}`} className="mega-group">
              <div className="mega-skel-title" />
              {[1, 2, 3].map((l) => (
                <div key={`skel-${col}-${g}-${l}`} className="mega-skel-link" />
              ))}
            </div>
          ))}
        </div>
      ))}
    </div>
  );

  return (
    <div
      ref={menuRef}
      className={`mega-dropdown-wrapper ${isOpen ? 'is-open' : ''} ${className}`}
      id="mega-dropdown"
      role="region"
      aria-label="Bag Store Mega Menu"
      aria-hidden={!isOpen}
      inert={!isOpen}
      onBlur={(event) => {
        if (event.relatedTarget && !menuRef.current?.contains(event.relatedTarget)
          && !event.relatedTarget.closest?.('[aria-controls="mega-dropdown"]')) onClose?.();
      }}
    >
      <div className="mega-dropdown-card">
        {/* Department Tabs Bar */}
        <div className="mega-dept-bar">
          <div className="mega-dept-tabs" role="tablist">
            {deptKeys.map((deptKey) => {
              const dept = departments[deptKey];
              const isActive = activeDeptKey === deptKey;

              return (
                <button
                  key={deptKey}
                  type="button"
                  role="tab"
                  aria-selected={isActive}
                  className={`mega-dept-tab ${isActive ? 'active' : ''}`}
                  onClick={() => handleTabChange(deptKey)}
                  onMouseEnter={() => handleTabChange(deptKey)}
                  onKeyDown={(event) => {
                    if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return;
                    event.preventDefault();
                    const index = deptKeys.indexOf(deptKey);
                    const next = event.key === 'Home' ? 0 : event.key === 'End' ? deptKeys.length - 1
                      : (index + (event.key === 'ArrowRight' ? 1 : -1) + deptKeys.length) % deptKeys.length;
                    handleTabChange(deptKeys[next]);
                    menuRef.current?.querySelectorAll('button[role="tab"]')[next]?.focus();
                  }}
                  data-dept-tab={deptKey}
                >
                  {dept.name}
                </button>
              );
            })}
            {/* Offers: direct link — no discount sort/filter in the catalog API, so no columns */}
            <a
              href={STORE_ROUTES.shop()}
              role="tab"
              aria-selected={false}
              className="mega-dept-tab offers-tab"
              onClick={(e) => handleCategoryClick(e, '')}
              data-dept-tab="offers"
            >
              Offers
            </a>
          </div>

          <button
            type="button"
            className="mega-close-btn"
            id="mega-close-btn"
            onClick={onClose}
            aria-label="Close mega menu"
            title="Close menu"
          >
            <i className="fa-solid fa-xmark" aria-hidden="true" />
          </button>
        </div>

        {/* Mega Menu Body: 3 Columns of Categories + 1 Spotlight Offer Card */}
        {!activeDept ? error ? <p role="alert" className="p-6 text-sm text-red-700">تعذّر تحميل الأقسام. <button type="button" className="underline font-semibold ml-1" onClick={onRetry}>إعادة المحاولة</button></p>
          : departments ? <p className="p-6 text-sm text-gray-600">لا توجد أقسام متاحة حاليًا. <a href="/shop" className="underline font-semibold">تصفّح المتجر</a></p> : renderSkeleton() : (
          <div className="mega-body" id="mega-body-content">
            {renderColumn(activeDept.col1, 1)}
            {renderColumn(activeDept.col2, 2)}
            {renderColumn(activeDept.col3, 3)}

            {/* Column 4: Spotlight Special Offer Product */}
            <div className="mega-spotlight" id="mega-spotlight-card">
              {spotlight.isError ? <p role="alert" className="text-sm text-red-700">تعذّر تحميل المنتج. <button type="button" className="underline font-semibold ml-1" onClick={() => spotlight.refetch()}>إعادة المحاولة</button></p>
                : spot === null ? <p className="text-sm text-gray-600">اكتشف منتجات القسم من الروابط.</p> : !spot ? (
                <div className="mega-spotlight-skel" aria-hidden="true">
                  <div className="mega-spotlight-skel-img" />
                  <div className="mega-skel-title" />
                  <div className="mega-skel-link" />
                </div>
              ) : (
                <>
                  <div className="mega-spotlight-img-wrap">
                    <span className="mega-special-badge" id="spotlight-badge">
                      {spot.discount ? `-${Math.round(spot.discount)}%` : 'SPOTLIGHT'}
                    </span>
                    <StoreProductImage
                      id="spotlight-img"
                      src={spot.img}
                      alt={spot.name}
                    />
                  </div>

                  <h5 className="mega-spotlight-title" id="spotlight-title">
                    {spot.name}
                  </h5>

                  <div className="mega-spotlight-price">
                    <span id="spotlight-price">{spot.priceLabel}</span>
                    {spot.oldPriceLabel && (
                      <span className="old-price" id="spotlight-old-price">
                        {spot.oldPriceLabel}
                      </span>
                    )}
                  </div>

                  <button
                    type="button"
                    className="mega-add-cart-btn"
                    id="mega-add-cart-btn"
                    onClick={handleAddToCart}
                    disabled={cartBusy}
                    aria-live="polite"
                    data-product={spot.name}
                    data-price={spot.price ?? ''}
                  >
                    {cartBusy
                      ? <span className="mega-btn-spinner" aria-hidden="true" />
                      : <i className="fa-solid fa-cart-shopping" aria-hidden="true" />}
                    {cartBusy ? 'Adding…' : spotActionLabel}
                  </button>
                  {addError && <p role="alert" className="text-xs text-red-700 mt-2">{addError}</p>}
                </>
              )}
            </div>
          </div>
        )}

        {/* Mega Footer Guarantee Bar */}
        <div className="mega-footer">
          <div className="mega-footer-perks">
            <span>
              <i className="fa-solid fa-truck-fast" aria-hidden="true" /> تفاصيل الشحن عند إتمام الطلب
            </span>
            <span>
              <i className="fa-solid fa-money-bill-wave" aria-hidden="true" /> الأسعار بالجنيه المصري
            </span>
            <span>
              <i className="fa-solid fa-bag-shopping" aria-hidden="true" /> اكتشف المجموعة
            </span>
          </div>

          <button
            type="button"
            className="mega-footer-cta"
            id="mega-goto-checkout"
            onClick={handleProceedCheckout}
          >
            Proceed to Checkout <i className="fa-solid fa-arrow-right" aria-hidden="true" />
          </button>
        </div>
      </div>
    </div>
  );
}

export default SophieMegaDropdown;